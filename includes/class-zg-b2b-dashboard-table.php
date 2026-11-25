<?php
/**
 * Seznam B2B zákazníků (WP_List_Table) pro B2B Dashboard.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ZG_B2B_Dashboard_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'zakaznik',
			'plural'   => 'zakaznici',
			'ajax'     => false,
		) );
	}

	public static function status_badge( $status ) {
		$labels = ZG_B2B_Approval::statuses() + array( 'none' => 'Bez stavu' );
		return '<span class="zg-b2b-st zg-b2b-st--' . esc_attr( $status ) . '">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</span>';
	}

	public function get_columns() {
		return array(
			'customer'   => 'Zákazník',
			'status'     => 'Stav',
			'companies'  => 'Firmy',
			'addresses'  => 'Doručovací adresy',
			'orders'     => 'Objednávky',
			'invoice'    => 'Na fakturu',
			'registered' => 'Registrace',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'customer'   => array( 'display_name', false ),
			'registered' => array( 'registered', true ),
		);
	}

	private function current_view() {
		$v = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $v, array( 'all', 'pending', 'companies', 'approved', 'rejected' ), true ) ? $v : 'all';
	}

	protected function get_views() {
		$current = $this->current_view();
		$items   = array(
			'all'       => array( 'Všichni', null ),
			'pending'   => array( 'Registrace ke schválení', ZG_B2B_Approval::pending_count() ),
			'companies' => array( 'Firmy ke schválení', ZG_B2B_Companies::pending_count() ),
			'approved'  => array( 'Schválení', ZG_B2B_Dashboard::count_status( 'approved' ) ),
			'rejected'  => array( 'Zamítnutí', ZG_B2B_Dashboard::count_status( 'rejected' ) ),
		);
		$views = array();
		foreach ( $items as $key => $item ) {
			$url           = ZG_B2B_Dashboard::url( 'all' === $key ? array() : array( 'view' => $key ) );
			$views[ $key ] = '<a href="' . esc_url( $url ) . '"' . ( $current === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $item[0] ) . ( null !== $item[1] ? ' <span class="count">(' . (int) $item[1] . ')</span>' : '' ) . '</a>';
		}
		return $views;
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'zg_b2b_dash_per_page', 20 );
		$paged    = $this->get_pagenum();
		// phpcs:disable WordPress.Security.NonceVerification
		$orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'display_name', 'registered' ), true ) ? sanitize_key( $_GET['orderby'] ) : 'registered';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'ASC' : 'DESC';
		$search  = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		// phpcs:enable

		$meta = array( 'relation' => 'AND' );
		switch ( $this->current_view() ) {
			case 'pending':
			case 'approved':
			case 'rejected':
				$meta[] = array( 'key' => 'zg_b2b_status', 'value' => $this->current_view() );
				break;
			case 'companies':
				$meta[] = array( 'key' => ZG_B2B_Companies::META_PENDING, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' );
				break;
			default:
				$meta[] = array(
					'relation' => 'OR',
					array( 'key' => 'zg_b2b_status', 'compare' => 'EXISTS' ),
					array( 'key' => 'billing_company', 'value' => '', 'compare' => '!=' ),
				);
		}
		if ( '' !== $search ) {
			$like = array( 'relation' => 'OR' );
			foreach ( array( 'billing_company', zg_b2b_opt( 'ico_meta' ), zg_b2b_opt( 'dic_meta' ), 'billing_email', 'billing_phone', 'first_name', 'last_name', ZG_B2B_Companies::META ) as $key ) {
				if ( $key ) {
					$like[] = array( 'key' => $key, 'value' => $search, 'compare' => 'LIKE' );
				}
			}
			$meta[] = $like;
		}

		$query = new WP_User_Query( array(
			'role__not_in' => array( 'administrator', 'shop_manager' ),
			'meta_query'   => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'       => $per_page,
			'paged'        => $paged,
			'orderby'      => $orderby,
			'order'        => $order,
			'count_total'  => true,
		) );

		$this->items = $query->get_results();
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'customer' );
		$this->set_pagination_args( array(
			'total_items' => (int) $query->get_total(),
			'per_page'    => $per_page,
		) );
	}

	public function no_items() {
		echo 'Žádní zákazníci.';
	}

	protected function column_customer( $user ) {
		$detail  = ZG_B2B_Dashboard::url( array( 'customer' => $user->ID ) );
		$name    = trim( get_user_meta( $user->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $user->ID, 'billing_last_name', true ) );
		$name    = $name ? $name : $user->display_name;
		$out     = '<strong><a class="row-title" href="' . esc_url( $detail ) . '">' . esc_html( $name ) . '</a></strong>';
		$out    .= '<br><a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a>';
		$phone   = get_user_meta( $user->ID, 'billing_phone', true );
		$out    .= $phone ? '<br>' . esc_html( $phone ) : '';
		$note    = get_user_meta( $user->ID, 'zg_b2b_admin_note', true );
		$out    .= $note ? '<br><small title="' . esc_attr( $note ) . '">📝 ' . esc_html( wp_trim_words( $note, 8 ) ) . '</small>' : '';
		$actions = array(
			'detail' => '<a href="' . esc_url( $detail ) . '">Detail a úpravy</a>',
			'orders' => '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&_customer_user=' . $user->ID ) ) . '">Objednávky</a>',
			'user'   => '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">Profil</a>',
		);
		return $out . $this->row_actions( $actions );
	}

	protected function column_status( $user ) {
		$status = zg_b2b_user_status( $user->ID );
		$out    = ZG_B2B_Dashboard_Table::status_badge( $status );
		if ( 'pending' === $status ) {
			$out .= '<br><a href="' . esc_url( ZG_B2B_Dashboard::status_url( $user->ID, 'approved' ) ) . '" style="color:#008a20;font-weight:600">Schválit</a>';
		}
		return $out;
	}

	protected function column_companies( $user ) {
		$ico  = get_user_meta( $user->ID, zg_b2b_opt( 'ico_meta' ), true );
		$out  = '<strong>' . esc_html( get_user_meta( $user->ID, 'billing_company', true ) ) . '</strong>' . ( $ico ? ' <small>IČO ' . esc_html( $ico ) . '</small>' : '' );
		$list = ZG_B2B_Companies::get_all( $user->ID );
		foreach ( $list as $c ) {
			$out .= '<br>' . esc_html( $c['company'] ) . ' ';
			if ( 'approved' !== $c['status'] ) {
				$out .= '<span class="zg-b2b-st zg-b2b-st--' . esc_attr( $c['status'] ) . '">' . esc_html( ZG_B2B_Approval::statuses()[ $c['status'] ] ?? $c['status'] ) . '</span>';
			}
		}
		return $out;
	}

	protected function column_addresses( $user ) {
		$list = ZG_B2B_Address_Book::get_all( $user->ID );
		if ( ! $list ) {
			return '—';
		}
		$labels = array_map(
			function ( $a ) {
				return esc_html( $a['label'] ) . ( ! empty( $a['default'] ) ? ' ★' : '' );
			},
			array_slice( $list, 0, 4 )
		);
		return '<strong>' . count( $list ) . '</strong><br><small>' . implode( '<br>', $labels ) . ( count( $list ) > 4 ? '<br>…' : '' ) . '</small>';
	}

	protected function column_orders( $user ) {
		$count = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user->ID ) : 0;
		if ( ! $count ) {
			return '0';
		}
		$last = function_exists( 'wc_get_customer_last_order' ) ? wc_get_customer_last_order( $user->ID ) : null;
		$out  = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&_customer_user=' . $user->ID ) ) . '"><strong>' . $count . '</strong></a>';
		if ( $last && $last->get_date_created() ) {
			$out .= '<br><small>poslední ' . esc_html( $last->get_date_created()->date_i18n( 'j. n. Y' ) ) . '</small>';
		}
		$spent = function_exists( 'wc_get_customer_total_spent' ) ? wc_get_customer_total_spent( $user->ID ) : 0;
		return $out . '<br><small>' . wp_kses_post( wc_price( $spent ) ) . '</small>';
	}

	protected function column_invoice( $user ) {
		$main  = 'yes' === get_user_meta( $user->ID, 'zg_b2b_invoice_allowed', true );
		$extra = 0;
		foreach ( ZG_B2B_Companies::get_all( $user->ID ) as $c ) {
			$extra += ( 'approved' === $c['status'] && isset( $c['invoice'] ) && 'yes' === $c['invoice'] ) ? 1 : 0;
		}
		return ( $main ? '✔ hlavní' : '—' ) . ( $extra ? '<br><small>+ ' . (int) $extra . ' další</small>' : '' );
	}

	protected function column_registered( $user ) {
		$reg = (int) get_user_meta( $user->ID, 'zg_b2b_registered_at', true );
		$ts  = $reg ? $reg : strtotime( $user->user_registered );
		return esc_html( wp_date( 'j. n. Y', $ts ) );
	}

	protected function column_default( $item, $column_name ) {
		return '';
	}
}
