## Why

Envato Theme Check has no automated coverage for the three security requirements that ThemeForest reviewers most often have to verify by hand: nonce/capability verification on request handlers, SQL built without `$wpdb->prepare()`, and request input (`$_GET`/`$_POST`/…) used without sanitization. All three are mandatory ("must") under Envato's *WordPress Theme Requirements Part 5 – Theme Security*, yet the plugin currently reports nothing for them, so a theme with a CSRF-able demo importer or an injectable view counter passes with "THEME PASSED REVIEW".

## Requirement source

| Rule family | Envato – [WP Theme Requirements Part 5: Theme Security](https://help.author.envato.com/hc/en-us/articles/360000481243-WordPress-Theme-Requirements-Part-5-Theme-Security) | WordPress.org | WPCS |
|---|---|---|---|
| Nonce + capability | "If a user is allowed to submit data to the server, a nonce **must** be used to verify the origin and intent of the request." / "User capabilities **must** be checked … before any data is submitted to the server." | Theme Handbook › Security: "If your theme includes any HTML or HTTP-based form submissions, use a nonce". Theme Review › Required §4: "The theme must be secure." | `WordPress.Security.NonceVerification` (WordPress-Extra, warning) |
| Prepared SQL | "Themes **must not** work directly with the database to create, update or delete site content… the wpdb class **must** be used. SQL statements **must** be prepared using `$wpdb->prepare()`. When using a LIKE expression, `$wpdb->esc_like()` **must** be used." | Theme Handbook › Security: "All data in SQL queries **must** be SQL-escaped before the SQL query is executed." | `WordPress.DB.PreparedSQL`, `WordPress.DB.PreparedSQLPlaceholders` (WordPress-Core, error) |
| Sanitized input | "Data **must** be validated on input… data … that cannot be validated **must** be sanitized." | Theme Review › Required §4: "Validate and sanitize untrusted data before entering it into the database." | `WordPress.Security.ValidatedSanitizedInput` (opt-in sniff) |

Conclusion: these are **policy-level REQUIRED** rules for ThemeForest. Where the check emits WARNING in v1 it is because automated detection cannot prove the absence of a control, not because the rule is optional. Messages therefore cite the requirement and say a manual review is needed.

## What Changes

- Add three new checks in `checks/`:
  - `class-nonce-check.php` — request handlers (`wp_ajax_*`, `admin_post_*`, `wc_ajax_*`, form handlers) without nonce verification and/or capability checks.
  - `class-sql-prepare-check.php` — `$wpdb` read/query calls whose SQL interpolates variables outside `$wpdb->prepare()`, misuse of `prepare()`, and any direct `mysql_*`/`mysqli_*`/PDO usage.
  - `class-superglobals-check.php` — reads of `$_GET/$_POST/$_REQUEST/$_COOKIE/$_FILES/$_SERVER` not passed through a sanitization/validation function, `extract()` of request data, and whole-array use.
- Add a shared tokenizer helper file (`tc-tokens.php`) so the new checks reason about function scope and call arguments instead of line regexes.
- Introduce a **structured findings** path: a builder for new checks, a collector that normalizes legacy HTML findings, a `themecheck_findings` filter, and a `findings[]` key in the WP-CLI JSON output. Legacy HTML output stays byte-identical.
- Per-rule severity table in each check with a `tc_rule_severity` filter so rules can be promoted from WARNING to REQUIRED without code changes beyond one constant.
- Findings inside bundled third-party frameworks (Redux, Kirki, CMB2, OCDI, aq_resizer, TGMPA, Merlin…) are downgraded to INFO rather than hidden.
- **BREAKING (lenient direction):** remove the blanket `$_SERVER` WARNING from `checks/class-envato-check.php` (L57); it is superseded by the superglobals check, which ignores non-user-controlled keys such as `REQUEST_METHOD`.
- Prerequisite fix: WP-CLI (`theme-check-cli.php`) reuses `run_themechecks_against_theme()` instead of its own file walk, so checks see comment-stripped code with correct line numbers and parent-theme files under CLI too.
- Author-facing documentation `docs/security-checks.md` (impact assessment, before/after snippets, rollout schedule).

## Capabilities

### New Capabilities
- `nonce-verification`: detection of request handlers that change state without nonce and/or capability verification.
- `sql-prepare`: detection of SQL executed through `$wpdb` without `prepare()`, misuse of `prepare()`, and direct database driver usage.
- `superglobals-sanitization`: detection of request superglobals read without sanitization/validation, `extract()` of request data, and whole-array use.
- `structured-findings`: machine-readable findings (severity, rule id, file, line) exposed to the admin UI, a filter hook, and WP-CLI JSON, while legacy HTML output is preserved.

### Modified Capabilities
<!-- No existing specs in openspec/specs yet; the $_SERVER rule removal is covered as a REMOVED requirement inside superglobals-sanitization. -->

## Out of scope

- REST API `permission_callback` auditing, Customizer `sanitize_callback` (already covered by `class-customizer-check.php`), output escaping (covered by `class-escaping-check.php`).
- Fixing `class-envato-check.php`'s behaviour of failing the run on every WARNING (tracked separately).
- The pre-existing fatal in `wp theme review active` (`tc_get_theme_data()` undefined) — noted, trivial follow-up.
- Multi-hop data-flow analysis (`$a = $_POST['x']; … $b = $a; $wpdb->query($b)`).
- The admin UI redesign and plain-text author message (change `results-plain-text-message`).

## Impact

- **Code:** new files `checks/class-nonce-check.php`, `checks/class-sql-prepare-check.php`, `checks/class-superglobals-check.php`, `tc-tokens.php`, `tests/**`, `docs/security-checks.md`; modified `checkbase.php` (require helper, findings collector), `theme-check-cli.php` (file walk + `findings` key), `checks/class-envato-check.php` (remove L57), `readme.md`, plugin version → 2.1.0.
- **Reviewers:** three new families of findings; WARNING-level findings require manual confirmation for 4–6 weeks (soft-reject guidance) before promotion.
- **Authors:** see `docs/security-checks.md`. Nonce/capability fixes are trivial per handler but can take hours on themes with many AJAX endpoints; SQL fixes are minutes per query; sanitization fixes are trivial per line but can be voluminous.
- **CLI consumers:** existing JSON keys unchanged; new `findings[]` key. Note WP-CLI already returns `FAIL` on any WARNING, so automation should read `findings[].severity`.
- **Performance:** three additional tokenizations per PHP file (single-file cache); measured on the 406-file `timbero` theme as part of the tasks.
