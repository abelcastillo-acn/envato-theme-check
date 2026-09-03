## 1. Prerequisites and shared infrastructure

- [x] 1.1 `theme-check-cli.php`: replace the manual file walk (L83–110) with `run_themechecks_against_theme( $theme, $theme->get_stylesheet() )`; verify `wp theme review check timbero --format=true` still returns the five existing JSON keys with the same content as before
- [x] 1.2 `theme-check-cli.php`: define the missing `tc_get_theme_data()` (or replace its use in `active()` with `wp_get_theme()->get()`); verify `wp theme review active` no longer fatals
- [x] 1.3 Create `tc-tokens.php` with the helper API from design.md D3 (`tc_tokens_for_file`, `tc_tokens`, `tc_next_sig`, `tc_prev_sig`, `tc_match`, `tc_token_scopes`, `tc_scope_at`, `tc_find_calls`, `tc_call_chain`, `tc_last_assignment`, `tc_tokens_text`, `tc_excerpt`, `tc_is_vendor_path`), all behind `function_exists` guards and PHP 8 token constants behind `defined()`; verify `php -l tc-tokens.php` passes on the Local PHP binary
- [x] 1.4 `checkbase.php`: add `require_once __DIR__ . '/tc-tokens.php';` before the checks glob (L33); verify the admin page still loads and "Running N tests" count is unchanged
- [x] 1.5 `checkbase.php`: add `tc_error()`, `tc_result_from_legacy_html()`, `tc_collect_results()` and the `$theme_check_current_context` global (design.md D8); refactor `display_themechecks()` to use the collector; verify the rendered `<li>` HTML for `timbero` is byte-identical before/after (diff of page source)
- [x] 1.6 `theme-check-cli.php`: build the JSON from `tc_collect_results()` and add the `findings` key; verify with `wp theme review check timbero --format=true | jq '.findings | length'` and that legacy keys are unchanged

## 2. New checks

- [x] 2.1 Create `checks/class-sql-prepare-check.php` (`SQL_Prepare_Check`, rules and algorithm per specs/sql-prepare and design.md D6, `RULES` constant + `tc_rule_severity`); verify `php -l` passes and the check appears in the admin "Running N tests" count
- [x] 2.2 Create `checks/class-nonce-check.php` (`Nonce_Check`, specs/nonce-verification, design.md D5, `STATE_CHANGING` list behind `tc_state_changing_functions`); verify `php -l` passes
- [x] 2.3 Create `checks/class-superglobals-check.php` (`Superglobals_Sanitization_Check`, specs/superglobals-sanitization, design.md D7, `SANITIZERS` behind `tc_sanitizing_functions`, per-file aggregation); verify `php -l` passes
- [x] 2.4 `checks/class-envato-check.php`: remove the `$_SERVER` rule (L57) and add a readme.md note; verify a file containing only `$_SERVER['REQUEST_METHOD']` no longer produces a finding
- [x] 2.5 Wire vendor-path INFO downgrade in all three checks via `tc_is_vendor_path()`; verify a fixture file under `inc/redux-framework/` yields INFO not WARNING

## 3. Fixtures and runners

- [x] 3.1 Create `tests/fixtures/tc-security-fixture/` theme (`style.css` with `Theme Name: TC Security Fixture`, `index.php`, `functions.php` requiring `inc/*.php`); each `inc/vuln-*.php` starts with `if ( ! defined( 'TC_FIXTURE' ) ) { return; }`; verify the theme appears in `wp theme list` after copying it to the Local site
- [x] 3.2 Write `inc/vuln-nonce.php` covering: privileged AJAX handler without nonce; nonce but no capability with `update_option`; `nopriv` handler that `setcookie`s; `admin_init` handler with `$_POST` + `update_option`; closure callback; `array( $this, 'm' )` callback; delegated `$this->verify()` (expects INFO); dynamic hook name (expects INFO summary)
- [x] 3.3 Write `inc/vuln-sql.php` covering: `"WHERE ID = $id"`, `' . $id`, `sprintf`, heredoc, `esc_sql` concat (WARNING), `$sql = "… $x"; $wpdb->query( $sql )`, `prepare( "SELECT 1" )`, `prepare( "… $order" )`, `mysqli_connect`, `new PDO`
- [x] 3.4 Write `inc/vuln-superglobals.php` covering: bare `$_GET['a']`, `wp_unslash( $_POST['b'] )` alone, `$_SERVER['REQUEST_URI']`, `$_COOKIE['x']`, `$_FILES['f']['tmp_name']` → `move_uploaded_file`, `extract( $_POST )`, `wp_parse_args( $_POST, … )`
- [x] 3.5 Write `inc/safe.php` (must yield zero findings): `$q = $wpdb->prepare(…); $wpdb->get_results( $q )`, `"FROM {$wpdb->prefix}x"`, `$table = $wpdb->prefix . 'x'`, `isset/empty/in_array/===` guards, `sanitize_text_field( wp_unslash() )`, `(int)`, `wc_clean`, `array_map( 'sanitize_text_field', … )`, `wp_verify_nonce( $_POST['n'], 'a' )`, `$_SERVER['REQUEST_METHOD']`, handler with `check_ajax_referer` + `current_user_can`, read-only `wp_ajax_nopriv` handler, commented-out `$wpdb->query( "… $id" )`
- [x] 3.6 Write `tests/fixtures/expectations.json` mapping `rule id → { file → [lines] }` plus `"__zero__": ["inc/safe.php"]`
- [x] 3.7 Write `tests/run-fixture.php` for `wp eval-file`: include `checkbase.php`, run `run_themechecks_against_theme()`, filter `tc_collect_results()` to `nonce/|sql/|superglobals/`, compare `{file,line}` sets to expectations, print diff, `exit(1)` on mismatch
- [x] 3.8 Write `tests/census.php` for `wp eval-file <slug>`: per-rule counts, per-severity totals, top-10 files, wall time, peak memory
- [x] 3.9 Write `tests/sync-to-local.ps1` (robocopy plugin → `Local Sites\themeforestcheck\app\public\wp-content\plugins\envato-theme-check`, excluding `tests/fixtures`; copy fixture theme → `wp-content\themes\tc-security-fixture`); verify a run copies files without error

## 4. Verification

- [x] 4.1 Local shell: `wp eval-file wp-content/plugins/envato-theme-check/tests/run-fixture.php tc-security-fixture` exits 0 (all expectations met, `inc/safe.php` yields zero findings)
- [x] 4.2 Local shell: `wp eval-file …/tests/census.php timbero`, `timbero-child`, `twentytwentyfive`; record counts, timing and peak memory in design.md; acceptance: 0 REQUIRED on all three, WARNINGs on timbero limited to `sql/variable-arg` at `inc/admin/import/trait-import-content.php:1292`; tune allowlists until met
- [x] 4.3 Local shell: `wp theme review check tc-security-fixture --format=true` contains `findings` with the expected `REQUIRED:` strings and the legacy keys are unchanged
- [x] 4.4 Admin UI smoke test (Appearance → Theme Check) on the fixture with `WP_DEBUG` on: messages render inside the `wp_kses` allowlist (no stripped tags), excerpts show the correct lines, "Learn more" links resolve
- [x] 4.5 Set `add_filter( 'tc_rule_severity', … )` in an mu-plugin promoting `nonce/ajax-missing` to `required`; verify the fixture run flips to FAIL, then remove the mu-plugin

## 5. Documentation and release

- [x] 5.1 Write `docs/security-checks.md` (author-facing guide: rule table with policy vs detection severity, before/after snippets, reviewer guidance, rollout dates, CLI caveat) and link it from `readme.md`; verify links render on GitHub
- [x] 5.2 Bump `Version:` in `theme-check.php` to 2.1.0 and add a changelog section to `readme.md` describing the new checks, the `$_SERVER` rule removal and the `findings` JSON key
- [x] 5.3 (P2) Create `bin/wp-shims.php` (`__`, `_x`, `esc_html`, `esc_html__`, `esc_attr`, `esc_url`, `apply_filters`, `do_action`, `add_action`) and `bin/run-check.php` to run the three checks on a directory without WordPress; verify `php bin/run-check.php tests/fixtures/tc-security-fixture` matches expectations; verify no check file calls WP functions at include time
- [x] 5.4 (P2) Add `.github/workflows/checks.yml` running `bin/run-check.php` on PHP 7.4 and 8.2; verify the workflow passes on the fork
