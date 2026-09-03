<?php
/**
 * SQL fixture. Inert unless TC_FIXTURE is defined.
 *
 * @package TC_Security_Fixture
 */

if ( ! defined( 'TC_FIXTURE' ) ) {
	return;
}

function tcf_sql_interpolated( $id ) {
	global $wpdb;
	return $wpdb->get_var( "SELECT post_title FROM {$wpdb->posts} WHERE ID = $id" ); // EXPECT: sql/concat
}

function tcf_sql_concat( $id ) {
	global $wpdb;
	$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = " . $id ); // EXPECT: sql/concat
}

function tcf_sql_sprintf( $type ) {
	global $wpdb;
	return $wpdb->get_results( sprintf( "SELECT ID FROM {$wpdb->posts} WHERE post_type = '%s'", $type ) ); // EXPECT: sql/concat
}

function tcf_sql_heredoc( $slug ) {
	global $wpdb;
	$sql = <<<SQL
SELECT ID FROM {$wpdb->posts} WHERE post_name = '$slug'
SQL;
	return $wpdb->get_row( $sql ); // EXPECT: sql/variable-arg-concat
}

function tcf_sql_esc_sql( $orderby ) {
	global $wpdb;
	return $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} ORDER BY " . esc_sql( $orderby ) ); // EXPECT: sql/esc-sql-concat
}

function tcf_sql_variable_concat( $id ) {
	global $wpdb;
	$sql = "DELETE FROM {$wpdb->posts} WHERE ID = " . $id;
	$wpdb->query( $sql ); // EXPECT: sql/variable-arg-concat
}

function tcf_sql_unknown_variable( $statements ) {
	global $wpdb;
	foreach ( $statements as $bit ) {
		$wpdb->query( $bit ); // EXPECT: sql/variable-arg
	}
}

function tcf_sql_prepare_no_placeholders() {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts}" ) ); // EXPECT: sql/prepare-no-placeholders
}

function tcf_sql_prepare_interpolated( $order ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s ORDER BY $order", 'publish' ) ); // EXPECT: sql/prepare-interpolated
}

function tcf_sql_prepare_variable_format( $order ) {
	global $wpdb;
	$format = "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s ORDER BY " . $order;
	return $wpdb->get_results( $wpdb->prepare( $format, 'publish' ) ); // EXPECT: sql/prepare-variable-format
}

function tcf_sql_raw_mysqli() {
	$link = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // EXPECT: sql/raw-driver
	return mysqli_query( $link, 'SELECT 1' ); // EXPECT: sql/raw-driver
}

function tcf_sql_raw_pdo() {
	return new PDO( 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD ); // EXPECT: sql/raw-driver
}
