<?php
/**
 * Token helpers shared by the security checks.
 *
 * All helpers work on a normalised token list produced by tc_tokens():
 * each token is array( 0 => int|null $id, 1 => string $text, 2 => int $line, 3 => int $offset ).
 * Single-character tokens have $id === null.
 *
 * Kept separate from checkbase.php so that file stays diffable against upstream Theme Check.
 *
 * @package Theme Check
 */

if ( ! function_exists( 'tc_tokens' ) ) {
	/**
	 * Normalise token_get_all() output into 4-tuples with line numbers for every token.
	 *
	 * @param string $code PHP source.
	 * @return array
	 */
	function tc_tokens( $code ) {
		if ( ! function_exists( 'token_get_all' ) ) {
			return array();
		}
		$raw    = @token_get_all( $code );
		$out    = array();
		$line   = 1;
		$offset = 0;
		foreach ( $raw as $t ) {
			if ( is_array( $t ) ) {
				$text  = $t[1];
				$out[] = array( $t[0], $text, $t[2], $offset );
				$line  = $t[2] + substr_count( $text, "\n" );
			} else {
				$text  = $t;
				$out[] = array( null, $text, $line, $offset );
			}
			$offset += strlen( $text );
		}
		return $out;
	}
}

if ( ! function_exists( 'tc_tokens_for_file' ) ) {
	/**
	 * Tokens for a file read from disk with comments stripped (line numbers preserved).
	 * Single-slot cache: the three security checks run sequentially over the same file list.
	 *
	 * @param string $path             Absolute path.
	 * @param string $fallback_content Content to use when the file cannot be read.
	 * @return array
	 */
	function tc_tokens_for_file( $path, $fallback_content = '' ) {
		static $cache_path   = null;
		static $cache_tokens = array();

		if ( $cache_path === $path ) {
			return $cache_tokens;
		}
		$code = ( is_string( $path ) && file_exists( $path ) ) ? file_get_contents( $path ) : $fallback_content;
		if ( function_exists( 'tc_strip_comments' ) && function_exists( 'token_get_all' ) ) {
			$code = tc_strip_comments( $code );
		}
		$cache_path   = $path;
		$cache_tokens = tc_tokens( $code );
		return $cache_tokens;
	}
}

if ( ! function_exists( 'tc_tok_is_ws' ) ) {
	/**
	 * Whether a token is whitespace (comments are already stripped to newlines).
	 *
	 * @param array $tok Token.
	 * @return bool
	 */
	function tc_tok_is_ws( $tok ) {
		return T_WHITESPACE === $tok[0];
	}
}

if ( ! function_exists( 'tc_next_sig' ) ) {
	/**
	 * Index of the next non-whitespace token after $i, or -1.
	 */
	function tc_next_sig( $tokens, $i ) {
		$n = count( $tokens );
		for ( $j = $i + 1; $j < $n; $j++ ) {
			if ( ! tc_tok_is_ws( $tokens[ $j ] ) ) {
				return $j;
			}
		}
		return -1;
	}
}

if ( ! function_exists( 'tc_prev_sig' ) ) {
	/**
	 * Index of the previous non-whitespace token before $i, or -1.
	 */
	function tc_prev_sig( $tokens, $i ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! tc_tok_is_ws( $tokens[ $j ] ) ) {
				return $j;
			}
		}
		return -1;
	}
}

if ( ! function_exists( 'tc_tok_is_opener' ) ) {
	function tc_tok_is_opener( $tok ) {
		if ( null === $tok[0] ) {
			return '(' === $tok[1] || '[' === $tok[1] || '{' === $tok[1];
		}
		return T_CURLY_OPEN === $tok[0] || T_DOLLAR_OPEN_CURLY_BRACES === $tok[0];
	}
}

if ( ! function_exists( 'tc_tok_is_closer' ) ) {
	function tc_tok_is_closer( $tok ) {
		return null === $tok[0] && ( ')' === $tok[1] || ']' === $tok[1] || '}' === $tok[1] );
	}
}

if ( ! function_exists( 'tc_match' ) ) {
	/**
	 * Index of the matching closer for the opener at $i, or -1.
	 */
	function tc_match( $tokens, $i ) {
		$n     = count( $tokens );
		$depth = 0;
		for ( $j = $i; $j < $n; $j++ ) {
			if ( tc_tok_is_opener( $tokens[ $j ] ) ) {
				$depth++;
			} elseif ( tc_tok_is_closer( $tokens[ $j ] ) ) {
				$depth--;
				if ( 0 === $depth ) {
					return $j;
				}
			}
		}
		return -1;
	}
}

if ( ! function_exists( 'tc_match_back' ) ) {
	/**
	 * Index of the matching opener for the closer at $i, or -1.
	 */
	function tc_match_back( $tokens, $i ) {
		$depth = 0;
		for ( $j = $i; $j >= 0; $j-- ) {
			if ( tc_tok_is_closer( $tokens[ $j ] ) ) {
				$depth++;
			} elseif ( tc_tok_is_opener( $tokens[ $j ] ) ) {
				$depth--;
				if ( 0 === $depth ) {
					return $j;
				}
			}
		}
		return -1;
	}
}

if ( ! function_exists( 'tc_token_scopes' ) ) {
	/**
	 * Function/method/closure scopes in a token list. Element 0 is always the file scope.
	 *
	 * Each scope: array( 'type' => file|function|method|closure, 'name' => string, 'class' => string|null,
	 *                    'start' => int, 'open' => int, 'end' => int, 'line' => int ).
	 */
	function tc_token_scopes( $tokens ) {
		$n      = count( $tokens );
		$scopes = array(
			array(
				'type'  => 'file',
				'name'  => '',
				'class' => null,
				'start' => 0,
				'open'  => 0,
				'end'   => max( 0, $n - 1 ),
				'line'  => 1,
			),
		);
		$classes = array(); // list of array( name, start, end ).

		for ( $i = 0; $i < $n; $i++ ) {
			$id = $tokens[ $i ][0];

			if ( T_CLASS === $id || T_TRAIT === $id || T_INTERFACE === $id ) {
				$prev = tc_prev_sig( $tokens, $i );
				if ( $prev >= 0 && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
					continue; // Foo::class.
				}
				$nm = tc_next_sig( $tokens, $i );
				if ( $nm < 0 || T_STRING !== $tokens[ $nm ][0] ) {
					continue; // anonymous class: ignore name.
				}
				$brace = $nm;
				while ( $brace < $n && ! ( null === $tokens[ $brace ][0] && '{' === $tokens[ $brace ][1] ) ) {
					$brace++;
				}
				if ( $brace >= $n ) {
					continue;
				}
				$end       = tc_match( $tokens, $brace );
				$classes[] = array( $tokens[ $nm ][1], $brace, $end < 0 ? $n - 1 : $end );
				continue;
			}

			if ( T_FUNCTION === $id || ( defined( 'T_FN' ) && T_FN === $id ) ) {
				$is_fn = defined( 'T_FN' ) && T_FN === $id;
				$j     = tc_next_sig( $tokens, $i );
				if ( $j < 0 ) {
					continue;
				}
				if ( null === $tokens[ $j ][0] && '&' === $tokens[ $j ][1] ) {
					$j = tc_next_sig( $tokens, $j );
				}
				$name = '{closure}';
				$type = 'closure';
				if ( $j >= 0 && T_STRING === $tokens[ $j ][0] ) {
					$name = $tokens[ $j ][1];
					$type = 'function';
					$j    = tc_next_sig( $tokens, $j );
				}
				if ( $j < 0 || ! ( null === $tokens[ $j ][0] && '(' === $tokens[ $j ][1] ) ) {
					continue;
				}
				$params_end = tc_match( $tokens, $j );
				if ( $params_end < 0 ) {
					continue;
				}
				$class = null;
				foreach ( $classes as $c ) {
					if ( $i > $c[1] && $i < $c[2] ) {
						$class = $c[0];
					}
				}
				if ( 'function' === $type && null !== $class ) {
					$type = 'method';
				}

				if ( $is_fn ) {
					// Arrow function: body runs from => to the next , ) ; at depth 0.
					$arrow = tc_next_sig( $tokens, $params_end );
					while ( $arrow >= 0 && T_DOUBLE_ARROW !== $tokens[ $arrow ][0] ) {
						$arrow = tc_next_sig( $tokens, $arrow );
					}
					if ( $arrow < 0 ) {
						continue;
					}
					$depth = 0;
					$end   = $n - 1;
					for ( $k = $arrow + 1; $k < $n; $k++ ) {
						$t = $tokens[ $k ];
						if ( tc_tok_is_opener( $t ) ) {
							$depth++;
						} elseif ( tc_tok_is_closer( $t ) ) {
							if ( 0 === $depth ) {
								$end = $k - 1;
								break;
							}
							$depth--;
						} elseif ( 0 === $depth && null === $t[0] && ( ',' === $t[1] || ';' === $t[1] ) ) {
							$end = $k - 1;
							break;
						}
					}
					$scopes[] = array(
						'type'  => 'closure',
						'name'  => '{closure}',
						'class' => $class,
						'start' => $i,
						'open'  => $arrow,
						'end'   => $end,
						'line'  => $tokens[ $i ][2],
					);
					continue;
				}

				// Find body brace (skip "use (...)" and ": type").
				$k = tc_next_sig( $tokens, $params_end );
				while ( $k >= 0 ) {
					$t = $tokens[ $k ];
					if ( null === $t[0] && '{' === $t[1] ) {
						break;
					}
					if ( null === $t[0] && ';' === $t[1] ) {
						$k = -1; // abstract / interface method.
						break;
					}
					if ( null === $t[0] && '(' === $t[1] ) {
						$k = tc_match( $tokens, $k );
						if ( $k < 0 ) {
							break;
						}
					}
					$k = tc_next_sig( $tokens, $k );
				}
				if ( $k < 0 ) {
					continue;
				}
				$end = tc_match( $tokens, $k );
				if ( $end < 0 ) {
					$end = $n - 1;
				}
				$scopes[] = array(
					'type'  => $type,
					'name'  => $name,
					'class' => $class,
					'start' => $i,
					'open'  => $k,
					'end'   => $end,
					'line'  => $tokens[ $i ][2],
				);
			}
		}
		return $scopes;
	}
}

if ( ! function_exists( 'tc_scope_at' ) ) {
	/**
	 * Innermost non-file scope containing token $i, else the file scope.
	 */
	function tc_scope_at( $scopes, $i ) {
		$best = $scopes[0];
		$size = PHP_INT_MAX;
		foreach ( $scopes as $s ) {
			if ( 'file' === $s['type'] ) {
				continue;
			}
			if ( $i >= $s['start'] && $i <= $s['end'] && ( $s['end'] - $s['start'] ) < $size ) {
				$best = $s;
				$size = $s['end'] - $s['start'];
			}
		}
		return $best;
	}
}

if ( ! function_exists( 'tc_is_wpdb_ref' ) ) {
	/**
	 * Whether token $i is a reference to the $wpdb object ($wpdb or $GLOBALS['wpdb']).
	 * Returns the index of the first token of the reference, or -1.
	 */
	function tc_is_wpdb_ref( $tokens, $i ) {
		if ( $i < 0 ) {
			return -1;
		}
		$t = $tokens[ $i ];
		if ( T_VARIABLE === $t[0] && '$wpdb' === $t[1] ) {
			return $i;
		}
		if ( null === $t[0] && ']' === $t[1] ) {
			$open = tc_match_back( $tokens, $i );
			if ( $open > 0 ) {
				$g = tc_prev_sig( $tokens, $open );
				$k = tc_next_sig( $tokens, $open );
				if ( $g >= 0 && T_VARIABLE === $tokens[ $g ][0] && '$GLOBALS' === $tokens[ $g ][1]
					&& $k >= 0 && T_CONSTANT_ENCAPSED_STRING === $tokens[ $k ][0]
					&& 'wpdb' === trim( $tokens[ $k ][1], '\'"' ) ) {
					return $g;
				}
			}
		}
		return -1;
	}
}

if ( ! function_exists( 'tc_find_calls' ) ) {
	/**
	 * Find calls by name.
	 *
	 * @param array       $tokens Tokens.
	 * @param array       $names  Lower-case function/method names.
	 * @param string|null $object null = plain function call; '$wpdb' = method on $wpdb; '*' = any method/static call.
	 * @param int         $from   Start index.
	 * @param int|null    $to     End index (inclusive).
	 * @return array List of array( 'name', 'index', 'open', 'close', 'line', 'args' => array( array( from, to ), ... ) ).
	 */
	function tc_find_calls( $tokens, array $names, $object = null, $from = 0, $to = null ) {
		$n     = count( $tokens );
		$to    = ( null === $to ) ? $n - 1 : min( $to, $n - 1 );
		$names = array_map( 'strtolower', $names );
		$calls = array();

		for ( $i = $from; $i <= $to; $i++ ) {
			$t = $tokens[ $i ];
			if ( T_STRING !== $t[0] || ! in_array( strtolower( $t[1] ), $names, true ) ) {
				continue;
			}
			$open = tc_next_sig( $tokens, $i );
			if ( $open < 0 || ! ( null === $tokens[ $open ][0] && '(' === $tokens[ $open ][1] ) ) {
				continue;
			}
			$prev    = tc_prev_sig( $tokens, $i );
			$prev_id = $prev >= 0 ? $tokens[ $prev ][0] : null;
			$is_obj  = ( T_OBJECT_OPERATOR === $prev_id || T_DOUBLE_COLON === $prev_id
				|| ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prev_id ) );

			if ( null === $object ) {
				if ( $is_obj || T_FUNCTION === $prev_id || T_NEW === $prev_id ) {
					continue;
				}
			} elseif ( '*' === $object ) {
				if ( ! $is_obj ) {
					continue;
				}
			} else {
				if ( ! $is_obj || T_DOUBLE_COLON === $prev_id ) {
					continue;
				}
				$obj = tc_prev_sig( $tokens, $prev );
				if ( '$wpdb' === $object ) {
					if ( tc_is_wpdb_ref( $tokens, $obj ) < 0 ) {
						continue;
					}
				} elseif ( $obj < 0 || $tokens[ $obj ][1] !== $object ) {
					continue;
				}
			}

			$close = tc_match( $tokens, $open );
			if ( $close < 0 ) {
				continue;
			}
			$args  = array();
			$depth = 0;
			$start = -1;
			$last  = -1;
			for ( $k = $open + 1; $k < $close; $k++ ) {
				$tk = $tokens[ $k ];
				if ( tc_tok_is_ws( $tk ) ) {
					continue;
				}
				if ( 0 === $depth && null === $tk[0] && ',' === $tk[1] ) {
					if ( $start >= 0 ) {
						$args[] = array( $start, $last );
					}
					$start = -1;
					$last  = -1;
					continue;
				}
				if ( tc_tok_is_opener( $tk ) ) {
					$depth++;
				} elseif ( tc_tok_is_closer( $tk ) ) {
					$depth--;
				}
				if ( $start < 0 ) {
					$start = $k;
				}
				$last = $k;
			}
			if ( $start >= 0 ) {
				$args[] = array( $start, $last );
			}
			$calls[] = array(
				'name'  => strtolower( $t[1] ),
				'index' => $i,
				'open'  => $open,
				'close' => $close,
				'line'  => $t[2],
				'args'  => $args,
			);
			$i = $open; // continue scanning inside the call too.
		}
		return $calls;
	}
}

if ( ! function_exists( 'tc_call_chain' ) ) {
	/**
	 * Names of the calls/constructs enclosing token $i within the current statement, innermost first.
	 * Casts are reported as "(int)" etc.; language constructs as their lower-case keyword; array literals as "array".
	 */
	function tc_call_chain( $tokens, $i, $max_depth = 5 ) {
		$chain  = array();
		$depth  = 0;
		$casts  = array( T_INT_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_STRING_CAST, T_ARRAY_CAST, T_OBJECT_CAST );
		$stops  = array( T_OPEN_TAG, T_CLOSE_TAG, T_RETURN, T_ECHO, T_PRINT, T_THROW, T_CASE );
		$constr = array( T_ISSET, T_EMPTY, T_UNSET, T_ARRAY, T_LIST, T_IF, T_ELSEIF, T_WHILE, T_SWITCH, T_FOR, T_FOREACH, T_EXIT, T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE );

		// Casts directly applied to the operand.
		$p = tc_prev_sig( $tokens, $i );
		if ( $p >= 0 && in_array( $tokens[ $p ][0], $casts, true ) ) {
			$chain[] = strtolower( preg_replace( '/\s+/', '', $tokens[ $p ][1] ) );
		}

		for ( $j = $i - 1; $j >= 0 && count( $chain ) < $max_depth; $j-- ) {
			$t = $tokens[ $j ];
			if ( tc_tok_is_ws( $t ) ) {
				continue;
			}
			if ( tc_tok_is_closer( $t ) ) {
				$depth++;
				continue;
			}
			if ( tc_tok_is_opener( $t ) ) {
				if ( $depth > 0 ) {
					$depth--;
					continue;
				}
				// Enclosing opener at depth 0.
				if ( null !== $t[0] ) {
					$chain[] = 'interp'; // "{$...}" inside a string.
					continue;
				}
				if ( '{' === $t[1] ) {
					break; // block boundary.
				}
				$c = tc_prev_sig( $tokens, $j );
				if ( '[' === $t[1] ) {
					if ( $c >= 0 && ( T_VARIABLE === $tokens[ $c ][0] || T_STRING === $tokens[ $c ][0] || ( null === $tokens[ $c ][0] && ( ']' === $tokens[ $c ][1] || ')' === $tokens[ $c ][1] ) ) ) ) {
						$chain[] = 'index';
					} else {
						$chain[] = 'array';
					}
					continue;
				}
				// '(' — callee?
				if ( $c >= 0 ) {
					$ct = $tokens[ $c ];
					if ( T_STRING === $ct[0] ) {
						$chain[] = strtolower( ltrim( $ct[1], '\\' ) );
						$j       = $c;
						continue;
					}
					if ( in_array( $ct[0], $constr, true ) ) {
						$chain[] = strtolower( $ct[1] );
						$j       = $c;
						continue;
					}
					if ( T_VARIABLE === $ct[0] ) {
						$chain[] = $ct[1];
						$j       = $c;
						continue;
					}
					if ( in_array( $ct[0], $casts, true ) ) {
						$chain[] = strtolower( preg_replace( '/\s+/', '', $ct[1] ) );
						continue;
					}
				}
				$chain[] = '(';
				continue;
			}
			if ( 0 === $depth ) {
				if ( null === $t[0] && ( ';' === $t[1] || '=' === $t[1] ) ) {
					break;
				}
				if ( in_array( $t[0], $stops, true ) ) {
					$chain[] = strtolower( trim( $t[1] ) );
					break;
				}
			}
		}
		return $chain;
	}
}

if ( ! function_exists( 'tc_last_assignment' ) ) {
	/**
	 * Last assignment to $var_name before $before, within [ $scope_start, $before ).
	 *
	 * @return array|null array( 'from' => int, 'to' => int, 'op' => '=' | '.=' , 'line' => int )
	 */
	function tc_last_assignment( $tokens, $var_name, $scope_start, $before ) {
		$n = count( $tokens );
		for ( $j = $before - 1; $j >= $scope_start; $j-- ) {
			$t = $tokens[ $j ];
			if ( T_VARIABLE !== $t[0] || $t[1] !== $var_name ) {
				continue;
			}
			$op = tc_next_sig( $tokens, $j );
			if ( $op < 0 ) {
				continue;
			}
			$ot = $tokens[ $op ];
			if ( ! ( ( null === $ot[0] && '=' === $ot[1] ) || T_CONCAT_EQUAL === $ot[0] ) ) {
				continue;
			}
			$from = tc_next_sig( $tokens, $op );
			if ( $from < 0 ) {
				continue;
			}
			$depth = 0;
			$to    = $from;
			for ( $k = $from; $k < $n; $k++ ) {
				$tk = $tokens[ $k ];
				if ( tc_tok_is_opener( $tk ) ) {
					$depth++;
				} elseif ( tc_tok_is_closer( $tk ) ) {
					if ( 0 === $depth ) {
						break;
					}
					$depth--;
				} elseif ( 0 === $depth && null === $tk[0] && ';' === $tk[1] ) {
					break;
				}
				if ( ! tc_tok_is_ws( $tk ) ) {
					$to = $k;
				}
			}
			return array(
				'from' => $from,
				'to'   => $to,
				'op'   => ( T_CONCAT_EQUAL === $ot[0] ) ? '.=' : '=',
				'line' => $t[2],
			);
		}
		return null;
	}
}

if ( ! function_exists( 'tc_tokens_text' ) ) {
	function tc_tokens_text( $tokens, $from, $to ) {
		$s = '';
		for ( $k = $from; $k <= $to && isset( $tokens[ $k ] ); $k++ ) {
			$s .= $tokens[ $k ][1];
		}
		return $s;
	}
}

if ( ! function_exists( 'tc_excerpt' ) ) {
	/**
	 * A tc_grep()-style excerpt for one known line of a file.
	 */
	function tc_excerpt( $file, $line, $highlight = '' ) {
		if ( ! file_exists( $file ) || $line < 1 ) {
			return '';
		}
		$lines = file( $file, FILE_IGNORE_NEW_LINES );
		if ( ! isset( $lines[ $line - 1 ] ) ) {
			return '';
		}
		$text = trim( str_replace( '"', "'", $lines[ $line - 1 ] ) );
		$text = htmlspecialchars( substr( $text, 0, 75 ) );
		if ( '' !== $highlight ) {
			$h = htmlspecialchars( str_replace( '"', "'", $highlight ) );
			if ( '' !== $h ) {
				$text = str_replace( $h, '<span class="tc-grep">' . $h . '</span>', $text );
			}
		}
		return "<pre class='tc-grep'>" . __( 'Line ', 'theme-check' ) . $line . ': ' . $text . '</pre>';
	}
}

if ( ! function_exists( 'tc_is_vendor_path' ) ) {
	/**
	 * Whether a file lives inside a bundled third-party framework.
	 */
	function tc_is_vendor_path( $file ) {
		$file  = strtolower( str_replace( '\\', '/', (string) $file ) );
		$paths = apply_filters(
			'tc_vendor_paths',
			array(
				'redux-framework',
				'redux-core',
				'reduxcore',
				'kirki',
				'cmb2',
				'one-click-demo-import',
				'ocdi',
				'wp-color-picker-alpha',
				'aq_resizer',
				'class-tgm-plugin-activation',
				'merlin',
				'envato-market',
				'/vendor/',
				'/node_modules/',
			)
		);
		foreach ( $paths as $p ) {
			if ( false !== strpos( $file, strtolower( $p ) ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'tc_rule_severity' ) ) {
	/**
	 * Effective severity for a rule, with vendor-path downgrade and the tc_rule_severity filter.
	 *
	 * @param string $rule_id  e.g. 'sql/concat'.
	 * @param string $default  required|warning|recommended|info.
	 * @param string $file     Absolute path (for the vendor downgrade); '' to skip.
	 * @param bool   $downgrade_vendor Whether vendor paths downgrade this rule to info.
	 */
	function tc_rule_severity( $rule_id, $default, $file = '', $downgrade_vendor = true ) {
		$severity = apply_filters( 'tc_rule_severity', $default, $rule_id );
		if ( $downgrade_vendor && '' !== $file && tc_is_vendor_path( $file ) ) {
			$severity = 'info';
		}
		return $severity;
	}
}
