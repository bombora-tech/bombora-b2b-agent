<?php
/**
 * Kontaktní osoby (příjemci zásilky): jméno, příjmení, telefon.
 * V pokladně se vybírají ze seznamu nebo zadávají nové; doplní se do shipping_first_name/last_name/phone.
 * Platí pro každou doručovací adresu včetně „Stejná jako fakturační adresa“.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Contacts {

	const META      = 'zg_b2b_contacts';
	const META_LAST = 'zg_b2b_last_contact';
	const MAX       = 30;

	/** @var array|null Příjemce vyřešený při odeslání objednávky. */
	private static $recipient = null;

	public static function init() {
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'posted_data' ), 1100 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'after_order' ), 20, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_account_post' ) );
		add_action( 'init', array( __CLASS__, 'register_endpoints' ), 5 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'account_assets' ) );
	}

	private static function active() {
		return is_user_logged_in() && (bool) apply_filters( 'zg_b2b_contacts_enabled', true );
	}

	/* ---------- Data ---------- */

	public static function sanitize( array $c ) {
		return array(
			'first_name' => isset( $c['first_name'] ) ? sanitize_text_field( $c['first_name'] ) : '',
			'last_name'  => isset( $c['last_name'] ) ? sanitize_text_field( $c['last_name'] ) : '',
			'phone'      => isset( $c['phone'] ) ? wc_sanitize_phone_number( $c['phone'] ) : '',
		);
	}

	private static function key( array $c ) {
		return strtolower( remove_accents( trim( $c['first_name'] ) . '|' . trim( $c['last_name'] ) ) ) . '|' . preg_replace( '/[^0-9]/', '', $c['phone'] );
	}

	/**
	 * Seznam kontaktů (id => kontakt). Při prvním použití se naplní z kontaktu účtu
	 * a ze jmen/telefonů uložených u doručovacích adres.
	 */
	public static function get_all( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$list = get_user_meta( $user_id, self::META, true );
		if ( is_array( $list ) ) {
			return $list;
		}
		$seed   = array();
		$seed[] = array(
			'first_name' => (string) get_user_meta( $user_id, 'billing_first_name', true ),
			'last_name'  => (string) get_user_meta( $user_id, 'billing_last_name', true ),
			'phone'      => (string) get_user_meta( $user_id, 'billing_phone', true ),
		);
		if ( class_exists( 'ZG_B2B_Address_Book' ) ) {
			foreach ( ZG_B2B_Address_Book::get_all( $user_id ) as $a ) {
				$f      = $a['fields'];
				$seed[] = array(
					'first_name' => isset( $f['first_name'] ) ? $f['first_name'] : '',
					'last_name'  => isset( $f['last_name'] ) ? $f['last_name'] : '',
					'phone'      => isset( $f['phone'] ) ? $f['phone'] : '',
				);
			}
		}
		$list = array();
		$seen = array();
		foreach ( $seed as $c ) {
			$c = self::sanitize( $c );
			if ( '' === $c['first_name'] || '' === $c['last_name'] ) {
				continue;
			}
			$k = self::key( $c );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ]       = true;
			$id               = 'c' . substr( md5( $k ), 0, 10 );
			$list[ $id ]      = $c + array( 'id' => $id );
		}
		update_user_meta( $user_id, self::META, $list );
		return $list;
	}

	public static function get( $user_id, $id ) {
		$list = self::get_all( $user_id );
		return isset( $list[ $id ] ) ? $list[ $id ] : null;
	}

	/** Uloží kontakt (duplicitní → vrátí existující ID). */
	public static function add( $user_id, array $c ) {
		$c = self::sanitize( $c );
		if ( '' === $c['first_name'] || '' === $c['last_name'] ) {
			return '';
		}
		$list = self::get_all( $user_id );
		$k    = self::key( $c );
		foreach ( $list as $id => $e ) {
			if ( self::key( $e ) === $k ) {
				return $id;
			}
		}
		if ( count( $list ) >= self::MAX ) {
			return '';
		}
		$id          = 'c' . substr( md5( $k . microtime() ), 0, 10 );
		$list[ $id ] = $c + array( 'id' => $id );
		update_user_meta( $user_id, self::META, $list );
		return $id;
	}

	public static function delete( $user_id, $id ) {
		$list = self::get_all( $user_id );
		unset( $list[ $id ] );
		update_user_meta( $user_id, self::META, $list );
	}

	public static function initial( $user_id ) {
		$list = self::get_all( $user_id );
		$last = (string) get_user_meta( $user_id, self::META_LAST, true );
		if ( isset( $list[ $last ] ) ) {
			return $last;
		}
		return $list ? (string) key( $list ) : 'new';
	}

	public static function format( array $c ) {
		return trim( $c['first_name'] . ' ' . $c['last_name'] );
	}

	/* ---------- Pokladna ---------- */

	public static function render_checkout() {
		if ( ! self::active() ) {
			return;
		}
		$user_id = get_current_user_id();
		$list    = self::get_all( $user_id );
		$current = self::initial( $user_id );

		echo '<div class="zg-b2b-recipient" id="zg-b2b-recipient">';
		echo '<h3 class="zg-b2b-addr-picker__title">' . esc_html__( 'Kontaktní osoba', 'zg-b2b' ) . '</h3>';
		echo '<p class="zg-b2b-recipient__hint">' . esc_html__( 'Příjemce zásilky – jméno a telefon pro dopravce.', 'zg-b2b' ) . '</p>';

		if ( $list ) {
			echo '<div class="zg-b2b-choices" data-zg-choices>';
			if ( class_exists( 'ZG_B2B_Companies' ) ) {
				$others = count( $list );
				ZG_B2B_Companies::choices_header( 'person', __( 'Vybrat jinou kontaktní osobu', 'zg-b2b' ), ZG_B2B_Companies::plural( $others, __( '%d možnost', 'zg-b2b' ), __( '%d možnosti', 'zg-b2b' ), __( '%d možností', 'zg-b2b' ) ) );
			}
			foreach ( $list as $id => $c ) {
				self::option( $id, self::format( $c ), $c['phone'], $id === $current );
			}
			self::option( 'new', __( 'Nová kontaktní osoba', 'zg-b2b' ), __( 'Zadat jméno a telefon příjemce', 'zg-b2b' ), 'new' === $current );
			echo '</div>';
		} else {
			echo '<input type="hidden" name="zg_b2b_contact" value="new">';
		}

		echo '<div class="zg-b2b-recipient__new"' . ( 'new' === $current ? '' : ' hidden' ) . '>';
		$inputs = array(
			'first_name' => array( __( 'Jméno', 'zg-b2b' ), 'text', 'given-name', 'form-row-first' ),
			'last_name'  => array( __( 'Příjmení', 'zg-b2b' ), 'text', 'family-name', 'form-row-last' ),
			'phone'      => array( __( 'Telefon', 'zg-b2b' ), 'tel', 'tel', 'form-row-wide' ),
		);
		foreach ( $inputs as $k => $d ) {
			$id = 'zg_b2b_contact_' . $k;
			echo '<p class="form-row ' . esc_attr( $d[3] ) . ' validate-required">';
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $d[0] ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'povinné', 'zg-b2b' ) . '">*</abbr></label>';
			echo '<span class="woocommerce-input-wrapper"><input type="' . esc_attr( $d[1] ) . '" class="input-text" name="' . esc_attr( $id ) . '" id="' . esc_attr( $id ) . '" autocomplete="' . esc_attr( $d[2] ) . '" maxlength="80"></span>';
			echo '</p>';
		}
		echo '<label class="zg-b2b-addr-check zg-b2b-recipient__save"><input type="checkbox" name="zg_b2b_contact_save" value="1" checked> <span>' . esc_html__( 'Uložit do mých kontaktních osob', 'zg-b2b' ) . '</span></label>';
		echo '</div>';
		echo '<p class="zg-b2b-addr-picker__manage"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Spravovat kontaktní osoby', 'zg-b2b' ) . '</a></p>';
		echo '</div>';
	}

	private static function option( $value, $label, $detail, $checked ) {
		echo '<label class="zg-b2b-addr-option' . ( $checked ? ' is-selected' : '' ) . '">';
		echo '<input type="radio" name="zg_b2b_contact" value="' . esc_attr( $value ) . '"' . checked( $checked, true, false ) . '>';
		echo '<span class="zg-b2b-addr-option__text"><strong>' . esc_html( $label ) . '</strong>';
		if ( '' !== $detail ) {
			echo '<span class="zg-b2b-addr-option__detail">' . esc_html( $detail ) . '</span>';
		}
		echo '</span></label>';
	}

	/** Data pro JS pokladny. */
	public static function script_data( $user_id ) {
		$out = array();
		foreach ( self::get_all( $user_id ) as $id => $c ) {
			$out[ $id ] = array(
				'first_name' => $c['first_name'],
				'last_name'  => $c['last_name'],
				'phone'      => $c['phone'],
			);
		}
		return (object) $out;
	}

	/** Příjemce z odeslaného formuláře (null = nic nevybráno / neplatné). */
	private static function posted_recipient() {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce ověřuje nonce pokladny.
		if ( empty( $_POST['zg_b2b_contact'] ) ) {
			return null;
		}
		$choice = sanitize_key( wp_unslash( $_POST['zg_b2b_contact'] ) );
		if ( 'new' === $choice ) {
			$c = self::sanitize( array(
				'first_name' => isset( $_POST['zg_b2b_contact_first_name'] ) ? wp_unslash( $_POST['zg_b2b_contact_first_name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'last_name'  => isset( $_POST['zg_b2b_contact_last_name'] ) ? wp_unslash( $_POST['zg_b2b_contact_last_name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'phone'      => isset( $_POST['zg_b2b_contact_phone'] ) ? wp_unslash( $_POST['zg_b2b_contact_phone'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			) );
			return $c + array( 'id' => 'new' );
		}
		// phpcs:enable
		$c = self::get( get_current_user_id(), $choice );
		return $c ? $c : array( 'id' => '', 'first_name' => '', 'last_name' => '', 'phone' => '' );
	}

	/**
	 * Příjemce → doručovací jméno a telefon objednávky.
	 * U „Stejná jako fakturační adresa“ se doručovací adresa doplní z fakturační,
	 * aby příjemce byl na objednávce (štítek dopravce) vždy.
	 */
	public static function posted_data( $data ) {
		if ( ! self::active() ) {
			return $data;
		}
		$r = self::posted_recipient();
		if ( null === $r ) {
			return $data;
		}
		self::$recipient = $r;
		if ( '' === $r['first_name'] || '' === $r['last_name'] ) {
			return $data; // chybu ohlásí validate().
		}
		if ( empty( $data['ship_to_different_address'] ) ) {
			$data['ship_to_different_address'] = 1;
			foreach ( array( 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state' ) as $k ) {
				$data[ 'shipping_' . $k ] = isset( $data[ 'billing_' . $k ] ) ? $data[ 'billing_' . $k ] : '';
			}
		}
		$data['shipping_first_name'] = $r['first_name'];
		$data['shipping_last_name']  = $r['last_name'];
		$data['shipping_phone']      = $r['phone'];
		return $data;
	}

	public static function validate( $data, $errors ) {
		if ( ! self::active() || null === self::$recipient ) {
			return;
		}
		$r = self::$recipient;
		if ( '' === $r['id'] ) {
			$errors->add( 'zg_b2b_contact', __( 'Vybraná kontaktní osoba již neexistuje. Zvolte prosím jinou.', 'zg-b2b' ) );
			return;
		}
		if ( '' === $r['first_name'] || '' === $r['last_name'] || '' === $r['phone'] ) {
			$errors->add( 'zg_b2b_contact', __( 'Vyplňte prosím jméno, příjmení a telefon kontaktní osoby pro doručení.', 'zg-b2b' ) );
		}
	}

	public static function after_order( $order_id, $posted, $order ) {
		if ( ! self::active() || ! self::$recipient || '' === self::$recipient['id'] ) {
			return;
		}
		$user_id = get_current_user_id();
		$id      = self::$recipient['id'];
		if ( 'new' === $id ) {
			$id = ! empty( $_POST['zg_b2b_contact_save'] ) ? self::add( $user_id, self::$recipient ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $id ) {
			update_user_meta( $user_id, self::META_LAST, $id );
		}
	}

	/* ---------- Můj účet → Kontaktní osoby (samostatná položka menu vedle Adres) ---------- */

	const ENDPOINT = 'zg-b2b-contacts';
	const SLUG_CS  = 'kontaktni-osoby';
	const SLUG_EN  = 'contact-persons';

	public static function register_endpoints() {
		add_rewrite_endpoint( self::SLUG_CS, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::SLUG_EN, EP_ROOT | EP_PAGES );
	}

	public static function query_vars( $vars ) {
		$lang                   = apply_filters( 'wpml_current_language', null );
		$vars[ self::ENDPOINT ] = ( $lang && 'cs' !== $lang ) ? self::SLUG_EN : self::SLUG_CS;
		return $vars;
	}

	public static function menu_items( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'edit-address' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Kontaktní osoby', 'zg-b2b' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Kontaktní osoby', 'zg-b2b' );
		}
		return $out;
	}

	public static function endpoint_title( $title ) {
		return __( 'Kontaktní osoby', 'zg-b2b' );
	}

	public static function url() {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	private static function is_page() {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	public static function account_assets() {
		if ( self::is_page() ) {
			self::enqueue();
		}
	}

	private static function enqueue() {
		wp_enqueue_style( 'zg-b2b-addresses', ZG_B2B_URL . 'assets/css/zg-b2b-addresses.css', array(), ZG_B2B_VERSION );
		wp_enqueue_script( 'zg-b2b-contacts-account', ZG_B2B_URL . 'assets/js/zg-b2b-contacts-account.js', array( 'jquery' ), ZG_B2B_VERSION, true );
		wp_localize_script( 'zg-b2b-contacts-account', 'zgB2bContacts', array(
			'confirmDelete' => __( 'Smazat tuto kontaktní osobu?', 'zg-b2b' ),
		) );
	}

	public static function render_endpoint() {
		$user_id = get_current_user_id();
		$list    = self::get_all( $user_id );
		self::enqueue();

		echo '<div class="zg-b2b-addr zg-b2b-contacts">';
		echo '<p class="zg-b2b-addr-intro">' . esc_html__( 'Kontaktní osoby jsou příjemci zásilek (jméno a telefon pro dopravce). V pokladně si je jen vyberete.', 'zg-b2b' ) . '</p>';
		echo '<div class="zg-b2b-addr-section-head"><h2 class="zg-b2b-addr-section-title">' . esc_html__( 'Kontaktní osoby pro doručení', 'zg-b2b' ) . '</h2>';
		if ( count( $list ) < self::MAX ) {
			echo '<button type="button" class="zg-b2b-addr-btn zg-b2b-addr-btn--primary" data-zg-contact-open="new">+ ' . esc_html__( 'Přidat kontaktní osobu', 'zg-b2b' ) . '</button>';
		}
		echo '</div>';

		if ( ! $list ) {
			echo '<p class="zg-b2b-addr-empty">' . esc_html__( 'Zatím nemáte uloženou žádnou kontaktní osobu.', 'zg-b2b' ) . '</p>';
		} else {
			echo '<div class="zg-b2b-addr-grid">';
			foreach ( $list as $id => $c ) {
				echo '<div class="zg-b2b-addr-card">';
				echo '<div class="zg-b2b-addr-card__head"><h3 class="zg-b2b-addr-card__label">' . esc_html( self::format( $c ) ) . '</h3>';
				echo '<button type="button" class="zg-b2b-addr-btn" data-zg-contact-open="' . esc_attr( $id ) . '">' . esc_html__( 'Upravit', 'zg-b2b' ) . '</button></div>';
				echo '<p class="zg-b2b-addr-card__address">' . esc_html( $c['phone'] ) . '</p>';
				echo '<div class="zg-b2b-addr-card__actions"><form method="post" class="zg-b2b-addr-inline-form zg-b2b-contact-delete">';
				wp_nonce_field( 'zg_b2b_contact_delete_' . $id, '_zg_b2b_contact_nonce' );
				echo '<input type="hidden" name="zg_b2b_contact_delete" value="' . esc_attr( $id ) . '">';
				echo '<button type="submit" class="zg-b2b-addr-btn zg-b2b-addr-btn--small zg-b2b-addr-btn--danger">' . esc_html__( 'Smazat', 'zg-b2b' ) . '</button>';
				echo '</form></div></div>';
			}
			echo '</div>';
		}

		// Pop-up: přidání / úprava.
		$data = array();
		foreach ( $list as $id => $c ) {
			$data[ $id ] = array( 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'phone' => $c['phone'] );
		}
		echo '<div class="zg-b2b-addr-modal" id="zg-b2b-contact-modal" hidden data-contacts="' . esc_attr( wp_json_encode( (object) $data ) ) . '">';
		echo '<div class="zg-b2b-addr-modal__backdrop" data-zg-modal-close></div>';
		echo '<div class="zg-b2b-addr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="zg-b2b-contact-modal-title">';
		echo '<div class="zg-b2b-addr-modal__head"><h2 class="zg-b2b-addr-modal__title" id="zg-b2b-contact-modal-title" data-title-new="' . esc_attr__( 'Nová kontaktní osoba', 'zg-b2b' ) . '" data-title-edit="' . esc_attr__( 'Upravit kontaktní osobu', 'zg-b2b' ) . '">' . esc_html__( 'Nová kontaktní osoba', 'zg-b2b' ) . '</h2>';
		echo '<button type="button" class="zg-b2b-addr-modal__close" data-zg-modal-close aria-label="' . esc_attr__( 'Zavřít', 'zg-b2b' ) . '">&times;</button></div>';
		echo '<div class="zg-b2b-addr-modal__body">';
		echo '<form method="post" action="' . esc_url( self::url() ) . '" class="zg-b2b-addr-form zg-b2b-addr-form--modal">';
		echo '<div class="woocommerce-address-fields"><div class="woocommerce-address-fields__field-wrapper">';
		$fields = array(
			'first_name' => array( __( 'Jméno', 'zg-b2b' ), 'text', 'given-name', 'form-row-first' ),
			'last_name'  => array( __( 'Příjmení', 'zg-b2b' ), 'text', 'family-name', 'form-row-last' ),
			'phone'      => array( __( 'Telefon', 'zg-b2b' ), 'tel', 'tel', 'form-row-wide' ),
		);
		foreach ( $fields as $k => $f ) {
			woocommerce_form_field( 'zg_b2b_contact_' . $k, array(
				'type'         => $f[1],
				'label'        => $f[0],
				'required'     => true,
				'autocomplete' => $f[2],
				'class'        => array( $f[3] ),
			), '' );
		}
		echo '</div></div>';
		wp_nonce_field( 'zg_b2b_contact_save', '_zg_b2b_contact_nonce' );
		echo '<input type="hidden" name="zg_b2b_contact_action" value="save"><input type="hidden" name="zg_b2b_contact_id" value="">';
		echo '<p class="zg-b2b-addr-form__buttons"><button type="submit" class="zg-b2b-addr-btn zg-b2b-addr-btn--primary">' . esc_html__( 'Uložit', 'zg-b2b' ) . '</button> ';
		echo '<button type="button" class="zg-b2b-addr-btn" data-zg-modal-close>' . esc_html__( 'Zrušit', 'zg-b2b' ) . '</button></p>';
		echo '</form></div></div></div>';
		echo '</div>';
	}

	/** Uložení (nová / úprava) a smazání kontaktní osoby. */
	public static function handle_account_post() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $_POST['zg_b2b_contact_delete'] ) ) {
			$id = sanitize_key( wp_unslash( $_POST['zg_b2b_contact_delete'] ) );
			if ( ! isset( $_POST['_zg_b2b_contact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zg_b2b_contact_nonce'] ) ), 'zg_b2b_contact_delete_' . $id ) ) {
				return;
			}
			self::delete( $user_id, $id );
			wc_add_notice( __( 'Kontaktní osoba byla smazána.', 'zg-b2b' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		if ( empty( $_POST['zg_b2b_contact_action'] ) || 'save' !== $_POST['zg_b2b_contact_action'] ) {
			return;
		}
		if ( ! isset( $_POST['_zg_b2b_contact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zg_b2b_contact_nonce'] ) ), 'zg_b2b_contact_save' ) ) {
			return;
		}
		$c  = self::sanitize( array(
			'first_name' => isset( $_POST['zg_b2b_contact_first_name'] ) ? wp_unslash( $_POST['zg_b2b_contact_first_name'] ) : '',
			'last_name'  => isset( $_POST['zg_b2b_contact_last_name'] ) ? wp_unslash( $_POST['zg_b2b_contact_last_name'] ) : '',
			'phone'      => isset( $_POST['zg_b2b_contact_phone'] ) ? wp_unslash( $_POST['zg_b2b_contact_phone'] ) : '',
		) );
		$id = isset( $_POST['zg_b2b_contact_id'] ) ? sanitize_key( wp_unslash( $_POST['zg_b2b_contact_id'] ) ) : '';
		// phpcs:enable
		if ( '' === $c['first_name'] || '' === $c['last_name'] || '' === $c['phone'] ) {
			wc_add_notice( __( 'Vyplňte prosím jméno, příjmení a telefon.', 'zg-b2b' ), 'error' );
		} else {
			$list = self::get_all( $user_id );
			if ( $id && isset( $list[ $id ] ) ) {
				$list[ $id ] = $c + array( 'id' => $id );
				update_user_meta( $user_id, self::META, $list );
				wc_add_notice( __( 'Kontaktní osoba byla uložena.', 'zg-b2b' ) );
			} elseif ( self::add( $user_id, $c ) ) {
				wc_add_notice( __( 'Kontaktní osoba byla uložena.', 'zg-b2b' ) );
			} else {
				wc_add_notice( __( 'Kontaktní osobu se nepodařilo uložit.', 'zg-b2b' ), 'error' );
			}
		}
		wp_safe_redirect( self::url() );
		exit;
	}
}
