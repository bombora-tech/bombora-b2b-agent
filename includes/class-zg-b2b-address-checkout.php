<?php
/**
 * Checkout: delivery address picker + automatic "Ship to a different address".
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Address_Checkout {

	const META_ID    = '_bombora_shipping_address_id';
	const META_LABEL = '_bombora_shipping_address_label';

	public static function init() {
		add_filter( 'woocommerce_ship_to_different_address_checked', array( __CLASS__, 'auto_check' ), 20 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render_picker' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'store_on_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'maybe_save_new' ), 10, 3 );
		// Adresa zůstává po polích (WMS, dopravci); pobočka se zobrazuje zvlášť.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_label' ) );
		add_action( 'woocommerce_order_details_after_customer_details', array( __CLASS__, 'customer_label' ) );
		add_action( 'woocommerce_email_customer_details', array( __CLASS__, 'email_label' ), 25, 3 );
	}

	/** Is the feature active for the current visitor? */
	private static function active() {
		return is_user_logged_in() && (bool) apply_filters( 'zg_b2b_addr_checkout_enabled', true );
	}

	/**
	 * Which option is preselected: saved address ID, or 'billing'.
	 */
	public static function initial_choice( $user_id ) {
		$default = ZG_B2B_Address_Book::get_default( $user_id );
		if ( ! $default ) {
			return 'billing';
		}
		return ZG_B2B_Address_Book::same_as_billing( $default['fields'], ZG_B2B_Address_Book::billing_fields( $user_id ) ) ? 'billing' : $default['id'];
	}

	/**
	 * Tick "Ship to a different address" when the customer's default delivery
	 * address differs from the billing address.
	 */
	public static function auto_check( $checked ) {
		if ( ! self::active() ) {
			return $checked;
		}
		$user_id = get_current_user_id();
		if ( ZG_B2B_Address_Book::get_all( $user_id ) ) {
			return 'billing' !== self::initial_choice( $user_id );
		}
		// No address book yet: compare the plain WooCommerce profile addresses.
		$customer = new WC_Customer( $user_id );
		if ( ! $customer->get_shipping_address_1() ) {
			return $checked;
		}
		return ! ZG_B2B_Address_Book::same_as_billing(
			array(
				'company'   => $customer->get_shipping_company(),
				'address_1' => $customer->get_shipping_address_1(),
				'postcode'  => $customer->get_shipping_postcode(),
				'city'      => $customer->get_shipping_city(),
				'country'   => $customer->get_shipping_country(),
			),
			ZG_B2B_Address_Book::billing_fields( $user_id )
		);
	}

	/**
	 * Prefill the shipping form from the default saved address (instead of the
	 * address used on the last order).
	 */
	public static function prefill( $value, $input ) {
		if ( 0 !== strpos( $input, 'shipping_' ) || ! self::active() || ! is_checkout() ) {
			return $value;
		}
		$user_id = get_current_user_id();
		$choice  = self::initial_choice( $user_id );
		if ( 'billing' === $choice ) {
			return $value;
		}
		$address = ZG_B2B_Address_Book::get( $user_id, $choice );
		$key     = substr( $input, 9 );
		if ( $address && array_key_exists( $key, $address['fields'] ) ) {
			return $address['fields'][ $key ];
		}
		return $value;
	}

	public static function render_picker( $checkout ) {
		if ( ! self::active() ) {
			return;
		}
		$user_id = get_current_user_id();
		$list    = ZG_B2B_Address_Book::get_all( $user_id );
		$choice  = self::initial_choice( $user_id );

		echo '<div class="zg-b2b-addr-picker" id="zg-b2b-addr-picker">';
		echo '<h3 class="zg-b2b-addr-picker__title">' . esc_html__( 'Doručovací adresa', 'zg-b2b' ) . '</h3>';

		echo '<div class="zg-b2b-choices" data-zg-choices>';
		if ( class_exists( 'ZG_B2B_Companies' ) ) {
			$others = count( $list ) + 1; // + fakturační / ostatní uložené (bez „Jiná adresa“ se počítá zvlášť)
			ZG_B2B_Companies::choices_header( 'delivery', __( 'Doručit na jinou adresu', 'zg-b2b' ), ZG_B2B_Companies::plural( $others, __( '%d adresa', 'zg-b2b' ), __( '%d adresy', 'zg-b2b' ), __( '%d adres', 'zg-b2b' ) ) );
		}

		self::option( 'billing', __( 'Stejná jako fakturační adresa', 'zg-b2b' ), '', 'billing' === $choice );

		foreach ( $list as $address ) {
			self::option( $address['id'], $address['label'], ZG_B2B_Address_Book::format_inline( $address['fields'] ), $choice === $address['id'] );
		}

		self::option( 'new', __( 'Jiná adresa', 'zg-b2b' ), __( 'Zadat novou doručovací adresu', 'zg-b2b' ), false );
		echo '</div>';

		echo '<div class="zg-b2b-addr-picker__save" hidden>';
		echo '<label class="zg-b2b-addr-picker__save-check zg-b2b-addr-check"><input type="checkbox" name="zg_b2b_addr_save_new" value="1" checked> <span>' . esc_html__( 'Uložit adresu do mých doručovacích adres', 'zg-b2b' ) . '</span></label>';
		echo '<input type="text" class="input-text" name="zg_b2b_addr_new_label" maxlength="100" placeholder="' . esc_attr__( 'Název pobočky', 'zg-b2b' ) . '">';
		echo '</div>';

		echo '<p class="zg-b2b-addr-picker__manage"><button type="button" class="zg-b2b-addr-edit" hidden>' . esc_html__( 'Upravit adresu', 'zg-b2b' ) . '</button><a href="' . esc_url( ZG_B2B_Address_Account::url() ) . '">' . esc_html__( 'Spravovat doručovací adresy', 'zg-b2b' ) . '</a></p>';
		echo '</div>';

		if ( class_exists( 'ZG_B2B_Contacts' ) ) {
			ZG_B2B_Contacts::render_checkout();
		}
	}

	private static function option( $value, $label, $detail, $checked ) {
		echo '<label class="zg-b2b-addr-option' . ( $checked ? ' is-selected' : '' ) . '">';
		echo '<input type="radio" name="zg_b2b_addr_choice" value="' . esc_attr( $value ) . '"' . checked( $checked, true, false ) . '>';
		echo '<span class="zg-b2b-addr-option__text"><strong>' . esc_html( $label ) . '</strong>';
		if ( $detail ) {
			echo '<span class="zg-b2b-addr-option__detail">' . esc_html( $detail ) . '</span>';
		}
		echo '</span></label>';
	}

	public static function assets() {
		if ( ! self::active() || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		wp_enqueue_style( 'zg-b2b-addresses', ZG_B2B_URL . 'assets/css/zg-b2b-addresses.css', array(), ZG_B2B_VERSION );
		wp_enqueue_script( 'zg-b2b-choices', ZG_B2B_URL . 'assets/js/zg-b2b-choices.js', array( 'jquery' ), ZG_B2B_VERSION, true );
		wp_enqueue_script( 'zg-b2b-addresses-checkout', ZG_B2B_URL . 'assets/js/zg-b2b-addresses-checkout.js', array( 'jquery', 'wc-checkout' ), ZG_B2B_VERSION, true );

		$user_id   = get_current_user_id();
		$addresses = array();
		foreach ( ZG_B2B_Address_Book::get_all( $user_id ) as $address ) {
			$addresses[ $address['id'] ] = $address['fields'];
		}
		$customer = WC()->customer;
		wp_localize_script( 'zg-b2b-addresses-checkout', 'zgB2bAddrCheckout', array(
			'addresses'      => (object) $addresses,
			'fieldKeys'      => ZG_B2B_Address_Book::field_keys(),
			'billingCountry' => $customer ? $customer->get_billing_country() : WC()->countries->get_base_country(),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'zg_b2b_save_contact' ),
			'saving'         => __( 'Ukládám…', 'zg-b2b' ),
			'saved'          => __( 'Uloženo do adresy', 'zg-b2b' ),
			'error'          => __( 'Nepodařilo se uložit – údaje se použijí jen pro tuto objednávku.', 'zg-b2b' ),
			'done'           => __( 'Hotovo', 'zg-b2b' ),
			'contacts'       => class_exists( 'ZG_B2B_Contacts' ) ? ZG_B2B_Contacts::script_data( $user_id ) : new stdClass(),
		) );
	}

	/** Reject IDs that don't belong to the customer. */
	public static function validate( $data, $errors ) {
		if ( ! self::active() || empty( $_POST['zg_b2b_addr_choice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$choice = sanitize_key( wp_unslash( $_POST['zg_b2b_addr_choice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $choice, array( 'billing', 'new' ), true ) ) {
			return;
		}
		if ( ! ZG_B2B_Address_Book::get( get_current_user_id(), $choice ) ) {
			$errors->add( 'zg_addr', __( 'Vybraná doručovací adresa již neexistuje. Zvolte prosím jinou.', 'zg-b2b' ) );
		}
	}

	/** Store the selected branch on the order (HPOS-safe). */
	public static function store_on_order( $order, $data ) {
		if ( ! self::active() || empty( $_POST['zg_b2b_addr_choice'] ) || empty( $data['ship_to_different_address'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$choice  = sanitize_key( wp_unslash( $_POST['zg_b2b_addr_choice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$address = ZG_B2B_Address_Book::get( get_current_user_id(), $choice );
		if ( $address ) {
			$order->update_meta_data( self::META_ID, $address['id'] );
			$order->update_meta_data( self::META_LABEL, $address['label'] );
		}
	}

	/** "Another address" + "Save" → add it to the address book. */
	public static function maybe_save_new( $order_id, $posted, $order ) {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verified the checkout nonce.
		if ( ! self::active() || empty( $_POST['zg_b2b_addr_choice'] ) || 'new' !== $_POST['zg_b2b_addr_choice'] || empty( $_POST['zg_b2b_addr_save_new'] ) ) {
			return;
		}
		if ( empty( $posted['ship_to_different_address'] ) || ! $order instanceof WC_Order ) {
			return;
		}
		$fields = array();
		foreach ( ZG_B2B_Address_Book::field_keys() as $key ) {
			$getter         = 'get_shipping_' . $key;
			$fields[ $key ] = is_callable( array( $order, $getter ) ) ? (string) $order->$getter() : (string) $order->get_meta( '_shipping_' . $key );
		}
		$label  = isset( $_POST['zg_b2b_addr_new_label'] ) ? wp_unslash( $_POST['zg_b2b_addr_new_label'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$result = ZG_B2B_Address_Book::save( get_current_user_id(), array(
			'label'  => $label,
			'fields' => $fields,
		) );
		// phpcs:enable
		if ( ! is_wp_error( $result ) ) {
			$saved = ZG_B2B_Address_Book::get( get_current_user_id(), $result );
			$order->update_meta_data( self::META_ID, $result );
			$order->update_meta_data( self::META_LABEL, $saved ? $saved['label'] : '' );
			$order->save();
		}
	}

	/** Název pobočky (doručovací adresy) uložený u objednávky. */
	public static function label( $order ) {
		return $order instanceof WC_Order ? (string) $order->get_meta( self::META_LABEL ) : '';
	}

	public static function admin_label( $order ) {
		$label = self::label( $order );
		if ( $label ) {
			echo '<p><strong>' . esc_html__( 'Pobočka / adresa', 'zg-b2b' ) . ':</strong> ' . esc_html( $label ) . '</p>';
		}
	}

	public static function customer_label( $order ) {
		$label = self::label( $order );
		if ( $label ) {
			echo '<p class="zg-b2b-addr-order-label"><strong>' . esc_html__( 'Pobočka / adresa', 'zg-b2b' ) . ':</strong> ' . esc_html( $label ) . '</p>';
		}
	}

	public static function email_label( $order, $sent_to_admin = false, $plain_text = false ) {
		$label = self::label( $order );
		if ( ! $label ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Pobočka / adresa', 'zg-b2b' ) . ': ' . esc_html( $label ) . "\n";
		} else {
			echo '<p style="margin:0 0 16px;"><strong>' . esc_html__( 'Pobočka / adresa', 'zg-b2b' ) . ':</strong> ' . esc_html( $label ) . '</p>';
		}
	}
}
