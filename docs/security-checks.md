# Security checks — guide for theme authors and reviewers

Envato Theme Check 2.1 adds three families of security checks. This page explains what they look for, why the rule exists, how to fix each finding, and how reviewers should treat each severity. Design and rationale live in `openspec/changes/security-checks-v1/`.

## Requirement source

These checks enforce rules that are **mandatory** for ThemeForest submissions:

- Envato — [WordPress Theme Requirements Part 5: Theme Security](https://help.author.envato.com/hc/en-us/articles/360000481243-WordPress-Theme-Requirements-Part-5-Theme-Security): nonces "must be used", capabilities "must be checked", SQL "must be prepared using `$wpdb->prepare()`", input "must be validated … [or] sanitized".
- WordPress.org — [Theme Review: Required](https://make.wordpress.org/themes/handbook/review/required/) ("The theme must be secure", "Validate and sanitize untrusted data") and [Theme Handbook: Security](https://developer.wordpress.org/themes/advanced-topics/security/).
- WordPress Coding Standards — `WordPress.DB.PreparedSQL` (Core), `WordPress.Security.NonceVerification` (Extra), `WordPress.Security.ValidatedSanitizedInput`.

## Policy severity vs. detection severity

Every rule below has two severities:

- **Policy** — what the requirement says. All rules on this page are REQUIRED under Envato's requirements.
- **Detection (v1)** — what the plugin reports today. Where static analysis cannot *prove* a control is missing (for example, the nonce may be verified in a wrapper method), the plugin reports WARNING with "A manual review is needed" and the reviewer confirms.

A WARNING from these checks is therefore not optional advice: if the reviewer confirms the issue, it is grounds for rejection. See the rollout table for when WARNINGs become REQUIRED.

## Rules

### Nonce / CSRF and capabilities (`nonce/*`)

| Rule id | Fires when | Detection v1 |
|---|---|---|
| `nonce/ajax-missing` | A privileged `wp_ajax_*` or `admin_post_*` handler has no `check_ajax_referer()`, `check_admin_referer()` or `wp_verify_nonce()` | WARNING |
| `nonce/nopriv-state-change` | A `wp_ajax_nopriv_*`, `admin_post_nopriv_*` or `wc_ajax_*` handler changes state (options, posts, meta, users, cookies, files, mail) without nonce verification | WARNING |
| `nonce/form-handler` | Any function that reads `$_POST`/`$_REQUEST`/`$_GET` and changes state without nonce verification | WARNING |
| `nonce/capability-missing` | A privileged handler changes state without `current_user_can()` / `user_can()` / `is_super_admin()` | WARNING → REQUIRED (phase 2) |
| `nonce/delegated` | Verification appears to be delegated to a wrapper (`$this->verify_request()`, `mytheme_security_check()`) | INFO |

**Fix**

```php
add_action( 'wp_ajax_theme_import_demo', 'theme_import_demo' );

function theme_import_demo() {
    check_ajax_referer( 'theme_import', 'nonce' );                 // CSRF
    if ( ! current_user_can( 'manage_options' ) ) {                // capability
        wp_send_json_error( 'forbidden', 403 );
    }
    $demo = sanitize_key( wp_unslash( $_POST['demo'] ?? '' ) );   // sanitize
    update_option( 'theme_demo', $demo );
    wp_send_json_success();
}

// Send the nonce from JavaScript
wp_localize_script( 'theme-admin', 'themeAdmin', array(
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    'nonce'   => wp_create_nonce( 'theme_import' ),
) );
```

Public read-only endpoints (`wp_ajax_nopriv_*` that only output data) do not need a nonce but must still sanitize their input.

**Reviewer guidance** — Every `wp_ajax_*`/`admin_post_*` handler must verify a nonce; privileged handlers that write must also check a capability. Open the function named in the finding; if verification is in a wrapper, confirm the wrapper actually calls one of the three functions.

**Typical effort** — Minutes per handler; hours for themes with 15–30 AJAX endpoints because the JavaScript must pass the nonce.

### SQL without `$wpdb->prepare()` (`sql/*`)

| Rule id | Fires when | Detection v1 |
|---|---|---|
| `sql/raw-driver` | `mysql_*`, `mysqli_*`, `new mysqli`, `new PDO`, `PDO::` | **REQUIRED** |
| `sql/concat` | `$wpdb->query/get_results/get_var/get_row/get_col()` receives SQL that interpolates or concatenates a variable other than `$wpdb->prefix`/`$wpdb->posts`/… without `prepare()` | **REQUIRED** |
| `sql/esc-sql-concat` | Same shape but every variable is wrapped in `esc_sql()`/`absint()`/`(int)` | WARNING |
| `sql/variable-arg-concat` | The SQL is a variable whose assignment concatenates variables | WARNING → REQUIRED (phase 2) |
| `sql/variable-arg` | The SQL is a variable whose origin cannot be determined | WARNING |
| `sql/prepare-no-placeholders` | `prepare()` with a literal that has no `%d/%s/%f/%i` placeholder | WARNING |
| `sql/prepare-interpolated` | A variable is interpolated into the `prepare()` format string | WARNING |
| `sql/prepare-variable-format` | The `prepare()` format string is a variable built by concatenation | WARNING |

**Fix**

```php
// before
$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = $post_id AND meta_key = 'views'" );

// after
$wpdb->query( $wpdb->prepare(
    "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
    $post_id,
    'views'
) );

// LIKE: escape the term first
$like = '%' . $wpdb->esc_like( $term ) . '%';
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", $like ) );

// Dynamic identifiers: allowlist + %i (WP 6.2+)
$orderby = in_array( $orderby, array( 'post_date', 'post_title' ), true ) ? $orderby : 'post_date';
$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} ORDER BY %i", $orderby ) );
```

Table names taken from `$wpdb` (`$wpdb->prefix`, `$wpdb->posts`, `$table = $wpdb->prefix . 'x'`) are fine. `$wpdb->insert()/update()/delete()/replace()` are not flagged.

**Reviewer guidance** — REQUIRED findings are hard rejects unless the author demonstrates the interpolated value is a constant or an allowlisted identifier. For WARNINGs, follow the variable: approve if it is a `prepare()` result or a constant.

**Typical effort** — Minutes per query.

### Unsanitized request input (`superglobals/*`)

| Rule id | Fires when | Detection v1 |
|---|---|---|
| `superglobals/unsanitized` | `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES` or a user-controlled `$_SERVER` key is read without a sanitizer/validator/guard/cast in the same statement (one finding per file, up to 8 excerpts) | WARNING |
| `superglobals/whole-array` | A whole superglobal is used (`wp_parse_args( $_POST, … )`, `$data = $_POST`, `foreach ( $_GET … )`) | WARNING |
| `superglobals/extract` | `extract( $_POST )` (or `$_GET`/`$_REQUEST`/`$_COOKIE`) | **REQUIRED** |

Safe patterns: `isset()`/`empty()`/`in_array()`/`switch` guards, comparisons with literals, `(int)`/`(bool)`/`(float)` casts, `absint()`, `intval()`, `sanitize_*()`, `wp_kses*()`, `filter_var()`, `esc_url_raw()`, `wp_verify_nonce( $_POST['n'], … )`, `wp_handle_upload()`, WooCommerce `wc_clean()`, `array_map( 'sanitize_text_field', … )`, and any function/method whose name starts with `sanitize`, `validate`, `clean` or `filter`. `wp_unslash()`, `trim()`, `stripslashes()` alone are **not** sanitization.

`$_SERVER['REQUEST_METHOD']`, `HTTPS`, `SERVER_PORT`, `REMOTE_ADDR` and similar server-controlled keys are ignored. `REQUEST_URI`, `HTTP_HOST`, `HTTP_REFERER`, `HTTP_USER_AGENT`, `QUERY_STRING`, `PHP_SELF` are user-controlled and must be sanitized.

**Fix**

```php
$layout = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( $_GET['layout'] ) ) : 'grid';
$layout = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';

$page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

$ids  = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
```

**Reviewer guidance** — Advisory by default. Skim the excerpts: a raw read that flows into SQL, `include`, `header()`, `wp_redirect()` or HTML output is a reject; a raw read compared to a literal or passed into a casting WordPress API is acceptable.

**Typical effort** — Trivial per line; hours to a day for a theme with 100+ raw reads.

## Bundled frameworks

Findings inside bundled third-party code (Redux, Kirki, CMB2, One Click Demo Import, aq_resizer, TGMPA, Merlin, Envato Market, `vendor/`, `node_modules/`) are reported at INFO so they stay visible without failing the theme. Keep bundled frameworks up to date rather than patching them. `sql/raw-driver` and `superglobals/extract` remain REQUIRED everywhere.

## Rollout

| Phase | Timing | Change |
|---|---|---|
| 0 | Release 2.1.0 | Only `sql/raw-driver`, `sql/concat`, `superglobals/extract` are REQUIRED. Other rules WARNING/INFO; reviewers treat them as soft-reject items. |
| 1 | Weeks 1–6 | Calibration on recently approved themes; false positives reported as GitHub issues labelled `security-checks`. |
| 2 | ~Week 6 | `sql/variable-arg-concat` and `nonce/capability-missing` become REQUIRED. |
| 3 | ~Week 10 | `nonce/ajax-missing` becomes REQUIRED. `superglobals/unsanitized` stays WARNING. |

## WP-CLI note

`wp theme review check <slug>` already returns `FAIL` when any WARNING is present. Automation should read the new `findings[]` array (`severity`, `check`, `file`, `line`, `message`) in `--format=true` output instead of the `result` string.

## Extending or tuning

- `tc_rule_severity( $severity, $rule_id )` — override the severity of any rule.
- `tc_sanitizing_functions( array )` — add custom sanitizers.
- `tc_state_changing_functions( array )` — add functions that count as writes.
- `tc_vendor_paths( array )` — add bundled-framework paths.
- `themecheck_findings( array $findings, array $context )` — filter the structured results before rendering.

## Further reading

- [Nonces](https://developer.wordpress.org/apis/security/nonces/) · [Roles and capabilities](https://developer.wordpress.org/apis/security/user-roles-and-capabilities/)
- [`wpdb` — protecting queries against SQL injection](https://developer.wordpress.org/reference/classes/wpdb/#protect-queries-against-sql-injection-attacks)
- [Sanitizing data](https://developer.wordpress.org/apis/security/sanitizing/) · [Data validation](https://developer.wordpress.org/apis/security/data-validation/)
