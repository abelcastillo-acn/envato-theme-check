<?php
/**
 * Census of security findings on an installed theme (false-positive calibration).
 *
 * Usage: wp eval-file wp-content/plugins/envato-theme-check/tests/census.php <theme-slug> [all]
 *   "all" also lists non-security findings counts.
 */

if ( ! defined( 'WP_CLI' ) ) {
	echo "Run with: wp eval-file tests/census.php <theme-slug>\n";
	exit( 1 );
}

$slug = isset( $args[0] ) ? $args[0] : get_stylesheet();
$all  = isset( $args[1] ) && 'all' === $args[1];

if ( ! function_exists( 'run_themechecks_against_theme' ) ) {
	include_once dirname( __DIR__ ) . '/checkbase.php';
	include_once dirname( __DIR__ ) . '/main.php';
}

$theme = wp_get_theme( $slug );
if ( ! $theme->exists() ) {
	WP_CLI::error( "Theme '$slug' is not installed." );
}

$php_count = count( $theme->get_files( 'php', -1, true ) );
$start     = microtime( true );
$success   = run_themechecks_against_theme( $theme, $slug );
$findings  = tc_collect_results();
$elapsed   = microtime( true ) - $start;

$security = array_filter( $findings, function ( $f ) {
	return preg_match( '#^(nonce|sql|superglobals)/#', $f['check'] );
} );

WP_CLI::line( sprintf( 'Theme: %s (%s)  |  PHP files: %d  |  result: %s  |  %.2fs  |  peak %.1f MB', $theme->get( 'Name' ), $slug, $php_count, $success ? 'pass' : 'FAIL', $elapsed, memory_get_peak_usage( true ) / 1048576 ) );
WP_CLI::line( sprintf( 'Findings: %d total, %d from security checks', count( $findings ), count( $security ) ) );

$sev = array( 'required' => 0, 'warning' => 0, 'recommended' => 0, 'info' => 0 );
$by_rule = array();
$by_file = array();
foreach ( $security as $f ) {
	$sev[ $f['severity'] ]++;
	$by_rule[ $f['check'] ] = isset( $by_rule[ $f['check'] ] ) ? $by_rule[ $f['check'] ] + 1 : 1;
	$by_file[ $f['file'] ]  = isset( $by_file[ $f['file'] ] ) ? $by_file[ $f['file'] ] + 1 : 1;
}
WP_CLI::line( '' );
WP_CLI::line( 'Security findings by severity: ' . json_encode( $sev ) );
WP_CLI::line( 'By rule:' );
ksort( $by_rule );
foreach ( $by_rule as $rule => $n ) {
	WP_CLI::line( sprintf( '  %-32s %d', $rule, $n ) );
}
arsort( $by_file );
WP_CLI::line( 'Top files:' );
foreach ( array_slice( $by_file, 0, 10, true ) as $file => $n ) {
	WP_CLI::line( sprintf( '  %-60s %d', $file, $n ) );
}
WP_CLI::line( '' );
WP_CLI::line( 'Security findings (severity, rule, file:line, message):' );
foreach ( $security as $f ) {
	WP_CLI::line( sprintf( '  [%s] %-28s %s:%d', strtoupper( $f['severity'] ), $f['check'], $f['file'], $f['line'] ) );
	WP_CLI::line( '      ' . mb_substr( $f['message'], 0, 160 ) );
}

if ( $all ) {
	$other = array();
	foreach ( $findings as $f ) {
		if ( preg_match( '#^(nonce|sql|superglobals)/#', $f['check'] ) ) {
			continue;
		}
		$k           = $f['severity'] . ' ' . $f['check'];
		$other[ $k ] = isset( $other[ $k ] ) ? $other[ $k ] + 1 : 1;
	}
	ksort( $other );
	WP_CLI::line( '' );
	WP_CLI::line( 'Other findings:' );
	foreach ( $other as $k => $n ) {
		WP_CLI::line( sprintf( '  %-50s %d', $k, $n ) );
	}
}
