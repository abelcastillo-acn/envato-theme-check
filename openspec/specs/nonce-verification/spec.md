# nonce-verification Specification

## Purpose
Detect theme request handlers (AJAX, admin-post, form handlers) that accept data from the browser without verifying a nonce and, where privileged, without checking user capabilities — a ThemeForest "must" requirement (Envato WP Theme Requirements Part 5 – Theme Security).

## Requirements

### Requirement: Privileged AJAX and admin-post handlers SHALL verify a nonce
For every handler registered with `add_action( 'wp_ajax_<action>', … )` or `add_action( 'admin_post_<action>', … )` (privileged, i.e. not `nopriv`), the check SHALL emit a finding with rule id `nonce/ajax-missing` when the resolved handler body contains no call to `check_ajax_referer()`, `check_admin_referer()` or `wp_verify_nonce()`.

Policy severity: REQUIRED (Envato: "a nonce must be used to verify the origin and intent of the request"). Detection severity v1: WARNING with the text "A manual review is needed."

#### Scenario: AJAX handler without nonce verification
- **WHEN** a theme file registers `add_action( 'wp_ajax_theme_import_demo', 'theme_import_demo' )` and the function `theme_import_demo()` contains no nonce verification call
- **THEN** a `nonce/ajax-missing` finding is emitted naming the hook, the handler, the file and the line of the handler signature, citing the ThemeForest requirement and linking to the WordPress nonce documentation

#### Scenario: AJAX handler with nonce verification
- **WHEN** the resolved handler body contains `check_ajax_referer( 'theme_import', 'nonce' )` (or `check_admin_referer()` / `wp_verify_nonce()`) anywhere in its body
- **THEN** no `nonce/ajax-missing` finding is emitted for that handler

#### Scenario: Handler registered as a class method
- **WHEN** the callback is `array( $this, 'import_demo' )` or `'My_Class::import_demo'` and a method with that name exists in the theme
- **THEN** the method body is analysed as the handler body

#### Scenario: Handler registered as a closure
- **WHEN** the callback is an anonymous function or arrow function
- **THEN** the closure body is analysed as the handler body and the finding names the handler as `{closure}`

### Requirement: Public (nopriv) handlers SHALL verify a nonce only when they change state
For handlers registered with `wp_ajax_nopriv_*`, `admin_post_nopriv_*` or `wc_ajax_*`, the check SHALL emit `nonce/nopriv-state-change` only when the handler body both lacks nonce verification and performs a state-changing operation (writing options, posts, meta, users, terms, transients, cookies, files, or sending mail).

Policy severity: REQUIRED. Detection severity v1: WARNING.

#### Scenario: Public read-only endpoint
- **WHEN** a `wp_ajax_nopriv_theme_quick_view` handler only reads data and echoes HTML/JSON, without nonce verification
- **THEN** no finding is emitted

#### Scenario: Public endpoint that writes
- **WHEN** a `wp_ajax_nopriv_theme_add_to_wishlist` handler calls `setcookie()` or `update_user_meta()` without nonce verification
- **THEN** a `nonce/nopriv-state-change` finding is emitted

### Requirement: Privileged handlers that change state SHALL check capabilities
For privileged `wp_ajax_*` and `admin_post_*` handlers whose body performs a state-changing operation, the check SHALL emit `nonce/capability-missing` when the body contains no call to `current_user_can()`, `user_can()`, `current_user_can_for_blog()` or `is_super_admin()`.

Policy severity: REQUIRED (Envato: "User capabilities must be checked … Only users with the appropriate capabilities may be allowed to submit data"). Detection severity v1: WARNING; planned promotion to REQUIRED after the calibration period.

#### Scenario: Importer callable by any logged-in user
- **WHEN** a `wp_ajax_theme_import_demo` handler verifies a nonce and calls `update_option()` but never calls a capability function
- **THEN** a `nonce/capability-missing` finding is emitted naming the state-changing function found

#### Scenario: Handler with capability check
- **WHEN** the handler body contains `if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }`
- **THEN** no `nonce/capability-missing` finding is emitted

#### Scenario: Capability checked before registration
- **WHEN** the `add_action( 'wp_ajax_…', … )` call itself is inside a function that calls `current_user_can()` (or another capability function) before registering the handler, so the hook is never registered for unprivileged users
- **THEN** no `nonce/capability-missing` finding is emitted for that handler

### Requirement: Form handlers outside AJAX hooks SHALL verify a nonce before writing
For any function body (or file scope) that reads `$_POST`, `$_REQUEST` or `$_GET` and performs a state-changing operation, and that was not already reported as an AJAX/admin-post handler, the check SHALL emit `nonce/form-handler` when no nonce verification call is present in the same body.

Policy severity: REQUIRED. Detection severity v1: WARNING.

#### Scenario: Theme options saved on admin_init
- **WHEN** a function hooked to `admin_init` does `if ( isset( $_POST['theme_options'] ) ) { update_option( 'theme_options', $_POST['theme_options'] ); }` without `check_admin_referer()`
- **THEN** a `nonce/form-handler` finding is emitted

#### Scenario: Read-only use of request data
- **WHEN** a template reads `$_GET['layout']` to choose a CSS class and performs no state-changing operation
- **THEN** no `nonce/form-handler` finding is emitted

### Requirement: Delegated verification SHALL be reported as informational
When a handler body lacks a direct nonce verification call but calls a function or method whose name matches `nonce`, `referer`, `verify`, `security`, `permission` or `capab` (case-insensitive), the check SHALL emit `nonce/delegated` at INFO severity instead of a WARNING, so reviewers can confirm the wrapper.

#### Scenario: Wrapper method
- **WHEN** a handler calls `$this->verify_request()` and contains no direct nonce verification call
- **THEN** a `nonce/delegated` INFO finding is emitted and no `nonce/ajax-missing` WARNING is emitted for that handler

### Requirement: Unresolvable handlers SHALL be summarised, not silently skipped
When a registration's hook name or callback cannot be resolved statically (dynamic string, variable callback, method not found in the theme), the check SHALL count it and emit one INFO finding per file: "N request handler registrations could not be resolved; manual review needed."

#### Scenario: Dynamic hook name
- **WHEN** a file contains `add_action( 'wp_ajax_' . $action, $callback )`
- **THEN** one INFO finding for that file reports the unresolved registration and no WARNING is emitted for it

### Requirement: Nonce emission SHALL not count as verification
`wp_nonce_field()`, `wp_create_nonce()` and `wp_nonce_url()` SHALL NOT be treated as verification calls.

#### Scenario: Handler that only outputs a nonce field
- **WHEN** a handler body calls `wp_nonce_field( 'save' )` but no verification function
- **THEN** the handler is still reported as missing verification

### Requirement: Findings inside bundled third-party frameworks SHALL be downgraded to INFO
When the file path matches a known bundled-framework path (e.g. `redux-framework`, `kirki`, `cmb2`, `one-click-demo-import`, `class-tgm-plugin-activation`, `merlin`, `envato-market`, `vendor/`, `node_modules/`; list filterable), any finding from this capability SHALL be emitted at INFO severity.

#### Scenario: Missing nonce inside Redux
- **WHEN** a `nonce/ajax-missing` condition is detected in `inc/redux-framework/…`
- **THEN** the finding is emitted at INFO severity with the same message

### Requirement: Comments SHALL never trigger findings
Code that appears only inside PHP comments SHALL NOT produce or suppress findings.

#### Scenario: Commented-out handler
- **WHEN** a registration and its handler exist only inside a `/* … */` block
- **THEN** no finding is emitted for them
