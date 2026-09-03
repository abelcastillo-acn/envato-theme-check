<?php
/**
 * Run the security checks against a theme directory without WordPress.
 *
 * Usage:
 *   php bin/run-check.php <theme-dir> [--all] [--only=<relative-path-prefix>] [--expect-zero] [--json]
 *
 *   --all          run every check (legacy checks need more WordPress APIs; some may misbehave)
 *   --only=PATH    only report findings whose theme-relative file starts with PATH
 *   --expect-zero  exit 1 if any (filtered) finding is reported
 *   --json         print findings as JSON instead of the human report
 *
 * Without --expect-zero the script compares findings with the `// EXPECT:` / `// EXPECT-FILE:` markers
 * in the theme's PHP files (see tests/run-fixture.php) and exits 1 on any mismatch.
 *
 * @package Theme Check
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$opts = array( 'all' => false, 'only' => '', 'expect_zero' => false, 'json' => false );
$dir  = null;
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( '--all' === $a ) {
		$opts['all'] = true;
	} elseif ( '--expect-zero' === $a ) {
		$opts['expect_zero'] = true;
	} elseif ( '--json' === $a ) {
		$opts['json'] = true;
	} elseif ( 0 === strpos( $a, '--only=' ) ) {
		$opts['only'] = str_replace( '\\', '/', substr( $a, 7 ) );
	} elseif ( null === $dir ) {
		$dir = $a;
	}
}
if ( null === $dir || ! is_dir( $dir ) ) {
	fwrite( STDERR, "Usage: php bin/run-check.php <theme-dir> [--all] [--only=PATH] [--expect-zero] [--json]\n" );
	exit( 2 );
}
$dir = rtrim( str_replace( '\\', '/', realpath( $dir ) ), '/' );

require_once __DIR__ . '/wp-shims.php';
require_once dirname( __DIR__ ) . '/checkbase.php';

/**
 * Just enough of WP_Theme for tc_filename() / _get_filename_from_current_theme().
 */
class TC_Standalone_Theme {
	public $dir;
	public $files = array();

	public function __construct( $dir ) {
		$this->dir = $dir;
		$it        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$abs                 = str_replace( '\\', '/', $f->getPathname() );
			$rel                 = ltrim( substr( $abs, strlen( $dir ) ), '/' );
			$this->files[ $rel ] = $abs;
		}
		ksort( $this->files );
	}
	public function get_stylesheet_directory() {
		return $this->dir;
	}
	public function get_files( $type = null, $depth = 0, $search_parent = false ) {
		if ( null === $type ) {
			return $this->files;
		}
		$out = array();
		foreach ( $this->files as $rel => $abs ) {
			if ( substr( $abs, -1 - strlen( $type ) ) === '.' . $type ) {
				$out[ $rel ] = $abs;
			}
		}
		return $out;
	}
	public function get( $key ) {
		return '';
	}
	public function exists() {
		return true;
	}
}

$theme = new TC_Standalone_Theme( $dir );
$slug  = basename( $dir );

$php   = array();
$css   = array();
$other = array();
foreach ( $theme->files as $rel => $abs ) {
	if ( false !== strpos( $abs, 'tgm-plugin-activation' ) || false !== strpos( $abs, 'class-merlin' ) ) {
		continue;
	}
	if ( '.php' === substr( $abs, -4 ) ) {
		$php[ $abs ] = tc_strip_comments( file_get_contents( $abs ) );
	} elseif ( '.css' === substr( $abs, -4 ) ) {
		$css[ $abs ] = file_get_contents( $abs );
	} else {
		$other[ $abs ] = file_get_contents( $abs );
	}
}

if ( ! $opts['all'] ) {
	foreach ( $themechecks as $k => $c ) {
		if ( ! ( $c instanceof Nonce_Check || $c instanceof SQL_Prepare_Check || $c instanceof Superglobals_Sanitization_Check ) ) {
			unset( $themechecks[ $k ] );
		}
	}
}

$start = microtime( true );
run_themechecks( $php, $css, $other, array( 'theme' => $theme, 'slug' => $slug ) );
$GLOBALS['theme_check_current_theme'] = $theme; // keep tc_filename() theme-relative after the run.
$findings = tc_collect_results();
$elapsed  = microtime( true ) - $start;

$findings = array_values( array_filter( $findings, function ( $f ) use ( $opts ) {
	if ( ! $opts['all'] && ! preg_match( '#^(nonce|sql|superglobals)/#', $f['check'] ) ) {
		return false;
	}
	$file = str_replace( '\\', '/', $f['file'] );
	return '' === $opts['only'] || 0 === strpos( $file, $opts['only'] );
} ) );

if ( $opts['json'] ) {
	echo json_encode( array_map( function ( $f ) {
		unset( $f['html'], $f['evidence'] );
		return $f;
	}, $findings ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
}

printf( "Theme: %s  |  findings: %d  |  %.2fs  |  peak %.1f MB\n", $slug, count( $findings ), $elapsed, memory_get_peak_usage( true ) / 1048576 );
$by_rule = array();
foreach ( $findings as $f ) {
	$by_rule[ $f['check'] ] = isset( $by_rule[ $f['check'] ] ) ? $by_rule[ $f['check'] ] + 1 : 1;
}
ksort( $by_rule );
foreach ( $by_rule as $rule => $n ) {
	printf( "  %-32s %d\n", $rule, $n );
}

if ( $opts['expect_zero'] ) {
	if ( $findings ) {
		foreach ( $findings as $f ) {
			printf( "  - %s %s:%d\n", $f['check'], $f['file'], $f['line'] );
		}
		fwrite( STDERR, "FAIL: expected zero findings.\n" );
		exit( 1 );
	}
	echo "OK: zero findings.\n";
	exit( 0 );
}

// EXPECT markers.
$expected = array();
foreach ( $theme->get_files( 'php', -1 ) as $rel => $abs ) {
	if ( '' !== $opts['only'] && 0 !== strpos( $rel, $opts['only'] ) ) {
		continue;
	}
	foreach ( file( $abs, FILE_IGNORE_NEW_LINES ) as $idx => $line ) {
		if ( preg_match( '#//\s*EXPECT(-FILE)?:\s*([a-z0-9/_\-]+(?:\s*,\s*[a-z0-9/_\-]+)*)#i', $line, $m ) ) {
			foreach ( preg_split( '/\s*,\s*/', trim( $m[2] ) ) as $rule ) {
				$expected[] = array( $rel, $m[1] ? 0 : $idx + 1, $rule );
			}
		}
	}
}
$has_line = function ( $f, $line ) {
	return false !== strpos( wp_strip_all_tags( str_replace( '</pre>', "\n", $f['evidence'] ) ), 'Line ' . $line . ':' );
};
$matched = array();
$missing = array();
foreach ( $expected as $e ) {
	list( $file, $line, $rule ) = $e;
	$hit = false;
	foreach ( $findings as $k => $f ) {
		if ( $f['check'] !== $rule || str_replace( '\\', '/', $f['file'] ) !== $file ) {
			continue;
		}
		if ( 0 === $line || (int) $f['line'] === $line || $has_line( $f, $line ) ) {
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
foreach ( $findings as $k => $f ) {
	if ( ! isset( $matched[ $k ] ) ) {
		$unexpected[] = sprintf( '%-28s %s:%d', $f['check'], $f['file'], $f['line'] );
	}
}
$safe_hits = array();
foreach ( $findings as $f ) {
	if ( preg_match( '#(^|/)safe\.php$#', str_replace( '\\', '/', $f['file'] ) ) ) {
		$safe_hits[] = $f['check'] . ' ' . $f['file'] . ':' . $f['line'];
	}
}

$ok = true;
printf( "Expectations: %d\n", count( $expected ) );
if ( $missing ) {
	$ok = false;
	echo "Missing findings:\n  - " . implode( "\n  - ", $missing ) . "\n";
}
if ( $unexpected ) {
	$ok = false;
	echo "Unexpected findings:\n  - " . implode( "\n  - ", $unexpected ) . "\n";
}
if ( $safe_hits ) {
	$ok = false;
	echo "Findings in safe.php (must be zero):\n  - " . implode( "\n  - ", $safe_hits ) . "\n";
}
echo $ok ? "OK: all expectations met.\n" : "FAIL: expectations not met.\n";
exit( $ok ? 0 : 1 );
