<?php
/**
 * Více firem v jednom B2B účtu.
 *
 * - Hlavní firma = fakturační údaje účtu (billing_* + IČO/DIČ meta), stav = stav B2B účtu.
 * - Další firmy: user meta `zg_b2b_companies` (pole), každá se stavem pending / approved / rejected.
 * - Zákazník firmu přidá (ARES / VIES), schvaluje ji administrátor; údaje firem mění jen administrátor.
 * - V pokladně zákazník vybere, na kterou schválenou firmu se fakturuje. Profil účtu (hlavní firma) se nepřepíše.
 * - Firmy a doručovací adresy na sobě nezávisí.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Companies {

	const META          = 'zg_b2b_companies';
	const META_PENDING  = 'zg_b2b_companies_pending';
	const META_LAST     = 'zg_b2b_last_company';
	const ORDER_ID      = '_zg_b2b_company_id';
	const ORDER_LABEL   = '_zg_b2b_company_label';
	const PRIMARY       = 'primary';
	const MAX_COMPANIES = 20;

	/** @var bool Přidání firmy neprošlo validací → pop-up se po načtení znovu otevře. */
	private static $add_failed = false;

	/** @var string Firma zvolená v pokladně (pro ochranu profilu účtu). */
	private static $checkout_company = '';

	/** @var array Fakturační údaje hlavní firmy před uložením objednávky. */
	private static $primary_snapshot = array();

	public static function init() {
		// Můj účet.
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );

		// Pokladna.
		add_action( 'woocommerce_before_checkout_billing_form', array( __CLASS__, 'render_checkout_picker' ), 5 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'hide_fixed_fields' ), 1001 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_from_review' ) );
		add_action( 'wp_ajax_zg_b2b_save_contact', array( __CLASS__, 'ajax_save_contact' ) );
		add_filter( 'zg_b2b_invoice_allowed', array( __CLASS__, 'filter_invoice_allowed' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_assets' ), 20 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_prefill' ), 30, 2 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'checkout_posted_data' ), 1000 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'protect_profile' ), 999, 2 );
		add_action( 'woocommerce_checkout_update_user_meta', array( __CLASS__, 'protect_user_meta' ), 999 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'store_on_order' ), 10, 2 );

		// Seznam objednávek: název doručovací adresy + firma pro fakturaci.
		add_filter( 'woocommerce_my_account_my_orders_columns', array( __CLASS__, 'account_order_columns' ) );
		add_action( 'woocommerce_my_account_my_orders_column_zg-delivery', array( __CLASS__, 'account_col_delivery' ) );
		add_action( 'woocommerce_my_account_my_orders_column_zg-company', array( __CLASS__, 'account_col_company' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'admin_order_columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'admin_order_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'admin_order_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'admin_order_column_legacy' ), 10, 2 );

		// Zobrazení firmy u objednávky.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order_label' ) );

		// Administrace.
		add_action( 'show_user_profile', array( __CLASS__, 'admin_profile' ), 6 );
		add_action( 'edit_user_profile', array( __CLASS__, 'admin_profile' ), 6 );
		add_action( 'personal_options_update', array( __CLASS__, 'admin_save' ), 20 );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'admin_save' ), 20 );
		add_filter( 'views_users', array( __CLASS__, 'admin_view' ), 20 );
		add_action( 'pre_get_users', array( __CLASS__, 'admin_filter_users' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'admin_column' ), 20, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/* =====================================================================
	 * Data
	 * =================================================================== */

	public static function statuses() {
		return array(
			'pending'  => __( 'Čeká na schválení', 'zg-b2b' ),
			'approved' => __( 'Schváleno', 'zg-b2b' ),
			'rejected' => __( 'Zamítnuto', 'zg-b2b' ),
		);
	}

	/** Další firmy účtu (bez hlavní). */
	public static function get_all( $user_id ) {
		$list = get_user_meta( (int) $user_id, self::META, true );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	public static function get( $user_id, $id ) {
		if ( self::PRIMARY === $id ) {
			return self::primary( $user_id );
		}
		foreach ( self::get_all( $user_id ) as $c ) {
			if ( $c['id'] === $id ) {
				return $c;
			}
		}
		return null;
	}

	/** Hlavní firma složená z fakturačních údajů účtu. */
	public static function primary( $user_id ) {
		$m = function ( $k ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $k, true );
		};
		return array(
			'id'        => self::PRIMARY,
			'company'   => $m( 'billing_company' ),
			'ico'       => $m( zg_b2b_opt( 'ico_meta' ) ),
			'dic'       => $m( zg_b2b_opt( 'dic_meta' ) ),
			'dic_dph'   => $m( 'billing_dic_dph' ),
			'address_1' => $m( 'billing_address_1' ),
			'address_2' => $m( 'billing_address_2' ),
			'city'      => $m( 'billing_city' ),
			'postcode'  => $m( 'billing_postcode' ),
			'country'   => $m( 'billing_country' ),
			'status'    => 'approved' === zg_b2b_user_status( $user_id ) ? 'approved' : zg_b2b_user_status( $user_id ),
		);
	}

	/** Firmy, na které lze fakturovat (hlavní + schválené další). */
	public static function billable( $user_id ) {
		$out = array( self::PRIMARY => self::primary( $user_id ) );
		foreach ( self::get_all( $user_id ) as $c ) {
			if ( 'approved' === $c['status'] ) {
				$out[ $c['id'] ] = $c;
			}
		}
		return $out;
	}

	private static function save_all( $user_id, array $list ) {
		$list = array_values( $list );
		update_user_meta( $user_id, self::META, $list );
		$pending = 0;
		foreach ( $list as $c ) {
			if ( 'pending' === $c['status'] ) {
				$pending++;
			}
		}
		if ( $pending ) {
			update_user_meta( $user_id, self::META_PENDING, $pending );
		} else {
			delete_user_meta( $user_id, self::META_PENDING );
		}
		delete_transient( 'zg_b2b_companies_pending_count' );
	}

	public static function sanitize( array $c ) {
		$country = strtoupper( sanitize_text_field( isset( $c['country'] ) ? $c['country'] : 'CZ' ) );
		$out     = array(
			'company'   => sanitize_text_field( isset( $c['company'] ) ? $c['company'] : '' ),
			'ico'       => preg_replace( '/[^0-9A-Za-z]/', '', (string) ( isset( $c['ico'] ) ? $c['ico'] : '' ) ),
			'dic'       => ZG_B2B_Registration::normalize_vat( isset( $c['dic'] ) ? sanitize_text_field( $c['dic'] ) : '', $country ),
			'address_1' => sanitize_text_field( isset( $c['address_1'] ) ? $c['address_1'] : '' ),
			'city'      => sanitize_text_field( isset( $c['city'] ) ? $c['city'] : '' ),
			'postcode'  => sanitize_text_field( isset( $c['postcode'] ) ? $c['postcode'] : '' ),
			'country'   => $country,
		);
		if ( 'CZ' === $country && '' !== $out['ico'] ) {
			$out['ico'] = ZG_B2B_Registration::normalize_ico( $out['ico'] );
		}
		if ( in_array( $country, array( 'CZ', 'SK' ), true ) ) {
			$d = preg_replace( '/\D/', '', $out['postcode'] );
			if ( 5 === strlen( $d ) ) {
				$out['postcode'] = substr( $d, 0, 3 ) . ' ' . substr( $d, 3 );
			}
		}
		return $out;
	}

	/** Duplicita IČO / DIČ v rámci účtu. */
	private static function is_duplicate( $user_id, array $c, $except_id = '' ) {
		$all = self::get_all( $user_id );
		$all[] = self::primary( $user_id );
		foreach ( $all as $e ) {
			if ( $e['id'] === $except_id ) {
				continue;
			}
			if ( ( '' !== $c['ico'] && $c['ico'] === $e['ico'] ) || ( '' !== $c['dic'] && $c['dic'] === $e['dic'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function format_lines( array $c ) {
		$lines = array( $c['address_1'], trim( $c['postcode'] . ' ' . $c['city'] ) );
		if ( $c['country'] && 'CZ' !== $c['country'] ) {
			$lines[] = zg_b2b_country_name( $c['country'] );
		}
		$ids = array();
		if ( $c['ico'] ) {
			$ids[] = __( 'IČO', 'zg-b2b' ) . ': ' . $c['ico'];
		}
		if ( $c['dic'] ) {
			$ids[] = __( 'DIČ', 'zg-b2b' ) . ': ' . $c['dic'];
		}
		return array_merge( array_filter( $lines ), $ids );
	}

	public static function short_label( array $c ) {
		return trim( $c['company'] . ( $c['ico'] ? ' (' . __( 'IČO', 'zg-b2b' ) . ' ' . $c['ico'] . ')' : '' ) );
	}

	/* =====================================================================
	 * Můj účet → Adresy: sekce Firmy
	 * =================================================================== */

	private static function country_options() {
		$codes = (array) zg_b2b_opt( 'reg_countries' );
		if ( ! $codes ) {
			$codes = array( 'CZ', 'SK' );
		}
		$names = array();
		foreach ( $codes as $c ) {
			$names[ $c ] = zg_b2b_country_name( $c );
		}
		$first = array_intersect_key( $names, array_flip( array( 'CZ', 'SK' ) ) );
		$rest  = array_diff_key( $names, $first );
		asort( $rest );
		return $first + $rest;
	}

	/**
	 * Vykreslí sekci firem (volá ZG_B2B_Address_Account::render_overview).
	 */
	public static function render_account_section( $user_id ) {
		$primary = self::primary( $user_id );
		$extra   = self::get_all( $user_id );

		echo '<div class="zg-b2b-addr-section-head"><h2 class="zg-b2b-addr-section-title">' . esc_html__( 'Firmy a fakturační údaje', 'zg-b2b' ) . '</h2>';
		if ( count( $extra ) < self::MAX_COMPANIES ) {
			echo '<a class="zg-b2b-addr-btn zg-b2b-addr-btn--primary" data-zg-company-open href="#zg-b2b-company-modal">+ ' . esc_html__( 'Přidat firmu', 'zg-b2b' ) . '</a>';
		}
		echo '</div>';

		echo '<div class="zg-b2b-addr-grid">';

		// Hlavní firma (fakturační adresa účtu).
		echo '<div class="zg-b2b-addr-card zg-b2b-addr-card--billing">';
		echo '<div class="zg-b2b-addr-card__head"><h3 class="zg-b2b-addr-card__label">' . esc_html( $primary['company'] ? $primary['company'] : __( 'Fakturační adresa', 'zg-b2b' ) );
		echo ' <span class="zg-b2b-addr-badge">' . esc_html__( 'Hlavní', 'zg-b2b' ) . '</span></h3>';
		echo '<a class="zg-b2b-addr-btn" href="' . esc_url( wc_get_endpoint_url( 'edit-address', 'billing' ) ) . '">' . esc_html__( 'Upravit', 'zg-b2b' ) . '</a></div>';
		$billing = wc_get_account_formatted_address( 'billing' );
		echo '<address class="zg-b2b-addr-card__address">' . ( $billing ? wp_kses_post( $billing ) : esc_html__( 'Zatím nemáte nastavenou fakturační adresu.', 'zg-b2b' ) ) . '</address>';
		if ( $extra && '' === self::cannot_delete_reason( $user_id, self::PRIMARY ) ) {
			echo '<div class="zg-b2b-addr-card__actions">';
			self::remove_button( self::PRIMARY, __( 'Odebrat', 'zg-b2b' ) );
			echo '<span class="zg-b2b-company-note">' . esc_html__( 'Hlavní firmou se stane další schválená firma.', 'zg-b2b' ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		foreach ( $extra as $c ) {
			$st = isset( self::statuses()[ $c['status'] ] ) ? $c['status'] : 'pending';
			echo '<div class="zg-b2b-addr-card zg-b2b-company zg-b2b-company--' . esc_attr( $st ) . '">';
			echo '<div class="zg-b2b-addr-card__head"><h3 class="zg-b2b-addr-card__label">' . esc_html( $c['company'] );
			echo ' <span class="zg-b2b-addr-badge zg-b2b-company-badge--' . esc_attr( $st ) . '">' . esc_html( self::statuses()[ $st ] ) . '</span></h3></div>';
			echo '<address class="zg-b2b-addr-card__address">' . implode( '<br>', array_map( 'esc_html', self::format_lines( $c ) ) ) . '</address>';
			echo '<div class="zg-b2b-addr-card__actions">';
			$why = self::cannot_delete_reason( $user_id, $c['id'] );
			if ( '' === $why ) {
				self::remove_button( $c['id'], 'pending' === $st ? __( 'Zrušit žádost', 'zg-b2b' ) : __( 'Odebrat', 'zg-b2b' ) );
			}
			if ( 'pending' === $st ) {
				echo '<span class="zg-b2b-company-note">' . esc_html__( 'Firmu po kontrole schválíme a pošleme vám e-mail.', 'zg-b2b' ) . '</span>';
			} elseif ( 'approved' === $st ) {
				$inv = isset( $c['invoice'] ) && 'yes' === $c['invoice'];
				echo '<span class="zg-b2b-company-note">' . esc_html( $inv ? __( 'Platba na fakturu povolena.', 'zg-b2b' ) : __( 'Platba na fakturu zatím nepovolena.', 'zg-b2b' ) ) . ( $why ? ' ' . esc_html( $why ) : '' ) . '</span>';
			}
			echo '</div></div>';
		}
		echo '</div>';

		self::render_modal();
	}

	private static function remove_button( $id, $label ) {
		echo '<form method="post" class="zg-b2b-addr-inline-form">';
		wp_nonce_field( 'zg_b2b_company_remove_' . $id, '_zg_b2b_company_nonce' );
		echo '<input type="hidden" name="zg_b2b_company_action" value="remove"><input type="hidden" name="zg_b2b_company_id" value="' . esc_attr( $id ) . '">';
		echo '<button type="submit" class="zg-b2b-addr-btn zg-b2b-addr-btn--small zg-b2b-addr-btn--danger">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private static function posted( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		return ( self::$add_failed && isset( $_POST[ 'zg_company_' . $key ] ) ) ? sanitize_text_field( wp_unslash( $_POST[ 'zg_company_' . $key ] ) ) : $default;
	}

	private static function render_modal() {
		$country = self::posted( 'country', 'CZ' );
		echo '<div class="zg-b2b-addr-modal" id="zg-b2b-company-modal" hidden' . ( self::$add_failed ? ' data-reopen="1"' : '' ) . '>';
		echo '<div class="zg-b2b-addr-modal__backdrop" data-zg-modal-close></div>';
		echo '<div class="zg-b2b-addr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="zg-b2b-company-modal-title">';
		echo '<div class="zg-b2b-addr-modal__head"><h2 class="zg-b2b-addr-modal__title" id="zg-b2b-company-modal-title">' . esc_html__( 'Přidat firmu', 'zg-b2b' ) . '</h2>';
		echo '<button type="button" class="zg-b2b-addr-modal__close" data-zg-modal-close aria-label="' . esc_attr__( 'Zavřít', 'zg-b2b' ) . '">&times;</button></div>';
		echo '<div class="zg-b2b-addr-modal__body">';
		echo '<p class="zg-b2b-addr-intro">' . esc_html__( 'Na další firmu můžete fakturovat po jejím schválení. Firmy a doručovací adresy na sobě nezávisí.', 'zg-b2b' ) . '</p>';
		echo '<form method="post" action="' . esc_url( ZG_B2B_Address_Account::url() ) . '" class="zg-b2b-addr-form zg-b2b-addr-form--modal zg-b2b-company-form" novalidate>';
		echo '<div class="woocommerce-address-fields"><div class="woocommerce-address-fields__field-wrapper zg-b2b-company-fields">';

		woocommerce_form_field( 'zg_company_country', array(
			'type'     => 'select',
			'label'    => __( 'Země sídla', 'zg-b2b' ),
			'required' => true,
			'options'     => self::country_options(),
			'class'       => array( 'form-row-wide' ),
			'input_class' => array( 'country_select' ), // selectWoo jako ostatní výběry zemí na webu.
		), $country );

		echo '<p class="form-row form-row-wide zg-b2b-company-ico-row" id="zg_company_ico_field"><label for="zg_company_ico">' . esc_html__( 'IČO', 'zg-b2b' ) . ' <abbr class="required zg-b2b-company-cz-only" title="required">*</abbr></label>';
		echo '<span class="zg-b2b-company-lookup"><input type="text" class="input-text" name="zg_company_ico" id="zg_company_ico" inputmode="numeric" autocomplete="off" value="' . esc_attr( self::posted( 'ico' ) ) . '">';
		echo '<button type="button" class="zg-b2b-addr-btn zg-b2b-company-ares" data-type="ares">' . esc_html__( 'Načíst z ARES', 'zg-b2b' ) . '</button></span></p>';

		echo '<p class="form-row form-row-wide" id="zg_company_dic_field"><label for="zg_company_dic">' . esc_html__( 'DIČ / IČ DPH', 'zg-b2b' ) . ' <abbr class="required zg-b2b-company-eu-only" title="required">*</abbr></label>';
		echo '<span class="zg-b2b-company-lookup"><input type="text" class="input-text" name="zg_company_dic" id="zg_company_dic" autocomplete="off" value="' . esc_attr( self::posted( 'dic' ) ) . '">';
		echo '<button type="button" class="zg-b2b-addr-btn zg-b2b-company-vies" data-type="vies">' . esc_html__( 'Ověřit ve VIES', 'zg-b2b' ) . '</button></span></p>';

		echo '<p class="zg-b2b-company-lookup-msg" aria-live="polite"></p>';

		$fields = array(
			'company'   => array( __( 'Název firmy', 'zg-b2b' ), 'organization' ),
			'address_1' => array( __( 'Ulice a č.p.', 'zg-b2b' ), 'address-line1' ),
			'postcode'  => array( __( 'PSČ', 'zg-b2b' ), 'postal-code' ),
			'city'      => array( __( 'Město', 'zg-b2b' ), 'address-level2' ),
		);
		foreach ( $fields as $key => $f ) {
			woocommerce_form_field( 'zg_company_' . $key, array(
				'type'         => 'text',
				'label'        => $f[0],
				'required'     => true,
				'autocomplete' => $f[1],
				'class'        => array( in_array( $key, array( 'postcode', 'city' ), true ) ? ( 'postcode' === $key ? 'form-row-first' : 'form-row-last' ) : 'form-row-wide' ),
			), self::posted( $key ) );
		}

		echo '</div></div>';
		wp_nonce_field( 'zg_b2b_company_add', '_zg_b2b_company_nonce' );
		echo '<input type="hidden" name="zg_b2b_company_action" value="add">';
		echo '<p class="zg-b2b-addr-form__buttons"><button type="submit" class="zg-b2b-addr-btn zg-b2b-addr-btn--primary">' . esc_html__( 'Odeslat ke schválení', 'zg-b2b' ) . '</button> ';
		echo '<button type="button" class="zg-b2b-addr-btn" data-zg-modal-close>' . esc_html__( 'Zrušit', 'zg-b2b' ) . '</button></p>';
		echo '</form></div></div></div>';
	}

	public static function handle_post() {
		if ( empty( $_POST['zg_b2b_company_action'] ) || ! is_user_logged_in() || ! ZG_B2B_Address_Account::is_page() ) {
			return;
		}
		$action  = sanitize_key( wp_unslash( $_POST['zg_b2b_company_action'] ) );
		$id      = isset( $_POST['zg_b2b_company_id'] ) ? sanitize_key( wp_unslash( $_POST['zg_b2b_company_id'] ) ) : '';
		$nonce   = isset( $_POST['_zg_b2b_company_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_zg_b2b_company_nonce'] ) ) : '';
		$user_id = get_current_user_id();

		if ( ! wp_verify_nonce( $nonce, 'add' === $action ? 'zg_b2b_company_add' : 'zg_b2b_company_' . $action . '_' . $id ) ) {
			wc_add_notice( __( 'Platnost odkazu vypršela, zkuste to prosím znovu.', 'zg-b2b' ), 'error' );
			return;
		}

		if ( 'remove' === $action ) {
			$why = self::cannot_delete_reason( $user_id, $id );
			if ( $why ) {
				wc_add_notice( $why, 'error' );
				wp_safe_redirect( ZG_B2B_Address_Account::url() );
				exit;
			}
			if ( self::PRIMARY === $id ) {
				self::promote_next_primary( $user_id );
			} else {
				$list = array_values( array_filter( self::get_all( $user_id ), function ( $c ) use ( $id ) {
					return $c['id'] !== $id;
				} ) );
				self::save_all( $user_id, $list );
			}
			if ( get_user_meta( $user_id, self::META_LAST, true ) === $id ) {
				delete_user_meta( $user_id, self::META_LAST );
			}
			wc_add_notice( __( 'Firma byla odebrána.', 'zg-b2b' ) );
			wp_safe_redirect( ZG_B2B_Address_Account::url() );
			exit;
		}

		if ( 'add' !== $action ) {
			return;
		}

		if ( zg_b2b_rate_limited( 'company_add_' . $user_id, 10, HOUR_IN_SECONDS ) ) {
			wc_add_notice( __( 'Příliš mnoho pokusů. Zkuste to prosím později.', 'zg-b2b' ), 'error' );
			self::$add_failed = true;
			return;
		}

		$raw = array();
		foreach ( array( 'company', 'ico', 'dic', 'address_1', 'city', 'postcode', 'country' ) as $k ) {
			$raw[ $k ] = isset( $_POST[ 'zg_company_' . $k ] ) ? wp_unslash( $_POST[ 'zg_company_' . $k ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$c      = self::sanitize( $raw );
		$errors = array();

		if ( ! isset( self::country_options()[ $c['country'] ] ) ) {
			$errors[] = __( 'Vyberte zemi sídla.', 'zg-b2b' );
		}
		if ( 'CZ' === $c['country'] && ! ZG_B2B_Registration::valid_cz_ico( $c['ico'] ) ) {
			$errors[] = __( 'IČO není platné – zkontrolujte číslice.', 'zg-b2b' );
		}
		if ( 'CZ' !== $c['country'] && '' === $c['dic'] ) {
			$errors[] = __( 'U firmy mimo Česko vyplňte DIČ / IČ DPH.', 'zg-b2b' );
		}
		foreach ( array( 'company' => __( 'Název firmy', 'zg-b2b' ), 'address_1' => __( 'Ulice a č.p.', 'zg-b2b' ), 'city' => __( 'Město', 'zg-b2b' ), 'postcode' => __( 'PSČ', 'zg-b2b' ) ) as $k => $label ) {
			if ( '' === $c[ $k ] ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s je povinné pole.', 'zg-b2b' ), $label );
			}
		}
		if ( ! $errors && self::is_duplicate( $user_id, $c ) ) {
			$errors[] = __( 'Tuto firmu už ve svém účtu máte.', 'zg-b2b' );
		}
		if ( count( self::get_all( $user_id ) ) >= self::MAX_COMPANIES ) {
			$errors[] = __( 'Dosáhli jste maximálního počtu firem.', 'zg-b2b' );
		}
		if ( $errors ) {
			foreach ( $errors as $e ) {
				wc_add_notice( $e, 'error' );
			}
			self::$add_failed = true;
			return;
		}

		// Ověření (informativní pro administrátora).
		$ares      = 'na';
		$ares_name = '';
		if ( 'CZ' === $c['country'] ) {
			$r         = ZG_B2B_Registration::ares( $c['ico'] );
			$ares      = is_wp_error( $r ) ? 'unverified' : 'verified';
			$ares_name = is_wp_error( $r ) ? '' : $r['company'];
		}
		$vies = 'na';
		if ( '' !== $c['dic'] ) {
			$r    = ZG_B2B_Registration::vies( $c['dic'] );
			$vies = is_wp_error( $r ) ? 'unverified' : ( $r['valid'] ? 'valid' : 'invalid' );
		}

		$c = array_merge(
			array( 'id' => substr( md5( uniqid( '', true ) . wp_rand() ), 0, 10 ) ),
			$c,
			array(
				'status'    => 'pending',
				'created'   => time(),
				'decided'   => 0,
				'ares'      => $ares,
				'ares_name' => $ares_name,
				'vies'      => $vies,
			)
		);
		$list   = self::get_all( $user_id );
		$list[] = $c;
		self::save_all( $user_id, $list );
		self::mail_admin_new( $user_id, $c );

		wc_add_notice( __( 'Firma byla odeslána ke schválení. Po schválení vám pošleme e-mail.', 'zg-b2b' ) );
		wp_safe_redirect( ZG_B2B_Address_Account::url() );
		exit;
	}

	/* =====================================================================
	 * Pokladna
	 * =================================================================== */

	/** Výběr / souhrn firmy v pokladně – pro přihlášené zákazníky s vyplněnou firmou. */
	private static function checkout_active() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$p = self::primary( get_current_user_id() );
		return '' !== $p['company'] || count( self::billable( get_current_user_id() ) ) > 1;
	}

	private static function has_choice() {
		return is_user_logged_in() && count( self::billable( get_current_user_id() ) ) > 1;
	}

	/**
	 * Pole, která zákazník v pokladně nemůže změnit, se nezobrazují (údaje jsou v kartě firmy).
	 * Skryjí se: zamčená pole s hodnotou a – při více firmách – i adresa sídla (řídí ji výběr firmy).
	 */
	public static function hide_fixed_fields( $fields ) {
		if ( ! self::checkout_active() || empty( $fields['billing'] ) ) {
			return $fields;
		}
		$user_id = get_current_user_id();
		$company = self::get( $user_id, self::initial_company( $user_id ) );
		$hide    = class_exists( 'ZG_B2B_Account' ) ? ZG_B2B_Account::locked_keys() : array();
		if ( self::has_choice() ) {
			$hide = array_merge( $hide, array_keys( self::field_map() ) );
		}
		// Kontaktní údaje účtu se v pokladně nezobrazují (příjemce zásilky = blok „Kontaktní osoba“).
		$contact = array( 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email' );
		$hide    = array_merge( $hide, $contact );
		$map     = self::field_map();
		foreach ( array_unique( $hide ) as $key ) {
			if ( ! isset( $fields['billing'][ $key ] ) ) {
				continue;
			}
			if ( in_array( $key, $contact, true ) ) {
				$getter = 'get_' . $key;
				$value  = ( WC()->customer && is_callable( array( WC()->customer, $getter ) ) ) ? (string) WC()->customer->$getter() : (string) get_user_meta( $user_id, $key, true );
			} else {
				$value = isset( $map[ $key ] ) && $company ? self::company_value( $company, $map[ $key ] ) : (string) get_user_meta( $user_id, $key, true );
			}
			// Prázdné povinné pole necháme viditelné, aby šlo objednávku dokončit.
			if ( '' === $value && ! empty( $fields['billing'][ $key ]['required'] ) ) {
				continue;
			}
			$fields['billing'][ $key ]['type']        = 'hidden';
			$fields['billing'][ $key ]['label']       = '';
			$fields['billing'][ $key ]['description'] = '';
			$fields['billing'][ $key ]['required']    = false;
			$fields['billing'][ $key ]['class']       = array( 'form-row-wide', 'zg-b2b-hidden-field' );
		}
		return $fields;
	}

	/** Firma předvybraná v pokladně (naposledy použitá, pokud je stále schválená). */
	private static function initial_company( $user_id ) {
		$last     = (string) get_user_meta( $user_id, self::META_LAST, true );
		$billable = self::billable( $user_id );
		return isset( $billable[ $last ] ) ? $last : self::PRIMARY;
	}

	/** Pole pokladny ↔ klíče firmy. */
	private static function field_map() {
		return array(
			'billing_company'        => 'company',
			zg_b2b_opt( 'ico_meta' ) => 'ico',
			zg_b2b_opt( 'dic_meta' ) => 'dic',
			'billing_dic_dph'        => 'dic_dph',
			'billing_address_1'      => 'address_1',
			'billing_address_2'      => 'address_2',
			'billing_city'           => 'city',
			'billing_postcode'       => 'postcode',
			'billing_country'        => 'country',
		);
	}

	private static function company_value( array $c, $key ) {
		return isset( $c[ $key ] ) ? (string) $c[ $key ] : '';
	}

	public static function render_checkout_picker() {
		if ( ! self::checkout_active() ) {
			return;
		}
		$user_id = get_current_user_id();
		$current = self::initial_company( $user_id );

		$choice = self::has_choice();
		echo '<div class="zg-b2b-addr-picker zg-b2b-company-picker' . ( $choice ? '' : ' zg-b2b-company-picker--single' ) . '" id="zg-b2b-company-picker">';

		$billable = self::billable( $user_id );
		echo '<div class="zg-b2b-choices" data-zg-choices>';
		if ( $choice ) {
			$others = count( $billable ) - 1;
			self::choices_header( 'company', __( 'Fakturovat na jinou firmu', 'zg-b2b' ), self::plural( $others, __( '%d firma', 'zg-b2b' ), __( '%d firmy', 'zg-b2b' ), __( '%d firem', 'zg-b2b' ) ) );
		}
		foreach ( $billable as $id => $c ) {
			$checked = ( $id === $current );
			echo '<label class="zg-b2b-addr-option' . ( $checked ? ' is-selected' : '' ) . '">';
			if ( $choice ) {
				echo '<input type="radio" name="zg_b2b_company" value="' . esc_attr( $id ) . '"' . checked( $checked, true, false ) . '>';
			} else {
				echo '<input type="hidden" name="zg_b2b_company" value="' . esc_attr( $id ) . '">';
			}
			echo '<span class="zg-b2b-addr-option__text"><strong>' . esc_html( $c['company'] ) . '</strong>';
			$detail = array_filter( array( $c['ico'] ? __( 'IČO', 'zg-b2b' ) . ' ' . $c['ico'] : '', $c['dic'] ? __( 'DIČ', 'zg-b2b' ) . ' ' . $c['dic'] : '', trim( $c['address_1'] . ', ' . $c['postcode'] . ' ' . $c['city'], ', ' ) ) );
			echo '<span class="zg-b2b-addr-option__detail">' . esc_html( implode( ' · ', $detail ) ) . '</span>';
			echo '</span></label>';
		}
		echo '</div>';
		echo '<p class="zg-b2b-company-picker__note">' . esc_html__( 'Fakturační údaje firmy mění velkoobchod – napište nám.', 'zg-b2b' ) . '</p>';
		echo '</div>';
	}

	/** Česká množná čísla (1 / 2–4 / 5+). */
	public static function plural( $n, $one, $few, $many ) {
		$f = ( 1 === $n ) ? $one : ( ( $n >= 2 && $n <= 4 ) ? $few : $many );
		return sprintf( $f, $n );
	}

	/**
	 * Rozbalovací řádek „Jiná firma / adresa (N)“ pod zvolenou položkou (styl jako výběr dopravy).
	 * Ostatní položky jsou skryté, dokud ho zákazník nerozbalí (prevence omylu).
	 */
	public static function choices_header( $icon, $label, $count ) {
		$icons = array(
			'company'  => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16"/><path d="M15 9h2a2 2 0 0 1 2 2v10"/><path d="M9 7h2M9 11h2M9 15h2"/></svg>',
			'person'   => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
			'delivery' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></svg>',
		);
		echo '<button type="button" class="zg-b2b-choices__more" aria-expanded="false" hidden>';
		echo '<span class="zg-b2b-choices__icon">' . $icons[ $icon ] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- statické SVG.
		echo '<span class="zg-b2b-choices__label">' . esc_html( $label ) . ' <span class="zg-b2b-choices__count">(' . esc_html( $count ) . ')</span></span>';
		echo '<span class="zg-b2b-choices__chevron" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>';
		echo '</button>';
	}

	public static function checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() || ! self::checkout_active() ) {
			return;
		}
		wp_enqueue_style( 'zg-b2b-addresses', ZG_B2B_URL . 'assets/css/zg-b2b-addresses.css', array(), ZG_B2B_VERSION );
		wp_enqueue_script( 'zg-b2b-choices', ZG_B2B_URL . 'assets/js/zg-b2b-choices.js', array( 'jquery' ), ZG_B2B_VERSION, true );
		wp_enqueue_script( 'zg-b2b-companies-checkout', ZG_B2B_URL . 'assets/js/zg-b2b-companies-checkout.js', array( 'jquery', 'wc-checkout' ), ZG_B2B_VERSION, true );
		$data = array();
		foreach ( self::billable( get_current_user_id() ) as $id => $c ) {
			$row = array();
			foreach ( self::field_map() as $field => $key ) {
				$row[ $field ] = self::company_value( $c, $key );
			}
			$data[ $id ] = $row;
		}
		wp_localize_script( 'zg-b2b-companies-checkout', 'zgB2bCompanies', array(
			'companies' => (object) $data,
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'zg_b2b_save_contact' ),
			'saving'    => __( 'Ukládám…', 'zg-b2b' ),
			'saved'     => __( 'Uloženo', 'zg-b2b' ),
			'error'     => __( 'Nepodařilo se uložit – údaje se použijí jen pro tuto objednávku.', 'zg-b2b' ),
		) );
	}

	/** Předvyplnění fakturačních polí naposledy použitou firmou. */
	public static function checkout_prefill( $value, $input ) {
		if ( ! isset( self::field_map()[ $input ] ) || ! is_checkout() || ! self::checkout_active() ) {
			return $value;
		}
		$user_id = get_current_user_id();
		$id      = self::initial_company( $user_id );
		if ( self::PRIMARY === $id ) {
			return $value;
		}
		return self::company_value( self::get( $user_id, $id ), self::field_map()[ $input ] );
	}

	/** Zvolená firma → fakturační údaje objednávky (přebije zamčené hodnoty z profilu). */
	public static function checkout_posted_data( $data ) {
		if ( ! is_user_logged_in() || empty( $_POST['zg_b2b_company'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $data;
		}
		$id       = sanitize_key( wp_unslash( $_POST['zg_b2b_company'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$billable = self::billable( get_current_user_id() );
		if ( ! isset( $billable[ $id ] ) ) {
			return $data;
		}
		self::$checkout_company = $id;
		if ( WC()->session ) {
			WC()->session->set( 'zg_b2b_company', $id );
		}
		if ( self::PRIMARY === $id ) {
			return $data;
		}
		$primary = self::primary( get_current_user_id() );
		foreach ( self::field_map() as $field => $key ) {
			self::$primary_snapshot[ $field ] = self::company_value( $primary, $key );
		}
		foreach ( self::field_map() as $field => $key ) {
			if ( '' !== $field ) {
				$data[ $field ] = self::company_value( $billable[ $id ], $key );
			}
		}
		return $data;
	}

	public static function checkout_validate( $data, $errors ) {
		if ( ! is_user_logged_in() || empty( $_POST['zg_b2b_company'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id = sanitize_key( wp_unslash( $_POST['zg_b2b_company'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::billable( get_current_user_id() )[ $id ] ) ) {
			$errors->add( 'zg_b2b_company', __( 'Na vybranou firmu zatím nelze fakturovat (není schválená). Zvolte prosím jinou.', 'zg-b2b' ) );
		}
	}

	/** Objednávka na další firmu nesmí přepsat fakturační údaje účtu (hlavní firmu). */
	public static function protect_profile( $customer, $data ) {
		if ( ! self::$checkout_company || self::PRIMARY === self::$checkout_company || ! $customer instanceof WC_Customer ) {
			return;
		}
		foreach ( self::$primary_snapshot as $field => $value ) {
			$setter = 'set_' . $field;
			if ( is_callable( array( $customer, $setter ) ) ) {
				$customer->$setter( $value );
			} else {
				$customer->update_meta_data( $field, $value );
			}
		}
	}

	/** Pojistka pro pluginy, které IČO/DIČ ukládají přímo do user meta (např. WPify). */
	public static function protect_user_meta( $customer_id ) {
		if ( ! self::$checkout_company || self::PRIMARY === self::$checkout_company || ! $customer_id ) {
			return;
		}
		$keep = self::$primary_snapshot;
		foreach ( $keep as $field => $value ) {
			update_user_meta( $customer_id, $field, $value );
		}
	}

	public static function store_on_order( $order, $data ) {
		if ( ! self::$checkout_company ) {
			return;
		}
		$user_id = get_current_user_id();
		$c       = self::get( $user_id, self::$checkout_company );
		if ( $c ) {
			$order->update_meta_data( self::ORDER_ID, self::$checkout_company );
			$order->update_meta_data( self::ORDER_LABEL, self::short_label( $c ) );
			update_user_meta( $user_id, self::META_LAST, self::$checkout_company );
		}
	}

	/** Název doručovací adresy objednávky (pobočka, jinak firma / město z doručovací adresy). */
	public static function order_delivery_name( $order ) {
		$label = (string) $order->get_meta( '_bombora_shipping_address_label' );
		if ( '' !== $label ) {
			return $label;
		}
		if ( $order->has_shipping_address() ) {
			return trim( $order->get_shipping_company() ? $order->get_shipping_company() : $order->get_shipping_city() );
		}
		return '';
	}

	public static function order_company_name( $order ) {
		return (string) $order->get_billing_company();
	}

	private static function insert_after( array $cols, $after, array $add ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( $k === $after ) {
				$out += $add;
			}
		}
		return count( $out ) === count( $cols ) ? $cols + $add : $out;
	}

	public static function account_order_columns( $cols ) {
		return self::insert_after( $cols, 'order-date', array(
			'zg-company'  => __( 'Firma', 'zg-b2b' ),
			'zg-delivery' => __( 'Doručení', 'zg-b2b' ),
		) );
	}

	public static function account_col_delivery( $order ) {
		$name = self::order_delivery_name( $order );
		echo $name ? esc_html( $name ) : '<span class="zg-b2b-muted">' . esc_html__( 'fakturační adresa', 'zg-b2b' ) . '</span>';
	}

	public static function account_col_company( $order ) {
		echo esc_html( self::order_company_name( $order ) );
	}

	public static function admin_order_columns( $cols ) {
		return self::insert_after( $cols, 'order_number', array(
			'zg_company'  => 'Firma (faktura)',
			'zg_delivery' => 'Doručení',
		) );
	}

	public static function admin_order_column( $col, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}
		if ( 'zg_company' === $col ) {
			echo esc_html( self::order_company_name( $order ) );
		} elseif ( 'zg_delivery' === $col ) {
			$name = self::order_delivery_name( $order );
			echo $name ? esc_html( $name ) : '<span style="color:#787c82">fakturační adresa</span>';
		}
	}

	public static function admin_order_column_legacy( $col, $post_id ) {
		self::admin_order_column( $col, wc_get_order( $post_id ) );
	}

	public static function admin_order_label( $order ) {
		$id = $order instanceof WC_Order ? $order->get_meta( self::ORDER_ID ) : '';
		if ( $id && self::PRIMARY !== $id ) {
			echo '<p><strong>Fakturováno na další firmu účtu:</strong> ' . esc_html( $order->get_meta( self::ORDER_LABEL ) ) . '</p>';
		}
	}


	/** Má firma objednávky? (podle ID firmy v objednávce nebo IČO / DIČ ve fakturačních údajích) */
	public static function has_orders( $user_id, array $c ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return true;
		}
		$or = array( 'relation' => 'OR', array( 'key' => self::ORDER_ID, 'value' => $c['id'] ) );
		if ( ! empty( $c['ico'] ) ) {
			$or[] = array( 'key' => '_' . zg_b2b_opt( 'ico_meta' ), 'value' => $c['ico'] );
		} elseif ( ! empty( $c['dic'] ) ) {
			$or[] = array( 'key' => '_' . zg_b2b_opt( 'dic_meta' ), 'value' => $c['dic'] );
		}
		$ids = wc_get_orders( array(
			'customer_id' => (int) $user_id,
			'limit'       => 1,
			'return'      => 'ids',
			'status'      => array_keys( wc_get_order_statuses() ),
			'meta_query'  => array( $or ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		return ! empty( $ids );
	}

	/** Prázdný řetězec = firmu lze odebrat; jinak důvod pro zákazníka. */
	public static function cannot_delete_reason( $user_id, $id ) {
		$c = self::get( $user_id, $id );
		if ( ! $c ) {
			return __( 'Firma nebyla nalezena.', 'zg-b2b' );
		}
		if ( self::PRIMARY === $id ) {
			$other = array_filter( self::get_all( $user_id ), function ( $e ) {
				return 'approved' === $e['status'];
			} );
			if ( ! $other ) {
				return __( 'V účtu musí zůstat alespoň jedna schválená firma.', 'zg-b2b' );
			}
		}
		if ( 'approved' === $c['status'] && self::has_orders( $user_id, $c ) ) {
			return __( 'Firmu s objednávkami nelze odebrat.', 'zg-b2b' );
		}
		return '';
	}

	/** Hlavní firma se maže → první schválená další firma se stane hlavní (fakturační údaje účtu). */
	private static function promote_next_primary( $user_id ) {
		$list = self::get_all( $user_id );
		foreach ( $list as $i => $c ) {
			if ( 'approved' !== $c['status'] ) {
				continue;
			}
			foreach ( self::field_map() as $field => $key ) {
				update_user_meta( $user_id, $field, self::company_value( $c, $key ) );
			}
			update_user_meta( $user_id, 'zg_b2b_invoice_allowed', ( isset( $c['invoice'] ) && 'yes' === $c['invoice'] ) ? 'yes' : 'no' );
			unset( $list[ $i ] );
			self::save_all( $user_id, $list );
			return true;
		}
		return false;
	}

	/** Platba na fakturu je povolená pro firmu? (hlavní = nastavení účtu, další = schválení administrátorem u firmy) */
	public static function invoice_allowed_for( $user_id, $id ) {
		if ( self::PRIMARY === $id ) {
			return null; // Rozhoduje nastavení účtu / platební metody.
		}
		$c = self::get( $user_id, $id );
		return $c && 'approved' === $c['status'] && isset( $c['invoice'] ) && 'yes' === $c['invoice'];
	}

	/** Firma zvolená v pokladně (relace), jinak předvybraná. */
	public static function current_checkout_company( $user_id ) {
		$id = ( function_exists( 'WC' ) && WC()->session ) ? (string) WC()->session->get( 'zg_b2b_company' ) : '';
		$billable = self::billable( $user_id );
		return isset( $billable[ $id ] ) ? $id : self::initial_company( $user_id );
	}

	/**
	 * Okamžité uložení kontaktních údajů z pokladny (bez čekání na „Hotovo“ / objednávku).
	 * billing: jméno, příjmení, telefon, e-mail účtu; shipping: údaje vybrané uložené doručovací adresy.
	 */
	public static function ajax_save_contact() {
		check_ajax_referer( 'zg_b2b_save_contact', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array(), 403 );
		}
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['fields'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( 'billing' === $type ) {
			$customer = new WC_Customer( $user_id );
			foreach ( array( 'first_name', 'last_name', 'phone', 'email' ) as $k ) {
				if ( ! isset( $fields[ $k ] ) || '' === trim( $fields[ $k ] ) ) {
					continue;
				}
				if ( 'email' === $k && ! is_email( $fields[ $k ] ) ) {
					wp_send_json_error( array( 'field' => 'email' ) );
				}
				$customer->{'set_billing_' . $k}( 'email' === $k ? sanitize_email( $fields[ $k ] ) : $fields[ $k ] );
			}
			$customer->save();
			if ( WC()->customer && WC()->customer->get_id() === $user_id ) {
				foreach ( array( 'first_name', 'last_name', 'phone', 'email' ) as $k ) {
					if ( ! empty( $fields[ $k ] ) ) {
						WC()->customer->{'set_billing_' . $k}( $fields[ $k ] );
					}
				}
			}
			wp_send_json_success();
		}

		if ( 'shipping' === $type ) {
			$id      = isset( $_POST['address'] ) ? sanitize_key( wp_unslash( $_POST['address'] ) ) : '';
			$address = ZG_B2B_Address_Book::get( $user_id, $id );
			if ( ! $address ) {
				wp_send_json_error( array( 'reason' => 'not_found' ) );
			}
			$keys = ZG_B2B_Address_Book::field_keys();
			foreach ( $fields as $k => $v ) {
				if ( in_array( $k, $keys, true ) && 'company' !== $k ) {
					$address['fields'][ $k ] = $v;
				}
			}
			foreach ( array( 'address_1', 'city', 'postcode', 'country' ) as $req ) {
				if ( empty( $address['fields'][ $req ] ) ) {
					wp_send_json_error( array( 'field' => $req ) );
				}
			}
			$r = ZG_B2B_Address_Book::save( $user_id, $address );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			$saved = ZG_B2B_Address_Book::get( $user_id, $id );
			wp_send_json_success( array(
				'fields' => $saved ? $saved['fields'] : array(),
				'inline' => $saved ? ZG_B2B_Address_Book::format_inline( $saved['fields'] ) : '',
			) );
		}

		wp_send_json_error( array(), 400 );
	}

	public static function remember_from_review( $post_data ) {
		parse_str( (string) $post_data, $p );
		if ( ! empty( $p['zg_b2b_company'] ) && WC()->session ) {
			WC()->session->set( 'zg_b2b_company', sanitize_key( $p['zg_b2b_company'] ) );
		}
	}

	public static function filter_invoice_allowed( $allowed, $user_id ) {
		if ( ! $user_id ) {
			return $allowed;
		}
		$per = self::invoice_allowed_for( $user_id, self::current_checkout_company( $user_id ) );
		return null === $per ? $allowed : $per;
	}

	/* =====================================================================
	 * Administrace
	 * =================================================================== */

	public static function pending_count() {
		$n = get_transient( 'zg_b2b_companies_pending_count' );
		if ( false === $n ) {
			$q = new WP_User_Query(
				array(
					'meta_key'     => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'   => 0, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_compare' => '>',
					'meta_type'    => 'NUMERIC',
					'fields'       => 'ID',
				)
			);
			$n = 0;
			foreach ( $q->get_results() as $uid ) {
				$n += (int) get_user_meta( $uid, self::META_PENDING, true );
			}
			set_transient( 'zg_b2b_companies_pending_count', $n, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $n;
	}

	public static function admin_view( $views ) {
		$n       = self::pending_count();
		$current = isset( $_GET['zg_b2b_company'] ) && 'pending' === $_GET['zg_b2b_company']; // phpcs:ignore WordPress.Security.NonceVerification
		$views['zg_b2b_company_pending'] = '<a href="' . esc_url( admin_url( 'users.php?zg_b2b_company=pending' ) ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>Firmy ke schválení <span class="count">(' . (int) $n . ')</span></a>';
		return $views;
	}

	public static function admin_filter_users( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'users.php' !== $pagenow || empty( $_GET['zg_b2b_company'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$query->set( 'meta_key', self::META_PENDING );
		$query->set( 'meta_value', 0 );
		$query->set( 'meta_compare', '>' );
		$query->set( 'meta_type', 'NUMERIC' );
	}

	public static function admin_column( $out, $col, $user_id ) {
		if ( 'zg_b2b' !== $col ) {
			return $out;
		}
		$list = self::get_all( $user_id );
		if ( ! $list ) {
			return $out;
		}
		$approved = 0;
		$pending  = 0;
		foreach ( $list as $c ) {
			$approved += 'approved' === $c['status'] ? 1 : 0;
			$pending  += 'pending' === $c['status'] ? 1 : 0;
		}
		$out .= '<br><small>+ další firmy: ' . (int) $approved . ' schválené';
		if ( $pending ) {
			$out .= ', <a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#zg-b2b-companies' ) ) . '" style="color:#8a5a00;font-weight:600">' . (int) $pending . ' ke schválení</a>';
		}
		return $out . '</small>';
	}

	public static function admin_notice() {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}
		$screen = get_current_screen();
		$n      = self::pending_count();
		if ( $n && $screen && in_array( $screen->id, array( 'dashboard', 'users', 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			echo '<div class="notice notice-warning"><p><strong>Velkoobchod:</strong> ' . (int) $n . ' nových firem čeká na schválení. <a href="' . esc_url( admin_url( 'users.php?zg_b2b_company=pending' ) ) . '">Zobrazit</a></p></div>';
		}
	}

	public static function admin_profile( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) || ! current_user_can( 'list_users' ) ) {
			return;
		}
		$list      = self::get_all( $user->ID );
		$countries = self::country_options() + WC()->countries->get_countries();
		wp_nonce_field( 'zg_b2b_companies_admin', 'zg_b2b_companies_nonce' );
		?>
		<h2 id="zg-b2b-companies">Další firmy účtu (B2B)</h2>
		<p class="description">Objednávky i platbu na fakturu schvalujete pro každou firmu zvlášť (sloupec Stav a Faktura; pro hlavní firmu platí „Platba na fakturu“ výše). Hlavní firma = fakturační údaje níže (WooCommerce). Na další firmu zákazník fakturuje v pokladně až po schválení. Údaje firem mění jen administrátor; zákazník dostane e-mail při schválení / zamítnutí.</p>
		<style>.zg-b2b-co td,.zg-b2b-co th{vertical-align:top;padding:6px}.zg-b2b-co input[type=text]{width:100%}.zg-b2b-co select{max-width:100%}.zg-b2b-co small{color:#646970}</style>
		<table class="widefat striped zg-b2b-co">
			<thead><tr><th style="width:22%">Firma</th><th style="width:11%">IČO</th><th style="width:13%">DIČ</th><th>Sídlo (ulice / PSČ / město / země)</th><th style="width:13%">Stav</th><th style="width:7%">Faktura</th><th style="width:6%">Smazat</th></tr></thead>
			<tbody>
			<?php foreach ( $list as $c ) : $n = 'zg_b2b_co[' . esc_attr( $c['id'] ) . ']'; ?>
				<tr>
					<td><input type="text" name="<?php echo $n; // phpcs:ignore ?>[company]" value="<?php echo esc_attr( $c['company'] ); ?>">
						<small>Přidáno <?php echo esc_html( wp_date( 'j. n. Y', (int) $c['created'] ) ); ?><?php echo ! empty( $c['ares_name'] ) ? '<br>ARES: ' . esc_html( $c['ares_name'] ) : ''; ?></small></td>
					<td><input type="text" name="<?php echo $n; // phpcs:ignore ?>[ico]" value="<?php echo esc_attr( $c['ico'] ); ?>"><small><?php echo esc_html( ZG_B2B_Registration::flag_label( 'ares', isset( $c['ares'] ) ? $c['ares'] : 'na' ) ); ?></small></td>
					<td><input type="text" name="<?php echo $n; // phpcs:ignore ?>[dic]" value="<?php echo esc_attr( $c['dic'] ); ?>"><small><?php echo esc_html( ZG_B2B_Registration::flag_label( 'vies', isset( $c['vies'] ) ? $c['vies'] : 'na' ) ); ?></small></td>
					<td>
						<input type="text" name="<?php echo $n; // phpcs:ignore ?>[address_1]" value="<?php echo esc_attr( $c['address_1'] ); ?>" placeholder="Ulice a č.p.">
						<input type="text" name="<?php echo $n; // phpcs:ignore ?>[postcode]" value="<?php echo esc_attr( $c['postcode'] ); ?>" placeholder="PSČ" style="width:30%">
						<input type="text" name="<?php echo $n; // phpcs:ignore ?>[city]" value="<?php echo esc_attr( $c['city'] ); ?>" placeholder="Město" style="width:68%">
						<select name="<?php echo $n; // phpcs:ignore ?>[country]"><?php foreach ( $countries as $code => $label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $c['country'], $code ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					</td>
					<td><select name="<?php echo $n; // phpcs:ignore ?>[status]"><?php foreach ( self::statuses() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['status'], $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
					<td><label title="Povolit platbu na fakturu pro tuto firmu"><input type="checkbox" name="<?php echo $n; // phpcs:ignore ?>[invoice]" value="yes" <?php checked( isset( $c['invoice'] ) ? $c['invoice'] : 'no', 'yes' ); ?>> ano</label></td>
					<td><label><input type="checkbox" name="<?php echo $n; // phpcs:ignore ?>[delete]" value="1"> ✕</label></td>
				</tr>
			<?php endforeach; ?>
				<tr>
					<td><input type="text" name="zg_b2b_co[new][company]" placeholder="+ Nová firma (název)"></td>
					<td><input type="text" name="zg_b2b_co[new][ico]" placeholder="IČO"></td>
					<td><input type="text" name="zg_b2b_co[new][dic]" placeholder="DIČ"></td>
					<td>
						<input type="text" name="zg_b2b_co[new][address_1]" placeholder="Ulice a č.p.">
						<input type="text" name="zg_b2b_co[new][postcode]" placeholder="PSČ" style="width:30%">
						<input type="text" name="zg_b2b_co[new][city]" placeholder="Město" style="width:68%">
						<select name="zg_b2b_co[new][country]"><?php foreach ( $countries as $code => $label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( 'CZ', $code ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					</td>
					<td><select name="zg_b2b_co[new][status]"><?php foreach ( self::statuses() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( 'approved', $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
					<td><label><input type="checkbox" name="zg_b2b_co[new][invoice]" value="yes"> ano</label></td>
					<td></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public static function admin_save( $user_id ) {
		if ( ! isset( $_POST['zg_b2b_companies_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zg_b2b_companies_nonce'] ) ), 'zg_b2b_companies_admin' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'list_users' ) || empty( $_POST['zg_b2b_co'] ) || ! is_array( $_POST['zg_b2b_co'] ) ) {
			return;
		}
		$posted  = wp_unslash( $_POST['zg_b2b_co'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$list    = self::get_all( $user_id );
		$changes = array();

		foreach ( $list as $i => $c ) {
			if ( ! isset( $posted[ $c['id'] ] ) || ! is_array( $posted[ $c['id'] ] ) ) {
				continue;
			}
			$p = $posted[ $c['id'] ];
			if ( ! empty( $p['delete'] ) ) {
				unset( $list[ $i ] );
				continue;
			}
			$new_status = isset( $p['status'] ) && isset( self::statuses()[ $p['status'] ] ) ? $p['status'] : $c['status'];
			$list[ $i ] = array_merge( $c, self::sanitize( $p ), array( 'status' => $new_status, 'invoice' => ! empty( $p['invoice'] ) ? 'yes' : 'no' ) );
			if ( $new_status !== $c['status'] ) {
				$list[ $i ]['decided'] = time();
				$changes[]             = $list[ $i ];
			}
		}

		if ( isset( $posted['new'] ) && is_array( $posted['new'] ) && '' !== trim( (string) ( isset( $posted['new']['company'] ) ? $posted['new']['company'] : '' ) ) ) {
			$p      = $posted['new'];
			$status = isset( $p['status'] ) && isset( self::statuses()[ $p['status'] ] ) ? $p['status'] : 'approved';
			$list[] = array_merge(
				array( 'id' => substr( md5( uniqid( '', true ) . wp_rand() ), 0, 10 ) ),
				self::sanitize( $p ),
				array( 'status' => $status, 'invoice' => ! empty( $p['invoice'] ) ? 'yes' : 'no', 'created' => time(), 'decided' => time(), 'ares' => 'na', 'ares_name' => '', 'vies' => 'na' )
			);
		}

		self::save_all( $user_id, $list );

		foreach ( $changes as $c ) {
			if ( in_array( $c['status'], array( 'approved', 'rejected' ), true ) ) {
				zg_b2b_in_language(
					(string) get_user_meta( $user_id, 'zg_b2b_lang', true ),
					function () use ( $user_id, $c ) {
						self::mail_customer_decision( $user_id, $c );
					}
				);
			}
		}
	}

	/* =====================================================================
	 * E-maily
	 * =================================================================== */

	private static function mail_admin_new( $user_id, array $c ) {
		$user  = get_userdata( $user_id );
		$rows  = array(
			'Účet'        => ( $user ? $user->user_email : '' ) . ' – hlavní firma: ' . get_user_meta( $user_id, 'billing_company', true ),
			'Nová firma'  => $c['company'],
			'IČO'         => $c['ico'] . ( $c['ico'] ? ' (' . ZG_B2B_Registration::flag_label( 'ares', $c['ares'] ) . ')' : '' ),
			'Název v ARES' => $c['ares_name'],
			'DIČ'         => $c['dic'] . ( $c['dic'] ? ' (' . ZG_B2B_Registration::flag_label( 'vies', $c['vies'] ) . ')' : '' ),
			'Sídlo'       => $c['address_1'] . ', ' . $c['postcode'] . ' ' . $c['city'] . ', ' . zg_b2b_country_name( $c['country'] ),
		);
		$html = '<p>Zákazník přidal do svého účtu další firmu. Na firmu může fakturovat až po schválení.</p><table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<tr><th align="left" style="border-bottom:1px solid #eee;width:34%">' . esc_html( $label ) . '</th><td style="border-bottom:1px solid #eee">' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</table><p style="margin-top:20px"><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#zg-b2b-companies' ) ) . '">Schválit / zamítnout v profilu zákazníka</a></p>';
		zg_b2b_send_mail( zg_b2b_contact_email(), 'Nová firma ke schválení: ' . $c['company'], 'Nová firma ke schválení', $html );
	}

	private static function mail_customer_decision( $user_id, array $c ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$approved = 'approved' === $c['status'];
		$html     = '<p>' . sprintf( esc_html__( 'Dobrý den, %s,', 'zg-b2b' ), esc_html( $user->first_name ) ) . '</p><p>';
		if ( $approved ) {
			/* translators: %s: firma */
			$html .= sprintf( esc_html__( 'firma %s je ve vašem velkoobchodním účtu schválená. V pokladně ji nyní můžete vybrat v části „Fakturovat na firmu“.', 'zg-b2b' ), '<strong>' . esc_html( $c['company'] ) . '</strong>' );
		} else {
			/* translators: %s: firma */
			$html .= sprintf( esc_html__( 'firmu %s jsme bohužel nemohli do vašeho účtu schválit. Pro více informací nám prosím napište.', 'zg-b2b' ), '<strong>' . esc_html( $c['company'] ) . '</strong>' );
		}
		$html .= '</p><p>' . sprintf(
			/* translators: %s: e-mail */
			esc_html__( 'Dotazy: %s', 'zg-b2b' ),
			'<a href="mailto:' . esc_attr( zg_b2b_contact_email() ) . '">' . esc_html( zg_b2b_contact_email() ) . '</a>'
		) . '</p>';
		zg_b2b_send_mail(
			$user->user_email,
			$approved ? __( 'Firma ve vašem účtu je schválená', 'zg-b2b' ) : __( 'Firmu jsme nemohli schválit', 'zg-b2b' ),
			$approved ? __( 'Firma schválena', 'zg-b2b' ) : __( 'Firma neschválena', 'zg-b2b' ),
			$html
		);
	}
}
