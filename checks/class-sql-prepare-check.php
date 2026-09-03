<?php
/**
 * Checks that SQL run through $wpdb is prepared and that no raw database driver is used.
 *
 * ThemeForest requirement (WordPress Theme Requirements Part 5 - Theme Security):
 * "SQL statements must be prepared using $wpdb->prepare()".
 *
 * @package Theme Check
 */

/**
 * SQL preparation check.
 */
class SQL_Prepare_Check implements themecheck {

	const RULES = array(
		'sql/raw-driver'              => 'required',
		'sql/concat'                  => 'required',
		'sql/esc-sql-concat'          => 'warning',
		'sql/variable-arg'            => 'warning',
		'sql/variable-arg-concat'     => 'warning',
		'sql/prepare-no-placeholders' => 'warning',
		'sql/prepare-interpolated'    => 'warning',
		'sql/prepare-variable-format' => 'warning',
	);

	const DOCS   = 'https://developer.wordpress.org/reference/classes/wpdb/#protect-queries-against-sql-injection-attacks';
	const ENVATO = 'https://help.author.envato.com/hc/en-us/articles/360000481243-WordPress-Theme-Requirements-Part-5-Theme-Security';

	/**
	 * Structured findings.
	 *
	 * @var array
	 */
	protected $results = array();

	/**
	 * Whether a REQUIRED finding was emitted.
	 *
	 * @var bool
	 */
	protected $failed = false;

	/**
	 * Functions that make a concatenated operand acceptable (WARNING instead of REQUIRED).
	 *
	 * @var array
	 */
	protected $escapers = array( 'esc_sql', 'absint', 'intval', 'floatval', '(int)', '(integer)', '(float)', '(double)' );

	public function check( $php_files, $css_files, $other_files ) {
		checkcount();
		$this->results = array();
		$this->failed  = false;

		if ( ! function_exists( 'token_get_all' ) || ! function_exists( 'tc_tokens_for_file' ) ) {
			return true;
		}

		$prefilter = '/\$wpdb\s*->\s*(?:query|get_results|get_var|get_row|get_col|prepare)\s*\(|\$GLOBALS\s*\[\s*[\'"]wpdb[\'"]\s*\]|\bmysqli?_[a-z_]+\s*\(|\bnew\s+\\\\?(?:mysqli|PDO)\b|\bPDO::/i';

		foreach ( $php_files as $path => $content ) {
			if ( ! preg_match( $prefilter, $content ) ) {
				continue;
			}
			$tokens = tc_tokens_for_file( $path, $content );
			if ( empty( $tokens ) ) {
				continue;
			}
			$scopes = tc_token_scopes( $tokens );

			$this->check_raw_drivers( $tokens, $path );

			$calls = tc_find_calls( $tokens, array( 'query', 'get_results', 'get_var', 'get_row', 'get_col', 'prepare' ), '$wpdb' );
			foreach ( $calls as $call ) {
				if ( empty( $call['args'] ) ) {
					if ( 'prepare' === $call['name'] ) {
						continue;
					}
					continue;
				}
				$scope = tc_scope_at( $scopes, $call['index'] );
				if ( 'prepare' === $call['name'] ) {
					$this->analyse_prepare( $tokens, $call, $scope, $path );
					continue;
				}
				$this->analyse_query( $tokens, $call, $scope, $path );
			}
		}

		return ! $this->failed;
	}

	/**
	 * mysql_* / mysqli_* / new mysqli / new PDO / PDO::
	 */
	protected function check_raw_drivers( $tokens, $path ) {
		$n = count( $tokens );
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( T_STRING === $t[0] ) {
				$name = strtolower( $t[1] );
				if ( preg_match( '/^mysqli?_[a-z_]+$/', $name ) ) {
					$next = tc_next_sig( $tokens, $i );
					$prev = tc_prev_sig( $tokens, $i );
					if ( $next >= 0 && null === $tokens[ $next ][0] && '(' === $tokens[ $next ][1]
						&& ! ( $prev >= 0 && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) ) {
						$this->add_raw_driver( $path, $t[2], $name . '()' );
					}
				} elseif ( 'pdo' === $name ) {
					$next = tc_next_sig( $tokens, $i );
					if ( $next >= 0 && T_DOUBLE_COLON === $tokens[ $next ][0] ) {
						$this->add_raw_driver( $path, $t[2], 'PDO::' );
					}
				}
			} elseif ( T_NEW === $t[0] ) {
				$j = tc_next_sig( $tokens, $i );
				if ( $j >= 0 && T_NS_SEPARATOR === $tokens[ $j ][0] ) {
					$j = tc_next_sig( $tokens, $j );
				}
				if ( $j >= 0 && in_array( strtolower( ltrim( $tokens[ $j ][1], '\\' ) ), array( 'mysqli', 'pdo' ), true ) ) {
					$this->add_raw_driver( $path, $t[2], 'new ' . ltrim( $tokens[ $j ][1], '\\' ) );
				}
			}
		}
	}

	protected function add_raw_driver( $path, $line, $what ) {
		$message = sprintf(
			/* translators: 1: driver function/class, 2: file name */
			__( 'Found %1$s in %2$s. Themes must not talk to the database directly: use the <code>$wpdb</code> class with <code>$wpdb->prepare()</code>. %3$s', 'theme-check' ),
			'<code>' . esc_html( $what ) . '</code>',
			'<strong>' . esc_html( tc_filename( $path ) ) . '</strong>',
			$this->envato_note( 'the wpdb class must be used' )
		);
		$this->add( 'sql/raw-driver', $path, $line, $message, false );
	}

	/**
	 * $wpdb->query|get_results|get_var|get_row|get_col( <sql> ).
	 */
	protected function analyse_query( $tokens, $call, $scope, $path ) {
		list( $from, $to ) = $call['args'][0];
		$shape             = $this->classify( $tokens, $from, $to, $scope, 0 );
		$method            = '$wpdb->' . $call['name'] . '()';
		$file              = '<strong>' . esc_html( tc_filename( $path ) ) . '</strong>';

		switch ( $shape['kind'] ) {
			case 'safe':
			case 'const':
				return;

			case 'concat':
				$this->add(
					'sql/concat',
					$path,
					$call['line'],
					sprintf(
						/* translators: 1: wpdb method, 2: file, 3: variables */
						__( 'Found %1$s in %2$s with SQL built from %3$s instead of <code>$wpdb->prepare()</code>. Pass values as placeholders, e.g. <code>$wpdb->prepare( "... WHERE ID = %%d", $id )</code>. Table names may use <code>$wpdb->prefix</code> / <code>$wpdb->posts</code> directly; dynamic identifiers must be allow-listed (use <code>%%i</code> on WordPress 6.2+). %4$s', 'theme-check' ),
						'<code>' . esc_html( $method ) . '</code>',
						$file,
						$this->vars_html( $shape['vars'] ),
						$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
					)
				);
				return;

			case 'escaped':
				$this->add(
					'sql/esc-sql-concat',
					$path,
					$call['line'],
					sprintf(
						/* translators: 1: wpdb method, 2: file, 3: variables */
						__( 'Found %1$s in %2$s with SQL that concatenates %3$s escaped with <code>esc_sql()</code>/casts instead of <code>$wpdb->prepare()</code>. Prefer <code>prepare()</code> placeholders; <code>esc_sql()</code> is acceptable only for identifiers taken from a fixed allow-list. A manual review is needed. %4$s', 'theme-check' ),
						'<code>' . esc_html( $method ) . '</code>',
						$file,
						$this->vars_html( $shape['vars'] ),
						$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
					)
				);
				return;

			case 'variable':
				if ( ! $shape['plain'] ) {
					$this->add_variable_arg( $path, $call['line'], $method, $file, $shape['text'] );
					return;
				}
				$assign = tc_last_assignment( $tokens, $shape['var'], $scope['start'], $call['index'] );
				if ( null === $assign ) {
					$this->add_variable_arg( $path, $call['line'], $method, $file, $shape['var'] );
					return;
				}
				if ( '.=' === $assign['op'] ) {
					$rhs = array( 'kind' => 'concat', 'vars' => array( $shape['var'] . ' .=' ) );
				} else {
					$rhs = $this->classify( $tokens, $assign['from'], $assign['to'], $scope, 1 );
				}
				if ( in_array( $rhs['kind'], array( 'safe', 'const' ), true ) ) {
					return;
				}
				if ( 'concat' === $rhs['kind'] ) {
					$this->add(
						'sql/variable-arg-concat',
						$path,
						$call['line'],
						sprintf(
							/* translators: 1: wpdb method, 2: file, 3: variable, 4: line, 5: variables */
							__( 'Found %1$s in %2$s executing %3$s, which is built on line %4$d by concatenating %5$s instead of using <code>$wpdb->prepare()</code>. Build the statement with <code>$wpdb->prepare()</code> placeholders. %6$s', 'theme-check' ),
							'<code>' . esc_html( $method ) . '</code>',
							$file,
							'<code>' . esc_html( $shape['var'] ) . '</code>',
							(int) $assign['line'],
							$this->vars_html( $rhs['vars'] ),
							$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
						)
					);
					return;
				}
				if ( 'escaped' === $rhs['kind'] ) {
					$this->add(
						'sql/esc-sql-concat',
						$path,
						$call['line'],
						sprintf(
							/* translators: 1: wpdb method, 2: file, 3: variable, 4: line */
							__( 'Found %1$s in %2$s executing %3$s, which is built on line %4$d with <code>esc_sql()</code>/casts instead of <code>$wpdb->prepare()</code>. Prefer <code>prepare()</code> placeholders. A manual review is needed. %5$s', 'theme-check' ),
							'<code>' . esc_html( $method ) . '</code>',
							$file,
							'<code>' . esc_html( $shape['var'] ) . '</code>',
							(int) $assign['line'],
							$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
						)
					);
					return;
				}
				$this->add_variable_arg( $path, $call['line'], $method, $file, $shape['var'] );
				return;

			default:
				return;
		}
	}

	protected function add_variable_arg( $path, $line, $method, $file, $var ) {
		$this->add(
			'sql/variable-arg',
			$path,
			$line,
			sprintf(
				/* translators: 1: wpdb method, 2: file, 3: variable */
				__( 'Found %1$s in %2$s executing %3$s whose origin could not be determined. Make sure the statement is produced by <code>$wpdb->prepare()</code> and never contains unescaped values. A manual review is needed. %4$s', 'theme-check' ),
				'<code>' . esc_html( $method ) . '</code>',
				$file,
				'<code>' . esc_html( $var ) . '</code>',
				$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
			)
		);
	}

	/**
	 * $wpdb->prepare( <format>, ... ).
	 */
	protected function analyse_prepare( $tokens, $call, $scope, $path ) {
		list( $from, $to ) = $call['args'][0];
		$file              = '<strong>' . esc_html( tc_filename( $path ) ) . '</strong>';
		$shape             = $this->classify( $tokens, $from, $to, $scope, 0 );

		if ( 'const' === $shape['kind'] ) {
			if ( 1 === count( $call['args'] ) && ! preg_match( '/%(?:\d+\$)?[dsfFi]/', tc_tokens_text( $tokens, $from, $to ) ) ) {
				$this->add(
					'sql/prepare-no-placeholders',
					$path,
					$call['line'],
					sprintf(
						/* translators: 1: file */
						__( 'Found <code>$wpdb->prepare()</code> in %1$s with a literal that contains no placeholders and no values. Either pass the values as <code>%%d</code>/<code>%%s</code> arguments or run the constant statement without <code>prepare()</code>. %2$s', 'theme-check' ),
						$file,
						$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
					)
				);
			}
			return;
		}

		if ( in_array( $shape['kind'], array( 'concat', 'escaped' ), true ) ) {
			$this->add(
				'sql/prepare-interpolated',
				$path,
				$call['line'],
				sprintf(
					/* translators: 1: file, 2: variables */
					__( 'Found <code>$wpdb->prepare()</code> in %1$s whose format string interpolates %2$s. Values must be passed as <code>%%d</code>/<code>%%s</code>/<code>%%f</code> arguments, never inside the format string. %3$s', 'theme-check' ),
					$file,
					$this->vars_html( $shape['vars'] ),
					$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
				)
			);
			return;
		}
		if ( 'variable' === $shape['kind'] && $shape['plain'] ) {
			$assign = tc_last_assignment( $tokens, $shape['var'], $scope['start'], $call['index'] );
			if ( null === $assign ) {
				return;
			}
			$rhs = ( '.=' === $assign['op'] ) ? array( 'kind' => 'concat', 'vars' => array( $shape['var'] . ' .=' ) ) : $this->classify( $tokens, $assign['from'], $assign['to'], $scope, 1 );
			if ( in_array( $rhs['kind'], array( 'concat', 'escaped' ), true ) ) {
				$this->add(
					'sql/prepare-variable-format',
					$path,
					$call['line'],
					sprintf(
						/* translators: 1: file, 2: variable, 3: line, 4: variables */
						__( 'Found <code>$wpdb->prepare()</code> in %1$s whose format string %2$s is built on line %3$d by concatenating %4$s. Values must be passed as placeholders, not concatenated into the format string. %5$s', 'theme-check' ),
						$file,
						'<code>' . esc_html( $shape['var'] ) . '</code>',
						(int) $assign['line'],
						$this->vars_html( $rhs['vars'] ),
						$this->envato_note( 'SQL statements must be prepared using $wpdb->prepare()' )
					)
				);
			}
		}
	}

	/**
	 * Classify an expression.
	 *
	 * @return array kind => safe|const|concat|escaped|variable, vars => array, plain => bool, var => string, text => string
	 */
	protected function classify( $tokens, $from, $to, $scope, $depth ) {
		$sig = $this->sig_range( $tokens, $from, $to );
		if ( empty( $sig ) ) {
			return array( 'kind' => 'const', 'vars' => array() );
		}
		$first = $tokens[ $sig[0] ];
		$last  = $sig[ count( $sig ) - 1 ];

		// Whole expression is $wpdb->prepare( ... ).
		if ( tc_is_wpdb_ref( $tokens, $sig[0] ) >= 0 || ( T_VARIABLE === $first[0] && '$GLOBALS' === $first[1] ) ) {
			$calls = tc_find_calls( $tokens, array( 'prepare' ), '$wpdb', $sig[0], $last );
			foreach ( $calls as $c ) {
				if ( $c['close'] === $last ) {
					return array( 'kind' => 'safe', 'vars' => array() );
				}
			}
		}

		// Single primary expression: $var, $var->prop, $var['k'], $obj->method(), func().
		if ( T_VARIABLE === $first[0] ) {
			$j     = tc_next_sig( $tokens, $sig[0] );
			$chain = false;
			while ( $j >= 0 && $j <= $last ) {
				$tj = $tokens[ $j ];
				if ( T_OBJECT_OPERATOR === $tj[0] || T_DOUBLE_COLON === $tj[0] ) {
					$j     = tc_next_sig( $tokens, $j );
					$j     = tc_next_sig( $tokens, $j );
					$chain = true;
					continue;
				}
				if ( null === $tj[0] && ( '[' === $tj[1] || '(' === $tj[1] ) ) {
					$j     = tc_match( $tokens, $j );
					$j     = ( $j < 0 ) ? -1 : tc_next_sig( $tokens, $j );
					$chain = true;
					continue;
				}
				break;
			}
			if ( $j < 0 || $j > $last ) {
				if ( tc_is_wpdb_ref( $tokens, $sig[0] ) >= 0 && $chain ) {
					return array( 'kind' => 'const', 'vars' => array() ); // $wpdb->posts etc.
				}
				return array(
					'kind'  => 'variable',
					'vars'  => array(),
					'plain' => ! $chain,
					'var'   => $first[1],
					'text'  => tc_tokens_text( $tokens, $sig[0], $last ),
				);
			}
		}
		if ( T_STRING === $first[0] ) {
			$j = tc_next_sig( $tokens, $sig[0] );
			if ( $j >= 0 && null === $tokens[ $j ][0] && '(' === $tokens[ $j ][1] && tc_match( $tokens, $j ) === $last
				&& ! in_array( strtolower( $first[1] ), array( 'sprintf', 'implode', 'str_replace', 'vsprintf', 'join' ), true ) ) {
				return array(
					'kind'  => 'variable',
					'vars'  => array(),
					'plain' => false,
					'var'   => $first[1] . '()',
					'text'  => $first[1] . '()',
				);
			}
		}

		// Composite expression: look at every operand.
		$unsafe  = array();
		$escaped = 0;
		foreach ( $sig as $i ) {
			$t = $tokens[ $i ];
			if ( T_VARIABLE === $t[0] ) {
				if ( '$GLOBALS' === $t[1] ) {
					continue;
				}
				if ( '$wpdb' === $t[1] ) {
					$op = tc_next_sig( $tokens, $i );
					if ( $op >= 0 && T_OBJECT_OPERATOR === $tokens[ $op ][0] ) {
						$nm = tc_next_sig( $tokens, $op );
						$pa = tc_next_sig( $tokens, $nm );
						if ( $pa >= 0 && null === $tokens[ $pa ][0] && '(' === $tokens[ $pa ][1] ) {
							if ( 'prepare' === strtolower( $tokens[ $nm ][1] ) ) {
								continue; // prepared fragment.
							}
							$unsafe[] = '$wpdb->' . $tokens[ $nm ][1] . '()';
							continue;
						}
						continue; // $wpdb->prefix, $wpdb->posts ...
					}
					continue;
				}
				// One-level propagation: $table = $wpdb->prefix . 'x'.
				if ( $depth < 1 ) {
					$assign = tc_last_assignment( $tokens, $t[1], $scope['start'], $from );
					if ( null !== $assign && '=' === $assign['op'] ) {
						$rhs = $this->classify( $tokens, $assign['from'], $assign['to'], $scope, $depth + 1 );
						if ( 'const' === $rhs['kind'] ) {
							continue;
						}
					}
				}
				$chain = tc_call_chain( $tokens, $i, 2 );
				if ( ! empty( $chain ) && in_array( $chain[0], $this->escapers, true ) ) {
					$escaped++;
					$unsafe[] = $t[1];
					continue;
				}
				$unsafe[] = $t[1];
				$escaped  = -1000; // any raw variable makes the whole thing raw.
				continue;
			}
			if ( T_STRING === $t[0] ) {
				$nx = tc_next_sig( $tokens, $i );
				if ( $nx >= 0 && null === $tokens[ $nx ][0] && '(' === $tokens[ $nx ][1] ) {
					$fn = strtolower( $t[1] );
					if ( in_array( $fn, $this->escapers, true ) || in_array( $fn, array( 'implode', 'join' ), true ) ) {
						continue; // wrapper itself; its operands are evaluated separately.
					}
					$pv = tc_prev_sig( $tokens, $i );
					if ( $pv >= 0 && T_OBJECT_OPERATOR === $tokens[ $pv ][0] ) {
						continue; // method name handled with its object.
					}
					$unsafe[] = $t[1] . '()';
					$escaped  = -1000;
				}
			}
		}

		if ( empty( $unsafe ) ) {
			return array( 'kind' => 'const', 'vars' => array() );
		}
		$unsafe = array_values( array_unique( $unsafe ) );
		if ( $escaped > 0 ) {
			return array( 'kind' => 'escaped', 'vars' => $unsafe );
		}
		return array( 'kind' => 'concat', 'vars' => $unsafe );
	}

	protected function sig_range( $tokens, $from, $to ) {
		$out = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			if ( ! tc_tok_is_ws( $tokens[ $i ] ) ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	protected function vars_html( $vars ) {
		if ( empty( $vars ) ) {
			return __( 'variables', 'theme-check' );
		}
		$vars = array_slice( array_values( array_unique( $vars ) ), 0, 4 );
		return '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $vars ) ) . '</code>';
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
		$this->results[] = tc_error( $severity, $rule, $message, $path, $line, '', self::DOCS );
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

$themechecks[] = new SQL_Prepare_Check();
