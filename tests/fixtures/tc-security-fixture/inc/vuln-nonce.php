<?php
/**
 * Nonce / capability fixture. Inert unless TC_FIXTURE is defined.
 *
 * @package TC_Security_Fixture
 */

if ( ! defined( 'TC_FIXTURE' ) ) {
	return;
}

add_action( 'wp_ajax_tcf_import_demo', 'tcf_import_demo' );
function tcf_import_demo() { // EXPECT: nonce/ajax-missing, nonce/capability-missing
	$demo = sanitize_key( $_POST['demo'] );
	update_option( 'tcf_demo', $demo );
	wp_send_json_success();
}

add_action( 'wp_ajax_tcf_save_options', 'tcf_save_options' );
function tcf_save_options() { // EXPECT: nonce/capability-missing
	check_ajax_referer( 'tcf_options', 'nonce' );
	update_option( 'tcf_options', array_map( 'sanitize_text_field', wp_unslash( $_POST['options'] ) ) );
	wp_send_json_success();
}

add_action( 'wp_ajax_nopriv_tcf_wishlist', 'tcf_wishlist' );
function tcf_wishlist() { // EXPECT: nonce/nopriv-state-change
	$ids = array_map( 'absint', (array) $_POST['ids'] );
	setcookie( 'tcf_wishlist', implode( ',', $ids ), time() + DAY_IN_SECONDS, '/' );
	wp_send_json_success();
}

add_action( 'admin_init', 'tcf_handle_options_form' );
function tcf_handle_options_form() { // EXPECT: nonce/form-handler
	if ( isset( $_POST['tcf_save'] ) ) {
		update_option( 'tcf_layout', sanitize_key( $_POST['tcf_layout'] ) );
	}
}

add_action( 'wp_ajax_tcf_dismiss', function () { // EXPECT: nonce/ajax-missing, nonce/capability-missing
	update_user_meta( get_current_user_id(), 'tcf_dismissed', 1 );
	wp_die();
} );

class TCF_Ajax {
	protected $action = 'tcf_dynamic';

	public function __construct() {
		add_action( 'wp_ajax_tcf_reset', array( $this, 'reset' ) );
		add_action( 'wp_ajax_tcf_delegated', array( $this, 'delegated' ) );
		add_action( 'wp_ajax_' . $this->action, array( $this, 'dynamic' ) ); // EXPECT-FILE: nonce/unresolved
	}

	public function reset() { // EXPECT: nonce/ajax-missing, nonce/capability-missing
		delete_option( 'tcf_options' );
		wp_send_json_success();
	}

	public function delegated() { // EXPECT: nonce/delegated
		$this->verify_request();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		update_option( 'tcf_flag', 1 );
	}

	protected function verify_request() {
		check_ajax_referer( 'tcf', 'nonce' );
	}

	public function dynamic() {
		wp_send_json_success();
	}
}
