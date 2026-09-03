<?php
/**
 * Appearance → Review Queue admin page, its AJAX actions and the hand-off to Theme Check.
 *
 * @package Theme Check
 */

if ( ! class_exists( 'ETC_Queue_Admin' ) ) {

	class ETC_Queue_Admin {

		const SLUG  = 'themecheck-queue';
		const NONCE = 'etc-queue-admin';

		protected static $page_hook = '';

		public static function register() {
			add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
			add_action( 'admin_init', array( 'ETC_Queue_Store', 'schedule_cleanup' ) );
			add_action( ETC_Queue_Store::CRON_HOOK, array( 'ETC_Queue_Store', 'cron_cleanup' ) );

			foreach ( array( 'set_status', 'set_theme', 'delete', 'purge', 'retention', 'bulk' ) as $a ) {
				add_action( 'wp_ajax_etc_queue_' . $a, array( __CLASS__, 'ajax_' . $a ) );
			}

			// Hand-off to Theme Check: author username for {author}, status → in review.
			add_filter( 'themecheck_author_username', array( __CLASS__, 'author_for_run' ), 10, 3 );
			add_action( 'themecheck_run_from_queue', array( __CLASS__, 'mark_in_review' ), 10, 2 );
		}

		public static function add_page() {
			self::$page_hook = add_theme_page(
				__( 'Review Queue', 'theme-check' ),
				__( 'Review Queue', 'theme-check' ),
				'manage_options',
				self::SLUG,
				array( __CLASS__, 'render' )
			);
		}

		public static function assets( $hook ) {
			if ( ! self::$page_hook || $hook !== self::$page_hook ) {
				return;
			}
			$base = plugins_url( '', dirname( __FILE__ ) . '/../theme-check.php' );
			wp_enqueue_style( 'envato-theme-check-queue', $base . '/assets/queue.css', array(), ETC_VERSION );
			wp_enqueue_script( 'envato-theme-check-queue', $base . '/assets/queue.js', array(), ETC_VERSION, true );
			wp_localize_script(
				'envato-theme-check-queue',
				'etcQueue',
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( self::NONCE ),
					'importNonce' => wp_create_nonce( ETC_Queue_Importer::NONCE ),
					'known'       => ETC_Queue_Store::known_item_ids(),
					'schema'      => ETC_Queue_Importer::SCHEMA,
					'i18n'        => array(
						'invalid'      => __( 'Unrecognised payload.', 'theme-check' ),
						'importN'      => __( 'Import %d items', 'theme-check' ),
						'dup'          => __( 'already imported', 'theme-check' ),
						'confirmPurge' => __( 'Delete all items marked done that are older than the retention period?', 'theme-check' ),
						'confirmDel'   => __( 'Delete this item?', 'theme-check' ),
						'saved'        => __( 'Saved', 'theme-check' ),
						'failed'       => __( 'Request failed', 'theme-check' ),
						'copied'       => __( 'Copied', 'theme-check' ),
						'result'       => __( '%1$d imported, %2$d updated, %3$d unchanged, %4$d skipped.', 'theme-check' ),
					),
				)
			);
		}

		/**
		 * Bookmarklet code with the target URL baked in, or '' when the dist file is missing.
		 */
		public static function bookmarklet_code() {
			$file = dirname( __DIR__ ) . '/tools/bookmarklet/dist/bookmarklet.txt';
			if ( ! file_exists( $file ) ) {
				return '';
			}
			$code   = trim( file_get_contents( $file ) );
			$target = admin_url( 'themes.php?page=' . self::SLUG );
			return str_replace( array( '__ETC_TARGET__', rawurlencode( '__ETC_TARGET__' ) ), array( $target, rawurlencode( $target ) ), $code );
		}

		public static function render() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'theme-check' ) );
			}
			require_once __DIR__ . '/class-queue-list-table.php';
			$table = new ETC_Queue_List_Table();
			$table->prepare_items();
			$bookmarklet = self::bookmarklet_code();
			$retention   = ETC_Queue_Store::retention_days();
			?>
			<div class="wrap etc-queue">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Review Queue', 'theme-check' ); ?></h1>
				<hr class="wp-header-end">

				<div class="etc-import-widget">
					<h2><?php esc_html_e( 'Import from the ThemeForest proofing queue', 'theme-check' ); ?></h2>
					<p><?php esc_html_e( 'Drag the button below to your bookmarks bar. On the proofing page, click it: the visible items open here for review before importing. Nothing is sent to any server — the data travels inside your browser only.', 'theme-check' ); ?></p>
					<?php if ( $bookmarklet ) : ?>
						<p>
							<a class="button button-primary etc-bookmarklet" href="<?php echo esc_attr( $bookmarklet ); /* javascript: URL — esc_url() would strip it */ ?>" draggable="true" onclick="return false;"><?php esc_html_e( 'Import ThemeForest queue', 'theme-check' ); ?></a>
							<button type="button" class="button" id="etc-copy-bookmarklet"><?php esc_html_e( 'Copy code', 'theme-check' ); ?></button>
							<span id="etc-copy-status" role="status" aria-live="polite"></span>
						</p>
						<textarea id="etc-bookmarklet-code" class="large-text code" rows="2" readonly><?php echo esc_textarea( $bookmarklet ); ?></textarea>
					<?php else : ?>
						<p class="notice notice-warning inline"><?php esc_html_e( 'The bookmarklet has not been built (tools/bookmarklet/dist/bookmarklet.txt missing). Run `node build.js` in tools/bookmarklet.', 'theme-check' ); ?></p>
					<?php endif; ?>

					<details class="etc-paste">
						<summary><?php esc_html_e( 'Paste a captured payload instead', 'theme-check' ); ?></summary>
						<textarea id="etc-payload" class="large-text code" rows="5" placeholder='{"schema":"etc-queue/1", ...}'></textarea>
						<p><button type="button" class="button" id="etc-preview-payload"><?php esc_html_e( 'Preview', 'theme-check' ); ?></button></p>
					</details>

					<div id="etc-import-preview" hidden>
						<h3><?php esc_html_e( 'Items to import', 'theme-check' ); ?></h3>
						<table class="widefat striped etc-preview-table"><thead><tr>
							<th><?php esc_html_e( 'Item', 'theme-check' ); ?></th><th><?php esc_html_e( 'Author', 'theme-check' ); ?></th><th>ID</th><th><?php esc_html_e( 'Submitted', 'theme-check' ); ?></th><th></th>
						</tr></thead><tbody></tbody></table>
						<p><button type="button" class="button button-primary" id="etc-import-run"></button> <span id="etc-import-status" role="status" aria-live="polite"></span></p>
					</div>
				</div>

				<form method="get" class="etc-list-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<?php if ( isset( $_GET['status'] ) ) : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( $_GET['status'] ) ); ?>">
					<?php endif; ?>
					<?php $table->views(); ?>
					<?php $table->search_box( __( 'Search title, author or item id', 'theme-check' ), 'etc-queue' ); ?>
					<?php $table->display(); ?>
				</form>

				<div class="etc-retention">
					<h2><?php esc_html_e( 'Retention', 'theme-check' ); ?></h2>
					<p>
						<label for="etc-retention-days"><?php esc_html_e( 'Delete items marked done after', 'theme-check' ); ?></label>
						<input type="number" id="etc-retention-days" min="1" max="3650" value="<?php echo (int) $retention; ?>"> <?php esc_html_e( 'days', 'theme-check' ); ?>
						<button type="button" class="button" id="etc-retention-save"><?php esc_html_e( 'Save', 'theme-check' ); ?></button>
						<button type="button" class="button" id="etc-purge"><?php esc_html_e( 'Purge done items now', 'theme-check' ); ?></button>
						<span id="etc-retention-status" role="status" aria-live="polite"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Items are stored only in this local WordPress database (author usernames are personal data — keep retention short). Uninstalling the plugin removes everything.', 'theme-check' ); ?></p>
				</div>
			</div>
			<?php
		}

		/* ---------- hand-off ---------- */

		public static function author_for_run( $author, $queue_item, $theme_slug ) {
			if ( $queue_item ) {
				$post = get_post( $queue_item );
				if ( $post && ETC_Queue_CPT::POST_TYPE === $post->post_type ) {
					return (string) get_post_meta( $post->ID, '_etc_author', true );
				}
			}
			return $author;
		}

		public static function mark_in_review( $queue_item, $theme_slug ) {
			if ( $queue_item ) {
				$post = get_post( $queue_item );
				if ( $post && 'etc_pending' === $post->post_status ) {
					ETC_Queue_Store::set_status( $post->ID, 'etc_in_review' );
				}
			}
		}

		/* ---------- AJAX ---------- */

		protected static function guard() {
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
			}
		}

		public static function ajax_set_status() {
			self::guard();
			$ok = ETC_Queue_Store::set_status( absint( $_POST['post'] ), sanitize_key( $_POST['status'] ) );
			$ok ? wp_send_json_success( array( 'counts' => ETC_Queue_Store::counts() ) ) : wp_send_json_error( array( 'message' => 'invalid' ), 400 );
		}

		public static function ajax_set_theme() {
			self::guard();
			$ok = ETC_Queue_Store::set_theme_slug( absint( $_POST['post'] ), isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '' );
			$ok ? wp_send_json_success() : wp_send_json_error( array( 'message' => 'unknown theme' ), 400 );
		}

		public static function ajax_delete() {
			self::guard();
			$ok = ETC_Queue_Store::delete( absint( $_POST['post'] ) );
			$ok ? wp_send_json_success( array( 'counts' => ETC_Queue_Store::counts() ) ) : wp_send_json_error( array( 'message' => 'not found' ), 404 );
		}

		public static function ajax_bulk() {
			self::guard();
			$ids    = isset( $_POST['items'] ) ? array_map( 'absint', (array) $_POST['items'] ) : array();
			$action = isset( $_POST['bulk'] ) ? sanitize_key( $_POST['bulk'] ) : '';
			$n      = 0;
			foreach ( $ids as $id ) {
				if ( 'mark_done' === $action && ETC_Queue_Store::set_status( $id, 'etc_done' ) ) {
					$n++;
				} elseif ( 'delete' === $action && ETC_Queue_Store::delete( $id ) ) {
					$n++;
				}
			}
			wp_send_json_success( array( 'affected' => $n, 'counts' => ETC_Queue_Store::counts() ) );
		}

		public static function ajax_purge() {
			self::guard();
			wp_send_json_success( array( 'deleted' => ETC_Queue_Store::purge_older_than( ETC_Queue_Store::retention_days() ), 'counts' => ETC_Queue_Store::counts() ) );
		}

		public static function ajax_retention() {
			self::guard();
			wp_send_json_success( array( 'days' => ETC_Queue_Store::set_retention_days( absint( $_POST['days'] ) ) ) );
		}
	}
}
