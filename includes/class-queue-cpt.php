<?php
/**
 * Private post type and statuses for imported ThemeForest proofing-queue items.
 *
 * @package Theme Check
 */

if ( ! class_exists( 'ETC_Queue_CPT' ) ) {

	class ETC_Queue_CPT {

		const POST_TYPE = 'etc_queue_item';

		const META_KEYS = array(
			'_etc_item_id',
			'_etc_author',
			'_etc_author_url',
			'_etc_item_url',
			'_etc_thumb_url',
			'_etc_preview_url',
			'_etc_category',
			'_etc_submitted_at',
			'_etc_queue_status',
			'_etc_imported_at',
			'_etc_last_seen_at',
			'_etc_theme_slug',
			'_etc_source_hash',
			'_etc_raw',
		);

		public static function statuses() {
			return array(
				'etc_pending'   => __( 'Pending', 'theme-check' ),
				'etc_in_review' => __( 'In review', 'theme-check' ),
				'etc_done'      => __( 'Done', 'theme-check' ),
			);
		}

		public static function register() {
			add_action( 'init', array( __CLASS__, 'register_types' ) );
		}

		public static function register_types() {
			register_post_type(
				self::POST_TYPE,
				array(
					'labels'              => array(
						'name'          => __( 'Review queue items', 'theme-check' ),
						'singular_name' => __( 'Review queue item', 'theme-check' ),
					),
					'public'              => false,
					'publicly_queryable'  => false,
					'show_ui'             => false,
					'show_in_menu'        => false,
					'show_in_rest'        => false,
					'exclude_from_search' => true,
					'rewrite'             => false,
					'query_var'           => false,
					'supports'            => array( 'title', 'editor', 'excerpt' ),
					'capability_type'     => 'post',
					'map_meta_cap'        => true,
				)
			);

			foreach ( self::statuses() as $status => $label ) {
				register_post_status(
					$status,
					array(
						'label'                     => $label,
						'internal'                  => true,
						'public'                    => false,
						'exclude_from_search'       => true,
						'show_in_admin_all_list'    => false,
						'show_in_admin_status_list' => false,
					)
				);
			}

			foreach ( self::META_KEYS as $key ) {
				register_post_meta(
					self::POST_TYPE,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => false,
						'sanitize_callback' => ( false !== strpos( $key, '_url' ) ) ? 'esc_url_raw' : 'sanitize_text_field',
						'auth_callback'     => function () {
							return current_user_can( 'manage_options' );
						},
					)
				);
			}
		}
	}
}
