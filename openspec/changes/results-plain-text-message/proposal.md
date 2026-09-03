## Why

ThemeForest reviewers run Envato Theme Check, then hand-copy each finding into the review tool, which accepts **plain text only**. The current results page is a flat `<ul>` sorted alphabetically by HTML (WARNINGs above REQUIREDs), with no counts, no selection, no copy action and no JavaScript at all — so turning a run into an author-facing message takes longer than the run itself and is error-prone.

## What Changes

- Group findings by severity with counts (REQUIRED / WARNING / RECOMMENDED / INFO), each finding with an include/exclude checkbox and collapsible evidence lines.
- Add a **Message to author** panel: ThemeForest author username field, reviewer notes, live plain-text preview (editable), **Copy to clipboard**, and a persisted, editable message template with placeholders.
- Introduce the plugin's first JavaScript asset (`assets/theme-check.js`, vanilla) and a JSON data block with the structured findings from change `security-checks-v1` (`structured-findings`), falling back to normalising legacy HTML strings when a check has no structured output.
- Render severity as a text badge with accessible colour contrast; fix the two legacy findings whose CSS class disagrees with their label (`checks/class-envato-check.php` L87, L121).
- **BREAKING:** remove the `TC_TRAC` textarea mode and its hidden `trac` checkbox (already non-functional: the `<textarea>` is stripped by `wp_kses`); keep `tc_trac()` as a no-op for one release.
- Rename the style handle `style` → `envato-theme-check` and enqueue assets through `admin_enqueue_scripts` scoped to the plugin page.
- Pre-select the theme from `?themename=` on the Theme Check form (used by the review-queue hand-off in change `proofing-queue-import`); the check itself still runs on POST with the nonce.

## Capabilities

### New Capabilities
- `results-rendering`: severity-grouped, selectable results view with counts and collapsible evidence, built from structured findings.
- `review-message`: generation of a plain-text message to the theme author from the selected findings, with reviewer notes, author username and copy-to-clipboard.
- `message-template`: persisted, editable message template with placeholders and defaults, editable from the results page.

### Modified Capabilities
- `structured-findings` (from change `security-checks-v1`): the findings list gains a stable per-finding `id` and a plain-text `text` field used by the UI. If `security-checks-v1` has not been archived yet, this delta is applied on top of its pending spec.

## Out of scope

- Markdown/HTML export (the ThemeForest tool is plain text only).
- Sending the message anywhere; writing back to ThemeForest.
- Per-user templates (site option first; user override is a follow-up).
- Any change to the checks themselves.

## Impact

- **Code:** modify `theme-check.php` (asset enqueue, AJAX handlers, version), `main.php` (`check_main()` result rendering and message panel, `tc_form()` preselect and trac removal), `checkbase.php` (collector already added by `security-checks-v1`; TC_TRAC branch removed), `assets/style.css`, `checks/class-envato-check.php` (L87/L121), `readme.md`. New: `includes/results-renderer.php`, `includes/class-message-template.php`, `assets/theme-check.js`.
- **Data:** one new site option `envato_theme_check_message_template` (autoload off).
- **Reviewers:** results page layout changes; new panel; template editing.
- **Compatibility:** `http://*.local` sites are not secure contexts, so the clipboard code must fall back to `document.execCommand('copy')`. Legacy checks keep working unchanged through the normaliser.
