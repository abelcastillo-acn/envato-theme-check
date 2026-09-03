## 1. Assets and plumbing

- [ ] 1.1 `theme-check.php`: define `ETC_VERSION`, store the page hook from `add_theme_page()`, rename `load_styles()` → `load_assets( $hook_suffix )` on `admin_enqueue_scripts` (guarded by hook), enqueue `envato-theme-check` style and script (footer) and `wp_localize_script( 'envato-theme-check', 'etcConfig', … )`; verify both assets load only on Appearance → Theme Check (check `<head>`/footer source)
- [ ] 1.2 `theme-check.php`: remove `define( 'TC_TRAC' )` (L65-67); `main.php::tc_form()`: remove the trac checkbox (L195-197); `checkbase.php::display_themechecks()`: remove the TC_TRAC/`<textarea>` branch, keep `tc_trac()` as a pass-through; verify a POST with `trac=1` renders the normal results
- [ ] 1.3 Create `includes/class-message-template.php` (`ETC_Message_Template`: `defaults`, `get`, `save`, `sanitize`, `placeholders`, `js_config`, `ajax_save`, `ajax_reset`) and register the two AJAX actions in `EnvatoThemeCheck::__construct`; verify saving via the browser network tab stores `envato_theme_check_message_template` and that a request without nonce returns an error

## 2. Collector and renderer

- [ ] 2.1 `checkbase.php`: add `tc_collect_findings()`, `tc_finding_from_html()`, `tc_finding_plain_text()` on top of `tc_collect_results()` (label-first severity, stable `id`, `text`, severity ordering, `s_info`); verify with a temporary `var_dump` on the fixture theme that every legacy finding has `severity`, `id`, `text` and the two envato-check mismatches resolve to `required`
- [ ] 2.2 Create `includes/results-renderer.php` (`tc_render_results`, `tc_render_finding`, `tc_render_message_panel`, `tc_render_findings_json`, `tc_results_kses_allowlist`); verify the JSON block parses with `JSON.parse(document.getElementById('tc-findings-data').textContent)` and that a message containing `</script>` does not break the page
- [ ] 2.3 `main.php::check_main()`: replace L97 and L123-140 with the renderer calls; keep heading/success/WP_DEBUG blocks; pass `author` from a validated `queue_item` when present; verify a clean run shows no groups/panel and a failing run shows groups with correct counts
- [ ] 2.4 `main.php::tc_form()`: pre-select from a sanitized `$_GET['themename']` that matches an installed stylesheet and carry `queue_item` as a hidden field; verify `themes.php?page=themecheck&themename=timbero` pre-selects Timbero and the check still requires the POST nonce
- [ ] 2.5 `checks/class-envato-check.php`: change L87 and L121 from `tc-warning` to `tc-required`; verify the two REQUIRED messages now render with the red badge

## 3. Front-end

- [ ] 3.1 Write `assets/theme-check.js`: JSON/config bootstrap, selection state with group tri-state, toolbar actions, `buildMessage()` implementing the plain-text rules, preview render + status line, dirty-flag handling, session persistence; verify in the browser that the two-finding example from specs/review-message reproduces byte-for-byte
- [ ] 3.2 Implement copy-to-clipboard with `navigator.clipboard` → `execCommand('copy')` → manual fallback; verify copy works on `http://themeforestcheck.local` in Chrome and Firefox and that the status line announces "Copied"
- [ ] 3.3 Implement the template editor (fields, placeholder list, Save via AJAX, Reset) and live regeneration; verify a saved footer persists across reloads and Reset restores defaults
- [ ] 3.4 `assets/style.css`: badges with AA contrast, group headers, two-column layout ≥ 1200 px, collapsed INFO group, `:focus-visible`, scoped `pre`; verify with the browser accessibility inspector that badge contrast ≥ 4.5:1 and keyboard navigation reaches every control

## 4. Verification and docs

- [ ] 4.1 Manual QA matrix on the Local site: theme with 0 findings; only INFO; mixed; `s_info` on/off; 300+ findings (performance); edited preview then selection change; reload restores state
- [ ] 4.2 Run the legacy regression: compare the rendered `<li>` message bodies for `timbero` before/after (text content identical, only wrapper/grouping changed)
- [ ] 4.3 Update `readme.md` (message composer, template placeholders, Trac mode removal) and bump the plugin version; verify readme renders on GitHub
