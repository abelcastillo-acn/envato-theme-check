<?php
/**
 * TC Security Fixture.
 *
 * The inc/vuln-*.php files start with `if ( ! defined( 'TC_FIXTURE' ) ) { return; }` so their code is
 * inert if the theme is ever activated. The static checks analyse them regardless.
 *
 * Expectations are declared inline with `// EXPECT: <rule>[, <rule>]` on the line the finding must point to
 * (function signature for nonce rules, statement line for SQL/superglobal rules) and `// EXPECT-FILE: <rule>`
 * for file-level findings. tests/run-fixture.php compares them with the actual findings.
 *
 * @package TC_Security_Fixture
 */

require_once get_template_directory() . '/inc/vuln-nonce.php';
require_once get_template_directory() . '/inc/vuln-sql.php';
require_once get_template_directory() . '/inc/vuln-superglobals.php';
require_once get_template_directory() . '/inc/safe.php';
