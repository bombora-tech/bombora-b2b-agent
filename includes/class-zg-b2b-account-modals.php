<?php
/**
 * Můj účet: všechny úpravy v pop-up okně.
 * - Adresy → „Upravit“ u hlavní firmy: standardní formulář fakturační adresy WooCommerce v pop-upu.
 * - Detaily účtu: přehled údajů + standardní formulář WooCommerce (jméno, e-mail, heslo) v pop-upu.
 * Formuláře zůstávají původní (WooCommerce je ukládá i validuje); při chybě se okno znovu otevře.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Account_Modals {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'replace_edit_account' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function replace_edit_account() {
		if ( has_action( 'woocommerce_account_edit-account_endpoint', 'woocommerce_account_edit_account' ) ) {
			remove_action( 'woocommerce_account_edit-account_endpoint', 'woocommerce_account_edit_account' );
			add_action( 'woocommerce_account_edit-account_endpoint', array( __CLASS__, 'render_edit_account' ) );
		}
	}

	public static function assets() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return;
		}
		self::enqueue();
	}

	public static function enqueue() {
		if ( class_exists( 'ZG_B2B_Address_Account' ) ) {
			ZG_B2B_Address_Account::enqueue();
		} else {
			wp_enqueue_style( 'zg-b2b-addresses', ZG_B2B_URL . 'assets/css/zg-b2b-addresses.css', array(), ZG_B2B_VERSION );
		}
		wp_enqueue_script( 'zg-b2b-account-modals', ZG_B2B_URL . 'assets/js/zg-b2b-account-modals.js', array( 'jquery' ), ZG_B2B_VERSION, true );
	}

	/** Začátek pop-upu. */
	public static function open( $id, $title, $autoopen = false ) {
		echo '<div class="zg-b2b-addr zg-b2b-addr-modal zg-b2b-gmodal" id="' . esc_attr( $id ) . '" hidden' . ( $autoopen ? ' data-autoopen="1"' : '' ) . '>';
		echo '<div class="zg-b2b-addr-modal__backdrop" data-zg-gmodal-close></div>';
		echo '<div class="zg-b2b-addr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $id ) . '-title">';
		echo '<div class="zg-b2b-addr-modal__head"><h2 class="zg-b2b-addr-modal__title" id="' . esc_attr( $id ) . '-title">' . esc_html( $title ) . '</h2>';
		echo '<button type="button" class="zg-b2b-addr-modal__close" data-zg-gmodal-close aria-label="' . esc_attr__( 'Zavřít', 'zg-b2b' ) . '">&times;</button></div>';
		echo '<div class="zg-b2b-addr-modal__body">';
	}

	public static function close() {
		echo '</div></div></div>';
	}

	/** Odstraní první nadpis (h2/h3) ze šablony – název je v hlavičce pop-upu. */
	private static function strip_heading( $html ) {
		return preg_replace( '#<h[23][^>]*>.*?</h[23]>#s', '', $html, 1 );
	}

	/* ---------- Fakturační adresa (Adresy) ---------- */

	/** Pop-up s formulářem fakturační adresy; volá se z přehledu Adres. */
	public static function render_billing_modal( $autoopen = false ) {
		ob_start();
		woocommerce_account_edit_address( 'billing' );
		$html = (string) ob_get_clean();
		// Formulář musí odeslat na /…/billing/, jinak ho WooCommerce nezpracuje.
		$action = wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) );
		$html   = preg_replace( '#<form\b(?![^>]*\baction=)#', '<form action="' . esc_url( $action ) . '"', $html, 1 );
		$html   = self::strip_heading( $html );

		self::open( 'zg-b2b-billing-modal', __( 'Upravit fakturační a kontaktní údaje', 'zg-b2b' ), $autoopen );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- výstup šablony WooCommerce.
		self::close();
	}

	/** Neúspěšné uložení fakturační adresy (WooCommerce při úspěchu přesměruje). */
	public static function billing_failed() {
		return isset( $_POST['action'] ) && 'edit_address' === $_POST['action']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ---------- Detaily účtu ---------- */

	public static function render_edit_account() {
		self::enqueue();
		$user   = wp_get_current_user();
		$failed = isset( $_POST['action'] ) && 'save_account_details' === $_POST['action']; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="zg-b2b-addr zg-b2b-account-details">';
		echo '<div class="zg-b2b-addr-section-head"><h2 class="zg-b2b-addr-section-title">' . esc_html__( 'Přihlašovací údaje', 'zg-b2b' ) . '</h2>';
		echo '<button type="button" class="zg-b2b-addr-btn zg-b2b-addr-btn--primary" data-zg-modal-open="zg-b2b-account-modal">' . esc_html__( 'Upravit údaje', 'zg-b2b' ) . '</button></div>';

		$rows = array(
			__( 'Jméno a příjmení', 'zg-b2b' ) => trim( $user->first_name . ' ' . $user->last_name ),
			__( 'Zobrazované jméno', 'zg-b2b' ) => $user->display_name,
			__( 'E-mail', 'zg-b2b' )            => $user->user_email,
			__( 'Heslo', 'zg-b2b' )             => '••••••••',
		);
		echo '<div class="zg-b2b-addr-card zg-b2b-account-card"><div class="zg-b2b-kv">';
		foreach ( $rows as $label => $value ) {
			echo '<div class="zg-b2b-kv__row"><span class="zg-b2b-kv__label">' . esc_html( $label ) . '</span><span class="zg-b2b-kv__value">' . esc_html( '' !== $value ? $value : '—' ) . '</span></div>';
		}
		echo '</div><div class="zg-b2b-addr-card__actions">';
		echo '<button type="button" class="zg-b2b-addr-btn zg-b2b-addr-btn--small" data-zg-modal-open="zg-b2b-account-modal" data-zg-modal-focus="#password_current">' . esc_html__( 'Změnit heslo', 'zg-b2b' ) . '</button>';
		echo '</div></div>';

		ob_start();
		woocommerce_account_edit_account();
		$html = self::strip_heading( (string) ob_get_clean() );

		self::open( 'zg-b2b-account-modal', __( 'Upravit přihlašovací údaje', 'zg-b2b' ), $failed );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- výstup šablony WooCommerce.
		self::close();
		echo '</div>';
	}
}
