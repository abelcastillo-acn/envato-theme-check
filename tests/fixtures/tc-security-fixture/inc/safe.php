<?php
/**
 * Safe patterns. Every construct in this file must produce ZERO findings from the security checks.
 *
 * @package TC_Security_Fixture
 */

function tcf_safe_prepared_variable( $url ) {
	global $wpdb;
	$query = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid = %s", $url );
	return $wpdb->get_var( $query );
}

function tcf_safe_table_variable( $id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'tcf_items';
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
}

function tcf_safe_constant_sql() {
	global $wpdb;
	return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'" );
}

function tcf_safe_like( $term ) {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( $term ) . '%';
	return $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", $like ) );
}

function tcf_safe_insert( $name ) {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'tcf_items', array( 'name' => $name ) );
}

function tcf_safe_reads() {
	$layout = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( $_GET['layout'] ) ) : 'grid';
	$layout = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';
	$page   = (int) $_GET['paged'];
	$ids    = array_map( 'sanitize_text_field', wp_unslash( $_POST['ids'] ) );
	$clean  = wc_clean( wp_unslash( $_POST['qty'] ) );
	$method = $_SERVER['REQUEST_METHOD'];
	$uri    = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['submit'] ) && 'list' === $_GET['view'] ) {
		return compact( 'layout', 'page', 'ids', 'clean', 'method', 'uri' );
	}

	switch ( $_GET['tab'] ) {
		case 'a':
			return 'a';
	}

	return array_key_exists( 'x', $_POST ) ? absint( $_POST['x'] ) : 0;
}

add_action( 'wp_ajax_tcf_safe_save', 'tcf_safe_save' );
function tcf_safe_save() {
	check_ajax_referer( 'tcf_safe', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}
	update_option( 'tcf_safe', sanitize_text_field( wp_unslash( $_POST['value'] ) ) );
	wp_send_json_success();
}

class TCF_Safe_Wizard {
	public function __construct() {
		// Capability checked before the handler is even registered: no capability finding expected.
		if ( current_user_can( 'manage_options' ) ) {
			add_action( 'wp_ajax_tcf_safe_wizard', array( $this, 'ajax_wizard' ) );
		}
	}

	public function ajax_wizard() {
		if ( ! check_ajax_referer( 'tcf_wizard', 'wpnonce', false ) ) {
			wp_send_json_error();
		}
		delete_transient( 'tcf_wizard_cache' );
		wp_send_json_success();
	}
}

add_action( 'wp_ajax_nopriv_tcf_safe_quick_view', 'tcf_safe_quick_view' );
function tcf_safe_quick_view() {
	$id = absint( $_GET['id'] );
	wp_send_json_success( get_the_title( $id ) );
}

add_action( 'admin_init', 'tcf_safe_form' );
function tcf_safe_form() {
	if ( ! isset( $_POST['tcf_save'] ) || ! wp_verify_nonce( $_POST['_tcf_nonce'], 'tcf_save' ) ) {
		return;
	}
	update_option( 'tcf_layout', sanitize_key( $_POST['tcf_layout'] ) );
}

function tcf_safe_shortcode() {
	$header = isset( $_GET['custom_header_id'] ) ? absint( wp_unslash( $_GET['custom_header_id'] ) ) : 12;
	return do_shortcode( '[tcf-header id="' . $header . '"]' ) . do_shortcode( '[tcf-footer id="' . absint( $_GET['footer'] ) . '"]' );
}

function tcf_safe_commented() {
	// $wpdb->query( "DELETE FROM wp_posts WHERE ID = $id" );
	// $x = $_GET['debug'];
	return function_exists( 'mysqli_connect' );
}
