<?php
/**
 * Checks that request superglobals are sanitized or validated where they are read.
 *
 * ThemeForest requirement (WordPress Theme Requirements Part 5 - Theme Security):
 * "data must be validated on input ... data that cannot be validated must be sanitized".
 *
 * @package Theme Check
 */

/**
 * Superglobal sanitization check.
 */
class Superglobals_Sanitization_Check implements themecheck {

	const RULES = array(
		'superglobals/unsanitized'         => 'warning',
		'superglobals/whole-array'         => 'warning',
		'superglobals/extract'             => 'required',
		'superglobals/shortcode-injection' => 'required',
	);

	/**
	 * Short, author-facing "what to change" per rule (used by the plain-text message).
	 */
	const FIXES = array(
		'superglobals/unsanitized' => 'Sanitize or validate each request value where it is read, e.g. sanitize_text_field( wp_unslash( $_POST[\'field\'] ) ), absint( $_GET[\'id\'] ) or an in_array() allow-list; wp_unslash()/trim() alone are not enough.',
		'superglobals/whole-array' => 'Do not use the whole $_POST/$_GET array; read the specific keys you need and sanitize each one.',
		'superglobals/extract'     => 'Remove extract() on request data; read and sanitize each key explicitly.',
		'superglobals/shortcode-injection' => 'Never build a shortcode string from request data: cast the value first (e.g. absint()) or pass it as a validated attribute; unsanitized input lets visitors close the attribute and run any shortcode.',
	);

	const DOCS   = 'https://developer.wordpress.org/apis/security/sanitizing/';
	const ENVATO = 'https://help.author.envato.com/hc/en-us/articles/360000481243-WordPress-Theme-Requirements-Part-5-Theme-Security';
	const MAX_EXCERPTS = 8;

	protected $results = array();
	protected $failed  = false;

	protected $superglobals     = array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$_SERVER' );
	protected $safe_server_keys = array( 'REQUEST_METHOD', 'HTTPS', 'SERVER_PORT', 'SERVER_PROTOCOL', 'DOCUMENT_ROOT', 'SCRIPT_FILENAME', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT', 'REMOTE_ADDR', 'SERVER_ADDR', 'SERVER_NAME', 'SERVER_SOFTWARE', 'GATEWAY_INTERFACE' );
	protected $safe_casts       = array( '(int)', '(integer)', '(bool)', '(boolean)', '(float)', '(double)', '(real)' );
	protected $guards           = array( 'isset', 'empty', 'unset', 'array_key_exists', 'in_array', 'is_array', 'is_numeric', 'is_scalar', 'is_string', 'is_int', 'is_integer', 'is_bool', 'is_email', 'is_object', 'is_null', 'switch', 'count', 'sizeof', 'is_user_logged_in', 'wp_is_numeric_array' );
	protected $passthrough      = array( 'wp_unslash', 'stripslashes', 'stripslashes_deep', 'urldecode', 'rawurldecode', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'json_decode', 'explode', 'array_keys', 'array_values', 'array', 'index', 'interp', '(', 'maybe_unserialize' );
	protected $sanitizers       = array();

	public function __construct() {
		$this->sanitizers = apply_filters(
			'tc_sanitizing_functions',
			array(
				'absint', 'intval', 'floatval', 'boolval', 'doubleval',
				'wp_kses', 'wp_kses_post', 'wp_kses_data', 'wp_filter_kses', 'wp_filter_nohtml_kses', 'wp_filter_post_kses', 'wp_strip_all_tags',
				'wp_parse_id_list', 'wp_parse_list', 'wp_parse_slug_list', 'wp_validate_boolean', 'rest_sanitize_value_from_schema', 'rest_sanitize_boolean',
				'filter_var', 'filter_var_array', 'filter_input',
				'esc_url_raw', 'sanitize_url', 'esc_sql', 'esc_html', 'esc_attr', 'esc_textarea', 'esc_js', 'esc_url', 'esc_html__', 'esc_attr__',
				'wp_verify_nonce', 'check_ajax_referer', 'check_admin_referer', 'wp_check_filetype', 'wp_check_filetype_and_ext',
				'wp_handle_upload', 'wp_handle_sideload', 'media_handle_upload',
				'wc_clean', 'wc_stock_amount', 'wc_format_decimal', 'wc_string_to_bool',
				'number_format', 'round', 'ceil', 'floor', 'min', 'max', 'md5', 'sha1', 'hash', 'wp_hash', 'crc32', 'date',
			)
		);
	}

	public function check( $php_files, $css_files, $other_files ) {
		checkcount();
		$this->results = array();
		$this->failed  = false;

		if ( ! function_exists( 'token_get_all' ) || ! function_exists( 'tc_tokens_for_file' ) ) {
			return true;
		}

		$prefilter = '/\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER)\b/';

		foreach ( $php_files as $path => $content ) {
			if ( ! preg_match( $prefilter, $content ) ) {
				continue;
			}
			$tokens = tc_tokens_for_file( $path, $content );
			if ( empty( $tokens ) ) {
				continue;
			}

			$this->check_shortcode_injection( $tokens, $path );

			$unsanitized = array(); // line => superglobal name
			$whole       = array();
			$n           = count( $tokens );

			for ( $i = 0; $i < $n; $i++ ) {
				$t = $tokens[ $i ];
				if ( T_VARIABLE !== $t[0] || ! in_array( $t[1], $this->superglobals, true ) ) {
					continue;
				}

				// Extent of the [...] chain.
				$e       = $i;
				$indexed = false;
				$key     = null;
				$nx      = tc_next_sig( $tokens, $e );
				while ( $nx >= 0 && null === $tokens[ $nx ][0] && '[' === $tokens[ $nx ][1] ) {
					$close = tc_match( $tokens, $nx );
					if ( $close < 0 ) {
						break;
					}
					if ( null === $key ) {
						$ks = tc_next_sig( $tokens, $nx );
						if ( $ks >= 0 && T_CONSTANT_ENCAPSED_STRING === $tokens[ $ks ][0] ) {
							$key = trim( $tokens[ $ks ][1], '\'"' );
						}
					}
					$indexed = true;
					$e       = $close;
					$nx      = tc_next_sig( $tokens, $e );
				}

				if ( '$_SERVER' === $t[1] ) {
					if ( ! $indexed || ( null !== $key && in_array( strtoupper( $key ), $this->safe_server_keys, true ) ) ) {
						continue;
					}
				}

				$prev = tc_prev_sig( $tokens, $i );
				$pt   = ( $prev >= 0 ) ? $tokens[ $prev ] : array( null, '', 0, 0 );
				$nt   = ( $nx >= 0 ) ? $tokens[ $nx ] : array( null, '', 0, 0 );

				// Writes.
				if ( ( null === $nt[0] && '=' === $nt[1] ) || in_array( $nt[0], array( T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_INC, T_DEC ), true ) || in_array( $pt[0], array( T_INC, T_DEC, T_UNSET ), true ) ) {
					continue;
				}
				// Comparisons / boolean uses.
				$cmp = array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP );
				if ( in_array( $nt[0], $cmp, true ) || in_array( $pt[0], $cmp, true )
					|| ( null === $nt[0] && in_array( $nt[1], array( '<', '>', '?' ), true ) )
					|| ( null === $pt[0] && in_array( $pt[1], array( '<', '>', '!' ), true ) )
					|| in_array( $nt[0], array( T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR ), true )
					|| in_array( $pt[0], array( T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR ), true ) ) {
					continue;
				}

				$chain = tc_call_chain( $tokens, $i, 6 );

				if ( in_array( 'extract', $chain, true ) && '$_FILES' !== $t[1] && '$_SERVER' !== $t[1] ) {
					$this->add(
						'superglobals/extract',
						$path,
						$t[2],
						sprintf(
							/* translators: 1: superglobal, 2: file */
							__( 'Found <code>extract( %1$s )</code> in %2$s. Extracting request data into local variables lets an attacker overwrite any variable in scope. Read each value explicitly and sanitize it (e.g. <code>sanitize_text_field( wp_unslash( %1$s[\'field\'] ) )</code>). %3$s', 'theme-check' ),
							esc_html( $t[1] ),
							'<strong>' . esc_html( tc_filename( $path ) ) . '</strong>',
							$this->envato_note( 'data that cannot be validated must be sanitized' )
						),
						false
					);
					continue;
				}

				// Direct condition of if/elseif/while.
				if ( ! empty( $chain ) && in_array( $chain[0], array( 'if', 'elseif', 'while' ), true ) && ( ( null === $nt[0] && ')' === $nt[1] ) || ( null === $pt[0] && '(' === $pt[1] ) ) ) {
					continue;
				}

				if ( $this->chain_is_safe( $tokens, $i, $chain ) ) {
					continue;
				}

				if ( $indexed ) {
					$unsanitized[ $t[2] ] = $t[1];
				} else {
					$whole[ $t[2] ] = $t[1];
				}
			}

			if ( ! empty( $unsanitized ) ) {
				$this->add_aggregate( 'superglobals/unsanitized', $path, $unsanitized );
			}
			if ( ! empty( $whole ) ) {
				$this->add_aggregate( 'superglobals/whole-array', $path, $whole );
			}
		}

		return ! $this->failed;
	}

	/**
	 * do_shortcode( ... ) whose argument contains request data (directly or through a variable assigned
	 * earlier in the same scope) without sanitization: visitors can close the attribute and run any shortcode.
	 */
	protected function check_shortcode_injection( $tokens, $path ) {
		$calls = tc_find_calls( $tokens, array( 'do_shortcode' ) );
		if ( empty( $calls ) ) {
			return;
		}
		$scopes = tc_token_scopes( $tokens );
		foreach ( $calls as $call ) {
			if ( empty( $call['args'] ) ) {
				continue;
			}
			list( $from, $to ) = $call['args'][0];
			$scope             = tc_scope_at( $scopes, $call['index'] );
			$source            = $this->tainted_source( $tokens, $from, $to, $scope, 0 );
			if ( '' === $source ) {
				continue;
			}
			$this->add(
				'superglobals/shortcode-injection',
				$path,
				$call['line'],
				sprintf(
					/* translators: 1: file, 2: source variable */
					__( 'Found <code>do_shortcode()</code> in %1$s built from request data (%2$s) without sanitization. A visitor can close the attribute with <code>"]</code> and execute any registered shortcode with attributes of their choice. Cast or validate the value before using it (e.g. <code>absint( wp_unslash( $_GET[\'id\'] ) )</code>). %3$s', 'theme-check' ),
					'<strong>' . esc_html( tc_filename( $path ) ) . '</strong>',
					'<code>' . esc_html( $source ) . '</code>',
					$this->envato_note( 'data that cannot be validated must be sanitized' )
				),
				false
			);
		}
	}

	/**
	 * Name of the request superglobal (or "$var (from $_GET)") feeding the token range, or '' when clean.
	 */
	protected function tainted_source( $tokens, $from, $to, $scope, $depth ) {
		for ( $i = $from; $i <= $to; $i++ ) {
			$t = $tokens[ $i ];
			if ( T_VARIABLE !== $t[0] ) {
				continue;
			}
			if ( in_array( $t[1], $this->superglobals, true ) ) {
				if ( '$_SERVER' === $t[1] ) {
					$nx = tc_next_sig( $tokens, $i );
					$ks = ( $nx >= 0 && null === $tokens[ $nx ][0] && '[' === $tokens[ $nx ][1] ) ? tc_next_sig( $tokens, $nx ) : -1;
					if ( $ks >= 0 && T_CONSTANT_ENCAPSED_STRING === $tokens[ $ks ][0] && in_array( strtoupper( trim( $tokens[ $ks ][1], '\'"' ) ), $this->safe_server_keys, true ) ) {
						continue;
					}
				}
				$chain = tc_call_chain( $tokens, $i, 6 );
				if ( $this->chain_is_safe( $tokens, $i, $chain ) ) {
					continue;
				}
				return $t[1];
			}
			if ( $depth < 2 && '$this' !== $t[1] ) {
				$assign = tc_last_assignment( $tokens, $t[1], $scope['start'], $from );
				if ( null !== $assign ) {
					$src = $this->tainted_source( $tokens, $assign['from'], $assign['to'], $scope, $depth + 1 );
					if ( '' !== $src ) {
						return $t[1] . ' ← ' . $src;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Whether any enclosing call sanitizes/validates/guards the value.
	 */
	protected function chain_is_safe( $tokens, $i, $chain ) {
		foreach ( $chain as $c ) {
			$c = ltrim( $c, '\\' );
			if ( in_array( $c, $this->safe_casts, true ) || in_array( $c, $this->guards, true ) || in_array( $c, $this->sanitizers, true ) ) {
				return true;
			}
			if ( preg_match( '/^(sanitize|sanitise|validate|clean|filter|wc_clean|wc_sanitize|is_)/i', $c ) || preg_match( '/^\$[a-z_]+$/i', $c ) && false ) {
				return true;
			}
			if ( '$this' === $c || preg_match( '/^\$/', $c ) ) {
				continue; // variable function/method call: unknown, keep walking.
			}
			if ( in_array( $c, array( 'array_map', 'array_filter', 'array_walk' ), true ) ) {
				if ( $this->array_map_callback_is_safe( $tokens, $i ) ) {
					return true;
				}
			}
			// Unknown or pass-through: keep walking outward.
		}
		return false;
	}

	/**
	 * array_map( 'sanitize_text_field', $_POST['x'] ): find the nearest preceding array_map( and read its first literal argument.
	 */
	protected function array_map_callback_is_safe( $tokens, $i ) {
		for ( $j = $i - 1; $j >= 0 && $j > $i - 60; $j-- ) {
			$t = $tokens[ $j ];
			if ( T_STRING === $t[0] && in_array( strtolower( $t[1] ), array( 'array_map', 'array_filter', 'array_walk' ), true ) ) {
				$open = tc_next_sig( $tokens, $j );
				$arg  = tc_next_sig( $tokens, $open );
				if ( $arg >= 0 && T_CONSTANT_ENCAPSED_STRING === $tokens[ $arg ][0] ) {
					$fn = strtolower( trim( $tokens[ $arg ][1], '\'"\\' ) );
					return in_array( $fn, $this->sanitizers, true ) || in_array( $fn, $this->guards, true ) || preg_match( '/^(sanitize|sanitise|validate|clean|filter|wc_clean|wc_sanitize|is_)/', $fn );
				}
				return false;
			}
			if ( null === $t[0] && ';' === $t[1] ) {
				return false;
			}
		}
		return false;
	}

	protected function add_aggregate( $rule, $path, $hits ) {
		ksort( $hits );
		$count    = count( $hits );
		$names    = array_values( array_unique( $hits ) );
		$evidence = '';
		$shown    = 0;
		foreach ( $hits as $line => $name ) {
			if ( $shown >= self::MAX_EXCERPTS ) {
				break;
			}
			$evidence .= tc_excerpt( $path, $line, $name );
			$shown++;
		}
		$more = ( $count > $shown ) ? ' ' . sprintf(
			/* translators: %d: number of additional occurrences */
			_n( '... and %d more.', '... and %d more.', $count - $shown, 'theme-check' ),
			$count - $shown
		) : '';

		$file  = '<strong>' . esc_html( tc_filename( $path ) ) . '</strong>';
		$globs = '<code>' . implode( '</code>/<code>', array_map( 'esc_html', $names ) ) . '</code>';

		if ( 'superglobals/whole-array' === $rule ) {
			$message = sprintf(
				/* translators: 1: count, 2: superglobals, 3: file, 4: more */
				_n(
					'Found %1$d use of the whole %2$s array in %3$s (e.g. <code>wp_parse_args( $_POST, ... )</code>, <code>foreach ( $_GET ... )</code>). Read the specific keys you need and sanitize each one. A manual review is needed.%4$s %5$s',
					'Found %1$d uses of the whole %2$s array in %3$s (e.g. <code>wp_parse_args( $_POST, ... )</code>, <code>foreach ( $_GET ... )</code>). Read the specific keys you need and sanitize each one. A manual review is needed.%4$s %5$s',
					$count,
					'theme-check'
				),
				$count,
				$globs,
				$file,
				$more,
				$this->envato_note( 'data that cannot be validated must be sanitized' )
			);
		} else {
			$message = sprintf(
				/* translators: 1: count, 2: superglobals, 3: file, 4: more */
				_n(
					'Found %1$d read of %2$s in %3$s that is not passed through a sanitization or validation function. Sanitize at the point of read, e.g. <code>sanitize_text_field( wp_unslash( $_POST[\'name\'] ) )</code>, <code>absint( $_GET[\'id\'] )</code>, or validate against an allow-list with <code>in_array()</code>. <code>wp_unslash()</code>/<code>trim()</code> alone are not sanitization. A manual review is needed.%4$s %5$s',
					'Found %1$d reads of %2$s in %3$s that are not passed through a sanitization or validation function. Sanitize at the point of read, e.g. <code>sanitize_text_field( wp_unslash( $_POST[\'name\'] ) )</code>, <code>absint( $_GET[\'id\'] )</code>, or validate against an allow-list with <code>in_array()</code>. <code>wp_unslash()</code>/<code>trim()</code> alone are not sanitization. A manual review is needed.%4$s %5$s',
					$count,
					'theme-check'
				),
				$count,
				$globs,
				$file,
				$more,
				$this->envato_note( 'data that cannot be validated must be sanitized' )
			);
		}

		$first_line = key( $hits );
		$severity   = tc_rule_severity( $rule, self::RULES[ $rule ], $path, true );
		$this->results[] = tc_error( $severity, $rule, $message, $path, $first_line, $evidence, self::DOCS, self::FIXES[ $rule ] );
		if ( 'required' === $severity ) {
			$this->failed = true;
		}
	}

	protected function envato_note( $quote ) {
		return sprintf(
			'<a href="%1$s">%2$s</a>: %3$s.',
			esc_url( self::ENVATO ),
			__( 'ThemeForest requirement', 'theme-check' ),
			esc_html( $quote )
		);
	}

	protected function add( $rule, $path, $line, $message, $vendor_downgrade = true ) {
		$severity        = tc_rule_severity( $rule, self::RULES[ $rule ], $path, $vendor_downgrade );
		$this->results[] = tc_error( $severity, $rule, $message, $path, $line, '', self::DOCS, self::FIXES[ $rule ] );
		if ( 'required' === $severity ) {
			$this->failed = true;
		}
	}

	public function getError() {
		return array_column( $this->results, 'html' );
	}

	public function getStructuredErrors() {
		return $this->results;
	}
}

$themechecks[] = new Superglobals_Sanitization_Check();
