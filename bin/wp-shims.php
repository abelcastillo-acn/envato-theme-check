<?php
/**
 * Minimal WordPress function shims so checkbase.php and the security checks can run without WordPress.
 * Used only by bin/run-check.php. Every shim is guarded so this file is harmless if WordPress is loaded.
 *
 * @package Theme Check
 */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return ( 1 === (int) $number ) ? $single : $plural;
	}
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) {
		echo $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_html_x' ) ) {
	function esc_html_x( $text, $context, $domain = 'default' ) {
		return esc_html( $text );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) {
		return rtrim( (string) $s, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'sanitize_title_with_dashes' ) ) {
	function sanitize_title_with_dashes( $title ) {
		$title = strtolower( strip_tags( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9\s\-_]/', '', $title );
		$title = preg_replace( '/[\s_]+/', '-', $title );
		return trim( preg_replace( '/-+/', '-', $title ), '-' );
	}
}

// Hooks: a tiny filter registry so tc_rule_severity & co. work in tests.
if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['tc_shim_filters'] = array();
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['tc_shim_filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
		return true;
	}
	function apply_filters( $tag, $value ) {
		if ( empty( $GLOBALS['tc_shim_filters'][ $tag ] ) ) {
			return $value;
		}
		$args = func_get_args();
		array_shift( $args );
		ksort( $GLOBALS['tc_shim_filters'][ $tag ] );
		foreach ( $GLOBALS['tc_shim_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$args[0] = call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) );
			}
		}
		return $args[0];
	}
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
	function do_action( $tag ) {
		if ( empty( $GLOBALS['tc_shim_filters'][ $tag ] ) ) {
			return;
		}
		$args = func_get_args();
		array_shift( $args );
		foreach ( $GLOBALS['tc_shim_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) );
			}
		}
	}
}
