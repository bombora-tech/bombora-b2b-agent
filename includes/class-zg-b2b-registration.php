<?php
/**
 * Registrace velkoobchodních partnerů: formulář, ARES/VIES, založení účtu ke schválení.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Registration {

	/** Výsledek zpracování formuláře bez JS (fallback). */
	private static $result = null;

	public static function init() {
		add_shortcode( 'zg_b2b_registration', array( __CLASS__, 'shortcode_form' ) );
		add_shortcode( 'zg_b2b_register_button', array( __CLASS__, 'shortcode_button' ) );
		add_action( 'wp_ajax_zg_b2b_lookup', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_nopriv_zg_b2b_lookup', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_zg_b2b_register', array( __CLASS__, 'ajax_register' ) );
		add_action( 'wp_ajax_nopriv_zg_b2b_register', array( __CLASS__, 'ajax_register' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_script( 'zg-b2b-register', ZG_B2B_URL . 'assets/js/zg-b2b-register.js', array(), ZG_B2B_VERSION, true );
		wp_localize_script(
			'zg-b2b-register',
			'zgB2B',
			array(
				'ajaxUrl'  => apply_filters( 'wpml_current_language', null ) ? add_query_arg( 'lang', apply_filters( 'wpml_current_language', null ), admin_url( 'admin-ajax.php' ) ) : admin_url( 'admin-ajax.php' ),
				'allowed'  => array_values( (array) zg_b2b_opt( 'allowed_countries' ) ),
				'i18n'     => array(
					'loading'      => __( 'Načítám údaje…', 'zg-b2b' ),
					'aresOk'       => __( 'Ověřeno v registru ARES', 'zg-b2b' ),
					'viesOk'       => __( 'DIČ ověřeno ve VIES', 'zg-b2b' ),
					'icoInvalid'   => __( 'IČO musí mít 8 číslic.', 'zg-b2b' ),
					'sending'      => __( 'Odesílám…', 'zg-b2b' ),
					'error'        => __( 'Něco se nepovedlo. Zkuste to prosím znovu.', 'zg-b2b' ),
					'fixErrors'    => __( 'Zkontrolujte prosím označená pole.', 'zg-b2b' ),
					'showPassword' => __( 'Zobrazit heslo', 'zg-b2b' ),
					'hidePassword' => __( 'Skrýt heslo', 'zg-b2b' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Tlačítko
	 * ------------------------------------------------------------------- */

	public static function shortcode_button( $atts ) {
		$atts = shortcode_atts(
			array(
				'text'    => __( 'Registrovat firmu', 'zg-b2b' ),
				'variant' => 'primary',
				'size'    => '',
				'show'    => 'guests',
			),
			$atts,
			'zg_b2b_register_button'
		);
		if ( 'guests' === $atts['show'] && is_user_logged_in() ) {
			return '';
		}
		return zg_b2b_button( zg_b2b_registration_url(), $atts['text'], $atts['variant'], 'arrow', $atts['size'] );
	}

	/* ---------------------------------------------------------------------
	 * Formulář
	 * ------------------------------------------------------------------- */

	private static function business_types() {
		return array(
			'horeca' => __( 'Restaurace, bar, hotel (HoReCa)', 'zg-b2b' ),
			'retail' => __( 'Vinotéka, prodejna, e-shop', 'zg-b2b' ),
			'gifts'  => __( 'Firemní dárky / vlastní spotřeba', 'zg-b2b' ),
			'other'  => __( 'Jiné', 'zg-b2b' ),
		);
	}

	private static function country_options() {
		$codes = (array) zg_b2b_opt( 'reg_countries' );
		$names = array();
		foreach ( $codes as $c ) {
			$names[ $c ] = zg_b2b_country_name( $c );
		}
		$first = array_intersect_key( $names, array_flip( array( 'CZ', 'SK' ) ) );
		$rest  = array_diff_key( $names, $first );
		asort( $rest );
		return $first + $rest;
	}

	private static function time_token() {
		$t = time();
		return $t . '.' . substr( wp_hash( 'zg_b2b_ts|' . $t ), 0, 16 );
	}

	private static function val( $key, $default = '' ) {
		if ( is_array( self::$result ) && isset( self::$result['values'][ $key ] ) ) {
			return self::$result['values'][ $key ];
		}
		return $default;
	}

	private static function err( $key ) {
		if ( is_array( self::$result ) && ! empty( self::$result['errors'][ $key ] ) ) {
			return self::$result['errors'][ $key ];
		}
		return '';
	}

	/**
	 * Jedno pole formuláře.
	 *
	 * @param array $a name, label, type, required, wide, autocomplete, placeholder, options, hint, attrs, addon
	 */
	private static function field( $a ) {
		$a     = wp_parse_args(
			$a,
			array(
				'type'         => 'text',
				'required'     => false,
				'wide'         => false,
				'autocomplete' => '',
				'placeholder'  => '',
				'options'      => array(),
				'hint'         => '',
				'attrs'        => '',
				'addon'        => '',
				'default'      => '',
				'class'        => '',
			)
		);
		$id    = 'zg-b2b-' . $a['name'];
		$error = self::err( $a['name'] );
		$value = self::val( $a['name'], $a['default'] );
		$cls   = 'zg-field' . ( $a['wide'] ? ' zg-field--wide' : '' ) . ( $error ? ' has-error' : '' ) . ( $a['class'] ? ' ' . $a['class'] : '' );
		$req   = $a['required'] ? ' required' : '';

		$out  = '<div class="' . esc_attr( $cls ) . '" data-field="' . esc_attr( $a['name'] ) . '">';
		$out .= '<label class="zg-label" for="' . esc_attr( $id ) . '">' . esc_html( $a['label'] ) . ( $a['required'] ? ' <span class="zg-req" aria-hidden="true">*</span>' : '' ) . '</label>';
		$out .= $a['addon'] ? '<div class="zg-input-group">' : '';

		if ( 'select' === $a['type'] ) {
			$out .= '<select class="zg-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $a['name'] ) . '"' . $req . ' ' . $a['attrs'] . '>';
			foreach ( $a['options'] as $k => $label ) {
				$out .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$out .= '</select>';
		} elseif ( 'textarea' === $a['type'] ) {
			$out .= '<textarea class="zg-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $a['name'] ) . '" rows="3" placeholder="' . esc_attr( $a['placeholder'] ) . '"' . $req . ' ' . $a['attrs'] . '>' . esc_textarea( $value ) . '</textarea>';
		} else {
			$out .= '<input class="zg-input" id="' . esc_attr( $id ) . '" type="' . esc_attr( $a['type'] ) . '" name="' . esc_attr( $a['name'] ) . '"'
				. ( 'password' === $a['type'] ? '' : ' value="' . esc_attr( $value ) . '"' )
				. ( $a['autocomplete'] ? ' autocomplete="' . esc_attr( $a['autocomplete'] ) . '"' : '' )
				. ( $a['placeholder'] ? ' placeholder="' . esc_attr( $a['placeholder'] ) . '"' : '' )
				. $req . ' ' . $a['attrs'] . '>';
		}

		$out .= $a['addon'] ? $a['addon'] . '</div>' : '';
		$out .= $a['hint'] ? '<p class="zg-hint">' . wp_kses_post( $a['hint'] ) . '</p>' : '';
		$out .= '<p class="zg-error" id="' . esc_attr( $id ) . '-error" role="alert">' . esc_html( $error ) . '</p>';
		$out .= '</div>';
		return $out;
	}

	private static function section( $num, $title, $subtitle, $body, $extra_class = '' ) {
		return '<fieldset class="zg-section ' . esc_attr( $extra_class ) . '">'
			. '<legend class="zg-section__head"><span class="zg-step">' . (int) $num . '</span><span><span class="zg-section__title">' . esc_html( $title ) . '</span>'
			. ( $subtitle ? '<span class="zg-section__sub">' . esc_html( $subtitle ) . '</span>' : '' ) . '</span></legend>'
			. '<div class="zg-grid">' . $body . '</div></fieldset>';
	}

	public static function success_html( $company = '' ) {
		return '<div class="zg-b2b-reg zg-b2b-reg--done"><div class="zg-done">'
			. '<span class="zg-done__icon">' . zg_b2b_icon( 'check' ) . '</span>'
			. '<h2 class="zg-done__title">' . esc_html__( 'Děkujeme, registrace je odeslaná', 'zg-b2b' ) . '</h2>'
			. '<p class="zg-done__text">' . sprintf(
				/* translators: 1: firma, 2: e-mail */
				esc_html__( 'Údaje firmy %1$s nyní zkontrolujeme a účet ručně schválíme. Po schválení vám přijde e-mail a uvidíte velkoobchodní ceny. Dotazy: %2$s', 'zg-b2b' ),
				'<strong>' . esc_html( $company ) . '</strong>',
				'<a href="mailto:' . esc_attr( zg_b2b_contact_email() ) . '">' . esc_html( zg_b2b_contact_email() ) . '</a>'
			) . '</p></div></div>';
	}

	public static function shortcode_form() {
		wp_enqueue_script( 'zg-b2b-register' );

		if ( is_user_logged_in() ) {
			$status = zg_b2b_user_status( get_current_user_id() );
			$msg    = 'approved' === $status || current_user_can( 'manage_woocommerce' )
				? __( 'Jste přihlášeni a váš velkoobchodní účet je aktivní.', 'zg-b2b' )
				: __( 'Váš účet čeká na schválení. Po schválení vám pošleme e-mail.', 'zg-b2b' );
			return '<div class="zg-b2b-reg zg-b2b-reg--done"><div class="zg-done"><span class="zg-done__icon">' . zg_b2b_icon( 'check' ) . '</span><p class="zg-done__text">' . esc_html( $msg ) . '</p>'
				. zg_b2b_button( wc_get_page_permalink( 'shop' ), __( 'Přejít do katalogu', 'zg-b2b' ) ) . '</div></div>';
		}

		if ( is_array( self::$result ) && ! empty( self::$result['ok'] ) ) {
			return self::success_html( self::$result['company'] );
		}

		$countries  = self::country_options();
		$default_cc = isset( $countries['CZ'] ) ? 'CZ' : (string) key( $countries );
		$company_cc = self::val( 'company_country', $default_cc );
		$same       = is_array( self::$result ) ? ! empty( self::$result['values']['same_shipping'] ) : true;
		$terms_url  = zg_b2b_page_url( zg_b2b_opt( 'terms_page' ) );
		$privacy    = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		$form_error = self::err( '_form' );

		$lookup_btn = '<button type="button" class="zg-addon-btn" data-zg-lookup="ares">' . esc_html__( 'Načíst z ARES', 'zg-b2b' ) . '</button>';
		$vies_btn   = '<button type="button" class="zg-addon-btn" data-zg-lookup="vies">' . esc_html__( 'Ověřit ve VIES', 'zg-b2b' ) . '</button>';
		$pw_btn     = '<button type="button" class="zg-addon-btn zg-addon-btn--icon" data-zg-toggle-pw aria-label="' . esc_attr__( 'Zobrazit heslo', 'zg-b2b' ) . '">' . zg_b2b_icon( 'eye' ) . '</button>';

		// 1) Firma.
		$s1  = self::field( array( 'name' => 'company_country', 'label' => __( 'Země sídla', 'zg-b2b' ), 'type' => 'select', 'options' => $countries, 'required' => true, 'default' => $company_cc, 'autocomplete' => 'country' ) );
		$s1 .= self::field(
			array(
				'name'        => 'ico',
				'label'       => __( 'IČO', 'zg-b2b' ),
				'required'    => true,
				'attrs'       => 'inputmode="numeric" maxlength="12"',
				'placeholder' => '12345678',
				'addon'       => $lookup_btn,
				'hint'        => __( 'Zadejte IČO – název, sídlo a DIČ doplníme z registru ARES.', 'zg-b2b' ),
				'class'       => 'zg-field--ico',
			)
		);
		$s1 .= '<div class="zg-lookup-status zg-field--wide" data-zg-status aria-live="polite"></div>';
		$s1 .= self::field( array( 'name' => 'company', 'label' => __( 'Název firmy', 'zg-b2b' ), 'required' => true, 'wide' => true, 'autocomplete' => 'organization' ) );
		$s1 .= self::field( array( 'name' => 'street', 'label' => __( 'Ulice a číslo', 'zg-b2b' ), 'required' => true, 'wide' => true, 'autocomplete' => 'address-line1' ) );
		$s1 .= self::field( array( 'name' => 'city', 'label' => __( 'Město', 'zg-b2b' ), 'required' => true, 'autocomplete' => 'address-level2' ) );
		$s1 .= self::field( array( 'name' => 'postcode', 'label' => __( 'PSČ', 'zg-b2b' ), 'required' => true, 'autocomplete' => 'postal-code', 'attrs' => 'maxlength="10"' ) );
		$s1 .= self::field(
			array(
				'name'        => 'dic',
				'label'       => __( 'DIČ / IČ DPH', 'zg-b2b' ),
				'placeholder' => 'CZ12345678',
				'addon'       => $vies_btn,
				'class'       => 'zg-field--dic',
				'hint'        => __( 'U firem z jiných zemí EU je DIČ povinné.', 'zg-b2b' ),
			)
		);
		$s1 .= self::field( array( 'name' => 'business_type', 'label' => __( 'Typ podnikání', 'zg-b2b' ), 'type' => 'select', 'options' => array( '' => __( '— vyberte —', 'zg-b2b' ) ) + self::business_types(), 'required' => true ) );

		// 2) Kontakt.
		$s2  = self::field( array( 'name' => 'first_name', 'label' => __( 'Jméno', 'zg-b2b' ), 'required' => true, 'autocomplete' => 'given-name' ) );
		$s2 .= self::field( array( 'name' => 'last_name', 'label' => __( 'Příjmení', 'zg-b2b' ), 'required' => true, 'autocomplete' => 'family-name' ) );
		$s2 .= self::field( array( 'name' => 'email', 'label' => __( 'E-mail', 'zg-b2b' ), 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'hint' => __( 'Slouží zároveň jako přihlašovací jméno.', 'zg-b2b' ) ) );
		$s2 .= self::field( array( 'name' => 'phone', 'label' => __( 'Telefon', 'zg-b2b' ), 'type' => 'tel', 'required' => true, 'autocomplete' => 'tel', 'placeholder' => '+420 …' ) );

		// 3) Doručení.
		$s3  = '<div class="zg-field zg-field--wide"><label class="zg-switch"><input type="checkbox" name="same_shipping" value="1" data-zg-same' . checked( $same, true, false ) . '><span class="zg-switch__ui" aria-hidden="true"></span><span>' . esc_html__( 'Zboží doručovat na adresu sídla', 'zg-b2b' ) . '</span></label></div>';
		$s3 .= '<div class="zg-shipping zg-grid zg-field--wide" data-zg-shipping' . ( $same ? ' hidden' : '' ) . '>';
		$s3 .= self::field( array( 'name' => 'ship_company', 'label' => __( 'Provozovna / příjemce', 'zg-b2b' ), 'wide' => true, 'placeholder' => __( 'např. Restaurace U Tbilisi', 'zg-b2b' ) ) );
		$s3 .= self::field( array( 'name' => 'ship_street', 'label' => __( 'Ulice a číslo', 'zg-b2b' ), 'wide' => true, 'required' => ! $same, 'autocomplete' => 'shipping address-line1', 'attrs' => 'data-zg-ship-req' ) );
		$s3 .= self::field( array( 'name' => 'ship_city', 'label' => __( 'Město', 'zg-b2b' ), 'required' => ! $same, 'autocomplete' => 'shipping address-level2', 'attrs' => 'data-zg-ship-req' ) );
		$s3 .= self::field( array( 'name' => 'ship_postcode', 'label' => __( 'PSČ', 'zg-b2b' ), 'required' => ! $same, 'autocomplete' => 'shipping postal-code', 'attrs' => 'data-zg-ship-req maxlength="10"' ) );
		$s3 .= self::field( array( 'name' => 'ship_country', 'label' => __( 'Země doručení', 'zg-b2b' ), 'type' => 'select', 'options' => $countries, 'default' => $company_cc, 'wide' => true, 'autocomplete' => 'shipping country' ) );
		$s3 .= '</div>';
		$s3 .= '<div class="zg-field--wide" data-zg-cz-warning hidden>' . zg_b2b_notice_box(
			zg_b2b_notice_title(),
			esc_html__( 'Lihoviny a pivo doručujeme pouze na adresy v ČR. Na zahraniční adresu můžete objednat víno a ostatní sortiment.', 'zg-b2b' ),
			'warning',
			'truck'
		) . '</div>';

		// 4) Přístup.
		$s4  = self::field(
			array(
				'name'         => 'password',
				'label'        => __( 'Heslo', 'zg-b2b' ),
				'type'         => 'password',
				'required'     => true,
				'autocomplete' => 'new-password',
				'attrs'        => 'minlength="8"',
				'addon'        => $pw_btn,
				'hint'         => __( 'Alespoň 8 znaků.', 'zg-b2b' ),
			)
		);
		$s4 .= self::field( array( 'name' => 'note', 'label' => __( 'Poznámka', 'zg-b2b' ), 'type' => 'textarea', 'placeholder' => __( 'Např. o jaký sortiment máte zájem nebo odhad odběru', 'zg-b2b' ) ) );

		$terms_label = $terms_url
			? sprintf(
				/* translators: %s: URL VOP */
				__( 'Souhlasím s <a href="%s" target="_blank" rel="noopener">obchodními podmínkami pro velkoobchod</a>', 'zg-b2b' ),
				esc_url( $terms_url )
			)
			: __( 'Souhlasím s obchodními podmínkami pro velkoobchod', 'zg-b2b' );
		$terms_error = self::err( 'terms' );

		$html  = '<div class="zg-b2b-reg" data-zg-reg>';
		$html .= '<div class="zg-reg-head">'
			. '<p class="zg-reg-eyebrow">' . esc_html__( 'Velkoobchod vo.zgruzie.cz', 'zg-b2b' ) . '</p>'
			. '<h2 class="zg-reg-title">' . esc_html__( 'Registrace velkoobchodního partnera', 'zg-b2b' ) . '</h2>'
			. '<p class="zg-reg-lead">' . esc_html__( 'Po schválení účtu uvidíte velkoobchodní ceny bez DPH i s DPH a můžete objednávat. Každou registraci ověřujeme ručně.', 'zg-b2b' ) . '</p>'
			. '<ul class="zg-reg-benefits">'
			. '<li>' . zg_b2b_icon( 'check' ) . esc_html__( 'Údaje firmy doplníme z ARES', 'zg-b2b' ) . '</li>'
			. '<li>' . zg_b2b_icon( 'check' ) . esc_html__( 'Fakturační údaje se předvyplní v pokladně', 'zg-b2b' ) . '</li>'
			. '<li>' . zg_b2b_icon( 'check' ) . esc_html__( 'O schválení vás informujeme e-mailem', 'zg-b2b' ) . '</li>'
			. '</ul></div>';

		$html .= '<form class="zg-reg-form" method="post" novalidate data-zg-form>';
		$html .= '<div class="zg-form-error" data-zg-form-error role="alert"' . ( $form_error ? '' : ' hidden' ) . '>' . esc_html( $form_error ) . '</div>';
		$html .= self::section( 1, __( 'Firma', 'zg-b2b' ), __( 'Fakturační údaje', 'zg-b2b' ), $s1 );
		$html .= self::section( 2, __( 'Kontaktní osoba', 'zg-b2b' ), '', $s2 );
		$html .= self::section( 3, __( 'Doručení', 'zg-b2b' ), '', $s3 );
		$html .= self::section( 4, __( 'Přístup do velkoobchodu', 'zg-b2b' ), '', $s4 );

		$html .= '<div class="zg-consent' . ( $terms_error ? ' has-error' : '' ) . '" data-field="terms">'
			. '<label class="zg-check"><input type="checkbox" name="terms" value="1" required' . checked( (bool) self::val( 'terms' ), true, false ) . '><span class="zg-check__ui" aria-hidden="true"></span><span>' . wp_kses_post( $terms_label ) . ' <span class="zg-req" aria-hidden="true">*</span></span></label>'
			. '<p class="zg-error" role="alert">' . esc_html( $terms_error ) . '</p>'
			. ( $privacy ? '<p class="zg-hint">' . sprintf(
				/* translators: %s: URL zásad ochrany osobních údajů */
				wp_kses_post( __( 'Osobní údaje zpracováváme podle <a href="%s" target="_blank" rel="noopener">zásad ochrany osobních údajů</a>.', 'zg-b2b' ) ),
				esc_url( $privacy )
			) . '</p>' : '' )
			. '</div>';

		$html .= '<div class="zg-hp" aria-hidden="true"><label>Website<input type="text" name="zg_website" tabindex="-1" autocomplete="off"></label></div>';
		$html .= '<input type="hidden" name="zg_ts" value="' . esc_attr( self::time_token() ) . '">';
		$html .= '<input type="hidden" name="zg_b2b_action" value="register">';
		$html .= '<div class="zg-submit-row"><button type="submit" class="zg-b2b-btn zg-b2b-btn--primary zg-b2b-btn--lg zg-submit" data-zg-submit><span>' . esc_html__( 'Odeslat registraci', 'zg-b2b' ) . '</span>' . zg_b2b_icon( 'arrow' ) . '</button>'
			. '<p class="zg-login-link">' . esc_html__( 'Už máte účet?', 'zg-b2b' ) . ' <a href="' . esc_url( zg_b2b_login_url() ) . '">' . esc_html__( 'Přihlaste se', 'zg-b2b' ) . '</a></p></div>';
		$html .= '</form></div>';

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Zpracování
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['zg_b2b_action'] ) || 'register' !== $_POST['zg_b2b_action'] ) { // phpcs:ignore
			return;
		}
		self::$result = self::process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function ajax_register() {
		$r = self::process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $r['ok'] ) {
			wp_send_json_success( array( 'html' => self::success_html( $r['company'] ) ) );
		}
		wp_send_json_error( array( 'errors' => $r['errors'] ) );
	}

	private static function clean( $post, $key ) {
		return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? trim( sanitize_text_field( (string) $post[ $key ] ) ) : '';
	}

	public static function normalize_ico( $ico ) {
		$ico = preg_replace( '/\D/', '', (string) $ico );
		return ( '' !== $ico && strlen( $ico ) < 8 ) ? str_pad( $ico, 8, '0', STR_PAD_LEFT ) : $ico;
	}

	public static function valid_cz_ico( $ico ) {
		if ( ! preg_match( '/^\d{8}$/', $ico ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$sum += (int) $ico[ $i ] * ( 8 - $i );
		}
		$mod = $sum % 11;
		$c   = 0 === $mod ? 1 : ( 1 === $mod ? 0 : 11 - $mod );
		return (int) $ico[7] === $c;
	}

	private static function vat_prefix( $country ) {
		return 'GR' === $country ? 'EL' : $country;
	}

	public static function normalize_vat( $vat, $country ) {
		$vat = strtoupper( preg_replace( '/[\s.\-]/', '', (string) $vat ) );
		if ( '' === $vat ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Z]{2}/', $vat ) ) {
			$vat = self::vat_prefix( $country ) . $vat;
		}
		return $vat;
	}

	private static function format_postcode( $pc, $country ) {
		$pc = trim( (string) $pc );
		if ( in_array( $country, array( 'CZ', 'SK' ), true ) ) {
			$digits = preg_replace( '/\D/', '', $pc );
			if ( 5 === strlen( $digits ) ) {
				return substr( $digits, 0, 3 ) . ' ' . substr( $digits, 3 );
			}
		}
		return strtoupper( $pc );
	}

	private static function valid_postcode( $pc, $country ) {
		if ( in_array( $country, array( 'CZ', 'SK' ), true ) ) {
			return (bool) preg_match( '/^\d{3} \d{2}$/', $pc );
		}
		return strlen( $pc ) >= 3;
	}

	private static function valid_ts( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		if ( ! hash_equals( substr( wp_hash( 'zg_b2b_ts|' . $parts[0] ), 0, 16 ), $parts[1] ) ) {
			return false;
		}
		return ( time() - (int) $parts[0] ) >= 3;
	}

	/**
	 * Zpracuje registraci. Vrací ['ok'=>bool, 'errors'=>[], 'values'=>[], 'company'=>''].
	 */
	public static function process( $post ) {
		$countries = self::country_options();
		$keys      = array( 'company_country', 'ico', 'dic', 'company', 'street', 'city', 'postcode', 'business_type', 'first_name', 'last_name', 'email', 'phone', 'ship_company', 'ship_street', 'ship_city', 'ship_postcode', 'ship_country' );
		$v         = array();
		foreach ( $keys as $k ) {
			$v[ $k ] = self::clean( $post, $k );
		}
		$v['note']          = isset( $post['note'] ) && is_scalar( $post['note'] ) ? sanitize_textarea_field( (string) $post['note'] ) : '';
		$v['same_shipping'] = ! empty( $post['same_shipping'] ) ? '1' : '';
		$v['terms']         = ! empty( $post['terms'] ) ? '1' : '';
		$password           = isset( $post['password'] ) && is_scalar( $post['password'] ) ? (string) $post['password'] : '';
		$v['email']         = sanitize_email( $v['email'] );
		$e                  = array();
		$res                = array( 'ok' => false, 'errors' => array(), 'values' => $v, 'company' => '' );

		// Ochrana proti robotům.
		if ( ! empty( $post['zg_website'] ) || ! self::valid_ts( isset( $post['zg_ts'] ) ? $post['zg_ts'] : '' ) ) {
			$res['errors']['_form'] = __( 'Formulář se nepodařilo ověřit. Obnovte prosím stránku a odešlete ho znovu.', 'zg-b2b' );
			return $res;
		}
		if ( zg_b2b_rate_limited( 'register', 20, HOUR_IN_SECONDS ) ) {
			$res['errors']['_form'] = __( 'Příliš mnoho pokusů. Zkuste to prosím za hodinu, nebo nám napište.', 'zg-b2b' );
			return $res;
		}

		$cc = strtoupper( $v['company_country'] );
		if ( ! isset( $countries[ $cc ] ) ) {
			$e['company_country'] = __( 'Vyberte zemi sídla.', 'zg-b2b' );
		}

		// IČO / DIČ.
		$v['ico'] = self::normalize_ico( $v['ico'] );
		$v['dic'] = self::normalize_vat( $v['dic'], $cc );
		if ( in_array( $cc, array( 'CZ', 'SK' ), true ) ) {
			if ( '' === $v['ico'] ) {
				$e['ico'] = __( 'Vyplňte IČO.', 'zg-b2b' );
			} elseif ( 'CZ' === $cc && ! self::valid_cz_ico( $v['ico'] ) ) {
				$e['ico'] = __( 'IČO není platné – zkontrolujte číslice.', 'zg-b2b' );
			} elseif ( 'SK' === $cc && ! preg_match( '/^\d{8}$/', $v['ico'] ) ) {
				$e['ico'] = __( 'IČO musí mít 8 číslic.', 'zg-b2b' );
			}
		} elseif ( '' === $v['dic'] ) {
			$e['dic'] = __( 'U firem mimo ČR a SK vyplňte DIČ (VAT ID).', 'zg-b2b' );
		}
		if ( '' !== $v['dic'] && ! preg_match( '/^[A-Z]{2}[0-9A-Z]{2,13}$/', $v['dic'] ) ) {
			$e['dic'] = __( 'DIČ má neplatný formát (např. CZ12345678).', 'zg-b2b' );
		}

		$required = array(
			'company'    => __( 'Vyplňte název firmy.', 'zg-b2b' ),
			'street'     => __( 'Vyplňte ulici a číslo.', 'zg-b2b' ),
			'city'       => __( 'Vyplňte město.', 'zg-b2b' ),
			'postcode'   => __( 'Vyplňte PSČ.', 'zg-b2b' ),
			'first_name' => __( 'Vyplňte jméno.', 'zg-b2b' ),
			'last_name'  => __( 'Vyplňte příjmení.', 'zg-b2b' ),
			'phone'      => __( 'Vyplňte telefon.', 'zg-b2b' ),
		);
		foreach ( $required as $k => $msg ) {
			if ( '' === $v[ $k ] ) {
				$e[ $k ] = $msg;
			}
		}
		if ( ! isset( self::business_types()[ $v['business_type'] ] ) ) {
			$e['business_type'] = __( 'Vyberte typ podnikání.', 'zg-b2b' );
		}

		$v['postcode'] = self::format_postcode( $v['postcode'], $cc );
		if ( '' !== $v['postcode'] && ! self::valid_postcode( $v['postcode'], $cc ) ) {
			$e['postcode'] = __( 'PSČ má neplatný formát.', 'zg-b2b' );
		}

		if ( ! is_email( $v['email'] ) ) {
			$e['email'] = __( 'Zadejte platný e-mail.', 'zg-b2b' );
		} elseif ( email_exists( $v['email'] ) ) {
			$e['email'] = __( 'Účet s tímto e-mailem už existuje. Přihlaste se, nebo si obnovte heslo.', 'zg-b2b' );
		}
		if ( '' !== $v['phone'] && strlen( preg_replace( '/\D/', '', $v['phone'] ) ) < 9 ) {
			$e['phone'] = __( 'Zadejte telefon včetně předvolby.', 'zg-b2b' );
		}
		if ( strlen( $password ) < 8 ) {
			$e['password'] = __( 'Heslo musí mít alespoň 8 znaků.', 'zg-b2b' );
		}
		if ( ! $v['terms'] ) {
			$e['terms'] = __( 'Pro registraci je nutný souhlas s obchodními podmínkami.', 'zg-b2b' );
		}

		// Doručovací adresa.
		if ( $v['same_shipping'] ) {
			$v['ship_company']  = '';
			$v['ship_street']   = $v['street'];
			$v['ship_city']     = $v['city'];
			$v['ship_postcode'] = $v['postcode'];
			$v['ship_country']  = $cc;
		} else {
			$v['ship_country'] = strtoupper( $v['ship_country'] );
			if ( ! isset( $countries[ $v['ship_country'] ] ) ) {
				$e['ship_country'] = __( 'Vyberte zemi doručení.', 'zg-b2b' );
			}
			foreach ( array( 'ship_street' => __( 'Vyplňte ulici a číslo.', 'zg-b2b' ), 'ship_city' => __( 'Vyplňte město.', 'zg-b2b' ), 'ship_postcode' => __( 'Vyplňte PSČ.', 'zg-b2b' ) ) as $k => $msg ) {
				if ( '' === $v[ $k ] ) {
					$e[ $k ] = $msg;
				}
			}
			$v['ship_postcode'] = self::format_postcode( $v['ship_postcode'], $v['ship_country'] );
			if ( '' !== $v['ship_postcode'] && ! self::valid_postcode( $v['ship_postcode'], $v['ship_country'] ) ) {
				$e['ship_postcode'] = __( 'PSČ má neplatný formát.', 'zg-b2b' );
			}
		}

		// Ověření v registrech (jen pokud zbytek prošel – šetří volání API).
		$ares_flag = 'na';
		$vies_flag = 'na';
		$ares_name = '';
		if ( ! $e ) {
			if ( 'CZ' === $cc ) {
				$ares = self::ares( $v['ico'] );
				if ( is_wp_error( $ares ) ) {
					if ( 'not_found' === $ares->get_error_code() ) {
						$e['ico'] = __( 'IČO jsme v registru ARES nenašli.', 'zg-b2b' );
					} else {
						$ares_flag = 'unverified';
					}
				} else {
					$ares_flag = 'verified';
					$ares_name = $ares['company'];
				}
			}
			if ( '' !== $v['dic'] && 'CZ' !== $cc ) {
				$vies      = self::vies( $v['dic'] );
				$vies_flag = is_wp_error( $vies ) ? 'unverified' : ( $vies['valid'] ? 'valid' : 'invalid' );
			}
		}

		if ( $e ) {
			$res['errors'] = $e;
			$res['values'] = $v;
			return $res;
		}

		// Založení účtu.
		if ( ! defined( 'ZG_B2B_REGISTERING' ) ) {
			define( 'ZG_B2B_REGISTERING', true );
		}
		$login = sanitize_user( current( explode( '@', $v['email'] ) ), true );
		$login = $login ? $login : 'partner';
		$base  = $login;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ( ++$i );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $v['email'],
				'user_pass'    => $password,
				'first_name'   => $v['first_name'],
				'last_name'    => $v['last_name'],
				'display_name' => trim( $v['first_name'] . ' ' . $v['last_name'] ),
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$res['errors']['_form'] = $user_id->get_error_message();
			return $res;
		}

		$meta = array(
			'billing_first_name'     => $v['first_name'],
			'billing_last_name'      => $v['last_name'],
			'billing_company'        => $v['company'],
			'billing_address_1'      => $v['street'],
			'billing_city'           => $v['city'],
			'billing_postcode'       => $v['postcode'],
			'billing_country'        => $cc,
			'billing_email'          => $v['email'],
			'billing_phone'          => $v['phone'],
			'shipping_first_name'    => $v['first_name'],
			'shipping_last_name'     => $v['last_name'],
			'shipping_company'       => $v['ship_company'] ? $v['ship_company'] : $v['company'],
			'shipping_address_1'     => $v['ship_street'],
			'shipping_city'          => $v['ship_city'],
			'shipping_postcode'      => $v['ship_postcode'],
			'shipping_country'       => $v['ship_country'],
			'shipping_phone'         => $v['phone'],
			zg_b2b_opt( 'ico_meta' ) => $v['ico'],
			zg_b2b_opt( 'dic_meta' ) => $v['dic'],
			'zg_b2b_status'          => 'pending',
			'zg_b2b_registered_at'   => time(),
			'zg_b2b_business_type'   => $v['business_type'],
			'zg_b2b_note'            => $v['note'],
			'zg_b2b_lang'            => (string) apply_filters( 'wpml_current_language', null ),
			'zg_b2b_ares'            => $ares_flag,
			'zg_b2b_ares_name'       => $ares_name,
			'zg_b2b_vies'            => $vies_flag,
		);
		foreach ( $meta as $k => $val ) {
			update_user_meta( $user_id, $k, $val );
		}
		delete_transient( 'zg_b2b_pending_count' );

		do_action( 'zg_b2b_registered', $user_id, $v );

		self::mail_admin( $user_id, $v, $ares_flag, $vies_flag, $ares_name );
		self::mail_customer( $v );

		return array( 'ok' => true, 'errors' => array(), 'values' => array(), 'company' => $v['company'] );
	}

	/* ---------------------------------------------------------------------
	 * ARES / VIES
	 * ------------------------------------------------------------------- */

	/** Načte firmu z ARES (CZ). */
	public static function ares( $ico ) {
		$ico = self::normalize_ico( $ico );
		$key = 'zg_b2b_ares_' . $ico;
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return ! empty( $hit['_nf'] ) ? new WP_Error( 'not_found', 'not found' ) : $hit;
		}

		$r = wp_remote_get(
			'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' . rawurlencode( $ico ),
			array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'unavailable', $r->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 404 === $code || 400 === $code ) {
			set_transient( $key, array( '_nf' => 1 ), HOUR_IN_SECONDS );
			return new WP_Error( 'not_found', 'not found' );
		}
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $j ) || empty( $j['obchodniJmeno'] ) ) {
			return new WP_Error( 'unavailable', 'ARES HTTP ' . $code );
		}

		$s      = isset( $j['sidlo'] ) && is_array( $j['sidlo'] ) ? $j['sidlo'] : array();
		$street = ! empty( $s['nazevUlice'] ) ? $s['nazevUlice'] : ( ! empty( $s['nazevCastiObce'] ) ? $s['nazevCastiObce'] : ( isset( $s['nazevObce'] ) ? $s['nazevObce'] : '' ) );
		$num    = isset( $s['cisloDomovni'] ) ? (string) $s['cisloDomovni'] : ( isset( $s['cisloEvidencni'] ) ? 'č.ev. ' . $s['cisloEvidencni'] : '' );
		if ( ! empty( $s['cisloOrientacni'] ) ) {
			$num .= '/' . $s['cisloOrientacni'] . ( isset( $s['cisloOrientacniPismeno'] ) ? $s['cisloOrientacniPismeno'] : '' );
		}
		$city = isset( $s['nazevObce'] ) ? $s['nazevObce'] : '';
		$psc  = isset( $s['psc'] ) ? str_pad( (string) $s['psc'], 5, '0', STR_PAD_LEFT ) : '';

		// Z textové adresy vezmeme přesnější název obce (např. „Praha 4“).
		if ( ! empty( $s['textovaAdresa'] ) ) {
			$parts = array_map( 'trim', explode( ',', $s['textovaAdresa'] ) );
			if ( preg_match( '/^(\d{3}\s?\d{2})\s+(.+)$/u', end( $parts ), $m ) ) {
				$city = $m[2];
				$psc  = $psc ? $psc : preg_replace( '/\s/', '', $m[1] );
			}
			if ( '' === trim( $street . $num ) ) {
				$street = $parts[0];
			}
		}

		$data = array(
			'company'  => (string) $j['obchodniJmeno'],
			'street'   => trim( $street . ' ' . $num ),
			'city'     => $city,
			'postcode' => self::format_postcode( $psc, 'CZ' ),
			'country'  => isset( $s['kodStatu'] ) ? $s['kodStatu'] : 'CZ',
			'dic'      => isset( $j['dic'] ) ? (string) $j['dic'] : '',
		);
		set_transient( $key, $data, DAY_IN_SECONDS );
		return $data;
	}

	/** Ověří DIČ ve VIES. */
	public static function vies( $vat ) {
		$vat = strtoupper( preg_replace( '/[\s.\-]/', '', (string) $vat ) );
		if ( ! preg_match( '/^([A-Z]{2})([0-9A-Z]{2,13})$/', $vat, $m ) ) {
			return new WP_Error( 'format', 'format' );
		}
		$key = 'zg_b2b_vies_' . $vat;
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		$r = wp_remote_get(
			'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' . $m[1] . '/vat/' . $m[2],
			array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'unavailable', $r->get_error_message() );
		}
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || ! is_array( $j ) ) {
			return new WP_Error( 'unavailable', 'VIES HTTP' );
		}
		$err = isset( $j['userError'] ) ? $j['userError'] : '';
		if ( $err && ! in_array( $err, array( 'VALID', 'INVALID' ), true ) ) {
			return new WP_Error( 'unavailable', $err );
		}
		$valid = ! empty( $j['isValid'] ) || ! empty( $j['valid'] );
		$name  = isset( $j['name'] ) && '---' !== trim( $j['name'] ) ? trim( $j['name'] ) : '';
		$addr  = isset( $j['address'] ) && '---' !== trim( $j['address'] ) ? trim( $j['address'] ) : '';

		$street = '';
		$city   = '';
		$pc     = '';
		if ( $addr ) {
			$lines  = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n|,/', $addr ) ) ) );
			$street = isset( $lines[0] ) ? $lines[0] : '';
			foreach ( array_slice( $lines, 1 ) as $line ) {
				if ( preg_match( '/^([A-Z]{0,2}-?\d[\d\s]{2,8})\s+(.+)$/u', $line, $pm ) ) {
					$pc   = trim( $pm[1] );
					$city = trim( $pm[2] );
					break;
				}
			}
		}

		$data = array(
			'valid'    => $valid,
			'company'  => $name,
			'street'   => $street,
			'city'     => $city,
			'postcode' => $pc,
			'dic'      => $vat,
		);
		set_transient( $key, $data, $valid ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	public static function ajax_lookup() {
		if ( zg_b2b_rate_limited( 'lookup', 30, 10 * MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Příliš mnoho dotazů. Vyplňte prosím údaje ručně.', 'zg-b2b' ) ) );
		}
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'ares' === $type ) {
			$ico = self::normalize_ico( isset( $_POST['ico'] ) ? sanitize_text_field( wp_unslash( $_POST['ico'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! self::valid_cz_ico( $ico ) ) {
				wp_send_json_error( array( 'message' => __( 'IČO není platné – zkontrolujte číslice.', 'zg-b2b' ) ) );
			}
			$r = self::ares( $ico );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error(
					array(
						'message' => 'not_found' === $r->get_error_code()
							? __( 'IČO jsme v registru ARES nenašli.', 'zg-b2b' )
							: __( 'Registr ARES teď neodpovídá. Vyplňte prosím údaje ručně.', 'zg-b2b' ),
					)
				);
			}
			wp_send_json_success( $r );
		}

		if ( 'vies' === $type ) {
			$country = isset( $_POST['country'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['country'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$vat     = self::normalize_vat( isset( $_POST['vat'] ) ? sanitize_text_field( wp_unslash( $_POST['vat'] ) ) : '', $country ); // phpcs:ignore WordPress.Security.NonceVerification
			$r       = self::vies( $vat );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error(
					array(
						'message' => 'format' === $r->get_error_code()
							? __( 'DIČ má neplatný formát.', 'zg-b2b' )
							: __( 'Systém VIES teď neodpovídá. Vyplňte prosím údaje ručně.', 'zg-b2b' ),
					)
				);
			}
			if ( ! $r['valid'] ) {
				wp_send_json_error( array( 'message' => __( 'DIČ není ve VIES vedeno jako platné. Zkontrolujte ho, případně pokračujte – údaje ověříme ručně.', 'zg-b2b' ) ) );
			}
			wp_send_json_success( $r );
		}

		wp_send_json_error( array( 'message' => 'bad request' ) );
	}

	/* ---------------------------------------------------------------------
	 * E-maily
	 * ------------------------------------------------------------------- */

	public static function flag_label( $type, $flag ) {
		$labels = array(
			'ares' => array(
				'verified'   => '✔ ověřeno v ARES',
				'unverified' => '⚠ ARES nedostupný – neověřeno',
				'na'         => '–',
			),
			'vies' => array(
				'valid'      => '✔ platné ve VIES',
				'invalid'    => '✖ VIES: neplatné',
				'unverified' => '⚠ VIES nedostupný – neověřeno',
				'na'         => '–',
			),
		);
		return isset( $labels[ $type ][ $flag ] ) ? $labels[ $type ][ $flag ] : '–';
	}

	private static function mail_admin( $user_id, $v, $ares_flag, $vies_flag, $ares_name ) {
		$types = self::business_types();
		$rows  = array(
			'Firma'            => $v['company'],
			'IČO'              => $v['ico'] . ' (' . self::flag_label( 'ares', $ares_flag ) . ')',
			'Název v ARES'     => $ares_name,
			'DIČ'              => $v['dic'] . ( $v['dic'] ? ' (' . self::flag_label( 'vies', $vies_flag ) . ')' : '' ),
			'Sídlo'            => $v['street'] . ', ' . $v['postcode'] . ' ' . $v['city'] . ', ' . zg_b2b_country_name( $v['company_country'] ),
			'Doručení'         => $v['same_shipping'] ? 'na adresu sídla' : trim( $v['ship_company'] . ', ' . $v['ship_street'] . ', ' . $v['ship_postcode'] . ' ' . $v['ship_city'] . ', ' . zg_b2b_country_name( $v['ship_country'] ), ', ' ),
			'Typ podnikání'    => isset( $types[ $v['business_type'] ] ) ? $types[ $v['business_type'] ] : '',
			'Kontaktní osoba'  => $v['first_name'] . ' ' . $v['last_name'],
			'E-mail'           => $v['email'],
			'Telefon'          => $v['phone'],
			'Poznámka'         => $v['note'],
		);
		$html = '<p>Nová registrace do velkoobchodu čeká na schválení.</p><table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<tr><th align="left" style="border-bottom:1px solid #eee;width:34%">' . esc_html( $label ) . '</th><td style="border-bottom:1px solid #eee">' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		$html .= '</table>';
		$html .= '<p style="margin-top:20px"><a href="' . esc_url( admin_url( 'users.php?zg_b2b_status=pending' ) ) . '">Zobrazit čekající registrace</a> · <a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $user_id ) ) . '">Otevřít účet</a></p>';

		zg_b2b_send_mail( zg_b2b_contact_email(), 'Nová B2B registrace: ' . $v['company'], 'Nová registrace partnera', $html );
	}

	private static function mail_customer( $v ) {
		$html = '<p>' . sprintf( esc_html__( 'Dobrý den, %s,', 'zg-b2b' ), esc_html( $v['first_name'] ) ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: firma */
				esc_html__( 'děkujeme za registraci firmy %s do velkoobchodu vo.zgruzie.cz. Údaje nyní zkontrolujeme a účet ručně schválíme.', 'zg-b2b' ),
				'<strong>' . esc_html( $v['company'] ) . '</strong>'
			) . '</p>'
			. '<p>' . esc_html__( 'Jakmile bude účet aktivní, pošleme vám další e-mail. Poté se přihlásíte svým e-mailem a heslem a uvidíte velkoobchodní ceny.', 'zg-b2b' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: e-mail */
				esc_html__( 'Dotazy: %s', 'zg-b2b' ),
				'<a href="mailto:' . esc_attr( zg_b2b_contact_email() ) . '">' . esc_html( zg_b2b_contact_email() ) . '</a>'
			) . '</p>';
		zg_b2b_send_mail( $v['email'], __( 'Registrace do velkoobchodu přijata', 'zg-b2b' ), __( 'Registrace přijata', 'zg-b2b' ), $html );
	}
}
