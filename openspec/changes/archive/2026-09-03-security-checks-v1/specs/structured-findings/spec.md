## Purpose

Expose every Theme Check finding in a machine-readable form (severity, rule id, file, line, plain text) to the admin UI, to extension hooks and to WP-CLI JSON, while keeping the existing HTML output for the 60 legacy checks byte-identical.

## ADDED Requirements

### Requirement: Every finding SHALL be available as a structured record
After a check run, the plugin SHALL provide a list of findings where each record contains: `severity` (`required` | `warning` | `recommended` | `info`), `check` (rule id such as `sql/concat`, or the legacy check class name), `message` (plain text, tags stripped, entities decoded), `file` (theme-relative path or empty), `line` (integer or 0), `evidence` (list of `{line, text}` excerpts) and `html` (the legacy HTML string).

#### Scenario: Finding from a new check
- **WHEN** the SQL check emits a `sql/concat` finding for `inc/views.php` line 42
- **THEN** the structured record has `severity = "required"`, `check = "sql/concat"`, `file = "inc/views.php"`, `line = 42` and `html` starting with `<span class="tc-lead tc-required">REQUIRED</span>:`

#### Scenario: Finding from a legacy check
- **WHEN** a legacy check returns `'<span class="tc-lead tc-warning">WARNING</span>: Found <strong>@import</strong> in <strong>style.css</strong>. …<pre class=\'tc-grep\'>Line 12: @import url(…)</pre>'`
- **THEN** the structured record has `severity = "warning"`, `check` equal to the legacy check's class name, `file = "style.css"`, `evidence = [{line: 12, text: "@import url(…)"}]` and `message` without HTML tags

### Requirement: Severity of legacy findings SHALL be derived from the label text first
When normalising a legacy HTML finding, the severity SHALL be taken from the label text inside the lead span (`REQUIRED`, `WARNING`, `RECOMMENDED`, `INFO`) and only fall back to the `tc-*` CSS class when the label is absent or unrecognised.

#### Scenario: Mismatched class and label
- **WHEN** a legacy finding reads `<span class="tc-lead tc-warning">REQUIRED</span>: …`
- **THEN** the structured record has `severity = "required"`

### Requirement: Legacy HTML output SHALL remain unchanged
The HTML list rendered on the admin page and the existing WP-CLI JSON keys (`result`, `required`, `warnings`, `recommended`, `errors`) SHALL be produced from the structured records and SHALL be identical to the output of the previous version for the same theme and the same set of legacy findings.

#### Scenario: Regression run on an existing theme
- **WHEN** the plugin is run against a theme that triggers only legacy checks before and after this change
- **THEN** the rendered `<li>` list and the CLI JSON keys `result`, `required`, `warnings`, `recommended`, `errors` are identical

### Requirement: WP-CLI JSON SHALL include a findings array
`wp theme review check <slug> --format=true` SHALL add a top-level `findings` key: an array of objects with `severity`, `check`, `message`, `file`, `line`, in addition to the existing keys.

#### Scenario: CLI JSON with structured findings
- **WHEN** a reviewer runs `wp theme review check timbero --format=true`
- **THEN** the output JSON contains `findings` and each element contains the five fields; the pre-existing keys are still present

### Requirement: Findings SHALL be filterable before rendering
The plugin SHALL apply a filter named `themecheck_findings` to the list of structured records, passing the run context (theme object and slug), before rendering or serialising them.

#### Scenario: Site-specific suppression
- **WHEN** a must-use plugin adds a `themecheck_findings` filter that removes records with `check = "nonce/delegated"`
- **THEN** those findings do not appear in the admin list nor in the CLI JSON

### Requirement: Rule severity SHALL be overridable per rule
The plugin SHALL apply a filter named `tc_rule_severity` with the default severity and the rule id before a new-style check emits a finding, so a rule can be promoted or demoted without editing the check.

#### Scenario: Promoting a rule in a test environment
- **WHEN** a must-use plugin returns `required` from `tc_rule_severity` for `nonce/ajax-missing`
- **THEN** that rule's findings are emitted at REQUIRED severity and make the run fail

### Requirement: WP-CLI SHALL analyse the same file set as the admin page
`wp theme review check` SHALL feed checks the same comment-stripped content, with preserved line numbers, and the same parent/child file set as the admin page does.

#### Scenario: Line numbers under CLI
- **WHEN** a finding is emitted for line 42 in the admin page
- **THEN** the same finding reports line 42 in the CLI `findings` output

#### Scenario: Comment-only match under CLI
- **WHEN** a pattern appears only inside a comment
- **THEN** the CLI run does not report it, matching the admin page
