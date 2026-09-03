# sql-prepare Specification

## Purpose
Detect SQL executed by a theme without WordPress' prepared-statement API, misuse of `$wpdb->prepare()`, and any direct use of PHP database drivers — all "must" requirements in Envato WP Theme Requirements Part 5 – Theme Security.

## Requirements

### Requirement: Direct database driver usage SHALL be reported as REQUIRED
The check SHALL emit `sql/raw-driver` at REQUIRED severity, and the run SHALL fail, for any call to `mysql_*` or `mysqli_*` functions, `new mysqli`, `new PDO`, or `PDO::` static usage in theme code.

Policy severity: REQUIRED (Envato: "the wpdb class must be used"). Detection severity v1: REQUIRED.

#### Scenario: mysqli connection in theme code
- **WHEN** a theme file contains `mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD )`
- **THEN** a `sql/raw-driver` REQUIRED finding is emitted with file and line and the check result is "fail"

#### Scenario: Feature detection is not flagged
- **WHEN** a theme file contains `function_exists( 'mysqli_connect' )` or `extension_loaded( 'mysqli' )` only
- **THEN** no `sql/raw-driver` finding is emitted

### Requirement: SQL passed to $wpdb read/query methods SHALL NOT interpolate non-$wpdb variables outside prepare()
For calls to `$wpdb->query()`, `get_results()`, `get_var()`, `get_row()` and `get_col()`, the check SHALL emit `sql/concat` at REQUIRED severity when the SQL argument is not a `$wpdb->prepare()` call and contains a variable, string interpolation, concatenation or `sprintf()` involving any expression other than `$wpdb` properties (`$wpdb->prefix`, `$wpdb->posts`, …).

Policy severity: REQUIRED (Envato: "SQL statements must be prepared using $wpdb->prepare()"). Detection severity v1: REQUIRED.

#### Scenario: Interpolated post ID
- **WHEN** a theme calls `$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = $post_id" )`
- **THEN** a `sql/concat` REQUIRED finding is emitted naming `$post_id` and suggesting `$wpdb->prepare( "… WHERE post_id = %d", $post_id )`

#### Scenario: Prepared query
- **WHEN** a theme calls `$wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $author ) )`
- **THEN** no finding is emitted

#### Scenario: Table names from $wpdb
- **WHEN** the only interpolated expressions are `$wpdb->prefix`, `$wpdb->posts`, `$wpdb->postmeta` or another `$wpdb` property
- **THEN** no finding is emitted

#### Scenario: Table name variable derived from $wpdb
- **WHEN** a variable is assigned from `$wpdb->prefix . 'theme_table'` in the same function and later interpolated into the SQL
- **THEN** that variable is treated as safe and no finding is emitted for it

### Requirement: SQL escaped only with esc_sql() or casts SHALL be reported as WARNING
When every non-`$wpdb` expression in an otherwise `sql/concat` shape is directly wrapped in `esc_sql()`, `absint()`, `intval()`, `(int)`, `(float)` or `floatval()`, the check SHALL emit `sql/esc-sql-concat` at WARNING severity instead of REQUIRED.

Policy severity: REQUIRED. Detection severity v1: WARNING ("prefer prepare(); esc_sql() is acceptable only for identifiers from a fixed allowlist").

#### Scenario: esc_sql around an ORDER BY value
- **WHEN** a theme calls `$wpdb->get_results( "SELECT … ORDER BY " . esc_sql( $orderby ) )`
- **THEN** a `sql/esc-sql-concat` WARNING finding is emitted

### Requirement: SQL held in a variable SHALL be resolved from its last assignment in scope
When the SQL argument is a plain variable, the check SHALL locate the last assignment to that variable in the same function (or file scope) before the call and classify the assigned expression: a `$wpdb->prepare()` result or constant string SHALL produce no finding; an interpolated/concatenated expression SHALL produce `sql/variable-arg-concat`; an unresolvable assignment SHALL produce `sql/variable-arg` at WARNING.

Policy severity: REQUIRED. Detection severity v1: `sql/variable-arg-concat` WARNING (promotion to REQUIRED planned after calibration), `sql/variable-arg` WARNING.

#### Scenario: Prepared statement stored in a variable
- **WHEN** a theme does `$query = $wpdb->prepare( "SELECT … %s", $url ); $result = $wpdb->get_results( $query );`
- **THEN** no finding is emitted

#### Scenario: Concatenated statement stored in a variable
- **WHEN** a theme does `$sql = "DELETE FROM {$wpdb->posts} WHERE ID = " . $id; $wpdb->query( $sql );`
- **THEN** a `sql/variable-arg-concat` finding is emitted at the line of the `$wpdb->query()` call, referencing the assignment line

#### Scenario: Variable from an unknown source
- **WHEN** a theme does `$wpdb->query( $bit )` where `$bit` is a loop variable over an imported SQL dump
- **THEN** a `sql/variable-arg` WARNING finding is emitted stating that the origin of the SQL could not be determined and a manual review is needed

### Requirement: prepare() misuse SHALL be reported as WARNING
The check SHALL emit: `sql/prepare-no-placeholders` when `$wpdb->prepare()` receives a literal format string with no `%d`, `%s`, `%f`, `%F`, `%i` or numbered placeholder and no further arguments; `sql/prepare-interpolated` when the format string interpolates or concatenates a non-`$wpdb` variable; `sql/prepare-variable-format` when the format string is a variable whose in-scope assignment concatenates non-`$wpdb` variables.

Policy severity: REQUIRED. Detection severity v1: WARNING.

#### Scenario: Value interpolated into the format string
- **WHEN** a theme calls `$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_name = '$slug'" )`
- **THEN** a `sql/prepare-interpolated` WARNING finding is emitted explaining that `$slug` must be passed as a `%s` argument

#### Scenario: prepare() with no placeholders
- **WHEN** a theme calls `$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts}" )`
- **THEN** a `sql/prepare-no-placeholders` WARNING finding is emitted

#### Scenario: Correct prepare()
- **WHEN** a theme calls `$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s", $slug, 'post' )`
- **THEN** no finding is emitted

### Requirement: $wpdb write helpers SHALL NOT be flagged
Calls to `$wpdb->insert()`, `update()`, `delete()` and `replace()` SHALL NOT produce findings from this capability, because they escape values internally.

#### Scenario: $wpdb->insert with array data
- **WHEN** a theme calls `$wpdb->insert( $table, array( 'name' => $name ) )`
- **THEN** no finding is emitted

### Requirement: Findings inside bundled third-party frameworks SHALL be downgraded to INFO
When the file path matches a known bundled-framework path (list filterable), any finding from this capability except `sql/raw-driver` SHALL be emitted at INFO severity.

#### Scenario: Unprepared query inside a bundled importer library
- **WHEN** a `sql/variable-arg` condition is detected in `inc/one-click-demo-import/…`
- **THEN** the finding is emitted at INFO severity

### Requirement: Comments SHALL never trigger findings
Code that appears only inside PHP comments SHALL NOT produce findings.

#### Scenario: Commented-out raw query
- **WHEN** a file contains `// $wpdb->query( "DELETE … $id" );` only in a comment
- **THEN** no finding is emitted
