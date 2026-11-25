<?php
/**
 * Můj účet: přehled firemních údajů z registrace a zamčení údajů,
 * které smí měnit jen velkoobchod (název firmy, IČO, DIČ, volitelně sídlo).
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Account {

	public static function init() {
		// Přehled v Můj účet.
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'dashboard_box' ), 5 );
		add_action( 'woocommerce_before_edit_account_address_form', array( __CLASS__, 'address_notice' ) );
		add_shortcode( 'zg_b2b_account_info', array( __CLASS__, 'shortcode' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ), 999 );

		// Zamčení: formulář adres v Můj účet.
		add_filter( 'woocommerce_address_to_edit', array( __CLASS__, 'lock_address_form' ), 20, 2 );
		add_action( 'init', array( __CLASS__, 'register_process_filters' ), 20 );

		// Zamčení: pokladna.
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'lock_checkout_fields' ), 999 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'force_checkout_data' ), 999 );

		// Pořadí polí IČO/DIČ a umístění tlačítka ARES (WPify Woo) u IČO.
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'field_priorities' ), 1000 );
		add_action( 'wp_footer', array( __CLASS__, 'ares_button_script' ), 50 );

		// Administrace: IČO/DIČ v sekci fakturačních údajů zákazníka (WooCommerce je uloží samo).
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'admin_customer_fields' ) );
	}

	/* ---------------------------------------------------------------------
	 * Které údaje jsou zamčené
	 * ------------------------------------------------------------------- */

	/** Klíče billing polí, které zákazník nesmí měnit. */
	public static function locked_keys() {
		$keys = array();
		$lock = (array) zg_b2b_opt( 'locked_fields' );
		if ( in_array( 'company', $lock, true ) ) {
			$keys[] = 'billing_company';
		}
		if ( in_array( 'ico', $lock, true ) ) {
			$keys[] = zg_b2b_opt( 'ico_meta' );
		}
		if ( in_array( 'dic', $lock, true ) ) {
			$keys[] = zg_b2b_opt( 'dic_meta' );
		}
		if ( in_array( 'address', $lock, true ) ) {
			$keys = array_merge( $keys, array( 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_postcode', 'billing_country', 'billing_state' ) );
		}
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/** Platí zámek pro aktuálního uživatele? (Ne pro správce obchodu.) */
	private static function applies( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $user_id && ! user_can( $user_id, 'manage_woocommerce' );
	}

	/** Uložená hodnota – zamyká se jen pole, které už má hodnotu. */
	private static function stored( $user_id, $key ) {
		return (string) get_user_meta( $user_id, $key, true );
	}

	/* ---------------------------------------------------------------------
	 * Formulář adres v Můj účet
	 * ------------------------------------------------------------------- */

	public static function lock_address_form( $address, $load_address ) {
		if ( 'billing' !== $load_address || ! self::applies() ) {
			return $address;
		}
		$uid = get_current_user_id();
		foreach ( self::locked_keys() as $key ) {
			if ( ! isset( $address[ $key ] ) || '' === self::stored( $uid, $key ) ) {
				continue;
			}
			$address[ $key ] = self::make_readonly( $address[ $key ] );
		}
		return $address;
	}

	private static function make_readonly( $field ) {
		$field['custom_attributes']             = isset( $field['custom_attributes'] ) ? (array) $field['custom_attributes'] : array();
		$field['custom_attributes']['readonly'] = 'readonly';
		$field['custom_attributes']['tabindex'] = '-1';
		$field['class']                         = array_merge( isset( $field['class'] ) ? (array) $field['class'] : array(), array( 'zg-b2b-locked' ) );
		$field['description']                   = __( 'Změnu provede velkoobchod – napište nám.', 'zg-b2b' );
		if ( isset( $field['type'] ) && in_array( $field['type'], array( 'country', 'state', 'select' ), true ) ) {
			$field['input_class'] = array_merge( isset( $field['input_class'] ) ? (array) $field['input_class'] : array(), array( 'zg-b2b-locked-select' ) );
		}
		return $field;
	}

	/** Server-side: při uložení adres se zamčené hodnoty vrátí na uložené. */
	public static function register_process_filters() {
		foreach ( self::locked_keys() as $key ) {
			add_filter(
				'woocommerce_process_myaccount_field_' . $key,
				function ( $value ) use ( $key ) {
					if ( ! self::applies() ) {
						return $value;
					}
					$stored = self::stored( get_current_user_id(), $key );
					return '' !== $stored ? $stored : $value;
				},
				999
			);
		}
	}

	public static function address_notice( $load_address ) {
		if ( 'billing' !== $load_address || ! self::applies() || ! self::locked_keys() ) {
			return;
		}
		echo zg_b2b_notice_box( // phpcs:ignore WordPress.Security.EscapeOutput
			__( 'Firemní údaje', 'zg-b2b' ),
			self::locked_text(),
			'info',
			'lock'
		);
	}

	private static function locked_text() {
		$labels = array();
		$lock   = (array) zg_b2b_opt( 'locked_fields' );
		$map    = array(
			'company' => __( 'název firmy', 'zg-b2b' ),
			'ico'     => __( 'IČO', 'zg-b2b' ),
			'dic'     => __( 'DIČ', 'zg-b2b' ),
			'address' => __( 'sídlo', 'zg-b2b' ),
		);
		foreach ( $map as $k => $label ) {
			if ( in_array( $k, $lock, true ) ) {
				$labels[] = $label;
			}
		}
		$mail = zg_b2b_contact_email();
		$link = '<a href="mailto:' . esc_attr( $mail ) . '?subject=' . rawurlencode( __( 'Změna firemních údajů', 'zg-b2b' ) ) . '">' . esc_html( $mail ) . '</a>';
		return sprintf(
			/* translators: 1: seznam údajů, 2: e-mail */
			esc_html__( 'Údaje %1$s může změnit pouze velkoobchod. Pošlete nám požadovanou změnu na %2$s. Jméno, kontakty a doručovací adresu můžete upravit sami.', 'zg-b2b' ),
			esc_html( implode( ', ', $labels ) ),
			$link
		);
	}

	/* ---------------------------------------------------------------------
	 * Pokladna
	 * ------------------------------------------------------------------- */

	public static function lock_checkout_fields( $fields ) {
		if ( ! is_user_logged_in() || ! self::applies() || empty( $fields['billing'] ) ) {
			return $fields;
		}
		$uid = get_current_user_id();
		foreach ( self::locked_keys() as $key ) {
			if ( isset( $fields['billing'][ $key ] ) && '' !== self::stored( $uid, $key ) ) {
				$fields['billing'][ $key ] = self::make_readonly( $fields['billing'][ $key ] );
			}
		}
		return $fields;
	}

	/** I kdyby někdo hodnotu v pokladně přepsal, objednávka dostane údaje z účtu. */
	public static function force_checkout_data( $data ) {
		if ( ! is_user_logged_in() || ! self::applies() ) {
			return $data;
		}
		$uid = get_current_user_id();
		foreach ( self::locked_keys() as $key ) {
			$stored = self::stored( $uid, $key );
			if ( '' !== $stored ) {
				$data[ $key ] = $stored;
			}
		}
		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Pořadí polí a tlačítko ARES
	 * ------------------------------------------------------------------- */

	/** IČO/DIČ hned za názvem firmy (WooCommerce jinak pole bez pořadí přesune na začátek). */
	public static function field_priorities( $fields ) {
		$order = array(
			'billing_company'                => 30,
			zg_b2b_opt( 'ico_meta' )         => 31,
			zg_b2b_opt( 'dic_meta' )         => 32,
			'billing_dic_dph'                => 33,
		);
		foreach ( $order as $key => $prio ) {
			if ( $key && isset( $fields[ $key ] ) ) {
				$fields[ $key ]['priority'] = $prio;
			}
		}
		return $fields;
	}

	/**
	 * Tlačítko „Hledat v ARESu“ umístí pod pole IČO. Zákazníkům se zamčeným IČO
	 * ho skryje (IČO stejně měnit nemohou).
	 */
	public static function ares_button_script() {
		if ( ! function_exists( 'is_account_page' ) || ! ( is_account_page() || is_checkout() ) ) {
			return;
		}
		$ico_key = zg_b2b_opt( 'ico_meta' );
		$hide    = self::applies() && in_array( $ico_key, self::locked_keys(), true ) && '' !== self::stored( get_current_user_id(), $ico_key );
		// Šablona webu řadí pole přes CSS „order“; blok ARES (WPify) žádné nemá → skočil na začátek.
		echo '<style id="zg-b2b-ares">.wpify-woo-ic-dic__company_field{order:41 !important;margin-top:-6px !important}'
			. ( $hide ? '.wpify-woo-ic-dic__company_field,#wpify-woo-icdic__ares-autofill-button{display:none !important}' : '' )
			. '</style>';
	}

	/* ---------------------------------------------------------------------
	 * Administrace
	 * ------------------------------------------------------------------- */

	public static function admin_customer_fields( $fields ) {
		if ( ! isset( $fields['billing']['fields'] ) ) {
			return $fields;
		}
		$add = array(
			zg_b2b_opt( 'ico_meta' ) => array( 'label' => 'IČO', 'description' => '' ),
			zg_b2b_opt( 'dic_meta' ) => array( 'label' => 'DIČ / IČ DPH', 'description' => '' ),
		);
		$new = array();
		foreach ( $fields['billing']['fields'] as $k => $f ) {
			$new[ $k ] = $f;
			if ( 'billing_company' === $k ) {
				foreach ( $add as $ak => $af ) {
					if ( $ak && ! isset( $fields['billing']['fields'][ $ak ] ) ) {
						$new[ $ak ] = $af;
					}
				}
			}
		}
		foreach ( $add as $ak => $af ) {
			if ( $ak && ! isset( $new[ $ak ] ) ) {
				$new[ $ak ] = $af;
			}
		}
		$fields['billing']['fields'] = $new;
		return $fields;
	}

	/* ---------------------------------------------------------------------
	 * Přehled firemních údajů
	 * ------------------------------------------------------------------- */

	private static function row( $label, $value, $locked = false ) {
		if ( '' === trim( (string) $value ) ) {
			$value = '—';
		}
		return '<tr><th>' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . ( $locked ? ' <span class="zg-b2b-lock" title="' . esc_attr__( 'Mění velkoobchod', 'zg-b2b' ) . '">' . zg_b2b_icon( 'lock' ) . '</span>' : '' ) . '</td></tr>';
	}

	public static function info_html( $user_id ) {
		$m = function ( $k ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $k, true );
		};
		$user   = get_userdata( $user_id );
		$lock   = (array) zg_b2b_opt( 'locked_fields' );
		$types  = array(
			'horeca' => __( 'Restaurace, bar, hotel (HoReCa)', 'zg-b2b' ),
			'retail' => __( 'Vinotéka, prodejna, e-shop', 'zg-b2b' ),
			'gifts'  => __( 'Firemní dárky / vlastní spotřeba', 'zg-b2b' ),
			'other'  => __( 'Jiné', 'zg-b2b' ),
		);
		$status = array(
			'approved' => __( 'Schválený velkoobchodní účet', 'zg-b2b' ),
			'pending'  => __( 'Čeká na schválení', 'zg-b2b' ),
			'rejected' => __( 'Neschválený', 'zg-b2b' ),
			'none'     => '—',
		);
		$st      = zg_b2b_user_status( $user_id );
		$reg     = (int) $m( 'zg_b2b_registered_at' );
		$sidlo   = trim( $m( 'billing_address_1' ) . ( $m( 'billing_address_2' ) ? ', ' . $m( 'billing_address_2' ) : '' ) . "\n" . trim( $m( 'billing_postcode' ) . ' ' . $m( 'billing_city' ) ) . ( $m( 'billing_country' ) ? "\n" . zg_b2b_country_name( $m( 'billing_country' ) ) : '' ) );
		$ship    = trim(
			implode(
				"\n",
				array_filter(
					array(
						$m( 'shipping_company' ),
						trim( $m( 'shipping_first_name' ) . ' ' . $m( 'shipping_last_name' ) ),
						$m( 'shipping_address_1' ),
						trim( $m( 'shipping_postcode' ) . ' ' . $m( 'shipping_city' ) ),
						$m( 'shipping_country' ) ? zg_b2b_country_name( $m( 'shipping_country' ) ) : '',
					)
				)
			)
		);
		$bt      = $m( 'zg_b2b_business_type' );
		$account = wc_get_account_endpoint_url( 'edit-account' );
		$address = wc_get_account_endpoint_url( 'edit-address' );

		$h  = '<section class="zg-b2b-paybox zg-b2b-accinfo">';
		$h .= '<h2 class="zg-b2b-paybox__title">' . esc_html__( 'Firemní údaje', 'zg-b2b' ) . '</h2>';
		$h .= '<table class="zg-b2b-paytable">';
		$h .= self::row( __( 'Název firmy', 'zg-b2b' ), $m( 'billing_company' ), in_array( 'company', $lock, true ) );
		$h .= self::row( __( 'IČO', 'zg-b2b' ), $m( zg_b2b_opt( 'ico_meta' ) ), in_array( 'ico', $lock, true ) );
		$h .= self::row( __( 'DIČ / IČ DPH', 'zg-b2b' ), $m( zg_b2b_opt( 'dic_meta' ) ), in_array( 'dic', $lock, true ) );
		$h .= self::row( __( 'Sídlo', 'zg-b2b' ), $sidlo, in_array( 'address', $lock, true ) );
		$h .= self::row( __( 'Typ podnikání', 'zg-b2b' ), isset( $types[ $bt ] ) ? $types[ $bt ] : '' );
		$h .= self::row( __( 'Stav účtu', 'zg-b2b' ), isset( $status[ $st ] ) ? $status[ $st ] : $st );
		if ( $reg ) {
			$h .= self::row( __( 'Registrace', 'zg-b2b' ), wp_date( 'j. n. Y', $reg ) );
		}
		$h .= '</table>';

		$h .= '<h3 class="zg-b2b-paybox__title">' . esc_html__( 'Kontaktní osoba', 'zg-b2b' ) . '</h3><table class="zg-b2b-paytable">';
		$h .= self::row( __( 'Jméno', 'zg-b2b' ), trim( $m( 'billing_first_name' ) . ' ' . $m( 'billing_last_name' ) ) );
		$h .= self::row( __( 'E-mail (přihlášení)', 'zg-b2b' ), $user ? $user->user_email : '' );
		$h .= self::row( __( 'Fakturační e-mail', 'zg-b2b' ), $m( 'billing_email' ) );
		$h .= self::row( __( 'Telefon', 'zg-b2b' ), $m( 'billing_phone' ) );
		$h .= '</table>';

		$book = class_exists( 'ZG_B2B_Address_Book' ) ? ZG_B2B_Address_Book::get_all( $user_id ) : array();
		$h   .= '<h3 class="zg-b2b-paybox__title">' . esc_html__( 'Doručovací adresy', 'zg-b2b' ) . '</h3><table class="zg-b2b-paytable">';
		if ( $book ) {
			foreach ( $book as $addr ) {
				$h .= self::row(
					$addr['label'] . ( ! empty( $addr['default'] ) ? ' (' . __( 'Výchozí', 'zg-b2b' ) . ')' : '' ),
					str_replace( ', ', "\n", ZG_B2B_Address_Book::format_inline( $addr['fields'] ) )
				);
			}
		} else {
			$h .= self::row( __( 'Adresa', 'zg-b2b' ), $ship );
		}
		$h .= '</table>';

		$h .= '<div class="zg-b2b-gate__actions">'
			. zg_b2b_button( $address, __( 'Upravit adresy', 'zg-b2b' ), 'outline', 'arrow', 'sm' )
			. zg_b2b_button( $account, __( 'Upravit jméno, e-mail a heslo', 'zg-b2b' ), 'outline', 'arrow', 'sm' )
			. '</div>';
		if ( self::locked_keys() ) {
			$h .= '<p class="zg-b2b-paybox__note">' . zg_b2b_icon( 'lock' ) . ' ' . self::locked_text() . '</p>';
		}
		$h .= '</section>';
		return $h;
	}

	/** Skryté položky menu Můj účet (výchozí: Stahování – velkoobchod nemá stahovatelné produkty). */
	public static function menu_items( $items ) {
		foreach ( (array) zg_b2b_opt( 'hidden_account_items' ) as $key ) {
			unset( $items[ $key ] );
		}
		return $items;
	}

	public static function dashboard_box() {
		if ( is_user_logged_in() ) {
			echo self::info_html( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function shortcode() {
		return is_user_logged_in() ? self::info_html( get_current_user_id() ) : '';
	}
}
