<?php
/**
 * Ceny a nákup jen pro schválené B2B partnery.
 *
 * Ceny se pro ostatní vyprázdní už na úrovni produktu, takže neuniknou ani přes
 * dynamická data Bricks, schema Rank Math/WooCommerce nebo Store API.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Prices {

	public static function init() {
		// Nativní registrace WooCommerce/WordPress vypnutá – registruje se jen přes B2B formulář.
		add_filter( 'pre_option_woocommerce_enable_myaccount_registration', array( __CLASS__, 'opt_no' ) );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', array( __CLASS__, 'opt_no' ) );
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', array( __CLASS__, 'opt_no' ) );
		add_filter( 'pre_option_users_can_register', array( __CLASS__, 'opt_zero' ) );
		add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'block_native_registration' ), 1 );
		add_filter( 'registration_errors', array( __CLASS__, 'block_native_registration' ), 1 );

		// Neschválené účty se nepřihlásí.
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'auth_gate' ), 30 );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'login_cta' ) );

		// Skrytí cen.
		$price_filters = array(
			'woocommerce_product_get_price',
			'woocommerce_product_get_regular_price',
			'woocommerce_product_get_sale_price',
			'woocommerce_product_variation_get_price',
			'woocommerce_product_variation_get_regular_price',
			'woocommerce_product_variation_get_sale_price',
			'woocommerce_variation_prices_price',
			'woocommerce_variation_prices_regular_price',
			'woocommerce_variation_prices_sale_price',
		);
		foreach ( $price_filters as $f ) {
			add_filter( $f, array( __CLASS__, 'blank' ), 9999 );
		}
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_hash' ), 99 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 9999, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 9999 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 9999 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_link' ), 9999, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_gate' ), 30 );
		add_shortcode( 'zg_b2b_price_gate', array( __CLASS__, 'shortcode_gate' ) );

		// Strukturovaná data – bez nabídky/ceny.
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'strip_wc_schema' ), 99 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'strip_rank_math_schema' ), 999 );
	}

	public static function opt_no() {
		return 'no';
	}

	public static function opt_zero() {
		return '0';
	}

	/** Mají se v tomto požadavku ceny skrýt? */
	public static function hide() {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		// Platební brány a callbacky (wc-api) – nic neskrývat.
		if ( isset( $_GET['wc-api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		// REST: skrývat jen ve veřejném Store API (ostatní WC REST vyžaduje přihlášení).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( false === strpos( $uri, 'wc/store' ) ) {
				return false;
			}
		}
		return ! zg_b2b_can_see_prices();
	}

	public static function blank( $value ) {
		return self::hide() ? '' : $value;
	}

	public static function variation_hash( $hash ) {
		$hash[] = self::hide() ? 'zg-b2b-hidden' : 'zg-b2b-visible';
		return $hash;
	}

	public static function purchasable( $purchasable ) {
		return self::hide() ? false : $purchasable;
	}

	private static function is_logged_unapproved() {
		return is_user_logged_in() && ! zg_b2b_can_see_prices();
	}

	public static function price_html( $html, $product ) {
		if ( ! self::hide() ) {
			return $html;
		}
		if ( self::is_logged_unapproved() ) {
			return '<span class="zg-b2b-price-locked is-pending">' . zg_b2b_icon( 'lock' ) . '<span>' . esc_html__( 'Ceny po schválení účtu', 'zg-b2b' ) . '</span></span>';
		}
		return '<a class="zg-b2b-price-locked" href="' . esc_url( zg_b2b_login_url() ) . '">' . zg_b2b_icon( 'lock' ) . '<span>' . esc_html__( 'Ceny po přihlášení', 'zg-b2b' ) . '</span></a>';
	}

	public static function loop_link( $html, $product ) {
		if ( ! self::hide() ) {
			return $html;
		}
		return '<a class="button zg-b2b-loop-login" href="' . esc_url( zg_b2b_login_url() ) . '">' . esc_html__( 'Přihlásit se', 'zg-b2b' ) . '</a>';
	}

	public static function gate_html() {
		if ( ! self::hide() ) {
			return '';
		}
		if ( self::is_logged_unapproved() ) {
			$title   = __( 'Váš účet zatím není schválen', 'zg-b2b' );
			$text    = sprintf(
				/* translators: %s: e-mail */
				__( 'Velkoobchodní ceny a objednávky se zpřístupní po schválení účtu. Dotazy: %s', 'zg-b2b' ),
				'<a href="mailto:' . esc_attr( zg_b2b_contact_email() ) . '">' . esc_html( zg_b2b_contact_email() ) . '</a>'
			);
			$actions = '';
		} else {
			$title   = __( 'Velkoobchodní ceny pro registrované partnery', 'zg-b2b' );
			$text    = esc_html__( 'Přihlaste se, nebo si zaregistrujte firemní účet. Po schválení uvidíte ceny bez DPH i s DPH a můžete objednávat.', 'zg-b2b' );
			$actions = zg_b2b_button( zg_b2b_registration_url(), __( 'Registrovat firmu', 'zg-b2b' ), 'primary', 'arrow' )
				. zg_b2b_button( zg_b2b_login_url(), __( 'Přihlásit se', 'zg-b2b' ), 'outline', 'login' );
		}
		return '<div class="zg-b2b-gate">'
			. '<span class="zg-b2b-gate__icon">' . zg_b2b_icon( 'lock' ) . '</span>'
			. '<div class="zg-b2b-gate__body">'
			. '<p class="zg-b2b-gate__title">' . esc_html( $title ) . '</p>'
			. '<p class="zg-b2b-gate__text">' . wp_kses_post( $text ) . '</p>'
			. ( $actions ? '<div class="zg-b2b-gate__actions">' . $actions . '</div>' : '' )
			. '</div></div>';
	}

	public static function single_gate() {
		echo self::gate_html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function shortcode_gate() {
		return self::gate_html();
	}

	public static function strip_wc_schema( $markup ) {
		if ( self::hide() && is_array( $markup ) ) {
			unset( $markup['offers'] );
		}
		return $markup;
	}

	private static function strip_offers_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$type = isset( $data['@type'] ) ? (array) $data['@type'] : array();
		if ( array_intersect( $type, array( 'Product', 'ProductGroup' ) ) ) {
			unset( $data['offers'] );
		}
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = self::strip_offers_recursive( $v );
			}
		}
		return $data;
	}

	public static function strip_rank_math_schema( $data ) {
		return self::hide() ? self::strip_offers_recursive( $data ) : $data;
	}

	/* ---------------------------------------------------------------------
	 * Registrace a přihlášení
	 * ------------------------------------------------------------------- */

	public static function block_native_registration( $errors ) {
		if ( defined( 'ZG_B2B_REGISTERING' ) || ! is_wp_error( $errors ) ) {
			return $errors;
		}
		$errors->add(
			'zg_b2b_registration',
			sprintf(
				/* translators: %s: URL registrace */
				__( 'Registrace velkoobchodních partnerů probíhá přes <a href="%s">registrační formulář</a>.', 'zg-b2b' ),
				esc_url( zg_b2b_registration_url() )
			)
		);
		return $errors;
	}

	public static function auth_gate( $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}
		$status = zg_b2b_user_status( $user->ID );
		if ( 'pending' === $status ) {
			return new WP_Error( 'zg_b2b_pending', __( 'Váš velkoobchodní účet čeká na schválení. Jakmile ho schválíme, pošleme vám e-mail.', 'zg-b2b' ) );
		}
		if ( 'rejected' === $status ) {
			return new WP_Error(
				'zg_b2b_rejected',
				sprintf(
					/* translators: %s: e-mail */
					__( 'Registraci se nepodařilo schválit. Kontaktujte nás prosím na %s.', 'zg-b2b' ),
					esc_html( zg_b2b_contact_email() )
				)
			);
		}
		return $user;
	}

	public static function login_cta() {
		echo '<div class="zg-b2b-login-cta"><span>' . esc_html__( 'Nemáte ještě velkoobchodní účet?', 'zg-b2b' ) . '</span>'
			. zg_b2b_button( zg_b2b_registration_url(), __( 'Registrovat firmu', 'zg-b2b' ), 'outline', 'arrow', 'sm' )
			. '</div>';
	}
}
