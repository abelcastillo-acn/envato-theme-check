<?php
/**
 * Validates capture payloads (schema etc-queue/1) and imports them into the review queue.
 * Shared by the admin page (AJAX), WP-CLI and the REST API.
 *
 * @package Theme Check
 */

if ( ! class_exists( 'ETC_Queue_Importer' ) ) {

	class ETC_Queue_Importer {

		const SCHEMA    = 'etc-queue/1';
		const MAX_ITEMS = 200;
		const MAX_FIELD = 2000;
		const MAX_RAW   = 2048;
		const NONCE     = 'etc-queue-import';

		public static function register() {
			add_action( 'wp_ajax_etc_queue_import', array( __CLASS__, 'ajax_import' ) );
		}

		public static function allowed_hosts() {
			return apply_filters(
				'etc_queue_allowed_hosts',
				array( 'themeforest.net', 'envato.com', 'envatousercontent.com', 'envato-static.com' )
			);
		}

		protected static function host_allowed( $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				return false;
			}
			foreach ( self::allowed_hosts() as $allowed ) {
				$allowed = strtolower( $allowed );
				if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * @param array $payload Decoded JSON.
		 * @return array array( 'items' => array, 'skipped' => array, 'error' => string|null )
		 */
		public static function validate_payload( $payload ) {
			$out = array( 'items' => array(), 'skipped' => array(), 'error' => null );
			if ( ! is_array( $payload ) || ! isset( $payload['schema'] ) || self::SCHEMA !== $payload['schema'] ) {
				$out['error'] = 'Unrecognised payload: expected schema ' . self::SCHEMA;
				return $out;
			}
			if ( empty( $payload['items'] ) || ! is_array( $payload['items'] ) ) {
				$out['error'] = 'Payload contains no items';
				return $out;
			}
			foreach ( array_values( $payload['items'] ) as $i => $raw ) {
				if ( $i >= self::MAX_ITEMS ) {
					$out['skipped'][] = array( 'index' => $i, 'item_id' => '', 'reason' => 'over the ' . self::MAX_ITEMS . ' items limit' );
					continue;
				}
				$res = self::validate_item( $raw );
				if ( isset( $res['reason'] ) ) {
					$out['skipped'][] = array( 'index' => $i, 'item_id' => isset( $raw['item_id'] ) ? sanitize_text_field( (string) $raw['item_id'] ) : '', 'reason' => $res['reason'] );
					continue;
				}
				$out['items'][] = $res;
			}
			return $out;
		}

		protected static function validate_item( $raw ) {
			if ( ! is_array( $raw ) ) {
				return array( 'reason' => 'item is not an object' );
			}
			$item_id = isset( $raw['item_id'] ) ? trim( (string) $raw['item_id'] ) : '';
			if ( ! preg_match( '/^\d{1,12}$/', $item_id ) ) {
				return array( 'reason' => 'item_id invalid' );
			}
			$item = array( 'item_id' => $item_id );

			foreach ( array( 'title', 'author', 'category', 'submitted_at', 'queue_status' ) as $f ) {
				$item[ $f ] = isset( $raw[ $f ] ) ? mb_substr( sanitize_text_field( (string) $raw[ $f ] ), 0, self::MAX_FIELD ) : '';
			}
			$item['excerpt']     = isset( $raw['excerpt'] ) ? mb_substr( sanitize_textarea_field( (string) $raw['excerpt'] ), 0, self::MAX_FIELD ) : '';
			$item['description'] = isset( $raw['description'] ) ? mb_substr( sanitize_textarea_field( (string) $raw['description'] ), 0, self::MAX_FIELD ) : $item['excerpt'];
			if ( '' === $item['title'] ) {
				$item['title'] = 'Item ' . $item_id;
			}

			foreach ( array( 'author_url', 'item_url', 'thumb_url', 'preview_url' ) as $f ) {
				$url = isset( $raw[ $f ] ) ? trim( (string) $raw[ $f ] ) : '';
				if ( '' === $url ) {
					$item[ $f ] = '';
					continue;
				}
				$valid = wp_http_validate_url( $url );
				if ( ! $valid || ! self::host_allowed( $valid ) ) {
					return array( 'reason' => $f . ' host not allowed' );
				}
				$item[ $f ] = esc_url_raw( $valid );
			}

			$item['raw'] = '';
			if ( isset( $raw['raw'] ) && is_array( $raw['raw'] ) ) {
				$json = wp_json_encode( $raw['raw'] );
				if ( false !== $json && strlen( $json ) <= self::MAX_RAW ) {
					$item['raw'] = sanitize_textarea_field( $json );
				}
			}
			return $item;
		}

		/**
		 * @return array imported, updated, unchanged, skipped[], items[] or error.
		 */
		public static function import( $payload ) {
			$v = self::validate_payload( $payload );
			if ( $v['error'] ) {
				return array( 'error' => $v['error'] );
			}
			$result = array( 'imported' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => $v['skipped'], 'items' => array() );
			foreach ( $v['items'] as $item ) {
				$r = ETC_Queue_Store::upsert( $item );
				if ( ! empty( $r['error'] ) ) {
					$result['skipped'][] = array( 'index' => -1, 'item_id' => $item['item_id'], 'reason' => $r['error'] );
					continue;
				}
				if ( $r['created'] ) {
					$result['imported']++;
				} elseif ( $r['changed'] ) {
					$result['updated']++;
				} else {
					$result['unchanged']++;
				}
				$result['items'][] = array(
					'item_id' => $item['item_id'],
					'post_id' => $r['post_id'],
					'status'  => get_post_status( $r['post_id'] ),
					'created' => $r['created'],
				);
			}
			return $result;
		}

		public static function ajax_import() {
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
			}
			$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // validated field-by-field in validate_payload().
			$payload = json_decode( (string) $raw, true );
			if ( ! is_array( $payload ) ) {
				wp_send_json_error( array( 'message' => 'Unrecognised payload' ), 400 );
			}
			$result = self::import( $payload );
			if ( isset( $result['error'] ) ) {
				wp_send_json_error( array( 'message' => $result['error'] ), 400 );
			}
			wp_send_json_success( $result );
		}
	}
}
