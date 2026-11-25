<?php
/**
 * Volba země doručení při přidání lihovin (produktů se štítkem) do košíku.
 *
 * Je-li v košíku/pokladně zvolena země mimo povolené (ČR), kliknutí na „Přidat do košíku“
 * u produktu se štítkem otevře okno s volbou země:
 *  – Česko → země se nastaví v košíku i pokladně a zboží se přidá,
 *  – jiná země → upozornění, že tam lihoviny neprodáváme.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Country_Prompt {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_ajax_zg_b2b_set_country', array( __CLASS__, 'ajax_set_country' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 20, 2 );

		// Zvolená země mimo povolené → lihoviny automaticky z košíku pryč + okno s informací.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'remove_forbidden' ), 5 );
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'checkout_fragment' ) );
		add_action( 'woocommerce_before_cart_totals', array( __CLASS__, 'print_slot' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_slot' ) );
	}

	/** Odebere produkty se štítkem, pokud je v košíku zvolena nepovolená země. */
	public static function remove_forbidden( $cart ) {
		static $running = false;
		if ( $running || ( is_admin() && ! wp_doing_ajax() ) || ! WC()->customer || ! WC()->session ) {
			return;
		}
		// Při odeslání objednávky nic tiše neodebírat – zastaví ji validace s chybou.
		if ( doing_action( 'woocommerce_checkout_process' ) || ( isset( $_GET['wc-ajax'] ) && 'checkout' === $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$country = self::current_country();
		if ( ! $country || zg_b2b_country_allowed( $country ) ) {
			return;
		}
		$running = true;
		$removed = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['data'] ) && zg_b2b_is_cz_only( $item['data'] ) ) {
				$removed[] = $item['data']->get_name();
				$cart->remove_cart_item( $key );
			}
		}
		$running = false;
		if ( $removed ) {
			$prev = (array) WC()->session->get( 'zg_b2b_removed', array() );
			WC()->session->set(
				'zg_b2b_removed',
				array(
					'country' => $country,
					'items'   => array_values( array_unique( array_merge( isset( $prev['items'] ) ? (array) $prev['items'] : array(), $removed ) ) ),
				)
			);
		}
	}

	/** HTML „slotu“ s daty pro okno (jednorázově – po vypsání se ze session smaže). */
	private static function slot_html() {
		$data = WC()->session ? WC()->session->get( 'zg_b2b_removed' ) : null;
		$attr = '';
		if ( ! empty( $data['items'] ) ) {
			WC()->session->set( 'zg_b2b_removed', null );
			$attr = ' data-items="' . esc_attr( wp_json_encode( array_values( $data['items'] ) ) ) . '" data-country="' . esc_attr( zg_b2b_country_name( $data['country'] ) ) . '"';
		}
		return '<div class="zg-b2b-removed-slot" hidden' . $attr . '></div>';
	}

	public static function checkout_fragment( $fragments ) {
		$fragments['.zg-b2b-removed-slot'] = self::slot_html();
		return $fragments;
	}

	public static function print_slot() {
		echo self::slot_html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function footer_slot() {
		if ( ! is_user_logged_in() || ! function_exists( 'is_cart' ) || is_cart() ) {
			return; // na stránce košíku je slot v souhrnu košíku
		}
		echo self::slot_html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Aktuální země doručení v košíku (session zákazníka). */
	public static function current_country() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}
		$c = WC()->customer->get_shipping_country();
		if ( ! $c ) {
			$c = WC()->customer->get_billing_country();
		}
		return strtoupper( (string) $c );
	}

	/** ID produktů se štítkem v aktuálním jazyce (+ původní ID). */
	private static function cz_ids() {
		$ids  = array();
		$lang = apply_filters( 'wpml_current_language', null );
		foreach ( zg_b2b_tagged_ids() as $id ) {
			$ids[] = (int) $id;
			if ( $lang ) {
				$ids[] = (int) apply_filters( 'wpml_object_id', $id, 'product', true, $lang );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function assets() {
		if ( ! is_user_logged_in() || ! zg_b2b_can_see_prices() || ! zg_b2b_tagged_ids() ) {
			return;
		}
		$countries = WC()->countries ? WC()->countries->get_shipping_countries() : array();
		$allowed   = (array) zg_b2b_opt( 'allowed_countries' );
		$first     = array_intersect_key( $countries, array_flip( $allowed ) );
		$list      = array();
		foreach ( $first + $countries as $code => $name ) {
			$list[] = array( $code, html_entity_decode( $name ) );
		}

		// Stejný rozbalovací seznam zemí jako v pokladně (selectWoo / Select2 z WooCommerce).
		$deps = array();
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			$deps = array( 'jquery', 'selectWoo' );
			if ( wp_style_is( 'select2', 'registered' ) ) {
				wp_enqueue_style( 'select2' );
			}
		}
		wp_enqueue_script( 'zg-b2b-country', ZG_B2B_URL . 'assets/js/zg-b2b-country.js', $deps, ZG_B2B_VERSION, true );
		wp_localize_script(
			'zg-b2b-country',
			'zgB2BCountry',
			array(
				'ajaxUrl'   => apply_filters( 'wpml_current_language', null ) ? add_query_arg( 'lang', apply_filters( 'wpml_current_language', null ), admin_url( 'admin-ajax.php' ) ) : admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'zg_b2b_country' ),
				'country'   => self::current_country(),
				'allowed'   => array_values( $allowed ),
				'ids'       => self::cz_ids(),
				'countries' => $list,
				'i18n'      => array(
					'title'    => __( 'Zvolte zemi doručení', 'zg-b2b' ),
					'intro'    => __( 'Lihoviny prodáváme pouze v České republice. Vyberte zemi, kam chcete zboží doručit.', 'zg-b2b' ),
					'label'    => __( 'Země doručení', 'zg-b2b' ),
					'hint'     => __( 'Země doručení se změní i v košíku a pokladně.', 'zg-b2b' ),
					'continue' => __( 'Pokračovat', 'zg-b2b' ),
					'cancel'   => __( 'Zrušit', 'zg-b2b' ),
					'close'    => __( 'Zavřít', 'zg-b2b' ),
					'denied'   => __( 'Lihoviny do zvolené země neprodáváme. Do košíku je můžete přidat jen s doručením v České republice.', 'zg-b2b' ),
					'error'    => __( 'Něco se nepovedlo. Zkuste to prosím znovu.', 'zg-b2b' ),
					'remTitle' => __( 'Lihoviny jsme odebrali z košíku', 'zg-b2b' ),
					/* translators: %s: země */
					'remIntro' => __( 'Lihoviny prodáváme pouze v České republice. Protože jste zvolili doručení do země %s, odebrali jsme z košíku:', 'zg-b2b' ),
					'remHint'  => __( 'Pokud je chcete objednat, zvolte doručení v České republice a přidejte je znovu.', 'zg-b2b' ),
					'ok'       => __( 'Rozumím', 'zg-b2b' ),
				),
			)
		);
	}

	/** Nastaví zemi doručení (a fakturační zemi) v session košíku – ne v uloženém účtu. */
	public static function ajax_set_country() {
		check_ajax_referer( 'zg_b2b_country', 'nonce' );
		$country   = isset( $_POST['country'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['country'] ) ) ) : '';
		$countries = WC()->countries ? WC()->countries->get_shipping_countries() : array();
		if ( ! isset( $countries[ $country ] ) || ! WC()->customer ) {
			wp_send_json_error();
		}
		if ( ! zg_b2b_country_allowed( $country ) ) {
			wp_send_json_error( array( 'denied' => true ) );
		}
		WC()->customer->set_shipping_country( $country );
		WC()->customer->set_billing_country( $country );
		WC()->customer->set_calculated_shipping( true );
		WC()->customer->save(); // session zákazníka (adresa v účtu se nemění)
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
		wp_send_json_success( array( 'country' => $country ) );
	}

	/** Pojistka bez JavaScriptu: lihovinu nelze přidat, je-li v košíku zvolena jiná země. */
	public static function validate_add( $passed, $product_id ) {
		if ( ! $passed || ! zg_b2b_is_cz_only( $product_id ) ) {
			return $passed;
		}
		$country = self::current_country();
		if ( $country && ! zg_b2b_country_allowed( $country ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: země */
					__( 'Lihoviny prodáváme pouze v České republice. V košíku máte zvolenou zemi doručení %s – změňte ji na Českou republiku.', 'zg-b2b' ),
					zg_b2b_country_name( $country )
				),
				'error'
			);
			return false;
		}
		return $passed;
	}
}
