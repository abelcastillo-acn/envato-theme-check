## Context

See proposal.md – Why. Constraints that shape the approach:

- **Check contract** (`checkbase.php:24-31`): `themecheck` interface has only `check( $php_files, $css_files, $other_files ): bool` and `getError(): string[]`. `set_context( array $data )` is optional (duck-typed via `is_callable`, `checkbase.php:113`). Checks self-register with `$themechecks[] = new X();` and are loaded by `glob( __DIR__ . '/checks/*.php' )` (`checkbase.php:34-36`).
- **Pass/fail**: `$pass = $pass & $check->check(...)` (`checkbase.php:117`). Convention: only REQUIRED findings return `false` (`class-escaping-check.php` is the correct model; `class-envato-check.php` wrongly fails on WARNING).
- **Input shape**: `$php_files` keys are absolute paths, values are comment-stripped content (`tc_strip_comments`, `checkbase.php:282-310`, token-based, line numbers preserved). Files containing `tgm-plugin-activation` or `class-merlin` never reach checks (`checkbase.php:60`).
- **Output shape**: pre-rendered HTML per finding, `'<span class="tc-lead tc-{sev}">LABEL</span>: message <pre class=\'tc-grep\'>Line N: …</pre>'`, filtered by `wp_kses` allowing only `li, span[class], strong, code, pre, a[href]` (`main.php:125-139`). `display_themechecks()` (`checkbase.php:126-163`) does `array_unique` + `rsort` over the HTML strings; there is no structured data and no `apply_filters` on results.
- **Helpers**: `tc_preg( $regex, $absPath )` / `tc_grep( $literal, $absPath )` re-read the raw file from disk and return `<pre class='tc-grep'>Line N: …</pre>` blocks; `tc_filename( $absPath )` returns a theme-relative path.
- **Tokenizer precedent**: `class-textdomain-check.php:87-160` walks `token_get_all()` with a `$parens_balance` counter behind a `function_exists( 'token_get_all' )` guard.
- **WP-CLI divergence** (`theme-check-cli.php`): builds its own file list (L83-110) using `php_strip_whitespace()` (collapses newlines, keeps no comments stripped in the same way, omits parent-theme files) and treats WARNING as FAIL (L151-162). `tc_get_theme_data()` used by `active` (L254) does not exist.
- **Calibration data (timbero, 406 PHP files, WooCommerce)**: 22 `wp_ajax_*` registrations, all with a nonce call in the handler file; 11 `$wpdb->` call sites, all prepared except `inc/helpers/resize.php:66-67` (`$query = $wpdb->prepare(…); $wpdb->get_results( $query )` — safe idiom) and `inc/admin/import/trait-import-content.php:1292` (`$wpdb->query( $bit )` over an imported SQL dump — legitimate WARNING); 211 superglobal reads, all wrapped (`wc_clean`, `wp_unslash` + `sanitize_*`, `absint`, literal comparisons).
- **Environment**: PHP 7.0-compatible style in the plugin (`array()`, no typed properties). Runtime for tests: PHP 8.2.29 and WP-CLI inside the Local site shell; no Composer, no PHPUnit, no CI.

## Goals / Non-Goals

**Goals:**
- Zero REQUIRED false positives on `timbero`, `timbero-child` and `twentytwentyfive`; WARNINGs on timbero limited to the known legitimate cases.
- Each rule independently promotable (WARNING → REQUIRED) via one constant or the `tc_rule_severity` filter.
- New findings carry `check`/`file`/`line` structurally; legacy output unchanged.
- Same results under admin and WP-CLI.

**Non-Goals:**
- Full taint/data-flow analysis; interprocedural resolution beyond "callback name → method body" and "variable → last assignment in scope".
- Rewriting legacy checks to the structured API.
- Fixing `class-envato-check.php`'s WARNING-fails-run behaviour (separate change).

## Decisions

### D1. Tokenizer-assisted detection with a regex pre-filter
All three checks need *scope* (which function body am I in?) or *argument boundaries* (what exactly is passed to `$wpdb->query()`?). Line regexes cannot express either — `class-customizer-check.php`'s `[^;]+` capture is the cautionary example (breaks on any body with more than one statement). `token_get_all()` is already used in the plugin (`tc_strip_comments`, `class-textdomain-check.php`, `class-i18n-check.php`).

Each check first runs a cheap `preg_match` on the comment-stripped content (`add_action\s*\(\s*['"](wp_ajax_|admin_post_|wc_ajax_)|\$_(POST|REQUEST|GET)\b` / `\$wpdb\s*->\s*(query|get_results|get_var|get_row|get_col|prepare)\s*\(|mysqli?_|new\s+\\?(mysqli|PDO)|PDO::` / `\$_(GET|POST|REQUEST|COOKIE|FILES|SERVER)\b`) and tokenizes only matching files (~10 % of a large theme).

*Alternative rejected*: regex-only like the rest of the plugin — cheaper to write, but produces either many false positives (`$wpdb->query($var)` flagged as REQUIRED, breaking the timbero `resize.php` idiom) or many false negatives.

### D2. Re-tokenize from disk, not from `$php_files`
Because WP-CLI passes `php_strip_whitespace()` output (newlines collapsed), token line numbers derived from `$php_files` values would be wrong under CLI. The helpers read the file at the array key path, run `tc_strip_comments()`, and tokenize that. A single-slot static cache keyed by path means each of the three checks tokenizes a given file once and memory never holds more than one token stream. Task 2 additionally makes the CLI reuse `run_themechecks_against_theme()`, removing the divergence at the source.

### D3. Shared helper file `tc-tokens.php`
Kept out of `checkbase.php` to keep that file diffable against upstream Theme Check. `require_once __DIR__ . '/tc-tokens.php';` is inserted in `checkbase.php` just before the checks glob (L33). All functions are wrapped in `if ( ! function_exists() )` because `checkbase.php` is `include`d from both the admin page callback and the CLI file.

Minimal API (PHP 7.0 style, arrays not objects):

| Function | Purpose |
|---|---|
| `tc_tokens_for_file( $path, $fallback_content = '' )` | Comment-stripped tokens for a file; returns list of `[ id|null, text, line, offset ]`; single-slot cache |
| `tc_tokens( $code )` | Normalise `token_get_all()` output to the 4-tuple form |
| `tc_next_sig( $tokens, $i )`, `tc_prev_sig( $tokens, $i )` | Next/previous non-whitespace token index or −1 |
| `tc_match( $tokens, $i )` | Index of the matching closer for `(`, `[`, `{`, `T_CURLY_OPEN`, `T_DOLLAR_OPEN_CURLY_BRACES` |
| `tc_token_scopes( $tokens )` | List of `{ type: file|function|method|closure, name, class, start, open, end, line }`; element 0 is file scope |
| `tc_scope_at( $scopes, $i )` | Innermost non-file scope containing token `$i`, else file scope |
| `tc_find_calls( $tokens, array $names, $object = null, $from = 0, $to = null )` | Calls by name; `$object = '$wpdb'` requires `$wpdb ->` before the name; returns `{ name, index, open, close, line, args: [[from,to],…] }` with depth-0 comma splitting |
| `tc_call_chain( $tokens, $i, $max_depth = 5 )` | Enclosing callee names for token `$i`, innermost first, within the current statement; includes casts as `(int)` and constructs `isset`, `empty`, `unset`, `switch`, `if`, `elseif`, `while`, `array`, `[` |
| `tc_last_assignment( $tokens, $var_name, $scope_start, $before )` | Token range of the RHS of the last `$var =` before `$before` within the scope, or `null` |
| `tc_tokens_text( $tokens, $from, $to )` | Source text for a token range |
| `tc_excerpt( $file, $line, $highlight = '' )` | `<pre class='tc-grep'>Line N: …</pre>` for one known line (75-char truncation like `tc_grep`) |
| `tc_is_vendor_path( $file )` | Matches `apply_filters( 'tc_vendor_paths', array( 'redux-framework', 'redux-core', 'ReduxCore', 'kirki', 'cmb2', 'one-click-demo-import', 'ocdi', 'wp-color-picker-alpha', 'aq_resizer', 'class-tgm-plugin-activation', 'merlin', 'envato-market', 'vendor/', 'node_modules/' ) )`; normalises `\` to `/` |

PHP 8-only token constants (`T_NAME_QUALIFIED`, `T_NAME_FULLY_QUALIFIED`, `T_NULLSAFE_OBJECT_OPERATOR`) are referenced through `defined()` guards. When `token_get_all` is unavailable the checks return `true` and emit nothing (established convention in `class-i18n-check.php`).

### D4. Per-rule severity table + policy/detection split
Each check declares `const RULES = array( 'rule/id' => 'required|warning|recommended|info' )` and resolves the effective severity with `apply_filters( 'tc_rule_severity', $severity, $rule_id )`. `check()` returns `false` only if at least one finding was emitted at `required`. Promotion = one edited array value (or an mu-plugin filter for pre-testing). Every message states the Envato requirement ("ThemeForest requirement: …") so authors know the rule is mandatory even when the plugin can only say WARNING.

*Alternative rejected*: a single severity per check (like legacy checks) — would force all nonce rules to REQUIRED or all to WARNING.

### D5. Nonce check algorithm
1. Pass 1 (all files): `tc_token_scopes()` per file; index method/function name → candidate bodies.
2. Pass 2 (per file): `preg_match_all( '/add_action\s*\(\s*([\'"])((?:wp_ajax_(?:nopriv_)?|admin_post_(?:nopriv_)?|wc_ajax_)[A-Za-z0-9_\-]+)\1\s*,/', …, PREG_OFFSET_CAPTURE )`; locate the callback tokens after the comma; resolve: closure → its body; string `'fn'` / `'Class::m'` → indexed body (prefer same class); `array( $this, 'm' )` / `array( 'Class', 'm' )` → indexed body; anything else → unresolved counter.
3. Analyse body: `has_verify` (`check_ajax_referer|check_admin_referer|wp_verify_nonce`), `delegated` (name matches `/nonce|referer|verify|security|permission|capab/i`), `has_cap` (`current_user_can|user_can|current_user_can_for_blog|is_super_admin`), `writes` (any `T_STRING` in `STATE_CHANGING`, filter `tc_state_changing_functions`, or `$wpdb->query|insert|update|delete|replace`).
4. Emit per the rule table in the spec. `admin_post_*` privileged handlers are flagged regardless of writes (admin-post is a form submit by construction); `wp_ajax_*` privileged handlers likewise; `nopriv` only when `writes`.
5. Pass 3 (per file): for every scope not reported in pass 2, `reads` (superglobal read tokens) ∧ `writes` ∧ ¬`has_verify` → `nonce/form-handler` (or `nonce/delegated`).

`STATE_CHANGING` initial list: `update_option add_option delete_option update_site_option update_post_meta add_post_meta delete_post_meta update_user_meta add_user_meta delete_user_meta update_term_meta update_metadata add_metadata delete_metadata wp_insert_post wp_update_post wp_delete_post wp_trash_post wp_insert_attachment wp_insert_user wp_update_user wp_delete_user wp_create_user wp_set_password wp_insert_term wp_update_term wp_delete_term wp_set_object_terms wp_insert_comment wp_update_comment wp_delete_comment wp_mail set_theme_mod remove_theme_mod set_transient delete_transient wp_handle_upload wp_handle_sideload media_handle_upload media_sideload_image download_url activate_plugin deactivate_plugins switch_theme file_put_contents unlink rename copy move_uploaded_file wp_mkdir_p setcookie wp_set_auth_cookie wp_set_current_user wp_signon add_role remove_role wp_schedule_event`. WooCommerce cart operations are deliberately excluded (public by design).

### D6. SQL check algorithm
- Raw drivers: regex on stripped content (`(?<![\w\$>:\\])(mysqli?_(connect|pconnect|query|real_query|multi_query|unbuffered_query|select_db|db_query|prepare|execute)|mysqli_stmt_[a-z_]+)\s*\(|(?<![\w\$])new\s+\\?(mysqli|PDO)\s*\(|(?<![\w\$\\])PDO::`), confirmed via tokens not to be inside a string literal. `function_exists('mysqli_connect')` does not match (quote before `)`).
- `$wpdb` calls via `tc_find_calls( $tokens, [query,get_results,get_var,get_row,get_col,prepare], '$wpdb' )`. Classify `args[0]`:
  - **CONST**: only `T_CONSTANT_ENCAPSED_STRING`, or interpolated/heredoc strings whose embedded variables are all `$wpdb->…`, or `.`-concatenation of those.
  - **CONCAT**: contains `.`/interpolation/`T_CURLY_OPEN`/`sprintf|implode|str_replace` with a non-`$wpdb` variable, constant or call operand.
  - **ESCAPED**: CONCAT where every non-`$wpdb` operand is directly wrapped in `esc_sql|absint|intval|(int)|(float)|floatval`.
  - **VARIABLE**: a single variable/property/call expression that is not `$wpdb->prepare(`.
- CONST → nothing; CONCAT → `sql/concat`; ESCAPED → `sql/esc-sql-concat`; VARIABLE (plain local) → `tc_last_assignment()`: `prepare(` → safe, CONST → safe, CONCAT → `sql/variable-arg-concat`, else `sql/variable-arg`. One-level propagation: a variable whose assignment is CONST-shaped with only `$wpdb->` operands is treated as a `$wpdb` operand in the calling expression (covers `$table = $wpdb->prefix . 'x'`).
- `prepare()` first argument: literal without `%(?:\d+\$)?[dsfFi]` and no further args → `sql/prepare-no-placeholders`; interpolated/concatenated non-`$wpdb` variable → `sql/prepare-interpolated`; variable whose assignment is CONCAT → `sql/prepare-variable-format`.
- `$GLOBALS['wpdb']` is recognised as `$wpdb`; aliases (`$db = $wpdb`) are not (accepted false negative).

### D7. Superglobals check algorithm
For each `T_VARIABLE` in `{$_GET,$_POST,$_REQUEST,$_COOKIE,$_FILES,$_SERVER}`: extend over `[...]` chains; skip if followed by an assignment operator (write); safe if adjacent to a comparison operator with a literal/constant operand, preceded by `!`, or the direct operand of an `if/elseif/while` condition; else compute `tc_call_chain()` (≤ 5 levels, stops at statement boundaries): `extract` → `superglobals/extract`; any element in SAFE_CASTS ∪ GUARDS ∪ SANITIZERS → safe; `array_map|array_filter|array_walk` whose first argument is a quoted sanitizer name → safe; first element matching `/^(sanitize|sanitise|validate|clean|filter|wc_clean|wc_sanitize)/i` (function or method) → safe; indexless use → `superglobals/whole-array`; otherwise collect. Pass-throughs (`wp_unslash stripslashes stripslashes_deep urldecode trim strtolower strtoupper json_decode explode array_keys`) do not terminate the chain.

SANITIZERS (filter `tc_sanitizing_functions`): `absint intval floatval boolval doubleval sanitize_* wp_kses wp_kses_post wp_kses_data wp_filter_kses wp_filter_nohtml_kses wp_filter_post_kses wp_strip_all_tags wp_parse_id_list wp_parse_list wp_parse_slug_list wp_validate_boolean rest_sanitize_value_from_schema rest_sanitize_boolean filter_var filter_var_array esc_url_raw sanitize_url esc_sql esc_html esc_attr esc_textarea esc_js esc_url wp_verify_nonce check_ajax_referer check_admin_referer wp_check_filetype wp_check_filetype_and_ext wp_handle_upload wp_handle_sideload media_handle_upload wc_clean wc_stock_amount wc_format_decimal wc_string_to_bool wc_sanitize_* number_format round ceil floor min max md5 sha1 hash wp_hash crc32 date`. Including `esc_*` output escapers is deliberately lenient (WPCS is stricter) because `<input value="<?php echo esc_attr( $_POST['email'] ); ?>">` is safe and ubiquitous; the message still says "manual review".

Aggregation: one finding per file with count, ≤ 8 excerpts via `tc_excerpt()`, "and N more".

### D8. Structured findings without touching the interface
- `tc_error( $severity, $check_id, $message_html, $file = '', $line = 0, $evidence = '', $docs_url = '' )` in `checkbase.php` builds both the legacy HTML (label first, then `: `, then message, optional `<a href>Learn more</a>`, then evidence `<pre>`) and the structured array `{ severity, check, message, file, line, evidence, html }`.
- New checks keep `protected $results = array()`; `getError()` returns `array_column( $this->results, 'html' )`; `getStructuredErrors()` returns `$this->results`.
- `tc_result_from_legacy_html( $html, $check_class )` derives severity from the label text inside `.tc-lead` first (because `class-envato-check.php:87,121` emit `tc-warning` with label REQUIRED), then the class; extracts `<pre class='tc-grep'>Line N: …</pre>` evidence; best-effort `file` from the first `<strong>` that looks like a path.
- `tc_collect_results()` iterates `$themechecks`, uses `getStructuredErrors()` when callable else wraps `getError()`, dedupes by `html`, then `apply_filters( 'themecheck_findings', $results, $context )`. `run_themechecks()` stores `$context` in a new global `$theme_check_current_context`.
- `display_themechecks()` becomes `array_column( tc_collect_results(), 'html' )` + the existing `rsort` and `<li>` rendering — byte-identical output.
- `theme-check-cli.php`: replace the manual walk with `run_themechecks_against_theme( $theme, $theme->get_stylesheet() )`; keep the five existing JSON keys built from `strip_tags( $r['html'] )`; add `findings` with `severity` (upper-case label), `check`, `message` (plain text), `file`, `line`.

*Alternative rejected*: changing the `themecheck` interface — would break all 60 legacy checks and any third-party check hooked on `themecheck_checks_loaded`.

### D9. Vendor paths → INFO, never hidden
Findings inside bundled frameworks are downgraded to INFO (except `sql/raw-driver` and `superglobals/extract`, which stay REQUIRED). Reviewers still see them; ThemeForest policy asks authors to keep bundled frameworks current, not to patch them. Hiding would create blind spots for genuinely vulnerable bundled code.

## Author impact

### Nonce / CSRF / capability
- **Prevalence: high.** Demo importers (`wp_ajax_*_import_*`), theme-option save handlers (`admin_init` + `isset( $_POST['save'] )`), AJAX product filters / infinite scroll / quick view / mini-cart / wishlist in WooCommerce themes, mega-menu builders, "dismiss notice" endpoints, contact/newsletter forms. Since Envato's 2019+ requirements most privileged handlers do call `check_ajax_referer`, but capability checks are frequently missing (importers callable by any logged-in role) and `nopriv` handlers that write (wishlist/compare cookies) often lack nonces.
- **Effort:** trivial per handler (2 lines of PHP plus passing the nonce from JS); **hours** for a theme with 15–30 handlers because JS and `wp_localize_script` need touching and retesting.
- **Before → after**
  ```php
  // before
  add_action( 'wp_ajax_theme_import_demo', 'theme_import_demo' );
  function theme_import_demo() {
      $demo = $_POST['demo'];
      update_option( 'theme_demo', $demo );
      wp_send_json_success();
  }
  // after
  function theme_import_demo() {
      check_ajax_referer( 'theme_import', 'nonce' );
      if ( ! current_user_can( 'manage_options' ) ) {
          wp_send_json_error( 'forbidden', 403 );
      }
      $demo = sanitize_key( wp_unslash( $_POST['demo'] ?? '' ) );
      update_option( 'theme_demo', $demo );
      wp_send_json_success();
  }
  // JS side
  wp_localize_script( 'theme-admin', 'themeAdmin', array( 'nonce' => wp_create_nonce( 'theme_import' ) ) );
  ```
- **False-positive risk:** medium — wrapper-based verification (INFO), verification in a dispatcher/constructor (WARNING, reviewer confirms), read-only `nopriv` endpoints (not flagged), bundled frameworks (INFO).
- **Reviewer guidance:** every `wp_ajax_*`/`admin_post_*` handler must verify a nonce; privileged handlers that write must also check a capability; public read-only endpoints may skip the nonce but must sanitize input. Point to the exact function and request the fix in the same round.

### SQL without prepare()
- **Prevalence: low-to-medium**, concentrated in risky spots: post-view counters (`UPDATE … WHERE ID = $post_id`), popular/related-posts widgets, custom search, demo importers (`REPLACE(post_content, '$old', '$new')`), attachment-by-URL lookups (aq_resizer style, usually prepared), custom tables for wishlists/compare. Raw `mysqli_*`/PDO is rare (< 1 %), usually debug leftovers or a bundled non-WP library.
- **Effort:** trivial (minutes per query). Only tricky case: dynamic `ORDER BY`/table identifiers → allowlist plus `%i` (WP 6.2+) or `esc_sql()`.
- **Before → after**
  ```php
  $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = $post_id AND meta_key = 'views'" );
  $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s", $post_id, 'views' ) );
  ```
- **False-positive risk:** low for REQUIRED shapes; medium for WARNING shapes (`$wpdb->query( $sql )` in importers, `prepare( $sql, $args )` with `$sql` built from allowlisted fragments).
- **Reviewer guidance:** REQUIRED findings are hard rejects unless the author shows the interpolated value is a constant or allowlisted identifier. WARNING findings: open the file, follow the variable; approve if it is a `prepare()` result or a constant.

### Unsanitized superglobals
- **Prevalence: very high.** `$_GET['orderby'|'view'|'layout']` for shop toggles, `$_GET['s']`, `$_REQUEST['paged']`, `$_POST['action']` in AJAX, `$_COOKIE['theme_layout'|'compare_ids']`, `$_SERVER['REQUEST_URI']` for active-menu/pagination/canonical, `$_FILES` in importers. Expect 20–150 raw reads in an unaudited theme; ~0 in a theme that already passed WPCS (timbero: 0).
- **Effort:** trivial per line, **hours to a day** by volume; no design changes.
- **Before → after**
  ```php
  $layout = $_GET['layout'];
  $layout = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( $_GET['layout'] ) ) : 'grid';
  $layout = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';

  $uri = $_SERVER['REQUEST_URI'];
  $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
  ```
- **False-positive risk:** medium-high per line (values passed to APIs that sanitize internally), low per file thanks to aggregation.
- **Reviewer guidance:** advisory. Skim the excerpts: a raw read that flows into SQL, `include`, `header()`, `wp_redirect()` or HTML output is a reject; a raw read compared to a literal or passed into a casting WP API is fine.

## Rollout

| Phase | When | Action |
|---|---|---|
| 0 | Release (v2.1.0) | REQUIRED only for `sql/raw-driver`, `sql/concat`, `superglobals/extract`. Everything else WARNING/INFO. Reviewer guidance: WARNINGs from these checks are *soft-reject* items (request fix in the round) for 4–6 weeks. |
| 1 | Weeks 1–6 | Census on ~20 recently approved themes (`tests/census.php`); collect false positives via GitHub issues labelled `security-checks`; tune allowlists through the `tc_sanitizing_functions` / `tc_state_changing_functions` / `tc_vendor_paths` filters first, constants second. |
| 2 | ~Week 6 | Promote `sql/variable-arg-concat` and `nonce/capability-missing` to REQUIRED. |
| 3 | ~Week 10 | Promote `nonce/ajax-missing` to REQUIRED. `superglobals/unsanitized` stays WARNING indefinitely. |

Promotion mechanics: edit one value in the check's `RULES` constant, bump the plugin version, add a readme changelog line. Reviewers can pre-test a promotion without a release via `add_filter( 'tc_rule_severity', … )` in an mu-plugin.

Published caveat: WP-CLI already reports `FAIL` on any WARNING; teams automating on JSON should read `findings[].severity`.

## Risks / Trade-offs

- [CLI semantics differ from admin] → Task 2 makes CLI reuse `run_themechecks_against_theme()`; document that WARNING = FAIL in CLI.
- [Noise from bundled frameworks dominates findings] → vendor-path INFO downgrade is mandatory, list filterable.
- [Volume: hundreds of superglobal hits] → per-file aggregation with 8 excerpts.
- [Memory/time: 3 extra tokenizations of 400+ files] → regex pre-filter + single-file token cache; measure on timbero (task 13); `wp_raise_memory_limit()` only runs on the admin path.
- [Heuristic name matching (`/nonce|verify|security/`, `/^sanitize|clean/`) can be gamed] → heuristics only ever *lower* severity to INFO/WARNING tiers; they never suppress a REQUIRED shape.
- [PHP version spread] → guard PHP 8 token constants; no arrow functions/typed properties in helpers; test on PHP 7.4 when the standalone runner lands.
- [Windows paths] → `WP_Theme::get_files()` returns forward slashes; helpers normalise `\` → `/`.
- [`rsort` on HTML lists WARNINGs above REQUIREDs] → unchanged in this change (byte-identical output goal); addressed by `results-plain-text-message`.

## Migration Plan

1. Ship as 2.1.0 on the fork; reviewers install by copying the plugin folder (no build step).
2. Rollback = revert to 2.0.0; no data is persisted by these checks.
3. Promotions per the rollout table are one-line changes with a version bump.

## Open Questions

- Should WooCommerce cart mutations (`WC()->cart->add_to_cart()`) count as state-changing for `nonce/nopriv-state-change`? Default: no (public by design); revisit after census.
- Exact retention of the `nonce/delegated` INFO tier after phase 3 (drop or keep).
