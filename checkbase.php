<?php
/**
 * Runs checks against themes and displays the results
 *
 * Runs checks against themes and displays the results. Includes helper functions
 * for performing checks.
 *
 * @package Theme Check
 */

// main global to hold our checks.
global $themechecks;
$themechecks = array();

// counter for the checks.
global $checkcount;
$checkcount = 0;

// current WP_Theme being tested. Internal use only.
global $theme_check_current_theme;
$theme_check_current_theme = false;

// context of the current/last run ( 'theme' => WP_Theme, 'slug' => string ). Internal use only.
global $theme_check_current_context;
$theme_check_current_context = array();

// shared token helpers used by the security checks.
require_once __DIR__ . '/tc-tokens.php';

// interface that all checks should implement.
interface themecheck {

	// should return true for good/okay/acceptable, false for bad/not-okay/unacceptable.
	public function check( $php_files, $css_files, $other_files );

	// should return an array of strings explaining any problems found.
	public function getError();
}

// load all the checks in the checks directory.
foreach ( glob( __DIR__ . '/checks/*.php' ) as $file ) {
	include $file;
}

do_action( 'themecheck_checks_loaded' );

/**
 * Run Theme Check against a given theme.
 *
 * @param WP_Theme $theme      A WP_Theme instance.
 * @param string   $theme_slug The slug of the given theme.
 * @return bool
 */
function run_themechecks_against_theme( $theme, $theme_slug ) {
	$files = $theme->get_files(
		null /* all file types */,
		-1 /* infinite recursion */,
		true /* include parent theme files */
	);
	unset( $files[0] ); // Work around https://core.trac.wordpress.org/ticket/53599

	$php   = array();
	$css   = array();
	$other = array();

	foreach ( $files as $filename ) {
		if ( strpos( $filename, 'tgm-plugin-activation' ) === false && strpos( $filename, 'class-merlin' ) === false ) {
			if ( substr( $filename, -4 ) === '.php' ) {
				$php[ $filename ] = file_get_contents( $filename );
				$php[ $filename ] = tc_strip_comments( $php[ $filename ] );
			} elseif ( substr( $filename, -4 ) === '.css' ) {
				$css[ $filename ] = file_get_contents( $filename );
			} else {
				// In local development it might be useful to skip other files
				// (non .php or .css files) in dev directories.
				if ( apply_filters( 'tc_skip_development_directories', false ) ) {
					if ( tc_is_other_file_in_dev_directory( $filename ) ) {
						continue;
					}
				}
				$other[ $filename ] = file_get_contents( $filename );
			}
		}
	}

	// Run the checks.
	return run_themechecks(
		$php,
		$css,
		$other,
		array(
			'theme' => $theme,
			'slug'  => $theme_slug,
		)
	);
}

/**
 * Run the Theme Checks against a set of files.
 *
 * @param array $php     The PHP files.
 * @param array $css     The CSS files.
 * @param array $other   Any non-php/css files.
 * @param array $context Any context for the Theme Checks.
 *
 * @return bool
 */
function run_themechecks( $php, $css, $other, $context = array() ) {
	global $themechecks, $theme_check_current_theme, $theme_check_current_context;

	// Provide context to some functions that need to know the current theme, but aren't passed the object.
	$theme_check_current_theme   = isset( $context['theme'] ) ? $context['theme'] : false;
	$theme_check_current_context = (array) $context;

	$pass = true;

	tc_adapt_checks_for_fse_themes( $php, $css, $other );

	foreach ( $themechecks as $check ) {
		if ( $check instanceof themecheck ) {
			if ( $context && is_callable( array( $check, 'set_context' ) ) ) {
				$check->set_context( $context );
			}

			$pass = $pass & $check->check( $php, $css, $other );
		}
	}

	$theme_check_current_theme = false;

	return $pass;
}

/**
 * Build one structured finding (and its legacy HTML string).
 *
 * @param string $severity required|warning|recommended|info.
 * @param string $check_id Rule id, e.g. 'sql/concat'.
 * @param string $message  HTML fragment (only li/span/strong/code/pre/a[href] survive wp_kses).
 * @param string $file     Absolute path of the offending file ('' if n/a).
 * @param int    $line     Line number (0 if n/a).
 * @param string $evidence Pre-rendered <pre class='tc-grep'> block(s); built from $file/$line when empty.
 * @param string $docs_url Optional "Learn more" URL.
 * @param string $fix      Optional one-sentence, author-facing instruction ("what to change"), plain text.
 * @return array
 */
function tc_error( $severity, $check_id, $message, $file = '', $line = 0, $evidence = '', $docs_url = '', $fix = '' ) {
	$labels = array(
		'required'    => __( 'REQUIRED', 'theme-check' ),
		'warning'     => __( 'WARNING', 'theme-check' ),
		'recommended' => __( 'RECOMMENDED', 'theme-check' ),
		'info'        => __( 'INFO', 'theme-check' ),
	);
	$severity = strtolower( (string) $severity );
	if ( ! isset( $labels[ $severity ] ) ) {
		$severity = 'info';
	}
	if ( '' === $evidence && '' !== $file && $line > 0 && function_exists( 'tc_excerpt' ) ) {
		$evidence = tc_excerpt( $file, $line );
	}
	$html = '<span class="tc-lead tc-' . $severity . '">' . $labels[ $severity ] . '</span>: ' . $message;
	if ( '' !== $docs_url ) {
		$html .= ' <a href="' . esc_url( $docs_url ) . '">' . __( 'Learn more', 'theme-check' ) . '</a>';
	}
	if ( '' !== $evidence ) {
		$html .= ' ' . $evidence;
	}
	return array(
		'severity' => $severity,
		'check'    => (string) $check_id,
		'message'  => tc_finding_plain_text( $message ),
		'file'     => ( '' !== $file ) ? tc_filename( $file ) : '',
		'line'     => (int) $line,
		'evidence' => $evidence,
		'html'     => $html,
		'fix'      => tc_finding_plain_text( $fix ),
	);
}

/**
 * Shorter, author-facing version of a finding text: drops reviewer boilerplate
 * ("A manual review is needed.", "ThemeForest requirement: ...", "Learn more").
 */
function tc_finding_short_text( $text ) {
	$text = preg_replace( '/\s*ThemeForest requirement:.*$/su', '', (string) $text );
	$text = preg_replace( '/\s*A manual review is needed\.?/i', '', $text );
	$text = preg_replace( '/\s*Learn more\.?$/i', '', $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	return trim( $text );
}

/**
 * Plain-text version of an HTML finding fragment.
 */
function tc_finding_plain_text( $html ) {
	$text = preg_replace( '#<br\s*/?>|<pre[^>]*>|</pre>|<li[^>]*>|</li>#i', "\n", (string) $html );
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/[ \t]+/', ' ', $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );
	return trim( $text );
}

/**
 * Wrap a legacy HTML finding string into the structured shape.
 */
function tc_result_from_legacy_html( $html, $check_class ) {
	$html     = (string) $html;
	$severity = 'info';
	if ( preg_match( '#<span class="tc-lead[^"]*">\s*(REQUIRED|WARNING|RECOMMENDED|INFO)\s*</span>#i', $html, $m ) ) {
		$severity = strtolower( $m[1] );
	} elseif ( preg_match( '/tc-(required|warning|recommended|info)/', $html, $m ) ) {
		$severity = $m[1];
	}

	$evidence_html = '';
	$lines         = array();
	if ( preg_match_all( "#<pre class=['\"]tc-grep['\"]>(.*?)</pre>#s", $html, $pm, PREG_SET_ORDER ) ) {
		foreach ( $pm as $p ) {
			$evidence_html .= $p[0];
			$plain          = html_entity_decode( wp_strip_all_tags( $p[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( preg_match( '/^\s*Line (\d+):\s?(.*)$/s', $plain, $lm ) ) {
				$lines[] = array( (int) $lm[1], trim( $lm[2] ) );
			}
		}
	}

	$file = '';
	if ( preg_match_all( '#<strong>(.*?)</strong>#s', $html, $sm ) ) {
		foreach ( $sm[1] as $candidate ) {
			$candidate = trim( wp_strip_all_tags( $candidate ) );
			if ( preg_match( '#^[\w\-. /\\\\]+\.[a-z0-9]{2,5}$#i', $candidate ) ) {
				$file = $candidate;
				break;
			}
		}
	}

	$body = preg_replace( '#<span class="tc-lead[^"]*">.*?</span>:?\s*#is', '', $html, 1 );
	$body = preg_replace( "#<pre class=['\"]tc-grep['\"]>.*?</pre>#s", '', $body );

	return array(
		'severity' => $severity,
		'check'    => (string) $check_class,
		'message'  => tc_finding_plain_text( $body ),
		'file'     => $file,
		'line'     => ! empty( $lines ) ? $lines[0][0] : 0,
		'evidence' => $evidence_html,
		'html'     => $html,
	);
}

/**
 * Collect structured findings from all checks (structured when available, legacy HTML otherwise).
 *
 * @return array List of findings; see tc_error() for the shape.
 */
function tc_collect_results() {
	global $themechecks, $theme_check_current_context;
	$results = array();
	$seen    = array();
	foreach ( $themechecks as $check ) {
		if ( ! ( $check instanceof themecheck ) ) {
			continue;
		}
		if ( is_callable( array( $check, 'getStructuredErrors' ) ) ) {
			$items = (array) $check->getStructuredErrors();
		} else {
			$items = array();
			foreach ( (array) $check->getError() as $html ) {
				$items[] = tc_result_from_legacy_html( $html, get_class( $check ) );
			}
		}
		foreach ( $items as $item ) {
			if ( ! isset( $item['html'] ) || isset( $seen[ $item['html'] ] ) ) {
				continue;
			}
			$seen[ $item['html'] ] = true;
			$results[]             = $item;
		}
	}
	return apply_filters( 'themecheck_findings', $results, $theme_check_current_context );
}

/**
 * Findings ready for the results UI: structured results plus a stable id, label, plain text and parsed
 * evidence lines, ordered REQUIRED > WARNING > RECOMMENDED > INFO, with INFO suppressed when requested.
 *
 * @return array
 */
function tc_collect_findings() {
	$rank   = array( 'required' => 0, 'warning' => 1, 'recommended' => 2, 'info' => 3 );
	$labels = array(
		'required'    => __( 'REQUIRED', 'theme-check' ),
		'warning'     => __( 'WARNING', 'theme-check' ),
		'recommended' => __( 'RECOMMENDED', 'theme-check' ),
		'info'        => __( 'INFO', 'theme-check' ),
	);
	$out  = array();
	$seen = array();
	foreach ( tc_collect_results() as $r ) {
		$sev = isset( $rank[ $r['severity'] ] ) ? $r['severity'] : 'info';
		if ( isset( $_POST['s_info'] ) && 'info' === $sev ) {
			continue;
		}
		$lines = array();
		if ( ! empty( $r['evidence'] ) && preg_match_all( "#<pre class=['\"]tc-grep['\"]>(.*?)</pre>#s", $r['evidence'], $pm ) ) {
			foreach ( $pm[1] as $p ) {
				$plain = html_entity_decode( wp_strip_all_tags( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( preg_match( '/^\s*Line (\d+):\s?(.*)$/s', $plain, $lm ) ) {
					$lines[] = array( 'line' => (int) $lm[1], 'text' => trim( $lm[2] ) );
				}
			}
		}
		$text = isset( $r['message'] ) && '' !== $r['message'] ? $r['message'] : tc_finding_plain_text( $r['html'] );
		$key  = md5( $r['check'] . '|' . $sev . '|' . $text . '|' . $r['file'] );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		$r['severity'] = $sev;
		$r['label']    = $labels[ $sev ];
		$r['text']     = $text;
		$r['short']    = tc_finding_short_text( $text );
		$r['fix']      = isset( $r['fix'] ) ? (string) $r['fix'] : '';
		$r['lines']    = $lines;
		$r['id']       = 'tc-' . substr( md5( $r['check'] . '|' . $r['html'] ), 0, 10 );
		$out[]         = $r;
	}
	usort(
		$out,
		function ( $a, $b ) use ( $rank ) {
			$d = $rank[ $a['severity'] ] - $rank[ $b['severity'] ];
			if ( 0 !== $d ) {
				return $d;
			}
			$d = strcmp( $a['check'], $b['check'] );
			if ( 0 !== $d ) {
				return $d;
			}
			$d = strcmp( $a['file'], $b['file'] );
			if ( 0 !== $d ) {
				return $d;
			}
			$d = (int) $a['line'] - (int) $b['line'];
			return ( 0 !== $d ) ? $d : strcmp( $a['text'], $b['text'] );
		}
	);
	return $out;
}

/**
 * Legacy flat list renderer. Kept for backwards compatibility; the admin page now uses tc_render_results_page().
 */
function display_themechecks() {
	$results = '';
	$errors  = array_column( tc_collect_results(), 'html' );
	if ( ! empty( $errors ) ) {
		rsort( $errors );
		foreach ( $errors as $e ) {
			$results .= ( isset( $_POST['s_info'] ) && preg_match( '/INFO/', $e ) ) ? '' : '<li>' . $e . '</li>';
		}
	}
	return $results;
}

function checkcount() {
	global $checkcount;
	$checkcount++;
}

// some functions theme checks use.
function tc_grep( $error, $file ) {
	if ( ! file_exists( $file ) ) {
		return '';
	}
	$lines      = file( $file, FILE_IGNORE_NEW_LINES ); // Read the theme file into an array.
	$line_index = 0;
	$bad_lines  = '';
	foreach ( $lines as $this_line ) {
		if ( stristr( $this_line, $error ) ) {
			$error      = str_replace( '"', "'", $error );
			$this_line  = str_replace( '"', "'", $this_line );
			$error      = ltrim( $error );
			$pos        = strpos( $this_line, $error );
			$pre        = ( false !== $pos ? substr( $this_line, 0, $pos ) : false );
			$pre        = ltrim( htmlspecialchars( $pre ) );
			$bad_lines .= "<pre class='tc-grep'>" . __( 'Line ', 'theme-check' ) . ( $line_index + 1 ) . ': ' . $pre . htmlspecialchars( substr( stristr( $this_line, $error ), 0, 75 ) ) . '</pre>';
		}
		$line_index++;
	}
	return str_replace( $error, '<span class="tc-grep">' . $error . '</span>', $bad_lines );
}

function tc_preg( $preg, $file ) {
	if ( ! file_exists( $file ) ) {
		return '';
	}
	$lines      = file( $file, FILE_IGNORE_NEW_LINES ); // Read the theme file into an array.
	$line_index = 0;
	$bad_lines  = '';
	$error      = '';
	foreach ( $lines as $this_line ) {
		if ( preg_match( $preg, $this_line, $matches ) ) {
			$error     = $matches[0];
			$this_line = str_replace( '"', "'", $this_line );
			$error     = ltrim( $error );
			$pre       = '';
			if ( ! empty( $error ) ) {
				$pos = strpos( $this_line, $error );
				$pre = ( false !== $pos ? substr( $this_line, 0, $pos ) : false );
			}
			$pre        = ltrim( htmlspecialchars( $pre ) );
			$bad_lines .= "<pre class='tc-grep'>" . __( 'Line ', 'theme-check' ) . ( $line_index + 1 ) . ': ' . $pre . htmlspecialchars( substr( stristr( $this_line, $error ), 0, 75 ) ) . '</pre>';
		}
		$line_index++;

	}
	return str_replace( $error, '<span class="tc-grep">' . $error . '</span>', $bad_lines );
}

function tc_filename( $file ) {
	// If we know the WP_Theme object, we can get the exact path.
	$filename = _get_filename_from_current_theme( $file );
	if ( $filename ) {
		return $filename;
	}

	// If the $file exists within a theme-like folder, use that.
	// Does not support themes nested in directories such as wp-content/themes/pub/wporg-themes/index.php
	if ( preg_match( '!/themes/[^/]+/(.*)$!i', $file, $m ) ) {
		return $m[1];
	}

	// If still nothing, use the basename.
	return basename( $file );
}

/**
 * Get a filename relative to the current theme.
 *
 * @param string $file the file to get a relative filename for.
 * @return false|string The filename, or false on failure.
 * @access private
 */
function _get_filename_from_current_theme( $file ) {
	global $theme_check_current_theme;
	static $theme_files = array();
	static $theme_path  = '';

	if ( empty( $theme_check_current_theme ) ) {
		return false;
	}

	// Fetch the files for the theme, once per theme.
	if ( $theme_path != $theme_check_current_theme->get_stylesheet_directory() ) {
		$theme_path = $theme_check_current_theme->get_stylesheet_directory();

		$theme_files = $theme_check_current_theme->get_files(
			null /* all file types */,
			-1 /* infinite recursion */,
			true /* include parent theme files */
		);
	}

	return array_search( $file, $theme_files, true );
}

/**
 * Deprecated: the Trac output mode was removed in 2.2.0 (superseded by the plain-text author message).
 * Kept as a pass-through for one release in case anything external calls it.
 */
function tc_trac( $e ) {
	return $e;
}

// Strip comments from a PHP file in a way that will not change the underlying structure of the file.
function tc_strip_comments( $code ) {
	$strip    = array(
		T_COMMENT     => true,
		T_DOC_COMMENT => true,
	);
	$newlines = array(
		"\n" => true,
		"\r" => true,
	);
	$tokens   = token_get_all( $code );
	reset( $tokens );
	$return = '';
	$token  = current( $tokens );
	while ( $token ) {
		if ( ! is_array( $token ) ) {
			$return .= $token;
		} elseif ( ! isset( $strip[ $token[0] ] ) ) {
			$return .= $token[1];
		} else {
			for ( $i = 0, $token_length = strlen( $token[1] ); $i < $token_length; ++$i ) {
				if ( isset( $newlines[ $token[1][ $i ] ] ) ) {
					$return .= $token[1][ $i ];
				}
			}
		}
		$token = next( $tokens );
	}
	return $return;
}

/**
 * Used to allow some directories to be skipped during development.
 *
 * @param  string $filename a filename/path.
 * @return boolean
 */
function tc_is_other_file_in_dev_directory( $filename ) {
	$skip = false;
	// Filterable List of dirs that you may want to skip other files in during
	// development.
	$dev_dirs = apply_filters(
		'tc_common_dev_directories',
		array(
			'node_modules',
			'vendor',
		)
	);
	foreach ( $dev_dirs as $dev_dir ) {
		if ( strpos( $filename, $dev_dir ) ) {
			$skip = true;
			break;
		}
	}
	return $skip;
}

/**
 * Adapt the Theme Checks if the theme is an experiment Full-Site Editing theme.
 *
 * @param array $php_files   The theme's PHP files.
 * @param array $css_files   The theme's CSS files.
 * @param array $other_files Any other theme files.
 *
 * @return bool Whether the theme checks were adapted for FSE or not.
 */
function tc_adapt_checks_for_fse_themes( $php_files, $css_files, $other_files ) {
	global $themechecks;

	// Get a list of all non PHP and CSS file paths, relative to the theme root.
	$other_filenames = array();
	foreach ( $other_files as $path => $contents ) {
		$other_filenames[] = tc_filename( $path );
	}

	// Check whether this is a FSE theme by searching for an index.html block template.
	if ( ! in_array( 'block-templates/index.html', $other_filenames, true ) && ! in_array( 'templates/index.html', $other_filenames, true ) ) {
		return false;
	}

	// Remove theme checks that do not apply to FSE themes.
	foreach ( $themechecks as $key => $check ) {
		if ( $check instanceof Tag_Check
			|| $check instanceof Suggested_Styles_Check
			|| $check instanceof Widgets_Check
			|| $check instanceof Gravatar_Check
			|| $check instanceof Post_Pagination_Check
			|| $check instanceof Basic_Check
			|| $check instanceof Comments_Check
			|| $check instanceof Comment_Pagination_Check
			|| $check instanceof Comment_Reply_Check
			|| $check instanceof Nav_Menu_Check
			|| $check instanceof Post_Thumbnail_Check
			|| $check instanceof Theme_Support_Check
			|| $check instanceof Editor_Style_Check
			|| $check instanceof Underscores_Check
			|| $check instanceof Constants_Check
			|| $check instanceof Customizer_Check
			|| $check instanceof Post_Format_Check
			|| $check instanceof Search_Form_Check
			|| $check instanceof Theme_Support_Title_Tag_Check
			|| $check instanceof Screen_Reader_Text_Check
			|| $check instanceof Include_Check
		) {
			unset( $themechecks[ $key ] );
		}
	}

	// Add FSE specific checks.
	$themechecks[] = new FSE_Required_Files_Check();

	return true;
}
