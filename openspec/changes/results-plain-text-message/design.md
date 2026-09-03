## Context

See proposal.md – Why. Constraints:

- Assets are enqueued on `admin_print_styles-{$page}` (`theme-check.php:34`), which fires in `<head>` **before** the page callback runs the checks — results data cannot go through `wp_localize_script` at enqueue time; it must be printed inline from `check_main()`.
- `check_main()` (`main.php:13-141`) prints the theme-info block, "Running N tests", the pass/fail `<h2>`, the WP_DEBUG notice, then `wp_kses( display_themechecks(), [li, span[class], strong, code, pre, a[href]] )` inside `<div class="tc-box"><ul class="tc-result">` (`main.php:123-140`). `tc_form()` (`main.php:178-201`) renders the theme `<select>`, "Check it!", the `s_info` checkbox, and the `trac` checkbox only when `TC_PRE`/`TC_POST` are defined.
- `display_themechecks()` (`checkbase.php:126-163`) merges `getError()` arrays, `array_unique`, `rsort` (alphabetical on HTML → WARNING before REQUIRED), applies `s_info` via `preg_match('/INFO/')`, and has a `TC_TRAC` branch producing a `<textarea>` that `wp_kses` then strips.
- Change `security-checks-v1` introduces `tc_collect_results()` (structured records with `severity`, `check`, `message`, `file`, `line`, `evidence`, `html`) and the `themecheck_findings` filter. This change builds on it; if that change is not merged first, the collector is implemented here with the same contract.
- Two legacy findings emit `class="tc-lead tc-warning"` with the label `REQUIRED` (`checks/class-envato-check.php:87,121`); labels are effectively always English (no `lang/` directory ships).
- `http://themeforestcheck.local` is not a secure context: `navigator.clipboard` is undefined; only `localhost`/`127.0.0.1` are treated as potentially trustworthy.
- CSS today: colour-only severity (`.tc-required` red, `.tc-warning` orange, …), `.tc-lead` and `.tc-result` have no rules, `pre` is styled globally.

## Goals / Non-Goals

**Goals:**
- Reviewer can produce a paste-ready plain-text message in under a minute from any run.
- Works with legacy checks unchanged (normaliser) and with structured findings when present.
- No jQuery dependency; one JS file, one CSS file; no build step.
- Accessible: text badges, keyboard operation, live-region status.

**Non-Goals:**
- Re-ordering or re-wording legacy check messages.
- Server-side rendering of the message (generated client-side from the JSON block; template saved server-side).
- Exporting anything other than plain text.

## Decisions

### D1. Three layers: collector (PHP) → renderer (PHP) → composer (JS)
- **Collector** (`checkbase.php`): `tc_collect_findings()` wraps `tc_collect_results()` and adds `id = 'tc-' . substr( md5( $check . '|' . $html ), 0, 10 )` and `text` (`tc_finding_plain_text()`: `<br>`, `</pre>`, `</li>` → `\n`; `wp_strip_all_tags()`; `html_entity_decode( …, ENT_QUOTES | ENT_HTML5 )`; collapse `[ \t]+`; collapse 3+ newlines to 2; trim). Severity from the label text inside `.tc-lead` first, class second (because of the envato-check mismatch). Sort by severity rank → check → text. `s_info` handled here. `apply_filters( 'themecheck_findings', … )` stays the extension point.
- **Renderer** (`includes/results-renderer.php`, included from `main.php`): `tc_render_results( $findings, $theme )` prints the summary, toolbar, one `<section class="tc-group" data-severity>` per severity with `<h3>` (group checkbox + badge + count) and `<ul class="tc-result">` of `<li class="tc-item" id data-severity data-check>` items; each item = checkbox + `<span class="tc-lead tc-{sev}">LABEL</span>: <span class="tc-msg">` + `wp_kses( body, existing allowlist )` + `<details class="tc-evidence"><summary>N matching lines</summary><pre class="tc-grep">…</pre></details>`. `tc_render_message_panel( $theme, $findings, $author )` prints the aside. `tc_render_findings_json()` prints `<script type="application/json" id="tc-findings-data">` with `wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES )`.
- **Composer** (`assets/theme-check.js`, IIFE, `'use strict'`, ES2017): reads the JSON block and `etcConfig` (template, i18n, AJAX URL, nonce), manages `state = { selected: Set, notes, author, dirty, template }`, `buildMessage( state, data )` is a pure function, `render()`, `copyToClipboard()`, `templateEditor()`, `persistSession()`.

*Alternative rejected*: extending the `wp_kses` allowlist with `data-*`/`details`/`summary` — unnecessary because the renderer builds the wrapper itself and only filters the message body; keeps the allowlist minimal.

### D2. Results data inline, config via `wp_localize_script`
`load_assets( $hook_suffix )` on `admin_enqueue_scripts` (guarded by `$hook_suffix === $this->page_hook`) enqueues `envato-theme-check` style and script (script in footer) and localizes `etcConfig` = `ETC_Message_Template::js_config()` (template, placeholders, i18n strings, `ajaxUrl`, `nonce`). Findings are printed inline by the renderer because they only exist after `check_main()` runs.

### D3. Plain-text formatting rules
Upper-case severity heading + `(n)`; `- ` bullets; `  File: path`; `  Line n: excerpt` up to `evidence_max_lines` (default 5) then `  ... and k more`; blank line between groups; empty groups omitted; `\n` line endings; whitespace collapsed inside a bullet. Placeholders: `{author} {theme_name} {theme_version} {date} {required_count} {warning_count} {recommended_count} {info_count} {selected_count} {reviewer_notes} {findings}`; `{date}` computed server-side (`wp_date( get_option( 'date_format' ) )`) into the JSON block. Assembly order fixed: greeting, blank, intro, blank, findings, blank, [notes heading + notes, blank], footer.

### D4. `{author}` = ThemeForest username, never the style.css Author header
Decision from the user. The panel has an "Author username" field; pre-filled when `check_main()` receives a valid `queue_item` (from `proofing-queue-import`), otherwise empty with a visible hint. Rationale: the style.css `Author` is often a brand, and the review tool addresses the account holder.

### D5. Template persistence
`includes/class-message-template.php` — static class `ETC_Message_Template` with `defaults()`, `get()`, `save( array )`, `sanitize( array )` (`sanitize_textarea_field` per text field; `default_included` intersected with the four severities; `evidence_max_lines` clamped 0–20; `show_file_line` bool), `placeholders()`, `js_config()`, `ajax_save()`, `ajax_reset()`. Option `envato_theme_check_message_template`, `update_option( …, false )` (autoload off). AJAX actions `etc_save_message_template` / `etc_reset_message_template` with `check_ajax_referer( 'etc-template', 'nonce' )` + `current_user_can( 'manage_options' )`, replying with `wp_send_json_success( $template )`. Included at plugin load (AJAX runs outside the page callback); `checkbase.php`/`main.php`/renderer stay page-scoped.

*Alternative rejected*: per-user meta — the reviewer's Local site is single-user; a site option is simpler to reset/export. User override can be added later.

### D6. Clipboard strategy
`navigator.clipboard.writeText()` when available (secure contexts) → fallback `textarea.select(); document.execCommand('copy')` → last resort: select the text and show "Press Ctrl/Cmd+C". On `http://*.local` the fallback is the primary path; `execCommand('copy')` is deprecated but supported by all current browsers.

### D7. Dirty-state protection and session persistence
`state.dirty` becomes true on manual edits to the preview; selection changes then show an inline "Preview was edited manually — Regenerate?" notice instead of overwriting; notes/author changes always regenerate (they are template inputs). State is saved to `sessionStorage['etc:' + slug]` on every change and restored on load.

### D8. Remove TC_TRAC
The trac checkbox only renders when `TC_PRE`/`TC_POST` are defined (`main.php:195`), and the `<textarea>` it produces is destroyed by `wp_kses` (`checkbase.php:156` vs `main.php:125`). Remove the checkbox, the `define( 'TC_TRAC' )` in `theme-check.php:65-67`, and the branch in `display_themechecks()`; keep `tc_trac()` returning its input unchanged for one release.

### D9. Accessibility and CSS
Badges `.tc-badge-{required|warning|recommended|info}` with backgrounds `#b32d2e`, `#8a6d00`, `#1d6f2b`, `#1d4ed8` and white text (≥ 4.5:1); replace the colour-only rules in `assets/style.css:5-16`. Native checkboxes with `<label>`s (`screen-reader-text` where visual label is redundant), `details/summary` for evidence, `aria-live="polite"` on the preview status and copy feedback, `:focus-visible` outlines, group checkbox `indeterminate` state. Two-column layout (results | message panel) at ≥ 1200 px, stacked below. INFO group rendered collapsed. Event delegation (one listener on `.tc-results-main`) to cope with hundreds of items.

### D10. Hand-off from the review queue
`tc_form()` reads `$_GET['themename']` (sanitized, must match an installed stylesheet) to pre-select the theme and carries `queue_item` as a hidden field; the check still runs only on POST with `themecheck-nonce`. `check_main()` passes `author` (from the queue item) into the JSON block and the panel.

## Risks / Trade-offs

- [File-path heuristic misfires on bolded function names] → require a dot-extension; prefer structured `file` when present.
- [English-only label parsing] → class fallback; if a `lang/` directory is ever added, make the label list translatable via `_x()`.
- [Clipboard API unavailable on `http://*.local`] → `execCommand` fallback + manual-copy last resort; documented.
- [Hundreds of INFO findings slow the page] → INFO group collapsed by default; delegated events; no per-item listeners.
- [Manual edits lost] → dirty flag + explicit regenerate; session persistence.
- [`wp_kses` strips legacy `<br>` inside messages] → unchanged for HTML; plain-text converter inserts newlines before stripping.

## Migration Plan

1. Ship with `security-checks-v1` or immediately after (shared collector). Version 2.1.x.
2. No data migration; the template option is created on first save. Rollback: previous version ignores the option.
3. Trac mode removal is documented in `readme.md` as removal of a non-functional feature.

## Open Questions

- Should RECOMMENDED be included by default in the message? Default yes (matches Envato's "strongly recommended … resolved if possible"); reviewers can change it in the template.
- Do reviewers want a "copy REQUIRED only" shortcut in addition to "Select REQUIRED"? Deferrable.
