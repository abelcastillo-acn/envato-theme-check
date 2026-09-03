<?php
/**
 * Fixture runner for the security checks.
 *
 * Usage (from the Local site shell, plugin active):
 *   wp eval-file wp-content/plugins/envato-theme-check/tests/run-fixture.php [theme-slug] [write-expectations]
 *
 * Expectations are declared inline in the fixture theme with
 *   // EXPECT: <rule>[, <rule>]      on the line the finding must reference (directly or via a "Line N:" excerpt)
 *   // EXPECT-FILE: <rule>           for file-level findings (line 0)
 * Files named safe.php must produce zero findings from the nonce/, sql/ and superglobals/ families.
 *
 * Exit code 0 on success, 1 on any mismatch.
 */

if ( ! defined( 'WP_CLI' ) ) {
	echo "Run with: wp eval-file tests/run-fixture.php <theme-slug>\n";
	exit( 1 );
}

$slug  = isset( $args[0] ) ? $args[0] : 'tc-security-fixture';
$write = isset( $args[1] ) && 'write-expectations' === $args[1];

if ( ! function_exists( 'run_themechecks_against_theme' ) ) {
	include_once dirname( __DIR__ ) . '/checkbase.php';
	include_once dirname( __DIR__ ) . '/main.php';
}

$theme = wp_get_theme( $slug );
if ( ! $theme->exists() ) {
	WP_CLI::error( "Theme '$slug' is not installed." );
}

$start = microtime( true );
run_themechecks_against_theme( $theme, $slug );
$findings = tc_collect_results();
$elapsed  = microtime( true ) - $start;

$families = '#^(nonce|sql|superglobals)/#';
$security = array_values( array_filter( $findings, function ( $f ) use ( $families ) {
	return preg_match( $families, $f['check'] );
} ) );

// Collect expectations from the fixture source.
$root     = trailingslashit( $theme->get_stylesheet_directory() );
$expected = array(); // [ file, line|0, rule ]
foreach ( $theme->get_files( 'php', -1, false ) as $rel => $abs ) {
	$lines = file( $abs, FILE_IGNORE_NEW_LINES );
	foreach ( $lines as $idx => $line ) {
		if ( preg_match( '#//\s*EXPECT(-FILE)?:\s*([a-z0-9/_\-]+(?:\s*,\s*[a-z0-9/_\-]+)*)#i', $line, $m ) ) {
			foreach ( preg_split( '/\s*,\s*/', trim( $m[2] ) ) as $rule ) {
				$expected[] = array( str_replace( '\\', '/', $rel ), $m[1] ? 0 : $idx + 1, $rule );
			}
		}
	}
}

// Match.
$matched   = array();
$missing   = array();
$evidence  = function ( $f, $line ) {
	return false !== strpos( wp_strip_all_tags( str_replace( '</pre>', "\n", $f['evidence'] ) ), 'Line ' . $line . ':' );
};
foreach ( $expected as $e ) {
	list( $file, $line, $rule ) = $e;
	$hit = false;
	foreach ( $security as $k => $f ) {
		if ( $f['check'] !== $rule || str_replace( '\\', '/', $f['file'] ) !== $file ) {
			continue;
		}
		if ( 0 === $line || (int) $f['line'] === $line || $evidence( $f, $line ) ) {
			$matched[ $k ] = true;
			$hit           = true;
			break;
		}
	}
	if ( ! $hit ) {
		$missing[] = "$rule  $file:$line";
	}
}

$unexpected = array();
foreach ( $security as $k => $f ) {
	if ( isset( $matched[ $k ] ) ) {
		continue;
	}
	// An aggregated finding may legitimately be "matched" by several markers; only unmatched findings are unexpected.
	$unexpected[] = sprintf( '%-28s %s:%d  %s', $f['check'], $f['file'], $f['line'], mb_substr( $f['message'], 0, 90 ) );
}

$safe_hits = array();
foreach ( $security as $f ) {
	if ( preg_match( '#(^|/)safe\.php$#', str_replace( '\\', '/', $f['file'] ) ) ) {
		$safe_hits[] = $f['check'] . ' ' . $f['file'] . ':' . $f['line'];
	}
}

// Report.
WP_CLI::line( sprintf( 'Theme: %s  |  security findings: %d  |  expectations: %d  |  %.2fs  |  peak %.1f MB', $slug, count( $security ), count( $expected ), $elapsed, memory_get_peak_usage( true ) / 1048576 ) );
$by_rule = array();
foreach ( $security as $f ) {
	$by_rule[ $f['check'] ] = isset( $by_rule[ $f['check'] ] ) ? $by_rule[ $f['check'] ] + 1 : 1;
}
ksort( $by_rule );
foreach ( $by_rule as $rule => $n ) {
	WP_CLI::line( sprintf( '  %-32s %d', $rule, $n ) );
}

$ok = true;
if ( $missing ) {
	$ok = false;
	WP_CLI::line( '' );
	WP_CLI::warning( 'Missing findings (expected but not emitted):' );
	foreach ( $missing as $m ) {
		WP_CLI::line( '  - ' . $m );
	}
}
if ( $unexpected ) {
	$ok = false;
	WP_CLI::line( '' );
	WP_CLI::warning( 'Unexpected findings (emitted but no EXPECT marker):' );
	foreach ( $unexpected as $u ) {
		WP_CLI::line( '  - ' . $u );
	}
}
if ( $safe_hits ) {
	$ok = false;
	WP_CLI::line( '' );
	WP_CLI::warning( 'Findings in safe.php (must be zero):' );
	foreach ( $safe_hits as $s ) {
		WP_CLI::line( '  - ' . $s );
	}
}

if ( $write ) {
	$map = array();
	foreach ( $security as $f ) {
		$lines = array();
		if ( $f['line'] ) {
			$lines[] = (int) $f['line'];
		}
		if ( preg_match_all( '/Line (\d+):/', wp_strip_all_tags( str_replace( '</pre>', "\n", $f['evidence'] ) ), $lm ) ) {
			$lines = array_merge( $lines, array_map( 'intval', $lm[1] ) );
		}
		$lines = array_values( array_unique( $lines ) );
		sort( $lines );
		$map[ $f['check'] ][ str_replace( '\\', '/', $f['file'] ) ] = $lines;
	}
	ksort( $map );
	$map['__zero__'] = array( 'inc/safe.php' );
	$out             = dirname( __FILE__ ) . '/fixtures/expectations.json';
	file_put_contents( $out, json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	WP_CLI::line( 'Wrote ' . $out );
}

if ( $ok ) {
	WP_CLI::success( 'All expectations met.' );
	exit( 0 );
}
WP_CLI::error( 'Fixture expectations not met.' );
