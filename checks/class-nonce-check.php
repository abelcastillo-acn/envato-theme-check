<?php
/**
 * Checks that request handlers verify a nonce and, when privileged and state-changing, a capability.
 *
 * ThemeForest requirement (WordPress Theme Requirements Part 5 - Theme Security):
 * "a nonce must be used to verify the origin and intent of the request" and
 * "User capabilities must be checked ... before any data is submitted to the server".
 *
 * @package Theme Check
 */

/**
 * Nonce / capability check.
 */
class Nonce_Check implements themecheck {

	const RULES = array(
		'nonce/ajax-missing'         => 'warning',
		'nonce/nopriv-state-change'  => 'warning',
		'nonce/form-handler'         => 'warning',
		'nonce/capability-missing'   => 'warning',
		'nonce/delegated'            => 'info',
		'nonce/unresolved'           => 'info',
	);

	/**
	 * Short, author-facing "what to change" per rule (used by the plain-text message).
	 */
	const FIXES = array(
		'nonce/ajax-missing'        => 'Verify a nonce at the start of this handler (check_ajax_referer() for AJAX, check_admin_referer() for admin-post) and send it from JavaScript with wp_create_nonce().',
		'nonce/nopriv-state-change' => 'This public endpoint changes data: verify a nonce with check_ajax_referer() and validate every input before writing.',
		'nonce/form-handler'        => 'Verify a nonce (check_admin_referer()/wp_verify_nonce()) before using the submitted data to modify anything, and output it with wp_nonce_field().',
		'nonce/capability-missing'  => 'Check the user capability (e.g. current_user_can( \'manage_options\' )) before this handler modifies data.',
		'nonce/delegated'           => 'Confirm that the helper this handler calls actually verifies the nonce (check_ajax_referer()/check_admin_referer()/wp_verify_nonce()).',
		'nonce/unresolved'          => 'Make sure every dynamically registered AJAX/admin-post handler verifies a nonce and checks capabilities.',
	);

	const DOCS   = 'https://developer.wordpress.org/apis/security/nonces/';
	const CAPS   = 'https://developer.wordpress.org/apis/security/user-roles-and-capabilities/';
	const ENVATO = 'https://help.author.envato.com/hc/en-us/articles/360000481243-WordPress-Theme-Requirements-Part-5-Theme-Security';

	protected $results = array();
	protected $failed  = false;

	protected $verify_functions = array( 'check_ajax_referer', 'check_admin_referer', 'wp_verify_nonce' );
	protected $cap_functions    = array( 'current_user_can', 'user_can', 'current_user_can_for_blog', 'is_super_admin' );
	protected $state_changing   = array();

	/** @var array file => scopes cache for handler resolution. */
	protected $scopes_cache = array();
	/** @var array "file|scope-start" => true for scopes already reported in pass 2. */
	protected $reported = array();

	public function __construct() {
		$this->state_changing = apply_filters(
			'tc_state_changing_functions',
			array(
				'update_option', 'add_option', 'delete_option', 'update_site_option',
				'update_post_meta', 'add_post_meta', 'delete_post_meta', 'update_user_meta', 'add_user_meta', 'delete_user_meta',
				'update_term_meta', 'update_metadata', 'add_metadata', 'delete_metadata',
				'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_trash_post', 'wp_insert_attachment',
				'wp_insert_user', 'wp_update_user', 'wp_delete_user', 'wp_create_user', 'wp_set_password',
				'wp_insert_term', 'wp_update_term', 'wp_delete_term', 'wp_set_object_terms',
				'wp_insert_comment', 'wp_update_comment', 'wp_delete_comment', 'wp_mail',
				'set_theme_mod', 'remove_theme_mod', 'set_transient', 'delete_transient',
				'wp_handle_upload', 'wp_handle_sideload', 'media_handle_upload', 'media_sideload_image', 'download_url',
				'activate_plugin', 'deactivate_plugins', 'switch_theme',
				'file_put_contents', 'unlink', 'rename', 'copy', 'move_uploaded_file', 'wp_mkdir_p',
				'setcookie', 'wp_set_auth_cookie', 'wp_set_current_user', 'wp_signon', 'add_role', 'remove_role', 'wp_schedule_event',
			)
		);
	}

	public function check( $php_files, $css_files, $other_files ) {
		checkcount();
		$this->results      = array();
		$this->failed       = false;
		$this->scopes_cache = array();
		$this->reported     = array();

		if ( ! function_exists( 'token_get_all' ) || ! function_exists( 'tc_tokens_for_file' ) ) {
			return true;
		}

		$reg_prefilter = '/add_action\s*\(\s*[\'"](?:wp_ajax_|admin_post_|wc_ajax_)/i';

		// Pass 2 (registrations) - pass 1 (indexing) happens lazily in resolve_handler().
		foreach ( $php_files as $path => $content ) {
			if ( ! preg_match( $reg_prefilter, $content ) ) {
				continue;
			}
			$tokens = tc_tokens_for_file( $path, $content );
			if ( empty( $tokens ) ) {
				continue;
			}
			$scopes     = $this->scopes_for( $path, $tokens );
			$unresolved = 0;

			foreach ( tc_find_calls( $tokens, array( 'add_action' ) ) as $call ) {
				if ( count( $call['args'] ) < 2 ) {
					continue;
				}
				$hook = $this->literal( $tokens, $call['args'][0] );
				if ( null === $hook ) {
					$txt = tc_tokens_text( $tokens, $call['args'][0][0], $call['args'][0][1] );
					if ( preg_match( '/wp_ajax_|admin_post_|wc_ajax_/', $txt ) ) {
						$unresolved++;
					}
					continue;
				}
				if ( ! preg_match( '/^(wp_ajax_(nopriv_)?|admin_post_(nopriv_)?|wc_ajax_)/', $hook, $hm ) ) {
					continue;
				}
				$public = ( ! empty( $hm[2] ) || ! empty( $hm[3] ) || 0 === strpos( $hook, 'wc_ajax_' ) );

				$handler = $this->resolve_handler( $tokens, $scopes, $call['args'][1], $php_files, $path );
				if ( null === $handler ) {
					$unresolved++;
					continue;
				}
				// A capability check guarding the registration itself (e.g. add_action() inside
				// `if ( current_user_can( 'manage_options' ) )`) protects the handler as well.
				if ( ! $handler['facts']['has_cap'] ) {
					$reg_scope = tc_scope_at( $scopes, $call['index'] );
					if ( 'file' !== $reg_scope['type'] && $this->scope_has_capability_check( $tokens, $reg_scope, $call['index'] ) ) {
						$handler['facts']['has_cap'] = true;
					}
				}
				$this->reported[ $handler['file'] . '|' . $handler['scope']['start'] ] = true;
				$this->evaluate_handler( $hook, $public, $handler );
			}

			if ( $unresolved > 0 ) {
				$this->add(
					'nonce/unresolved',
					$path,
					0,
					sprintf(
						/* translators: 1: count, 2: file */
						_n(
							'%1$d request handler registration in %2$s could not be resolved statically (dynamic hook name or callback). Confirm manually that the handler verifies a nonce and checks capabilities.',
							'%1$d request handler registrations in %2$s could not be resolved statically (dynamic hook names or callbacks). Confirm manually that each handler verifies a nonce and checks capabilities.',
							$unresolved,
							'theme-check'
						),
						$unresolved,
						'<strong>' . esc_html( tc_filename( $path ) ) . '</strong>'
					),
					self::DOCS
				);
			}
		}

		// Pass 3: form handlers anywhere (reads request data + writes, no verification).
		$sg_prefilter = '/\$_(?:POST|REQUEST|GET)\b/';
		$sc_prefilter = '/\b(?:' . implode( '|', array_map( 'preg_quote', $this->state_changing ) ) . ')\s*\(|\$wpdb\s*->\s*(?:query|insert|update|delete|replace)\s*\(/';
		foreach ( $php_files as $path => $content ) {
			if ( ! preg_match( $sg_prefilter, $content ) || ! preg_match( $sc_prefilter, $content ) ) {
				continue;
			}
			$tokens = tc_tokens_for_file( $path, $content );
			if ( empty( $tokens ) ) {
				continue;
			}
			$scopes = $this->scopes_for( $path, $tokens );
			foreach ( $scopes as $scope ) {
				if ( isset( $this->reported[ $path . '|' . $scope['start'] ] ) ) {
					continue;
				}
				$facts = $this->analyse_body( $tokens, $scope, $scopes );
				if ( ! $facts['reads'] || empty( $facts['writes'] ) || $facts['has_verify'] ) {
					continue;
				}
				$where = ( 'file' === $scope['type'] )
					? __( 'the top level of the file', 'theme-check' )
					: '<code>' . esc_html( $this->scope_label( $scope ) ) . '</code>';
				$line  = ( 'file' === $scope['type'] ) ? $facts['first_write_line'] : $scope['line'];
				if ( $facts['delegated'] ) {
					$this->add_delegated( $path, $line, $where, $facts );
					continue;
				}
				$this->add(
					'nonce/form-handler',
					$path,
					$line,
					sprintf(
						/* translators: 1: function or "the top level of the file", 2: file, 3: write function */
						__( 'Found request data (<code>$_POST</code>/<code>$_GET</code>/<code>$_REQUEST</code>) being used to modify data (%3$s) in %1$s of %2$s without verifying a nonce in the same function. Add <code>check_admin_referer( \'your_action\' )</code> (or <code>wp_verify_nonce()</code>) before processing the submission and output the nonce with <code>wp_nonce_field( \'your_action\' )</code>. A manual review is needed. %4$s', 'theme-check' ),
						$where,
						'<strong>' . esc_html( tc_filename( $path ) ) . '</strong>',
						'<code>' . esc_html( $facts['writes'][0] ) . '</code>',
						$this->envato_note( 'a nonce must be used to verify the origin and intent of the request' )
					),
					self::DOCS
				);
			}
		}

		return ! $this->failed;
	}

	/**
	 * Apply the rule table to one resolved handler.
	 */
	protected function evaluate_handler( $hook, $public, $handler ) {
		$facts = $handler['facts'];
		$path  = $handler['file'];
		$line  = $handler['scope']['line'];
		$name  = $this->scope_label( $handler['scope'] );
		$file  = '<strong>' . esc_html( tc_filename( $path ) ) . '</strong>';
		$where = sprintf(
			/* translators: 1: handler, 2: hook, 3: file */
			__( 'The handler %1$s (hook %2$s) in %3$s', 'theme-check' ),
			'<code>' . esc_html( $name ) . '</code>',
			'<code>' . esc_html( $hook ) . '</code>',
			$file
		);

		if ( ! $facts['has_verify'] ) {
			if ( $facts['delegated'] ) {
				$this->add_delegated( $path, $line, $where, $facts );
			} elseif ( ! $public ) {
				$this->add(
					'nonce/ajax-missing',
					$path,
					$line,
					sprintf(
						/* translators: 1: where */
						__( '%1$s does not verify a nonce. Call <code>check_ajax_referer( \'your_action\', \'nonce\' )</code> (AJAX) or <code>check_admin_referer( \'your_action\' )</code> (admin-post) as the first statement and send the nonce from JavaScript via <code>wp_create_nonce()</code> + <code>wp_localize_script()</code>. A manual review is needed. %2$s', 'theme-check' ),
						$where,
						$this->envato_note( 'a nonce must be used to verify the origin and intent of the request' )
					),
					self::DOCS
				);
			} elseif ( ! empty( $facts['writes'] ) ) {
				$this->add(
					'nonce/nopriv-state-change',
					$path,
					$line,
					sprintf(
						/* translators: 1: where, 2: write function */
						__( '%1$s is public (nopriv) and modifies data (%2$s) without verifying a nonce. Public endpoints that change state must call <code>check_ajax_referer()</code> and validate every input. A manual review is needed. %3$s', 'theme-check' ),
						$where,
						'<code>' . esc_html( $facts['writes'][0] ) . '</code>',
						$this->envato_note( 'a nonce must be used to verify the origin and intent of the request' )
					),
					self::DOCS
				);
			}
		}

		if ( ! $public && ! empty( $facts['writes'] ) && ! $facts['has_cap'] ) {
			$this->add(
				'nonce/capability-missing',
				$path,
				$line,
				sprintf(
					/* translators: 1: where, 2: write function */
					__( '%1$s modifies data (%2$s) without a capability check. Add <code>if ( ! current_user_can( \'manage_options\' ) ) { wp_send_json_error( \'forbidden\', 403 ); }</code> (use the capability appropriate to the action) before any write. A manual review is needed. %3$s', 'theme-check' ),
					$where,
					'<code>' . esc_html( $facts['writes'][0] ) . '</code>',
					$this->envato_note( 'User capabilities must be checked before any data is submitted to the server' )
				),
				self::CAPS
			);
		}
	}

	protected function add_delegated( $path, $line, $where, $facts ) {
		$this->add(
			'nonce/delegated',
			$path,
			$line,
			sprintf(
				/* translators: 1: where, 2: wrapper name */
				__( '%1$s has no direct nonce verification but calls %2$s, which may verify the request. Confirm that it calls <code>check_ajax_referer()</code>, <code>check_admin_referer()</code> or <code>wp_verify_nonce()</code>.', 'theme-check' ),
				$where,
				'<code>' . esc_html( $facts['delegate'] ) . '()</code>'
			),
			self::DOCS
		);
	}

	/**
	 * Resolve a callback expression to { file, scope, facts } or null.
	 */
	protected function resolve_handler( $tokens, $scopes, $arg, $php_files, $path ) {
		list( $from, $to ) = $arg;
		$first             = tc_next_sig( $tokens, $from - 1 );
		if ( $first < 0 || $first > $to ) {
			return null;
		}
		$ft = $tokens[ $first ];

		// Closure / arrow function.
		if ( T_FUNCTION === $ft[0] || ( defined( 'T_FN' ) && T_FN === $ft[0] ) || T_STATIC === $ft[0] ) {
			foreach ( $scopes as $s ) {
				if ( 'closure' === $s['type'] && $s['start'] >= $first && $s['start'] <= $to ) {
					return array( 'file' => $path, 'scope' => $s, 'facts' => $this->analyse_body( $tokens, $s, $scopes ) );
				}
			}
			return null;
		}

		$class  = null;
		$method = null;

		if ( T_CONSTANT_ENCAPSED_STRING === $ft[0] && $first === $to ) {
			$name = trim( $ft[1], '\'"' );
			if ( false !== strpos( $name, '::' ) ) {
				list( $class, $method ) = explode( '::', $name, 2 );
			} else {
				$method = $name;
			}
		} elseif ( T_ARRAY === $ft[0] || ( null === $ft[0] && '[' === $ft[1] ) ) {
			$open = ( T_ARRAY === $ft[0] ) ? tc_next_sig( $tokens, $first ) : $first;
			$strs = array();
			for ( $k = $open + 1; $k <= $to; $k++ ) {
				if ( T_CONSTANT_ENCAPSED_STRING === $tokens[ $k ][0] ) {
					$strs[] = trim( $tokens[ $k ][1], '\'"' );
				} elseif ( T_STRING === $tokens[ $k ][0] && empty( $strs ) ) {
					$nx = tc_next_sig( $tokens, $k );
					if ( $nx >= 0 && T_DOUBLE_COLON === $tokens[ $nx ][0] ) {
						$class = $tokens[ $k ][1]; // Foo::class
					}
				}
			}
			if ( empty( $strs ) ) {
				return null;
			}
			$method = end( $strs );
			if ( count( $strs ) > 1 ) {
				$class = $strs[0];
			}
		} else {
			return null;
		}

		if ( ! $method || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $method ) ) {
			return null;
		}
		$class = $class ? ltrim( $class, '\\' ) : null;

		// Same file first, then any theme file that declares the function.
		$candidates = array( $path => $tokens );
		$re         = '/function\s+&?\s*' . preg_quote( $method, '/' ) . '\s*\(/i';
		foreach ( $php_files as $p => $c ) {
			if ( $p !== $path && preg_match( $re, $c ) ) {
				$candidates[ $p ] = null;
			}
		}
		$fallback = null;
		foreach ( $candidates as $p => $tk ) {
			if ( null === $tk ) {
				$tk = tc_tokens_for_file( $p, $php_files[ $p ] );
			}
			$sc = $this->scopes_for( $p, $tk );
			foreach ( $sc as $s ) {
				if ( 'file' === $s['type'] || 'closure' === $s['type'] || strcasecmp( $s['name'], $method ) !== 0 ) {
					continue;
				}
				$hit = array( 'file' => $p, 'scope' => $s, 'facts' => $this->analyse_body( $tk, $s, $sc ) );
				if ( null === $class || ( $s['class'] && strcasecmp( $s['class'], $class ) === 0 ) ) {
					return $hit;
				}
				if ( null === $fallback ) {
					$fallback = $hit;
				}
			}
		}
		return $fallback;
	}

	/**
	 * Facts about a scope body (nested function bodies are excluded).
	 */
	protected function analyse_body( $tokens, $scope, $scopes ) {
		$facts = array(
			'has_verify'       => false,
			'has_cap'          => false,
			'delegated'        => false,
			'delegate'         => '',
			'writes'           => array(),
			'reads'            => false,
			'first_write_line' => 0,
		);
		$from = ( 'file' === $scope['type'] ) ? 0 : $scope['open'];
		$to   = $scope['end'];

		// Ranges of nested scopes to skip (only for the file scope; nested closures inside functions are part of the handler).
		$skip = array();
		if ( 'file' === $scope['type'] ) {
			foreach ( $scopes as $s ) {
				if ( 'file' !== $s['type'] ) {
					$skip[] = array( $s['start'], $s['end'] );
				}
			}
		}

		for ( $i = $from; $i <= $to; $i++ ) {
			foreach ( $skip as $r ) {
				if ( $i >= $r[0] && $i <= $r[1] ) {
					$i = $r[1];
					continue 2;
				}
			}
			$t = $tokens[ $i ];
			if ( T_STRING === $t[0] ) {
				$nx = tc_next_sig( $tokens, $i );
				if ( $nx < 0 || ! ( null === $tokens[ $nx ][0] && '(' === $tokens[ $nx ][1] ) ) {
					continue;
				}
				$name = strtolower( $t[1] );
				if ( in_array( $name, $this->verify_functions, true ) ) {
					$facts['has_verify'] = true;
				} elseif ( in_array( $name, $this->cap_functions, true ) ) {
					$facts['has_cap'] = true;
				} elseif ( in_array( $name, $this->state_changing, true ) ) {
					$facts['writes'][] = $name . '()';
					if ( ! $facts['first_write_line'] ) {
						$facts['first_write_line'] = $t[2];
					}
				} elseif ( preg_match( '/nonce|referer|verify|security|permission|capab/i', $name ) && ! preg_match( '/^wp_(create_nonce|nonce_field|nonce_url|nonce_ays)$/', $name ) ) {
					$facts['delegated'] = true;
					if ( '' === $facts['delegate'] ) {
						$pv               = tc_prev_sig( $tokens, $i );
						$facts['delegate'] = ( $pv >= 0 && T_OBJECT_OPERATOR === $tokens[ $pv ][0] ) ? '$this->' . $t[1] : $t[1];
					}
				}
			} elseif ( T_VARIABLE === $t[0] ) {
				if ( in_array( $t[1], array( '$_POST', '$_REQUEST', '$_GET' ), true ) ) {
					$nx = tc_next_sig( $tokens, $i );
					if ( $nx >= 0 && null === $tokens[ $nx ][0] && '[' === $tokens[ $nx ][1] ) {
						$facts['reads'] = true;
					}
				} elseif ( '$wpdb' === $t[1] ) {
					$op = tc_next_sig( $tokens, $i );
					$nm = tc_next_sig( $tokens, $op );
					if ( $op >= 0 && T_OBJECT_OPERATOR === $tokens[ $op ][0] && $nm >= 0
						&& in_array( strtolower( $tokens[ $nm ][1] ), array( 'query', 'insert', 'update', 'delete', 'replace' ), true ) ) {
						$facts['writes'][] = '$wpdb->' . $tokens[ $nm ][1] . '()';
						if ( ! $facts['first_write_line'] ) {
							$facts['first_write_line'] = $t[2];
						}
					}
				}
			}
		}
		return $facts;
	}

	/**
	 * Whether a capability function is called in $scope before token $before.
	 */
	protected function scope_has_capability_check( $tokens, $scope, $before ) {
		for ( $i = $scope['open']; $i < $before; $i++ ) {
			$t = $tokens[ $i ];
			if ( T_STRING === $t[0] && in_array( strtolower( $t[1] ), $this->cap_functions, true ) ) {
				$nx = tc_next_sig( $tokens, $i );
				if ( $nx >= 0 && null === $tokens[ $nx ][0] && '(' === $tokens[ $nx ][1] ) {
					return true;
				}
			}
		}
		return false;
	}

	protected function scopes_for( $path, $tokens ) {
		if ( ! isset( $this->scopes_cache[ $path ] ) ) {
			$this->scopes_cache[ $path ] = tc_token_scopes( $tokens );
		}
		return $this->scopes_cache[ $path ];
	}

	protected function scope_label( $scope ) {
		if ( 'closure' === $scope['type'] ) {
			return '{closure}';
		}
		return ( $scope['class'] ? $scope['class'] . '::' : '' ) . $scope['name'] . '()';
	}

	/**
	 * Literal string value of an argument range, or null.
	 */
	protected function literal( $tokens, $arg ) {
		$sig = array();
		for ( $i = $arg[0]; $i <= $arg[1]; $i++ ) {
			if ( ! tc_tok_is_ws( $tokens[ $i ] ) ) {
				$sig[] = $i;
			}
		}
		if ( 1 === count( $sig ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $sig[0] ][0] ) {
			return trim( $tokens[ $sig[0] ][1], '\'"' );
		}
		return null;
	}

	protected function envato_note( $quote ) {
		return sprintf(
			'<a href="%1$s">%2$s</a>: %3$s.',
			esc_url( self::ENVATO ),
			__( 'ThemeForest requirement', 'theme-check' ),
			esc_html( $quote )
		);
	}

	protected function add( $rule, $path, $line, $message, $docs ) {
		$severity        = tc_rule_severity( $rule, self::RULES[ $rule ], $path, true );
		$this->results[] = tc_error( $severity, $rule, $message, $path, $line, '', $docs, self::FIXES[ $rule ] );
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

$themechecks[] = new Nonce_Check();
