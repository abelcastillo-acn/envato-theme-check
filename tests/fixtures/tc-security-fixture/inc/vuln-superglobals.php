<?php
/**
 * Superglobals fixture. Inert unless TC_FIXTURE is defined.
 *
 * @package TC_Security_Fixture
 */

if ( ! defined( 'TC_FIXTURE' ) ) {
	return;
}

function tcf_sg_raw_get() {
	$layout = $_GET['layout']; // EXPECT: superglobals/unsanitized
	return $layout;
}

function tcf_sg_unslash_only() {
	return wp_unslash( $_POST['name'] ); // EXPECT: superglobals/unsanitized
}

function tcf_sg_request_uri() {
	return $_SERVER['REQUEST_URI']; // EXPECT: superglobals/unsanitized
}

function tcf_sg_cookie() {
	return explode( ',', $_COOKIE['tcf_compare'] ); // EXPECT: superglobals/unsanitized
}

function tcf_sg_files() {
	return move_uploaded_file( $_FILES['import']['tmp_name'], WP_CONTENT_DIR . '/import.xml' ); // EXPECT: superglobals/unsanitized
}

function tcf_sg_coalesce() {
	return $_REQUEST['paged'] ?? 1; // EXPECT: superglobals/unsanitized
}

function tcf_sg_extract() {
	extract( $_POST ); // EXPECT: superglobals/extract
}

function tcf_sg_whole_array( $defaults ) {
	return wp_parse_args( $_POST, $defaults ); // EXPECT: superglobals/whole-array
}

function tcf_sg_shortcode_via_variable() {
	$header = isset( $_GET['custom_header_id'] ) ? $_GET['custom_header_id'] : 12; // EXPECT: superglobals/unsanitized
	return do_shortcode( '[tcf-header id="' . $header . '"]' ); // EXPECT: superglobals/shortcode-injection
}

function tcf_sg_shortcode_direct() {
	return do_shortcode( '[tcf-footer id="' . $_GET['footer'] . '"]' ); // EXPECT: superglobals/shortcode-injection, superglobals/unsanitized
}

function tcf_sg_foreach() {
	foreach ( $_GET as $key => $value ) { // EXPECT: superglobals/whole-array
		echo esc_html( $key );
	}
}
