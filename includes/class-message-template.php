<?php
/**
 * Persisted, editable template for the plain-text message to the theme author.
 *
 * @package Theme Check
 */

if ( ! class_exists( 'ETC_Message_Template' ) ) {

	class ETC_Message_Template {

		const OPTION = 'envato_theme_check_message_template';
		const NONCE  = 'etc-template';

		public static function register() {
			add_action( 'wp_ajax_etc_save_message_template', array( __CLASS__, 'ajax_save' ) );
			add_action( 'wp_ajax_etc_reset_message_template', array( __CLASS__, 'ajax_reset' ) );
		}

		public static function defaults() {
			return array(
				'greeting'           => 'Hi {author},',
				'intro'              => 'Thanks for submitting {theme_name} {theme_version} to ThemeForest. Our automated review found the following issues that need to be addressed before the item can be approved:',
				'notes_heading'      => 'Reviewer notes:',
				'footer'             => "Once these are resolved, please resubmit and we will take another look. Thanks for your patience.\n\nThe ThemeForest Review Team",
				'default_included'   => array( 'required', 'warning', 'recommended' ),
				'evidence_max_lines' => 5,
				'show_file_line'     => true,
				'version'            => 1,
			);
		}

		public static function get() {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			return self::sanitize( array_merge( self::defaults(), $saved ) );
		}

		public static function save( $data ) {
			$clean = self::sanitize( array_merge( self::defaults(), (array) $data ) );
			update_option( self::OPTION, $clean, false );
			return $clean;
		}

		public static function reset() {
			delete_option( self::OPTION );
			return self::defaults();
		}

		public static function sanitize( $data ) {
			$defaults = self::defaults();
			$out      = array();
			foreach ( array( 'greeting', 'intro', 'notes_heading', 'footer' ) as $k ) {
				$out[ $k ] = isset( $data[ $k ] ) ? sanitize_textarea_field( (string) $data[ $k ] ) : $defaults[ $k ];
			}
			$sevs                    = array( 'required', 'warning', 'recommended', 'info' );
			$inc                     = isset( $data['default_included'] ) ? (array) $data['default_included'] : $defaults['default_included'];
			$out['default_included'] = array_values( array_intersect( $sevs, array_map( 'strtolower', array_map( 'strval', $inc ) ) ) );
			$out['evidence_max_lines'] = isset( $data['evidence_max_lines'] ) ? max( 0, min( 20, absint( $data['evidence_max_lines'] ) ) ) : $defaults['evidence_max_lines'];
			$out['show_file_line']     = isset( $data['show_file_line'] ) ? rest_sanitize_boolean( $data['show_file_line'] ) : $defaults['show_file_line'];
			$out['version']            = 1;
			return $out;
		}

		public static function placeholders() {
			return array(
				'{author}'            => __( 'ThemeForest username of the author', 'theme-check' ),
				'{theme_name}'        => __( 'Theme name from style.css', 'theme-check' ),
				'{theme_version}'     => __( 'Theme version from style.css', 'theme-check' ),
				'{date}'              => __( 'Date of the run (site date format)', 'theme-check' ),
				'{required_count}'    => __( 'Number of selected REQUIRED findings', 'theme-check' ),
				'{warning_count}'     => __( 'Number of selected WARNING findings', 'theme-check' ),
				'{recommended_count}' => __( 'Number of selected RECOMMENDED findings', 'theme-check' ),
				'{info_count}'        => __( 'Number of selected INFO findings', 'theme-check' ),
				'{selected_count}'    => __( 'Total number of selected findings', 'theme-check' ),
				'{reviewer_notes}'    => __( 'The reviewer notes text', 'theme-check' ),
				'{findings}'          => __( 'The formatted findings block (inserted automatically after the intro when not used in a field)', 'theme-check' ),
			);
		}

		public static function js_config() {
			return array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'template'     => self::get(),
				'defaults'     => self::defaults(),
				'placeholders' => self::placeholders(),
				'i18n'         => array(
					'copied'        => __( 'Copied', 'theme-check' ),
					'copyFailed'    => __( 'Copy failed — the text is selected, press Ctrl/Cmd+C.', 'theme-check' ),
					'saved'         => __( 'Template saved.', 'theme-check' ),
					'resetDone'     => __( 'Template reset to defaults.', 'theme-check' ),
					'saveFailed'    => __( 'Could not save the template.', 'theme-check' ),
					'status'        => __( '%1$s findings selected · %2$s characters', 'theme-check' ),
					'authorMissing' => __( 'Enter the author\'s ThemeForest username.', 'theme-check' ),
				),
			);
		}

		protected static function guard() {
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
			}
		}

		public static function ajax_save() {
			self::guard();
			$raw = isset( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : array(); // sanitized in sanitize().
			if ( is_string( $raw ) ) {
				$raw = json_decode( $raw, true );
			}
			wp_send_json_success( self::save( is_array( $raw ) ? $raw : array() ) );
		}

		public static function ajax_reset() {
			self::guard();
			wp_send_json_success( self::reset() );
		}
	}
}
