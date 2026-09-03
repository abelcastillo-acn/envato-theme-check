## Purpose

Machine-readable Theme Check findings (severity, rule id, file, line, text) shared by the admin UI, hooks and WP-CLI; this delta extends the capability introduced by change `security-checks-v1`.

## ADDED Requirements

### Requirement: Each finding SHALL carry a stable identifier and plain text
In addition to the fields defined by change `security-checks-v1`, every structured finding SHALL include `id` (stable across runs of the same theme for the same finding, derived from the rule/check and the message) and `text` (the message as plain text: tags removed, `<br>` and `</pre>` converted to line breaks, entities decoded, whitespace collapsed).

#### Scenario: Same finding on two runs
- **WHEN** the same theme is checked twice without changes
- **THEN** each finding has the same `id` in both runs

#### Scenario: Plain text of an HTML message
- **WHEN** a finding's HTML is `Found <strong>@import</strong> in <strong>style.css</strong>.<br>Use wp_enqueue_style().`
- **THEN** its `text` is `Found @import in style.css.` followed by a line break and `Use wp_enqueue_style().`

### Requirement: Findings SHALL be ordered by severity
The structured list SHALL be ordered REQUIRED, WARNING, RECOMMENDED, INFO, then by rule/check id, then by text; this order SHALL be used by the results page.

#### Scenario: Mixed severities
- **WHEN** a run produces WARNING and REQUIRED findings
- **THEN** REQUIRED findings precede WARNING findings in the structured list and on the page

### Requirement: Best-effort file extraction SHALL be conservative
When normalising legacy HTML, `file` SHALL be set only from a bolded fragment that looks like a relative file path (contains a dot-extension of 2–5 characters); otherwise `file` SHALL be empty.

#### Scenario: Bolded function name
- **WHEN** a legacy message bolds `add_theme_support()` and nothing else
- **THEN** `file` is empty
