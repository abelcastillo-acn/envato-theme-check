# Envato Theme Check
Plugin Name: Envato Theme Check

Author: Scott Parry

Author URI: https://scottparry.co/

Plugin URI: https://github.com/envato/Envato-Theme-Check

License: GPLv2

License URI: https://www.gnu.org/licenses/gpl-2.0.html


## Description
<p>The Envato Theme Check is a modified fork of the original Theme Check by Otto42 with additional Envato specific WordPress checks.</p>
<p>It is an easy way to test your theme and make sure it's up-to-date with the latest Envato review standards. With it, you can run all the same automated testing tools on your theme that Envato Reviewers use for WordPress theme submissions.</p>

<p>The tests are run through a simple admin menu and all results are displayed at once. This is very handy for theme developers, or anybody looking to make sure that their theme supports the latest WordPress theme standards and practices.</p>

## Security checks (2.1.0)

Version 2.1.0 adds three families of security checks that enforce mandatory rules from Envato's [WordPress Theme Requirements Part 5 – Theme Security](https://help.author.envato.com/hc/en-us/articles/360000481243-WordPress-Theme-Requirements-Part-5-Theme-Security):

- `nonce/*` — request handlers (`wp_ajax_*`, `admin_post_*`, form handlers) without nonce verification and/or capability checks.
- `sql/*` — SQL run through `$wpdb` without `$wpdb->prepare()`, misuse of `prepare()`, and direct `mysqli`/PDO usage.
- `superglobals/*` — `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_FILES`/`$_SERVER` read without sanitization, `extract()` of request data, whole-array use.

See [docs/security-checks.md](docs/security-checks.md) for every rule, its severity, how to fix it, reviewer guidance and the rollout schedule. Rule severities can be tuned with the `tc_rule_severity` filter; allow-lists with `tc_sanitizing_functions`, `tc_state_changing_functions` and `tc_vendor_paths`.

## WP-CLI

```
wp theme review list
wp theme review check <theme-slug> [--format=true]
wp theme review active [--format=true]
```

`--format=true` prints JSON. Besides the legacy keys (`result`, `required`, `warnings`, `recommended`, `errors`) the output includes `findings[]` with `severity`, `check`, `message`, `file` and `line` for every finding. Note that the CLI reports `FAIL` whenever a WARNING is present; automation should use `findings[].severity`.

## Tests

- `tests/fixtures/tc-security-fixture/` is a theme with intentionally vulnerable snippets; expectations are inline `// EXPECT: <rule>` markers, `inc/safe.php` must stay clean.
- With WordPress (Local site shell): `wp eval-file tests/run-fixture.php tc-security-fixture` and `wp eval-file tests/census.php <theme-slug>` (false-positive census on a real theme). `tests/sync-to-local.ps1` copies the plugin and fixture into the Local site.
- Without WordPress: `php bin/run-check.php tests/fixtures/tc-security-fixture` (uses `bin/wp-shims.php`); `--only=inc/safe.php --expect-zero` asserts the safe file is clean. GitHub Actions runs both on PHP 7.4 and 8.2 (`.github/workflows/checks.yml`).

## Changelog

### 2.1.0
- New: `Nonce_Check`, `SQL_Prepare_Check` and `Superglobals_Sanitization_Check` (see docs/security-checks.md) with per-rule severities, vendor-path downgrade to INFO and the `tc_rule_severity` filter.
- New: shared token helpers (`tc-tokens.php`) and structured findings (`tc_error()`, `tc_collect_results()`, `themecheck_findings` filter).
- New: `findings[]` key in the WP-CLI JSON output.
- Changed: WP-CLI now analyses the same file set as the admin page (comment-stripped, parent theme included, correct line numbers).
- Removed: the blanket `$_SERVER` warning in the Envato check — superseded by `superglobals/unsanitized`, which ignores server-controlled keys such as `REQUEST_METHOD`.
- Fixed: `wp theme review active` fatal error (`tc_get_theme_data()` undefined).
- Added: test fixture theme and runners under `tests/`.

## Frequently Asked Questions

#### Do I need to address WARNINGS and INFO notices?
<p>It is strongly recommended that WARNING, RECOMMENDED and INFO notices be resolved if possible. Some may be the result of an issue that is cause for rejection (Reviewers make this decision).</p>

#### What do I do if I find a bug, or think something is wrong with the plugin?
<p>You can submit an issue directly here: https://github.com/envato/envato-theme-check/issues</p>