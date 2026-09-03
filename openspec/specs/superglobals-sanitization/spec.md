# superglobals-sanitization Specification

## Purpose
Detect request data (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`, user-controlled `$_SERVER` keys) that a theme reads without validating or sanitizing it, which Envato WP Theme Requirements Part 5 – Theme Security makes mandatory.

## Requirements

### Requirement: Reads of request superglobals SHALL be sanitized or validated in the same statement
For every read of `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`, or `$_SERVER` with a user-controlled key, the check SHALL treat the read as safe only if, within the same statement, it is (a) compared against a literal or constant, (b) used in a boolean context (`!`, `if`/`while` condition), (c) wrapped by a guard (`isset`, `empty`, `unset`, `array_key_exists`, `in_array`, `is_numeric`, `is_array`, `switch`, `count`, …), (d) wrapped by a cast `(int)`, `(integer)`, `(bool)`, `(float)`, or (e) wrapped — at any nesting depth within the statement — by a sanitizing/validating function (`absint`, `intval`, `sanitize_*`, `wp_kses*`, `filter_var`, `esc_url_raw`, `esc_*`, `wp_verify_nonce`, `check_ajax_referer`, `check_admin_referer`, `wp_handle_upload`, `wc_clean`, `wc_*` sanitizers, or any function/method whose name starts with `sanitize`, `validate`, `clean` or `filter`; list filterable). Otherwise the read SHALL be collected as unsanitized.

Policy severity: REQUIRED (Envato: "data … that cannot be validated must be sanitized"). Detection severity v1: WARNING.

#### Scenario: Raw assignment
- **WHEN** a theme does `$layout = $_GET['layout'];`
- **THEN** the read is collected as unsanitized

#### Scenario: Sanitized read
- **WHEN** a theme does `$layout = sanitize_key( wp_unslash( $_GET['layout'] ) );`
- **THEN** the read is treated as safe

#### Scenario: Comparison with a literal
- **WHEN** a theme does `if ( 'list' === $_GET['layout'] )`
- **THEN** the read is treated as safe

#### Scenario: Guard only
- **WHEN** a theme does `if ( isset( $_POST['name'] ) )` and no other use of `$_POST['name']` exists
- **THEN** the read is treated as safe

#### Scenario: Array mapped through a sanitizer
- **WHEN** a theme does `array_map( 'sanitize_text_field', wp_unslash( $_POST['ids'] ) )`
- **THEN** the read is treated as safe

#### Scenario: Nonce argument
- **WHEN** a theme does `wp_verify_nonce( $_POST['nonce'], 'save' )`
- **THEN** the read is treated as safe

#### Scenario: Cast
- **WHEN** a theme does `$page = (int) $_GET['paged'];`
- **THEN** the read is treated as safe

### Requirement: Pass-through functions SHALL NOT count as sanitization
`wp_unslash`, `stripslashes`, `stripslashes_deep`, `urldecode`, `trim`, `strtolower`, `strtoupper`, `json_decode`, `explode` and `array_keys` SHALL NOT terminate the search for a sanitizer; the check SHALL continue to the enclosing call.

#### Scenario: Unslash only
- **WHEN** a theme does `$name = wp_unslash( $_POST['name'] );`
- **THEN** the read is collected as unsanitized

### Requirement: Unsanitized reads SHALL be reported once per file
The check SHALL emit a single `superglobals/unsanitized` finding per file that aggregates the count of unsanitized reads, lists up to eight line excerpts, and appends "and N more" when applicable, so large themes do not produce hundreds of entries.

Policy severity: REQUIRED. Detection severity v1: WARNING with "A manual review is needed."

#### Scenario: Multiple raw reads in one file
- **WHEN** `inc/ajax.php` contains 12 unsanitized reads
- **THEN** exactly one `superglobals/unsanitized` finding is emitted for `inc/ajax.php` reporting 12 reads, showing 8 excerpts and "and 4 more"

#### Scenario: File with only sanitized reads
- **WHEN** a file contains 30 superglobal reads, all sanitized or guarded
- **THEN** no finding is emitted for that file

### Requirement: extract() of request data SHALL be reported as REQUIRED
The check SHALL emit `superglobals/extract` at REQUIRED severity, failing the run, when `extract()` is called with `$_GET`, `$_POST`, `$_REQUEST` or `$_COOKIE` (or any expression containing them) as its first argument.

Policy severity: REQUIRED. Detection severity v1: REQUIRED.

#### Scenario: extract($_POST)
- **WHEN** a theme calls `extract( $_POST );`
- **THEN** a `superglobals/extract` REQUIRED finding is emitted and the check result is "fail"

### Requirement: Whole-array use of request superglobals SHALL be reported
When a request superglobal is used without an index outside a guard context (for example `wp_parse_args( $_POST, $defaults )`, `$data = $_POST;`, `foreach ( $_GET as … )`), the check SHALL emit `superglobals/whole-array` at WARNING severity per file.

Policy severity: REQUIRED. Detection severity v1: WARNING.

#### Scenario: Whole $_POST merged into settings
- **WHEN** a theme does `$settings = wp_parse_args( $_POST, $defaults );`
- **THEN** a `superglobals/whole-array` finding is emitted

#### Scenario: Whole array inside a guard
- **WHEN** a theme does `if ( empty( $_POST ) ) { return; }`
- **THEN** no `superglobals/whole-array` finding is emitted

### Requirement: Non-user-controlled $_SERVER keys SHALL be ignored
Reads of `$_SERVER['REQUEST_METHOD']`, `HTTPS`, `SERVER_PORT`, `SERVER_PROTOCOL`, `DOCUMENT_ROOT`, `SCRIPT_FILENAME`, `REQUEST_TIME`, `REQUEST_TIME_FLOAT`, `REMOTE_ADDR` and `SERVER_ADDR` SHALL NOT be collected. Reads of other `$_SERVER` keys (`REQUEST_URI`, `HTTP_HOST`, `HTTP_REFERER`, `HTTP_USER_AGENT`, `QUERY_STRING`, `PHP_SELF`, `HTTP_X_*`, …) SHALL follow the normal sanitization rule.

#### Scenario: REQUEST_METHOD comparison
- **WHEN** a theme does `if ( 'POST' === $_SERVER['REQUEST_METHOD'] )`
- **THEN** no finding is emitted

#### Scenario: Raw REQUEST_URI
- **WHEN** a theme does `$current = $_SERVER['REQUEST_URI'];`
- **THEN** the read is collected as unsanitized

### Requirement: Writes to superglobals SHALL NOT be reported
Assignments to a superglobal element (`$_POST['x'] = …`, `$_GET['y'] .= …`) SHALL NOT be treated as reads.

#### Scenario: Normalising a request value
- **WHEN** a theme does `$_GET['s'] = trim( $_GET['s'] );`
- **THEN** only the right-hand `$_GET['s']` read is evaluated (and is collected as unsanitized, since `trim` is a pass-through)

### Requirement: Findings inside bundled third-party frameworks SHALL be downgraded to INFO
When the file path matches a known bundled-framework path (list filterable), `superglobals/unsanitized` and `superglobals/whole-array` findings SHALL be emitted at INFO severity. `superglobals/extract` SHALL remain REQUIRED.

#### Scenario: Raw reads inside Kirki
- **WHEN** unsanitized reads are detected in `inc/kirki/…`
- **THEN** the aggregated finding is emitted at INFO severity

### Requirement: Comments SHALL never trigger findings
Superglobal references that appear only inside PHP comments SHALL NOT be collected.

#### Scenario: Commented example
- **WHEN** a file contains `// e.g. $_GET['debug']` only in a comment
- **THEN** no finding is emitted
