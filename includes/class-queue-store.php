<?php
/**
 * CRUD for review-queue items (CPT-backed). Shared by the admin page, the importer, WP-CLI and the REST API.
 *
 * @package Theme Check
 */

if ( ! class_exists( 'ETC_Queue_Store' ) ) {

	class ETC_Queue_Store {

		const RETENTION_OPTION = 'etc_queue_retention_days';
		const CRON_HOOK        = 'etc_queue_cleanup';

		/**
		 * Item fields copied from the capture payload into post meta.
		 */
		const ITEM_FIELDS = array( 'item_id', 'author', 'author_url', 'item_url', 'thumb_url', 'preview_url', 'category', 'submitted_at', 'queue_status' );

		public static function retention_days() {
			$days = absint( get_option( self::RETENTION_OPTION, 30 ) );
			return $days > 0 ? $days : 30;
		}

		public static function set_retention_days( $days ) {
			$days = max( 1, min( 3650, absint( $days ) ) );
			update_option( self::RETENTION_OPTION, $days, false );
			return $days;
		}

		/**
		 * @return WP_Post|null
		 */
		public static function find_by_item_id( $item_id ) {
			$q = new WP_Query(
				array(
					'post_type'              => ETC_Queue_CPT::POST_TYPE,
					'post_status'            => array_keys( ETC_Queue_CPT::statuses() ),
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_key'               => '_etc_item_id',
					'meta_value'             => (string) $item_id,
				)
			);
			return $q->have_posts() ? $q->posts[0] : null;
		}

		/**
		 * Create or update an item.
		 *
		 * @param array $item Validated item (see ETC_Queue_Importer::validate_payload()).
		 * @return array array( 'post_id' => int, 'created' => bool, 'changed' => bool )
		 */
		public static function upsert( array $item ) {
			$now      = current_time( 'mysql', true );
			$hash     = self::hash( $item );
			$existing = self::find_by_item_id( $item['item_id'] );

			$postarr = array(
				'post_type'    => ETC_Queue_CPT::POST_TYPE,
				'post_title'   => $item['title'],
				'post_content' => isset( $item['description'] ) ? $item['description'] : $item['excerpt'],
				'post_excerpt' => $item['excerpt'],
			);
			if ( ! empty( $item['submitted_at'] ) && strtotime( $item['submitted_at'] ) ) {
				$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $item['submitted_at'] ) );
				$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
			}

			if ( $existing ) {
				$changed        = ( get_post_meta( $existing->ID, '_etc_source_hash', true ) !== $hash );
				$postarr['ID']  = $existing->ID;
				$postarr['post_status'] = $existing->post_status;
				if ( $changed ) {
					wp_update_post( wp_slash( $postarr ) );
				}
				$post_id = $existing->ID;
				$created = false;
			} else {
				$postarr['post_status'] = 'etc_pending';
				$post_id                = wp_insert_post( wp_slash( $postarr ), true );
				if ( is_wp_error( $post_id ) ) {
					return array( 'post_id' => 0, 'created' => false, 'changed' => false, 'error' => $post_id->get_error_message() );
				}
				$created = true;
				$changed = true;
				update_post_meta( $post_id, '_etc_imported_at', $now );
				$guess = self::guess_theme_slug( $item['title'] );
				if ( $guess ) {
					update_post_meta( $post_id, '_etc_theme_slug', $guess );
				}
			}

			if ( $changed ) {
				foreach ( self::ITEM_FIELDS as $f ) {
					update_post_meta( $post_id, '_etc_' . $f, isset( $item[ $f ] ) ? $item[ $f ] : '' );
				}
				update_post_meta( $post_id, '_etc_raw', isset( $item['raw'] ) ? $item['raw'] : '' );
				update_post_meta( $post_id, '_etc_source_hash', $hash );
			}
			update_post_meta( $post_id, '_etc_last_seen_at', $now );

			return array( 'post_id' => (int) $post_id, 'created' => $created, 'changed' => $changed );
		}

		protected static function hash( array $item ) {
			$subset = array();
			foreach ( array_merge( array( 'title', 'excerpt' ), self::ITEM_FIELDS ) as $f ) {
				$subset[ $f ] = isset( $item[ $f ] ) ? $item[ $f ] : '';
			}
			return sha1( wp_json_encode( $subset ) );
		}

		public static function set_status( $post_id, $status ) {
			if ( ! isset( ETC_Queue_CPT::statuses()[ $status ] ) ) {
				return false;
			}
			$post = get_post( $post_id );
			if ( ! $post || ETC_Queue_CPT::POST_TYPE !== $post->post_type ) {
				return false;
			}
			if ( $post->post_status === $status ) {
				return true;
			}
			return ! is_wp_error( wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ), true ) );
		}

		public static function set_theme_slug( $post_id, $slug ) {
			$slug = sanitize_text_field( $slug );
			if ( '' !== $slug && ! wp_get_theme( $slug )->exists() ) {
				return false;
			}
			update_post_meta( $post_id, '_etc_theme_slug', $slug );
			return true;
		}

		/**
		 * Map an item title to an installed theme slug ("Timbero - Parallax ..." -> "timbero").
		 */
		public static function guess_theme_slug( $title ) {
			$head = preg_split( '/\s+[-–—|:]\s+/u', (string) $title, 2 );
			$head = sanitize_title( trim( $head[0] ) );
			if ( '' === $head ) {
				return '';
			}
			foreach ( wp_get_themes() as $slug => $theme ) {
				if ( $head === $slug || $head === sanitize_title( $theme->get( 'Name' ) ) ) {
					return $slug;
				}
			}
			return '';
		}

		/**
		 * Delete items with the given statuses whose last modification is older than $days.
		 *
		 * @return int Number of deleted items.
		 */
		public static function purge_older_than( $days, $statuses = array( 'etc_done' ) ) {
			$q = new WP_Query(
				array(
					'post_type'      => ETC_Queue_CPT::POST_TYPE,
					'post_status'    => $statuses,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'date_query'     => array(
						array(
							'column' => 'post_modified_gmt',
							'before' => gmdate( 'Y-m-d H:i:s', time() - absint( $days ) * DAY_IN_SECONDS ),
						),
					),
				)
			);
			$n = 0;
			foreach ( $q->posts as $id ) {
				if ( wp_delete_post( $id, true ) ) {
					$n++;
				}
			}
			return $n;
		}

		public static function delete( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ETC_Queue_CPT::POST_TYPE !== $post->post_type ) {
				return false;
			}
			return (bool) wp_delete_post( $post_id, true );
		}

		public static function counts() {
			// Bypass the per-request counts cache so AJAX responses reflect deletions/status changes just made.
			wp_cache_delete( _count_posts_cache_key( ETC_Queue_CPT::POST_TYPE ), 'counts' );
			$c   = wp_count_posts( ETC_Queue_CPT::POST_TYPE );
			$out = array( 'all' => 0 );
			foreach ( array_keys( ETC_Queue_CPT::statuses() ) as $s ) {
				$out[ $s ] = isset( $c->$s ) ? (int) $c->$s : 0;
				$out['all'] += $out[ $s ];
			}
			return $out;
		}

		/**
		 * All stored item ids (for duplicate marking in the import preview).
		 */
		public static function known_item_ids() {
			global $wpdb;
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s",
					'_etc_item_id',
					ETC_Queue_CPT::POST_TYPE
				)
			);
		}

		/**
		 * Flatten a post into an item array.
		 */
		public static function to_item( $post ) {
			$post = get_post( $post );
			$item = array(
				'post_id'      => $post->ID,
				'title'        => $post->post_title,
				'excerpt'      => $post->post_excerpt,
				'description'  => $post->post_content,
				'status'       => $post->post_status,
				'submitted'    => $post->post_date_gmt,
				'modified'     => $post->post_modified_gmt,
			);
			foreach ( array_merge( self::ITEM_FIELDS, array( 'imported_at', 'last_seen_at', 'theme_slug' ) ) as $f ) {
				$item[ $f ] = (string) get_post_meta( $post->ID, '_etc_' . $f, true );
			}
			return $item;
		}

		public static function schedule_cleanup() {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			}
		}

		public static function cron_cleanup() {
			self::purge_older_than( self::retention_days() );
		}
	}
}
