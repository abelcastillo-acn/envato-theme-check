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

## Results page and message to the author (2.2.0)

Findings are grouped by severity with counts, each with a checkbox and collapsible line excerpts. The **Message to author** panel turns the selected findings into a plain-text message (the ThemeForest review tool accepts plain text only): fill in the author's ThemeForest username, add reviewer notes, edit the preview if needed and click **Copy to clipboard**. By default findings are written concisely as `file:line — what to change` followed by the code lines to review; the security checks provide the "what to change" sentence, legacy checks use their message without reviewer boilerplate (uncheck *Concise findings* in the template for the full text). The message template (greeting, intro, notes heading, footer, default included severities, evidence lines per finding, concise toggle) is editable from the same panel and stored site-wide; placeholders: `{author}`, `{theme_name}`, `{theme_version}`, `{date}`, `{required_count}`, `{warning_count}`, `{recommended_count}`, `{info_count}`, `{selected_count}`, `{reviewer_notes}`, `{findings}`.

`themes.php?page=themecheck&themename=<slug>` pre-selects a theme in the form (used by the review-queue hand-off). The old Trac output mode (`TC_TRAC`/`TC_PRE`/`TC_POST`) was removed; it had been non-functional since the output was filtered with `wp_kses`.

## Review Queue (2.3.0)

**Appearance → Review Queue** keeps a local list of the items waiting in the ThemeForest proofing queue. Items are captured with a bookmarklet that runs in your already-authenticated browser tab on `themeforest.net/admin/awesome_proofing` and hands the data to the plugin through a new tab (URL fragment) or, as a fallback, JSON you paste in. The plugin never touches Envato credentials. From the list you map each item to the installed theme and click **Check this theme**: Theme Check opens pre-selected, the item moves to *In review* and the author's username is pre-filled in the message panel. Items marked *Done* are purged after the retention period (default 30 days). See [docs/proofing-import.md](docs/proofing-import.md) for details, limitations and the approvals still pending.

## Changelog

### 2.3.0
- New: Review Queue (private post type `etc_queue_item`, statuses pending/in review/done, theme mapping, retention cron, purge, uninstall cleanup).
- New: capture bookmarklet (`tools/bookmarklet/`, built to `dist/`) with fragment hand-off and clipboard fallback; import preview with duplicate marking; payload validation with an Envato host allow-list.
- New: `wp theme review queue list|import|purge`; filters `etc_queue_allowed_hosts`, `themecheck_author_username`; action `themecheck_run_from_queue`.
- Note: the page selectors in the bookmarklet still need to be confirmed against the real proofing page.

### 2.2.0
- New: severity-grouped results with counts, per-finding selection, collapsible evidence, accessible text badges.
- New: "Message to author" panel — plain-text message from the selected findings, reviewer notes, ThemeForest author username, copy to clipboard (with fallback for `http://` local sites), editable persisted template (`includes/class-message-template.php`).
- New: `tc_collect_findings()`, `includes/results-renderer.php`, `assets/theme-check.js`; findings JSON block on the results page; `themecheck_author_username` filter.
- Changed: assets enqueued via `admin_enqueue_scripts` with the `envato-theme-check` handle; `?themename=` pre-selection.
- Fixed: two REQUIRED findings in the Envato check rendered with the WARNING colour.
- Removed: Trac output mode (`tc_trac()` is now a no-op).

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