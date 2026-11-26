<?php
/**
 * B2B Dashboard (Bombora → B2B Dashboard).
 *
 * Přehled velkoobchodních zákazníků: stav účtu, firmy, doručovací adresy, objednávky,
 * co čeká na schválení. Detail zákazníka s úpravou všech údajů na jednom místě.
 *
 * Postaveno na nativních prvcích WordPressu / WooCommerce (WP_List_Table, admin-post.php,
 * nonce, capability `manage_woocommerce`, wc_get_orders, WC_Customer data), aby šlo
 * udržovat s dalšími verzemi WP/WC.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Dashboard {

	const PAGE = 'zg-b2b-dashboard';
	const CAP  = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 59 );
		add_action( 'admin_post_zg_b2b_dash_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_zg_b2b_dash_status', array( __CLASS__, 'handle_quick_status' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'user_row_action' ), 20, 2 );
		add_filter( 'set-screen-option', array( __CLASS__, 'screen_option' ), 10, 3 );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function pending_total() {
		return ZG_B2B_Approval::pending_count() + ZG_B2B_Companies::pending_count();
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['bombora'] ) ? 'bombora' : 'woocommerce';
		$n      = self::pending_total();
		$label  = 'B2B Dashboard' . ( $n ? ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . (int) $n . '</span></span>' : '' );
		$hook   = add_submenu_page( $parent, 'B2B Dashboard', $label, self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
		add_action( 'load-' . $hook, array( __CLASS__, 'load_screen' ) );
	}

	public static function load_screen() {
		if ( empty( $_GET['customer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_screen_option( 'per_page', array( 'label' => 'Zákazníků na stránku', 'default' => 20, 'option' => 'zg_b2b_dash_per_page' ) );
		}
	}

	public static function screen_option( $status, $option, $value ) {
		return 'zg_b2b_dash_per_page' === $option ? absint( $value ) : $status;
	}

	public static function user_row_action( $actions, $user ) {
		if ( current_user_can( self::CAP ) && ! user_can( $user, 'manage_woocommerce' ) ) {
			$actions['zg_b2b_dash'] = '<a href="' . esc_url( self::url( array( 'customer' => $user->ID ) ) ) . '">B2B detail</a>';
		}
		return $actions;
	}

	/* =====================================================================
	 * Render
	 * =================================================================== */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		}
		require_once __DIR__ . '/class-zg-b2b-dashboard-table.php';
		echo '<div class="wrap zg-b2b-dash">';
		self::styles();
		$customer = isset( $_GET['customer'] ) ? absint( $_GET['customer'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $customer ) {
			self::render_customer( $customer );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['zg_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Změny byly uloženy.</p></div>';
		}
		if ( isset( $_GET['zg_status'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Stav účtu byl změněn.</p></div>';
		}
		if ( isset( $_GET['zg_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['zg_error'] ) ) ) . '</p></div>';
		}
		// phpcs:enable
	}

	private static function stat( $label, $value, $url = '', $variant = '' ) {
		echo '<a class="zg-b2b-dash__stat' . ( $variant ? ' is-' . esc_attr( $variant ) : '' ) . '" href="' . esc_url( $url ? $url : '#' ) . '">';
		echo '<span class="zg-b2b-dash__stat-value">' . esc_html( $value ) . '</span><span class="zg-b2b-dash__stat-label">' . esc_html( $label ) . '</span></a>';
	}

	private static function render_list() {

		echo '<h1 class="wp-heading-inline">B2B Dashboard</h1>';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=zg-b2b' ) ) . '">Nastavení B2B</a>';
		echo '<hr class="wp-header-end">';
		self::notice();

		$pending_reg = ZG_B2B_Approval::pending_count();
		$pending_co  = ZG_B2B_Companies::pending_count();
		$approved    = self::count_status( 'approved' );
		$invoices    = self::count_unpaid_invoices();

		echo '<div class="zg-b2b-dash__stats">';
		self::stat( 'Registrace ke schválení', $pending_reg, self::url( array( 'view' => 'pending' ) ), $pending_reg ? 'warning' : '' );
		self::stat( 'Firmy ke schválení', $pending_co, self::url( array( 'view' => 'companies' ) ), $pending_co ? 'warning' : '' );
		self::stat( 'Schválení partneři', $approved, self::url( array( 'view' => 'approved' ) ) );
		self::stat( 'Proformy čekající na úhradu', $invoices, admin_url( 'admin.php?page=wc-orders&status=wc-on-hold' ), $invoices ? 'info' : '' );
		echo '</div>';

		$table = new ZG_B2B_Dashboard_Table();
		$table->prepare_items();
		$table->views();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		if ( ! empty( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<input type="hidden" name="view" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['view'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$table->search_box( 'Hledat zákazníka / firmu / IČO', 'zg-b2b-search' );
		$table->display();
		echo '</form>';
	}

	public static function count_status( $status ) {
		$q = new WP_User_Query( array(
			'meta_key'    => 'zg_b2b_status', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => $status, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'      => 'ID',
			'number'      => 1,
			'count_total' => true,
		) );
		return (int) $q->get_total();
	}

	private static function count_unpaid_invoices() {
		if ( ! class_exists( 'ZG_B2B_Gateway_Invoice' ) ) {
			return 0;
		}
		$ids = wc_get_orders( array(
			'status'         => array( 'on-hold' ),
			'payment_method' => ZG_B2B_Gateway_Invoice::ID,
			'limit'          => 200,
			'return'         => 'ids',
		) );
		return count( $ids );
	}

	/* ---------------------------------------------------------------------
	 * Detail zákazníka
	 * ------------------------------------------------------------------- */

	private static function input( $name, $value, $attrs = '' ) {
		return '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . $attrs . '>';
	}

	private static function country_select( $name, $value ) {
		$out = '<select name="' . esc_attr( $name ) . '" class="wc-enhanced-select zg-b2b-dash__country">';
		foreach ( WC()->countries->get_countries() as $code => $label ) {
			$out .= '<option value="' . esc_attr( $code ) . '"' . selected( $value, $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $out . '</select>';
	}

	private static function render_customer( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! current_user_can( 'edit_user', $user_id ) ) {
			echo '<h1>B2B Dashboard</h1><p>Zákazník nebyl nalezen.</p>';
			return;
		}
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		$m      = function ( $k ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $k, true );
		};
		$status = zg_b2b_user_status( $user_id );

		echo '<p><a href="' . esc_url( self::url() ) . '">← B2B Dashboard</a></p>';
		echo '<h1 class="wp-heading-inline">' . esc_html( $m( 'billing_company' ) ? $m( 'billing_company' ) : $user->display_name ) . '</h1> ';
		echo ZG_B2B_Dashboard_Table::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <a class="page-title-action" href="' . esc_url( get_edit_user_link( $user_id ) ) . '">Profil uživatele WordPress</a>';
		echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=wc-orders&_customer_user=' . $user_id ) ) . '">Objednávky zákazníka</a>';
		echo '<hr class="wp-header-end">';
		self::notice();

		if ( 'pending' === $status ) {
			echo '<div class="notice notice-warning inline"><p><strong>Registrace čeká na schválení.</strong> ';
			echo '<a class="button button-primary" href="' . esc_url( self::status_url( $user_id, 'approved' ) ) . '">Schválit účet</a> ';
			echo '<a class="button" href="' . esc_url( self::status_url( $user_id, 'rejected' ) ) . '">Zamítnout</a></p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="zg-b2b-dash__form">';
		wp_nonce_field( 'zg_b2b_dash_save_' . $user_id, '_zg_b2b_dash_nonce' );
		echo '<input type="hidden" name="action" value="zg_b2b_dash_save"><input type="hidden" name="customer" value="' . (int) $user_id . '">';

		echo '<div class="zg-b2b-dash__grid">';

		// Účet.
		echo '<div class="postbox"><div class="postbox-header"><h2>Účet</h2></div><div class="inside"><table class="form-table" role="presentation">';
		echo '<tr><th>Stav účtu</th><td><select name="zg_status">';
		foreach ( ZG_B2B_Approval::statuses() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">Při schválení odejde zákazníkovi e-mail (jen poprvé).</p></td></tr>';
		echo '<tr><th>Platba na fakturu</th><td><label><input type="checkbox" name="zg_invoice" value="yes"' . checked( 'yes', $m( 'zg_b2b_invoice_allowed' ), false ) . '> Povolit pro hlavní firmu</label><p class="description">U dalších firem v sekci „Další firmy účtu“ (sloupec Faktura).</p></td></tr>';
		echo '<tr><th>Přihlašovací e-mail</th><td>' . esc_html( $user->user_email ) . '</td></tr>';
		$reg = (int) $m( 'zg_b2b_registered_at' );
		echo '<tr><th>Registrace</th><td>' . esc_html( $reg ? wp_date( 'j. n. Y H:i', $reg ) : wp_date( 'j. n. Y', strtotime( $user->user_registered ) ) ) . ' · jazyk ' . esc_html( $m( 'zg_b2b_lang' ) ? $m( 'zg_b2b_lang' ) : 'cs' ) . '</td></tr>';
		echo '<tr><th>Interní poznámka</th><td><textarea name="zg_note" rows="3" class="large-text">' . esc_textarea( $m( 'zg_b2b_admin_note' ) ) . '</textarea><p class="description">Vidí jen administrátoři.</p></td></tr>';
		echo '</table></div></div>';

		// Kontaktní osoba.
		echo '<div class="postbox"><div class="postbox-header"><h2>Kontaktní osoba</h2></div><div class="inside"><table class="form-table" role="presentation">';
		echo '<tr><th>Jméno</th><td>' . self::input( 'c[billing_first_name]', $m( 'billing_first_name' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Příjmení</th><td>' . self::input( 'c[billing_last_name]', $m( 'billing_last_name' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Telefon</th><td>' . self::input( 'c[billing_phone]', $m( 'billing_phone' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Fakturační e-mail</th><td>' . self::input( 'c[billing_email]', $m( 'billing_email' ), 'type="email"' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</table></div></div>';

		echo '</div>';

		// Hlavní firma.
		echo '<div class="postbox"><div class="postbox-header"><h2>Hlavní firma (fakturační údaje účtu)</h2></div><div class="inside"><table class="form-table" role="presentation">';
		echo '<tr><th>Název firmy</th><td>' . self::input( 'c[billing_company]', $m( 'billing_company' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>IČO</th><td>' . self::input( 'c[' . zg_b2b_opt( 'ico_meta' ) . ']', $m( zg_b2b_opt( 'ico_meta' ) ) ) . ' <span class="description">' . esc_html( ZG_B2B_Registration::flag_label( 'ares', $m( 'zg_b2b_ares' ) ) ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>DIČ</th><td>' . self::input( 'c[' . zg_b2b_opt( 'dic_meta' ) . ']', $m( zg_b2b_opt( 'dic_meta' ) ) ) . ' <span class="description">' . esc_html( ZG_B2B_Registration::flag_label( 'vies', $m( 'zg_b2b_vies' ) ) ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Ulice a č.p.</th><td>' . self::input( 'c[billing_address_1]', $m( 'billing_address_1' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>PSČ / Město</th><td>' . self::input( 'c[billing_postcode]', $m( 'billing_postcode' ), 'style="width:8em"' ) . ' ' . self::input( 'c[billing_city]', $m( 'billing_city' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>Země</th><td>' . self::country_select( 'c[billing_country]', $m( 'billing_country' ) ? $m( 'billing_country' ) : 'CZ' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</table></div></div>';

		// Další firmy (stejná tabulka jako v profilu uživatele).
		echo '<div class="postbox"><div class="inside">';
		ZG_B2B_Companies::admin_profile( $user );
		echo '</div></div>';

		// Doručovací adresy.
		self::render_addresses( $user_id );

		submit_button( 'Uložit změny' );
		echo '</form>';

		// Objednávky.
		self::render_orders( $user_id );
	}

	private static function render_addresses( $user_id ) {
		$list = ZG_B2B_Address_Book::get_all( $user_id );
		echo '<div class="postbox"><div class="postbox-header"><h2>Doručovací adresy (pobočky)</h2></div><div class="inside">';
		echo '<table class="widefat striped zg-b2b-co"><thead><tr><th style="width:16%">Název pobočky</th><th style="width:16%">Jméno / Příjmení</th><th>Ulice / PSČ / Město / Země</th><th style="width:12%">Telefon</th><th style="width:6%">Výchozí</th><th style="width:6%">Smazat</th></tr></thead><tbody>';
		$rows        = $list;
		$rows[]      = array( 'id' => 'new', 'label' => '', 'default' => false, 'fields' => array() );
		$has_default = false;
		foreach ( $rows as $a ) {
			$f  = $a['fields'];
			$n  = 'addr[' . $a['id'] . ']';
			$v  = function ( $k ) use ( $f ) {
				return isset( $f[ $k ] ) ? $f[ $k ] : '';
			};
			$is_new = 'new' === $a['id'];
			echo '<tr>';
			echo '<td>' . self::input( $n . '[label]', $a['label'], $is_new ? 'placeholder="+ Nová adresa (název)"' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . self::input( $n . '[first_name]', $v( 'first_name' ), 'placeholder="Jméno"' ) . self::input( $n . '[last_name]', $v( 'last_name' ), 'placeholder="Příjmení"' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . self::input( $n . '[address_1]', $v( 'address_1' ), 'placeholder="Ulice a č.p."' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. self::input( $n . '[postcode]', $v( 'postcode' ), 'placeholder="PSČ" style="width:30%"' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. self::input( $n . '[city]', $v( 'city' ), 'placeholder="Město" style="width:68%"' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. self::country_select( $n . '[country]', $v( 'country' ) ? $v( 'country' ) : 'CZ' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . self::input( $n . '[phone]', $v( 'phone' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . ( $is_new ? '' : '<input type="radio" name="addr_default" value="' . esc_attr( $a['id'] ) . '"' . checked( ! empty( $a['default'] ), true, false ) . '>' ) . '</td>';
			echo '<td>' . ( $is_new ? '' : '<label><input type="checkbox" name="' . esc_attr( $n ) . '[delete]" value="1"> ✕</label>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table><p class="description">Název pobočky se uvádí i jako název firmy v doručovací adrese.</p></div></div>';
	}

	private static function render_orders( $user_id ) {
		$orders = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 15, 'orderby' => 'date', 'order' => 'DESC' ) );
		$count  = function_exists( 'wc_get_customer_order_count' ) ? wc_get_customer_order_count( $user_id ) : count( $orders );
		$spent  = function_exists( 'wc_get_customer_total_spent' ) ? wc_get_customer_total_spent( $user_id ) : 0;
		echo '<div class="postbox"><div class="postbox-header"><h2>Objednávky (' . (int) $count . ' · celkem ' . wp_kses_post( wc_price( $spent ) ) . ')</h2></div><div class="inside">';
		if ( ! $orders ) {
			echo '<p>Zatím žádné objednávky.</p></div></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Objednávka</th><th>Datum</th><th>Stav</th><th>Firma (faktura)</th><th>Doručení</th><th>Platba</th><th style="text-align:right">Celkem</th></tr></thead><tbody>';
		foreach ( $orders as $o ) {
			$date = $o->get_date_created();
			echo '<tr><td><a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $date ? $date->date_i18n( 'j. n. Y' ) : '' ) . '</td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $o->get_status() ) ) . '</td>';
			echo '<td>' . esc_html( ZG_B2B_Companies::order_company_name( $o ) ) . '</td>';
			$d = ZG_B2B_Companies::order_delivery_name( $o );
			echo '<td>' . esc_html( $d ? $d : 'fakturační adresa' ) . '</td>';
			echo '<td>' . esc_html( $o->get_payment_method_title() ) . '</td>';
			echo '<td style="text-align:right">' . wp_kses_post( $o->get_formatted_order_total() ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/* =====================================================================
	 * Uložení
	 * =================================================================== */

	public static function status_url( $user_id, $status ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=zg_b2b_dash_status&customer=' . (int) $user_id . '&status=' . $status ), 'zg_b2b_dash_status_' . (int) $user_id );
	}

	public static function handle_quick_status() {
		$user_id = isset( $_GET['customer'] ) ? absint( $_GET['customer'] ) : 0;
		check_admin_referer( 'zg_b2b_dash_status_' . $user_id );
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		ZG_B2B_Approval::set_status( $user_id, $status );
		wp_safe_redirect( self::url( array( 'customer' => $user_id, 'zg_status' => 1 ) ) );
		exit;
	}

	public static function handle_save() {
		$user_id = isset( $_POST['customer'] ) ? absint( $_POST['customer'] ) : 0;
		check_admin_referer( 'zg_b2b_dash_save_' . $user_id, '_zg_b2b_dash_nonce' );
		if ( ! $user_id || ! current_user_can( self::CAP ) || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}

		// Účet.
		$status = isset( $_POST['zg_status'] ) ? sanitize_key( wp_unslash( $_POST['zg_status'] ) ) : '';
		if ( $status && $status !== zg_b2b_user_status( $user_id ) ) {
			ZG_B2B_Approval::set_status( $user_id, $status );
		}
		update_user_meta( $user_id, 'zg_b2b_invoice_allowed', isset( $_POST['zg_invoice'] ) ? 'yes' : 'no' );
		update_user_meta( $user_id, 'zg_b2b_admin_note', isset( $_POST['zg_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['zg_note'] ) ) : '' );

		// Kontaktní osoba + hlavní firma (jen povolené klíče).
		$allowed = array( 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email', 'billing_company', zg_b2b_opt( 'ico_meta' ), zg_b2b_opt( 'dic_meta' ), 'billing_address_1', 'billing_postcode', 'billing_city', 'billing_country' );
		$c       = isset( $_POST['c'] ) && is_array( $_POST['c'] ) ? wp_unslash( $_POST['c'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$errors  = array();
		foreach ( $allowed as $key ) {
			if ( ! $key || ! isset( $c[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( $c[ $key ] );
			if ( 'billing_email' === $key && '' !== $value && ! is_email( $value ) ) {
				$errors[] = 'Fakturační e-mail není platný – nezměněn.';
				continue;
			}
			update_user_meta( $user_id, $key, 'billing_email' === $key ? sanitize_email( $value ) : $value );
		}
		if ( isset( $c['billing_first_name'] ) || isset( $c['billing_last_name'] ) ) {
			wp_update_user( array(
				'ID'         => $user_id,
				'first_name' => sanitize_text_field( isset( $c['billing_first_name'] ) ? $c['billing_first_name'] : '' ),
				'last_name'  => sanitize_text_field( isset( $c['billing_last_name'] ) ? $c['billing_last_name'] : '' ),
			) );
		}

		// Další firmy (vlastní nonce v tabulce).
		ZG_B2B_Companies::admin_save( $user_id );

		// Doručovací adresy.
		$addr    = isset( $_POST['addr'] ) && is_array( $_POST['addr'] ) ? wp_unslash( $_POST['addr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$default = isset( $_POST['addr_default'] ) ? sanitize_key( wp_unslash( $_POST['addr_default'] ) ) : '';
		foreach ( $addr as $id => $row ) {
			$id = sanitize_key( $id );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'new' !== $id && ! empty( $row['delete'] ) ) {
				ZG_B2B_Address_Book::delete( $user_id, $id );
				continue;
			}
			$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
			if ( 'new' === $id && '' === $label ) {
				continue;
			}
			if ( '' === $label ) {
				$errors[] = 'Název pobočky je povinný – adresa nezměněna.';
				continue;
			}
			$fields = array();
			foreach ( array( 'first_name', 'last_name', 'address_1', 'postcode', 'city', 'country', 'phone' ) as $k ) {
				$fields[ $k ] = isset( $row[ $k ] ) ? (string) $row[ $k ] : '';
			}
			if ( 'new' !== $id ) {
				$existing = ZG_B2B_Address_Book::get( $user_id, $id );
				if ( ! $existing ) {
					continue;
				}
				$fields = array_merge( $existing['fields'], $fields );
			}
			$saved = ZG_B2B_Address_Book::save( $user_id, array(
				'id'      => 'new' === $id ? '' : $id,
				'label'   => $label,
				'default' => ( $default === $id ),
				'fields'  => $fields,
			) );
			if ( is_wp_error( $saved ) ) {
				$errors[] = $saved->get_error_message();
			}
		}

		$args = array( 'customer' => $user_id, 'zg_saved' => 1 );
		if ( $errors ) {
			$args['zg_error'] = rawurlencode( implode( ' ', array_unique( $errors ) ) );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function styles() {
		echo '<style>
		.zg-b2b-dash__stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}
		.zg-b2b-dash__stat{display:flex;flex-direction:column;gap:4px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;text-decoration:none;color:#1d2327}
		.zg-b2b-dash__stat:hover{border-color:#2271b1}
		.zg-b2b-dash__stat-value{font-size:26px;font-weight:600;line-height:1.1}
		.zg-b2b-dash__stat-label{color:#646970}
		.zg-b2b-dash__stat.is-warning{border-left:4px solid #dba617}
		.zg-b2b-dash__stat.is-info{border-left:4px solid #2271b1}
		.zg-b2b-dash__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
		.zg-b2b-dash .postbox{margin:16px 0 0}
		.zg-b2b-dash .postbox .inside{margin:0;padding:8px 12px 12px}
		.zg-b2b-dash .form-table th{width:170px}
		.zg-b2b-co td,.zg-b2b-co th{vertical-align:top;padding:6px}
		.zg-b2b-co input[type=text]{width:100%;margin-bottom:4px}
		.zg-b2b-dash .zg-b2b-dash__country{max-width:100%}
		.zg-b2b-st{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e;vertical-align:middle}
		.zg-b2b-st--pending{background:#fcf0d0;color:#8a5a00}.zg-b2b-st--approved{background:#d7f5dd;color:#0a6b1f}.zg-b2b-st--rejected{background:#fbe0e0;color:#a10e0e}
		.zg-b2b-dash .column-orders,.zg-b2b-dash .column-addresses,.zg-b2b-dash .column-invoice{width:10%}
		.zg-b2b-dash .column-status{width:11%}
		@media (max-width:960px){.zg-b2b-dash__stats,.zg-b2b-dash__grid{grid-template-columns:1fr 1fr}}
		</style>';
	}
}
