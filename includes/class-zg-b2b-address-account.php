<?php
/**
 * My Account → Delivery addresses (address book).
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Address_Account {

	/** @var bool Uložení z pop-upu neprošlo validací → okno se po načtení znovu otevře. */
	private static $save_failed = false;

	/**
	 * Adresář je součástí Můj účet → Adresy (edit-address). Starý samostatný
	 * endpoint (/dorucovaci-adresy/, /delivery-addresses/) jen přesměrovává.
	 */
	const LEGACY_ENDPOINT = 'zg-b2b-addr-addresses';
	const SLUG_CS         = 'dorucovaci-adresy';
	const SLUG_EN         = 'delivery-addresses';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoints' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'wp', array( __CLASS__, 'replace_edit_address' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_customer_save_address', array( 'ZG_B2B_Address_Book', 'on_profile_saved' ), 20, 2 );
	}

	public static function register_endpoints() {
		add_rewrite_endpoint( self::SLUG_CS, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::SLUG_EN, EP_ROOT | EP_PAGES );
	}

	public static function maybe_flush() {
		if ( get_option( 'zg_b2b_addr_flush_rewrite' ) || ZG_B2B_VERSION !== get_option( 'zg_b2b_addr_version' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'zg_b2b_addr_flush_rewrite' );
			update_option( 'zg_b2b_addr_version', ZG_B2B_VERSION );
		}
	}

	public static function query_vars( $vars ) {
		$lang                          = apply_filters( 'wpml_current_language', null );
		$vars[ self::LEGACY_ENDPOINT ] = ( $lang && 'cs' !== $lang ) ? self::SLUG_EN : self::SLUG_CS;
		return $vars;
	}

	/** Starý odkaz → Můj účet → Adresy (se stejnými parametry). */
	public static function redirect_legacy() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::LEGACY_ENDPOINT ) ) {
			$args = array_intersect_key( wp_unslash( $_GET ), array_flip( array( 'zg_addr', 'id' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( self::url( array_map( 'sanitize_key', $args ) ), 301 );
			exit;
		}
	}

	/** URL stránky Můj účet → Adresy. */
	public static function url( $args = array() ) {
		$url = wc_get_account_endpoint_url( 'edit-address' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	public static function is_page() {
		global $wp;
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' ) ) {
			return true;
		}
		// Záloha pro přeložené/upravené endpointy (WPML, Bricks šablona Můj účet).
		return function_exists( 'is_account_page' ) && is_account_page() && isset( $wp->query_vars['edit-address'] );
	}

	/** Vlastní obsah stránky Adresy místo šablony WooCommerce. */
	public static function replace_edit_address() {
		if ( remove_action( 'woocommerce_account_edit-address_endpoint', 'woocommerce_account_edit_address' ) ) {
			add_action( 'woocommerce_account_edit-address_endpoint', array( __CLASS__, 'render' ) );
		}
	}

	public static function assets() {
		if ( self::is_page() ) {
			self::enqueue();
		}
	}

	/** Volá se i přímo z render() – pro případ, že se stránka Adresy vykreslí jinou šablonou. */
	public static function enqueue() {
		if ( wp_style_is( 'zg-b2b-addresses', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'zg-b2b-addresses', ZG_B2B_URL . 'assets/css/zg-b2b-addresses.css', array(), ZG_B2B_VERSION );
		wp_enqueue_script( 'wc-country-select' );
		wp_enqueue_script( 'wc-address-i18n' );
		wp_enqueue_script( 'zg-b2b-addresses-account', ZG_B2B_URL . 'assets/js/zg-b2b-addresses-account.js', array( 'jquery' ), ZG_B2B_VERSION, true );
		wp_localize_script( 'zg-b2b-addresses-account', 'zgB2bAddrAccount', array(
			'confirmDelete'  => __( 'Smazat tuto doručovací adresu?', 'zg-b2b' ),
			'confirmRemove'  => __( 'Odebrat tuto firmu?', 'zg-b2b' ),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'loading'        => __( 'Načítám…', 'zg-b2b' ),
			'filled'         => __( 'Údaje jsme doplnili – zkontrolujte je.', 'zg-b2b' ),
			'error'          => __( 'Údaje se nepodařilo načíst. Vyplňte je prosím ručně.', 'zg-b2b' ),
		) );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $type '' (přehled) | 'billing' | 'shipping' (WooCommerce load_address).
	 */
	public static function render( $type = '' ) {
		$user_id = get_current_user_id();
		$action  = isset( $_GET['zg_addr'] ) ? sanitize_key( $_GET['zg_addr'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$id      = isset( $_GET['id'] ) ? sanitize_key( $_GET['id'] ) : '';   // phpcs:ignore WordPress.Security.NonceVerification
		$type    = wc_edit_address_i18n( sanitize_title( $type ), true );

		self::enqueue();

		// Fakturační adresa: standardní formulář WooCommerce (vč. zámků B2B).
		if ( 'billing' === $type ) {
			woocommerce_account_edit_address( 'billing' );
			return;
		}

		echo '<div class="zg-b2b-addr">';

		if ( 'shipping' === $type ) {
			// Původní „Doručovací adresa → Upravit“ = úprava výchozí adresy adresáře.
			self::render_form( ZG_B2B_Address_Book::get_default( $user_id ) );
		} elseif ( 'add' === $action || ( 'edit' === $action && $id ) ) {
			$address = $id ? ZG_B2B_Address_Book::get( $user_id, $id ) : null;
			if ( 'edit' === $action && ! $address ) {
				wc_print_notice( __( 'Adresa nebyla nalezena.', 'zg-b2b' ), 'error' );
			} else {
				self::render_form( $address );
			}
		} else {
			self::render_overview( $user_id );
			self::render_modal( $user_id );
		}

		echo '</div>';
	}

	private static function render_overview( $user_id ) {
		$list = ZG_B2B_Address_Book::get_all( $user_id );

		echo '<p class="zg-b2b-addr-intro">' . esc_html__( 'Fakturační adresa se uvádí na dokladech. Doručovací adresy (provozovny) si v pokladně jen vyberete a adresa se doplní.', 'zg-b2b' ) . '</p>';

		// Firmy (hlavní = fakturační adresa účtu + další firmy ke schválení).
		ZG_B2B_Companies::render_account_section( $user_id );

		echo '<div class="zg-b2b-addr-section-head"><h2 class="zg-b2b-addr-section-title">' . esc_html__( 'Doručovací adresy', 'zg-b2b' ) . '</h2>';
		if ( count( $list ) < ZG_B2B_Address_Book::max_addresses() ) {
			echo '<a class="zg-b2b-addr-btn zg-b2b-addr-btn--primary" data-zg-addr-open="new" href="' . esc_url( self::url( array( 'zg_addr' => 'add' ) ) ) . '">+ ' . esc_html__( 'Přidat doručovací adresu', 'zg-b2b' ) . '</a>';
		}
		echo '</div>';

		if ( ! $list ) {
			echo '<p class="zg-b2b-addr-empty">' . esc_html__( 'Zatím nemáte uloženou žádnou doručovací adresu.', 'zg-b2b' ) . '</p>';
			return;
		}

		echo '<div class="zg-b2b-addr-grid">';
		foreach ( $list as $address ) {
			$is_default = ! empty( $address['default'] );
			echo '<div class="zg-b2b-addr-card' . ( $is_default ? ' zg-b2b-addr-card--default' : '' ) . '">';
			echo '<div class="zg-b2b-addr-card__head"><h3 class="zg-b2b-addr-card__label">' . esc_html( $address['label'] );
			if ( $is_default ) {
				echo ' <span class="zg-b2b-addr-badge">' . esc_html__( 'Výchozí', 'zg-b2b' ) . '</span>';
			}
			echo '</h3>';
			echo '<a class="zg-b2b-addr-btn" data-zg-addr-open="' . esc_attr( $address['id'] ) . '" href="' . esc_url( self::url( array( 'zg_addr' => 'edit', 'id' => $address['id'] ) ) ) . '">' . esc_html__( 'Upravit', 'zg-b2b' ) . '</a></div>';
			echo '<address class="zg-b2b-addr-card__address">' . wp_kses_post( ZG_B2B_Address_Book::format( $address['fields'] ) ) . '</address>';
			echo '<div class="zg-b2b-addr-card__actions">';
			if ( ! $is_default ) {
				self::action_button( 'default', $address['id'], __( 'Nastavit jako výchozí', 'zg-b2b' ) );
			}
			self::action_button( 'delete', $address['id'], __( 'Smazat', 'zg-b2b' ), 'zg-b2b-addr-btn--danger' );
			echo '</div></div>';
		}
		echo '</div>';
	}

	/** Pop-up s formulářem pro přidání / úpravu doručovací adresy. */
	private static function render_modal( $user_id ) {
		$data = array();
		foreach ( ZG_B2B_Address_Book::get_all( $user_id ) as $a ) {
			$data[ $a['id'] ] = array(
				'label'   => $a['label'],
				'default' => ! empty( $a['default'] ),
				'fields'  => $a['fields'],
			);
		}
		$posted_id = isset( $_POST['zg_b2b_addr_id'] ) ? sanitize_key( wp_unslash( $_POST['zg_b2b_addr_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$reopen    = self::$save_failed ? ( $posted_id ? $posted_id : 'new' ) : '';

		echo '<div class="zg-b2b-addr-modal" id="zg-b2b-addr-modal" hidden'
			. ' data-addresses="' . esc_attr( wp_json_encode( (object) $data ) ) . '"'
			. ' data-base-country="' . esc_attr( WC()->countries->get_base_country() ) . '"'
			. ( $reopen ? ' data-reopen="' . esc_attr( $reopen ) . '"' : '' ) . '>';
		echo '<div class="zg-b2b-addr-modal__backdrop" data-zg-addr-close></div>';
		echo '<div class="zg-b2b-addr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="zg-b2b-addr-modal-title">';
		echo '<div class="zg-b2b-addr-modal__head">';
		echo '<h2 class="zg-b2b-addr-modal__title" id="zg-b2b-addr-modal-title" data-title-new="' . esc_attr__( 'Nová doručovací adresa', 'zg-b2b' ) . '" data-title-edit="' . esc_attr__( 'Upravit doručovací adresu', 'zg-b2b' ) . '">' . esc_html__( 'Nová doručovací adresa', 'zg-b2b' ) . '</h2>';
		echo '<button type="button" class="zg-b2b-addr-modal__close" data-zg-addr-close aria-label="' . esc_attr__( 'Zavřít', 'zg-b2b' ) . '">&times;</button>';
		echo '</div>';
		echo '<div class="zg-b2b-addr-modal__body">';
		self::render_form( null, true );
		echo '</div></div></div>';
	}

	private static function action_button( $action, $id, $label, $class = '' ) {
		echo '<form method="post" class="zg-b2b-addr-inline-form">';
		wp_nonce_field( 'zg_b2b_addr_' . $action . '_' . $id, '_zg_b2b_addr_nonce' );
		echo '<input type="hidden" name="zg_b2b_addr_action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="zg_b2b_addr_id" value="' . esc_attr( $id ) . '">';
		echo '<button type="submit" class="zg-b2b-addr-btn zg-b2b-addr-btn--small ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * @param array|null $address Adresa k úpravě (null = nová).
	 * @param bool       $modal   Vykreslit pro pop-up (bez nadpisu, „Zrušit“ zavírá okno).
	 */
	private static function render_form( $address, $modal = false ) {
		$fields_values = $address ? $address['fields'] : array();
		// Keep posted values after a validation error.
		if ( isset( $_POST['zg_b2b_addr_action'] ) && 'save' === $_POST['zg_b2b_addr_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			foreach ( ZG_B2B_Address_Book::field_keys() as $key ) {
				if ( isset( $_POST[ 'shipping_' . $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					$fields_values[ $key ] = sanitize_text_field( wp_unslash( $_POST[ 'shipping_' . $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				}
			}
		}
		$country = ! empty( $fields_values['country'] ) ? $fields_values['country'] : WC()->countries->get_base_country();
		$label   = $address ? $address['label'] : '';
		if ( isset( $_POST['zg_b2b_addr_label'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$label = sanitize_text_field( wp_unslash( $_POST['zg_b2b_addr_label'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$id = $address ? $address['id'] : '';

		if ( ! $modal ) {
			echo '<h2 class="zg-b2b-addr-section-title">' . esc_html( $address ? __( 'Upravit doručovací adresu', 'zg-b2b' ) : __( 'Nová doručovací adresa', 'zg-b2b' ) ) . '</h2>';
		}
		echo '<form method="post" action="' . esc_url( self::url() ) . '" class="zg-b2b-addr-form' . ( $modal ? ' zg-b2b-addr-form--modal' : '' ) . '" novalidate>';
		echo '<div class="woocommerce-address-fields"><div class="woocommerce-address-fields__field-wrapper">';

		woocommerce_form_field( 'zg_b2b_addr_label', array(
			'type'        => 'text',
			'label'       => __( 'Název adresy (např. název pobočky)', 'zg-b2b' ),
			'placeholder' => __( 'např. Restaurace Vinohrady', 'zg-b2b' ),
			'class'       => array( 'form-row-wide' ),
			'required'    => false,
			'priority'    => 1, // wc-address-i18n řadí pole podle priority.
		), $label );

		foreach ( ZG_B2B_Address_Book::field_definitions( $country ) as $key => $def ) {
			woocommerce_form_field( 'shipping_' . $key, $def, isset( $fields_values[ $key ] ) ? $fields_values[ $key ] : ( 'country' === $key ? $country : '' ) );
		}

		woocommerce_form_field( 'zg_b2b_addr_default', array(
			'type'  => 'checkbox',
			'label'    => __( 'Použít jako výchozí doručovací adresu', 'zg-b2b' ),
			'class'    => array( 'form-row-wide' ),
			'priority' => 1000,
		), ( $address && ! empty( $address['default'] ) ) ? 1 : 0 );

		echo '</div></div>';
		wp_nonce_field( 'zg_b2b_addr_save', '_zg_b2b_addr_nonce' );
		echo '<input type="hidden" name="zg_b2b_addr_action" value="save">';
		echo '<input type="hidden" name="zg_b2b_addr_id" value="' . esc_attr( $id ) . '">';
		echo '<p class="zg-b2b-addr-form__buttons"><button type="submit" class="zg-b2b-addr-btn zg-b2b-addr-btn--primary">' . esc_html__( 'Uložit adresu', 'zg-b2b' ) . '</button> ';
		if ( $modal ) {
			echo '<button type="button" class="zg-b2b-addr-btn" data-zg-addr-close>' . esc_html__( 'Zrušit', 'zg-b2b' ) . '</button></p>';
		} else {
			echo '<a class="zg-b2b-addr-btn" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Zrušit', 'zg-b2b' ) . '</a></p>';
		}
		echo '</form>';
	}

	/* ---------------------------------------------------------------------
	 * POST handling
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['zg_b2b_addr_action'] ) || ! is_user_logged_in() || ! self::is_page() ) {
			return;
		}
		$action  = sanitize_key( wp_unslash( $_POST['zg_b2b_addr_action'] ) );
		$id      = isset( $_POST['zg_b2b_addr_id'] ) ? sanitize_key( wp_unslash( $_POST['zg_b2b_addr_id'] ) ) : '';
		$nonce   = isset( $_POST['_zg_b2b_addr_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_zg_b2b_addr_nonce'] ) ) : '';
		$user_id = get_current_user_id();

		$nonce_action = ( 'save' === $action ) ? 'zg_b2b_addr_save' : 'zg_b2b_addr_' . $action . '_' . $id;
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wc_add_notice( __( 'Platnost odkazu vypršela, zkuste to prosím znovu.', 'zg-b2b' ), 'error' );
			return;
		}

		switch ( $action ) {
			case 'delete':
				ZG_B2B_Address_Book::delete( $user_id, $id );
				wc_add_notice( __( 'Doručovací adresa byla smazána.', 'zg-b2b' ) );
				wp_safe_redirect( self::url() );
				exit;

			case 'default':
				ZG_B2B_Address_Book::set_default( $user_id, $id );
				wc_add_notice( __( 'Výchozí doručovací adresa byla změněna.', 'zg-b2b' ) );
				wp_safe_redirect( self::url() );
				exit;

			case 'save':
				$fields = self::validate_posted_fields();
				if ( false === $fields ) {
					self::$save_failed = true;
					return; // Notices added, form re-rendered with posted values.
				}
				$result = ZG_B2B_Address_Book::save( $user_id, array(
					'id'      => $id,
					'label'   => isset( $_POST['zg_b2b_addr_label'] ) ? wp_unslash( $_POST['zg_b2b_addr_label'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'default' => ! empty( $_POST['zg_b2b_addr_default'] ),
					'fields'  => $fields,
				) );
				if ( is_wp_error( $result ) ) {
					wc_add_notice( $result->get_error_message(), 'error' );
					self::$save_failed = true;
					return;
				}
				wc_add_notice( __( 'Doručovací adresa byla uložena.', 'zg-b2b' ) );
				wp_safe_redirect( self::url() );
				exit;
		}
	}

	/**
	 * Validate posted shipping_* fields like WooCommerce does on edit-address.
	 *
	 * @return array|false Unslashed raw values or false on error.
	 */
	private static function validate_posted_fields() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in handle_post().
		$country = isset( $_POST['shipping_country'] ) ? wc_clean( wp_unslash( $_POST['shipping_country'] ) ) : '';
		$defs    = ZG_B2B_Address_Book::field_definitions( $country );
		$values  = array();
		$ok      = true;

		foreach ( $defs as $key => $def ) {
			$value          = isset( $_POST[ 'shipping_' . $key ] ) ? wc_clean( wp_unslash( $_POST[ 'shipping_' . $key ] ) ) : '';
			$values[ $key ] = $value;
			$label          = isset( $def['label'] ) ? $def['label'] : $key;

			if ( ! empty( $def['required'] ) && '' === $value ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( $label ) . '</strong>' ), 'error' );
				$ok = false;
			}
		}

		$allowed = WC()->countries->get_shipping_countries();
		if ( $country && ! isset( $allowed[ $country ] ) ) {
			wc_add_notice( __( 'Do této země nedoručujeme.', 'zg-b2b' ), 'error' );
			$ok = false;
		}

		if ( $country && ! empty( $values['postcode'] ) && ! WC_Validation::is_postcode( $values['postcode'], $country ) ) {
			wc_add_notice( __( 'Please enter a valid postcode / ZIP.', 'woocommerce' ), 'error' );
			$ok = false;
		}
		// phpcs:enable

		return $ok ? $values : false;
	}
}
