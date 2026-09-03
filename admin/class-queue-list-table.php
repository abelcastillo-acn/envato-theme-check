<?php
/**
 * Review Queue list table.
 *
 * @package Theme Check
 */

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ETC_Queue_List_Table extends WP_List_Table {

	protected $counts = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'etc_queue_item',
				'plural'   => 'etc_queue_items',
				'ajax'     => false,
				'screen'   => 'appearance_page_themecheck-queue',
			)
		);
		$this->counts = ETC_Queue_Store::counts();
	}

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'thumb'     => '',
			'title'     => __( 'Item', 'theme-check' ),
			'author'    => __( 'Author', 'theme-check' ),
			'category'  => __( 'Category', 'theme-check' ),
			'submitted' => __( 'Submitted', 'theme-check' ),
			'imported'  => __( 'Imported', 'theme-check' ),
			'status'    => __( 'Status', 'theme-check' ),
			'theme'     => __( 'Installed theme', 'theme-check' ),
			'actions'   => __( 'Actions', 'theme-check' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'     => array( 'title', false ),
			'submitted' => array( 'date', true ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'mark_done' => __( 'Mark done', 'theme-check' ),
			'delete'    => __( 'Delete', 'theme-check' ),
		);
	}

	protected function get_views() {
		$current = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$base    = admin_url( 'themes.php?page=themecheck-queue' );
		$views   = array();
		$labels  = array( 'all' => __( 'All', 'theme-check' ) ) + ETC_Queue_CPT::statuses();
		foreach ( $labels as $key => $label ) {
			$url     = ( 'all' === $key ) ? $base : add_query_arg( 'status', $key, $base );
			$class   = ( $current === $key ) ? ' class="current"' : '';
			$views[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), isset( $this->counts[ $key ] ) ? $this->counts[ $key ] : 0 );
		}
		return $views;
	}

	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) && 'title' === $_GET['orderby'] ? 'title' : 'date';
		$order    = isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'      => ETC_Queue_CPT::POST_TYPE,
			'post_status'    => isset( ETC_Queue_CPT::statuses()[ $status ] ) ? $status : array_keys( ETC_Queue_CPT::statuses() ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		if ( '' !== $search ) {
			if ( preg_match( '/^\d+$/', $search ) ) {
				$args['meta_query'] = array( array( 'key' => '_etc_item_id', 'value' => $search ) );
			} else {
				// Title/content match OR author match.
				$title_ids  = get_posts( array_merge( $args, array( 's' => $search, 'fields' => 'ids', 'posts_per_page' => -1, 'paged' => 1 ) ) );
				$author_ids = get_posts( array_merge( $args, array( 'fields' => 'ids', 'posts_per_page' => -1, 'paged' => 1, 'meta_query' => array( array( 'key' => '_etc_author', 'value' => $search, 'compare' => 'LIKE' ) ) ) ) );
				$ids        = array_unique( array_merge( $title_ids, $author_ids ) );
				$args['post__in'] = $ids ? $ids : array( 0 );
			}
		}

		$q           = new WP_Query( $args );
		$this->items = array_map( array( 'ETC_Queue_Store', 'to_item' ), $q->posts );
		$this->set_pagination_args(
			array(
				'total_items' => (int) $q->found_posts,
				'per_page'    => $per_page,
				'total_pages' => (int) $q->max_num_pages,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	public function no_items() {
		esc_html_e( 'No queue items yet. Use the bookmarklet on the ThemeForest proofing page, or paste a captured payload above.', 'theme-check' );
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="items[]" value="%d" />', $item['post_id'] );
	}

	protected function column_thumb( $item ) {
		if ( empty( $item['thumb_url'] ) ) {
			return '<span class="etc-thumb etc-thumb-empty" aria-hidden="true"></span>';
		}
		return sprintf( '<img class="etc-thumb" src="%s" alt="" width="60" loading="lazy" referrerpolicy="no-referrer" />', esc_url( $item['thumb_url'] ) );
	}

	protected function column_title( $item ) {
		$title = $item['item_url']
			? sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $item['item_url'] ), esc_html( $item['title'] ) )
			: esc_html( $item['title'] );
		$meta  = sprintf( '<span class="etc-item-id">#%s</span>', esc_html( $item['item_id'] ) );
		if ( $item['preview_url'] ) {
			$meta .= sprintf( ' · <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $item['preview_url'] ), esc_html__( 'Preview', 'theme-check' ) );
		}
		return '<strong>' . $title . '</strong><br><small>' . $meta . '</small>';
	}

	protected function column_author( $item ) {
		if ( '' === $item['author'] ) {
			return '—';
		}
		return $item['author_url']
			? sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $item['author_url'] ), esc_html( $item['author'] ) )
			: esc_html( $item['author'] );
	}

	protected function column_category( $item ) {
		return $item['category'] ? esc_html( $item['category'] ) : '—';
	}

	protected function column_submitted( $item ) {
		return $item['submitted'] ? esc_html( get_date_from_gmt( $item['submitted'], get_option( 'date_format' ) ) ) : '—';
	}

	protected function column_imported( $item ) {
		return $item['imported_at'] ? esc_html( get_date_from_gmt( $item['imported_at'], get_option( 'date_format' ) ) ) : '—';
	}

	protected function column_status( $item ) {
		$out = sprintf( '<select class="etc-status" data-post="%d" aria-label="%s">', $item['post_id'], esc_attr__( 'Status', 'theme-check' ) );
		foreach ( ETC_Queue_CPT::statuses() as $status => $label ) {
			$out .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $status ), selected( $item['status'], $status, false ), esc_html( $label ) );
		}
		return $out . '</select>';
	}

	protected function column_theme( $item ) {
		$out = sprintf( '<select class="etc-theme" data-post="%d" aria-label="%s"><option value="">%s</option>', $item['post_id'], esc_attr__( 'Installed theme', 'theme-check' ), esc_html__( '— not mapped —', 'theme-check' ) );
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $item['theme_slug'], $slug, false ), esc_html( $theme->get( 'Name' ) ) );
		}
		return $out . '</select>';
	}

	protected function column_actions( $item ) {
		$check_url = add_query_arg(
			array( 'page' => 'themecheck', 'themename' => $item['theme_slug'], 'queue_item' => $item['post_id'] ),
			admin_url( 'themes.php' )
		);
		$disabled  = '' === $item['theme_slug'];
		$out       = sprintf(
			'<a class="button button-small etc-check%s" href="%s"%s>%s</a> ',
			$disabled ? ' disabled' : '',
			$disabled ? '#' : esc_url( $check_url ),
			$disabled ? ' aria-disabled="true" title="' . esc_attr__( 'Select an installed theme first', 'theme-check' ) . '"' : '',
			esc_html__( 'Check this theme', 'theme-check' )
		);
		$out .= sprintf( '<button type="button" class="button button-small etc-done" data-post="%d">%s</button> ', $item['post_id'], esc_html__( 'Mark done', 'theme-check' ) );
		$out .= sprintf( '<button type="button" class="button-link-delete etc-delete" data-post="%d">%s</button>', $item['post_id'], esc_html__( 'Delete', 'theme-check' ) );
		return $out;
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
}
