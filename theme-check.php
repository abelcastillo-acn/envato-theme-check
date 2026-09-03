<?php
/*
Plugin Name: Envato Theme Check
Plugin URI: https://github.com/envato/Envato-Theme-Check
Description: Envato Theme Check is a modified fork of the original Theme Check by Otto42 with additional Themeforest specific WordPress checks.
Author: Scott Parry
Author URI: https://envato.com
Version: 2.2.0
Text Domain: theme-check
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

define( 'ETC_VERSION', '2.2.0' );

require_once __DIR__ . '/includes/class-message-template.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	include 'theme-check-cli.php';
}

class EnvatoThemeCheck  {

	/**
	 * Hook suffix of the Theme Check admin page.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	function __construct() {
		add_action( 'admin_init', array( $this, 'tc_i18n' ) );
		add_action( 'admin_menu', array( $this, 'themecheck_add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_assets' ) );
		ETC_Message_Template::register();
	}

	function tc_i18n() {
		load_plugin_textdomain( 'theme-check', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}

	/**
	 * Enqueue the plugin's style and script on its own admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	function load_assets( $hook_suffix ) {
		if ( ! $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}
		wp_enqueue_style( 'envato-theme-check', plugins_url( 'assets/style.css', __FILE__ ), array(), ETC_VERSION, 'screen' );
		wp_enqueue_script( 'envato-theme-check', plugins_url( 'assets/theme-check.js', __FILE__ ), array(), ETC_VERSION, true );
		wp_localize_script( 'envato-theme-check', 'etcConfig', ETC_Message_Template::js_config() );
	}

	function themecheck_add_page() {
		$this->page_hook = add_theme_page( 'Theme Check', 'Theme Check', 'manage_options', 'themecheck', array( $this, 'themecheck_do_page' ) );
	}

	function tc_add_headers( $extra_headers ) {
		$extra_headers = array( 'License', 'License URI', 'Template Version' );
		return $extra_headers;
	}

	function themecheck_do_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'theme-check' ) );
		}

		add_filter( 'extra_theme_headers', array( $this, 'tc_add_headers' ) );

		include 'checkbase.php';
		include 'main.php';
		include 'includes/results-renderer.php';

		?>
		<div id="theme-check" class="wrap">
		<h1><?php echo esc_html_x( 'Theme Check', 'title of the main page', 'theme-check' ); ?></h1>
		<div class="theme-check">
		<?php
		tc_form();
		if ( ! isset( $_POST['themename'] ) ) {
			tc_intro();
		}

		if ( isset( $_POST['themename'] ) ) {
			check_admin_referer( 'themecheck-nonce' );

			wp_raise_memory_limit();

			$queue_item = isset( $_POST['queue_item'] ) ? absint( $_POST['queue_item'] ) : 0;

			/**
			 * ThemeForest username of the theme author for the {author} placeholder.
			 * The review-queue integration hooks this to resolve it from the queue item.
			 *
			 * @param string $author     Username (empty by default).
			 * @param int    $queue_item Review queue item id, 0 when the run was not started from the queue.
			 * @param string $theme_slug Theme being checked.
			 */
			$author = apply_filters( 'themecheck_author_username', '', $queue_item, sanitize_text_field( wp_unslash( $_POST['themename'] ) ) );

			check_main( sanitize_text_field( wp_unslash( $_POST['themename'] ) ), $author );
		}
		?>
		</div> <!-- .theme-check-->
		</div>
		<?php
	}
}
new EnvatoThemeCheck();
