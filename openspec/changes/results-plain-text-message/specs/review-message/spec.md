## Purpose

Turn the selected findings of a run into a plain-text message the reviewer can paste into the ThemeForest review tool, with author username, reviewer notes and one-click copy.

## ADDED Requirements

### Requirement: A plain-text message SHALL be generated from the selected findings
The results page SHALL show a "Message to author" panel with an editable plain-text preview that is regenerated from the current selection, the reviewer notes, the author username and the active template. The output SHALL contain no HTML tags or entities.

#### Scenario: Two findings selected
- **WHEN** one REQUIRED and one WARNING finding are selected and the author username is "studioexample"
- **THEN** the preview reads (template defaults):
  ```
  Hi studioexample,

  Thanks for submitting Timbero 1.0.0 to ThemeForest. Our automated review found the following issues that need to be addressed before the item can be approved:

  REQUIRED (1)
  - Found @import url( in the file style.css. Do not use @import. Instead, use wp_enqueue to load any external stylesheets and fonts correctly.
    File: style.css
    Line 12: @import url('https://fonts.googleapis.com/css?family=Lato');

  WARNING (1)
  - Found echo $ in the file inc/template-tags.php. Possible data validation issues found. All dynamic data must be correctly escaped for the context where it is rendered.
    File: inc/template-tags.php
    Line 88: echo $post_meta['subtitle'];

  Once these are resolved, please resubmit and we will take another look. Thanks for your patience.

  The ThemeForest Review Team
  ```

#### Scenario: HTML in a finding
- **WHEN** a selected finding's message contains `<strong>`, `<code>`, `<br>` or `&amp;`
- **THEN** the preview shows the text without tags, with `<br>` rendered as a line break and entities decoded

### Requirement: Findings SHALL be formatted with fixed plain-text rules
Within the message, each severity present in the selection SHALL appear as an upper-case heading followed by the count in parentheses; each finding SHALL be a `- ` bullet with its text on one line (whitespace collapsed); when the finding has a file it SHALL be followed by an indented `File: <path>` line; up to N evidence lines (template setting, default 5) SHALL follow as indented `Line <n>: <excerpt>` lines, then `... and <k> more` when truncated. Groups SHALL be separated by one blank line; empty groups SHALL be omitted; line endings SHALL be `\n`.

#### Scenario: Finding with seven evidence lines
- **WHEN** a selected finding has 7 excerpts and the template limit is 5
- **THEN** the message lists five `Line n:` entries followed by `... and 2 more`

#### Scenario: Nothing selected in a severity
- **WHEN** no INFO finding is selected
- **THEN** the message contains no "INFO" heading

### Requirement: Author username SHALL come from ThemeForest
The panel SHALL provide an "Author username" text field used for the `{author}` placeholder. It SHALL be pre-filled from the review-queue item when the run was started from the queue (change `proofing-queue-import`) and SHALL otherwise be empty; the theme's `Author` header SHALL NOT be used as a fallback for `{author}`.

#### Scenario: Run started from the review queue
- **WHEN** the run was opened from a queue item whose author is "studioexample"
- **THEN** the field is pre-filled with "studioexample"

#### Scenario: Manual run
- **WHEN** the run was started from the Theme Check form directly
- **THEN** the field is empty and the greeting renders as "Hi ," until the reviewer fills it, with a visible hint to enter the username

### Requirement: Reviewer notes SHALL be included when present
The panel SHALL provide a "Reviewer notes" textarea whose content replaces the `{reviewer_notes}` placeholder; when empty, the notes heading and block SHALL be omitted from the message.

#### Scenario: Notes provided
- **WHEN** the reviewer types "Please also check the demo importer."
- **THEN** the message contains "Reviewer notes:" followed by that text, between the findings and the footer

#### Scenario: Notes empty
- **WHEN** the notes textarea is empty
- **THEN** the message contains no "Reviewer notes:" heading

### Requirement: Manual edits to the preview SHALL NOT be silently discarded
The preview SHALL be editable. Once edited, a subsequent selection change SHALL show a prompt to regenerate rather than overwriting the edited text; a "Regenerate from selection" action SHALL always rebuild the preview.

#### Scenario: Edited preview then checkbox change
- **WHEN** the reviewer edits the preview text and then unchecks a finding
- **THEN** the preview is unchanged and a notice "Preview was edited manually — Regenerate?" appears with a button to regenerate

### Requirement: The message SHALL be copyable in one action
A "Copy to clipboard" button SHALL copy the full preview text and confirm success with a visible, screen-reader-announced status. Copy SHALL work on `http://` local sites where the asynchronous clipboard API is unavailable.

#### Scenario: Copy on a local http site
- **WHEN** the reviewer clicks "Copy to clipboard" on `http://themeforestcheck.local`
- **THEN** the preview text is placed on the clipboard and the status reads "Copied"

#### Scenario: Copy failure
- **WHEN** both clipboard mechanisms fail
- **THEN** the preview text is selected and the status instructs the reviewer to press Ctrl/Cmd+C

### Requirement: Selection and draft SHALL survive a reload
The current selection, reviewer notes, author username and edited preview SHALL be kept per theme slug in session storage and restored when the same theme's results page is reloaded in the same tab.

#### Scenario: Accidental reload
- **WHEN** the reviewer reloads the results page for the same theme in the same tab
- **THEN** the checkboxes, notes, username and preview are restored

### Requirement: Placeholders SHALL be substituted
The following placeholders SHALL be replaced when building the message: `{author}`, `{theme_name}`, `{theme_version}`, `{date}`, `{required_count}`, `{warning_count}`, `{recommended_count}`, `{info_count}`, `{selected_count}`, `{reviewer_notes}`, `{findings}`. Counts refer to selected findings.

#### Scenario: Counts in the intro
- **WHEN** the template intro contains "{required_count} required issue(s)" and 2 REQUIRED findings are selected
- **THEN** the message reads "2 required issue(s)"
