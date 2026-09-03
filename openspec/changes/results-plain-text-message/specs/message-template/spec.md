## Purpose

Let reviewers keep a consistent, editable message template (greeting, intro, notes heading, footer, formatting limits) that the review-message feature fills in for every run.

## ADDED Requirements

### Requirement: A default template SHALL be provided
When no template has been saved, the plugin SHALL use these defaults:
- greeting: `Hi {author},`
- intro: `Thanks for submitting {theme_name} {theme_version} to ThemeForest. Our automated review found the following issues that need to be addressed before the item can be approved:`
- notes heading: `Reviewer notes:`
- footer: `Once these are resolved, please resubmit and we will take another look. Thanks for your patience.` followed by a blank line and `The ThemeForest Review Team`
- default included severities: REQUIRED, WARNING, RECOMMENDED
- evidence lines per finding: 5
- show file and line information: yes

#### Scenario: Fresh install
- **WHEN** the results page loads on a site where the template option does not exist
- **THEN** the message preview uses the defaults above

### Requirement: The template SHALL be editable from the results page
The message panel SHALL contain an "Edit template" section with fields for greeting, intro, notes heading, footer, default included severities, evidence lines per finding and the show-file-line toggle, plus "Save template" and "Reset to default" actions; the assembly order (greeting, intro, findings, notes, footer) SHALL be fixed.

#### Scenario: Save a custom footer
- **WHEN** the reviewer changes the footer and clicks "Save template"
- **THEN** the template is stored, the preview regenerates with the new footer, and reloading the page keeps the new footer

#### Scenario: Reset
- **WHEN** the reviewer clicks "Reset to default"
- **THEN** all fields return to the default values and the stored template is removed

### Requirement: Template storage SHALL be site-wide and sanitized
The template SHALL be stored as a single site option, not autoloaded, with every text field sanitized as multi-line plain text (no HTML) and numeric fields clamped to sensible ranges (evidence lines 0–20).

#### Scenario: HTML pasted into the intro
- **WHEN** the reviewer saves an intro containing `<b>important</b>`
- **THEN** the stored intro contains `important` without tags

#### Scenario: Out-of-range evidence limit
- **WHEN** the reviewer saves evidence lines = 99
- **THEN** the stored value is 20

### Requirement: Saving SHALL require capability and a nonce
Template save and reset requests SHALL be rejected unless the current user can manage options and a valid nonce for the template action is present.

#### Scenario: Missing nonce
- **WHEN** a save request arrives without a valid nonce
- **THEN** the request is rejected with an error and the template is unchanged

### Requirement: Placeholders SHALL be documented inline
The template editor SHALL list the supported placeholders with a one-line description each.

#### Scenario: Editor open
- **WHEN** the reviewer expands "Edit template"
- **THEN** a list of `{author}`, `{theme_name}`, `{theme_version}`, `{date}`, `{required_count}`, `{warning_count}`, `{recommended_count}`, `{info_count}`, `{selected_count}`, `{reviewer_notes}`, `{findings}` with descriptions is visible
