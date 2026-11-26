<?php
/**
 * Sdílené pomocné funkce.
 */

defined( 'ABSPATH' ) || exit;

function zg_b2b_eu_countries() {
	return array( 'CZ', 'SK', 'AT', 'BE', 'BG', 'CY', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI' );
}

function zg_b2b_defaults() {
	$sv = class_exists( 'ZG_B2B_SV_Stock' ) ? ZG_B2B_SV_Stock::defaults() : array();
	return $sv + array(
		'allowed_countries'       => array( 'CZ' ),
		'cz_categories'           => array(),
		'block_foreign_customers' => 'yes',
		'notice_title'            => 'Doručení pouze v České republice',
		'notice_text'             => 'Lihoviny a pivo doručujeme výhradně na adresy v ČR. Do jiných zemí tento produkt neodesíláme.',
		'notice_title_en'         => 'Delivery in the Czech Republic only',
		'notice_text_en'          => 'We deliver spirits and beer only to addresses in the Czech Republic. This product cannot be shipped to other countries.',
		'site_title_en'           => 'Georgian Products Wholesale for HoReCa',
		'site_tagline_en'         => 'Georgian wines, brandy, chacha, lemonades, spices',
		'registration_page'       => 0,
		'terms_page'              => 0,
		'notify_email'            => 'velkoobchod@zgruzie.cz',
		'ico_meta'                => 'billing_ic',
		'dic_meta'                => 'billing_dic',
		'legacy_approved'         => 'yes',
		'reg_countries'           => zg_b2b_eu_countries(),
		'tag_name'                => 'Doručení pouze v ČR',
		'locked_fields'           => array( 'company', 'ico', 'dic' ),
		'hidden_account_items'    => array( 'downloads' ),
		'ef_endpoint'             => 'https://eurofaktura.cz/WebServicesCZ/API',
		'ef_username'             => '',
		'ef_secret'               => '',
		'ef_token'                => '',
		'ef_quote_prefix'         => 'SalesQuote',
		'ef_quote_status'         => 'Issued',
		'ef_send_sku'             => 'no',
		'ef_field_dic'            => 'buyerTaxNumber',
		'ef_field_ico'            => '',
		'ef_auto_paid'            => 'yes',
		'ef_docs_module'          => 'no',
		'ef_invoice_meta'         => '',
		'ef_invoice_date_meta'    => '',
	);
}

function zg_b2b_settings() {
	$saved = get_option( 'zg_b2b_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), zg_b2b_defaults() );
}

function zg_b2b_opt( $key ) {
	$s = zg_b2b_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/** ID štítku „doručení pouze v ČR“ (uložené v options; název si volíte v nastavení). */
function zg_b2b_tag_id() {
	$id = (int) get_option( 'zg_b2b_tag_id' );
	if ( $id && term_exists( $id, 'product_tag' ) ) {
		return $id;
	}
	// Zpětná kompatibilita s verzí 1.0 (pevný slug).
	$t = taxonomy_exists( 'product_tag' ) ? get_term_by( 'slug', ZG_B2B_TAG, 'product_tag' ) : false;
	if ( $t ) {
		update_option( 'zg_b2b_tag_id', (int) $t->term_id );
		return (int) $t->term_id;
	}
	return 0;
}

/** Vytvoří štítek, pokud neexistuje. */
function zg_b2b_ensure_tag() {
	if ( ! taxonomy_exists( 'product_tag' ) || zg_b2b_tag_id() ) {
		return zg_b2b_tag_id();
	}
	$name     = zg_b2b_opt( 'tag_name' ) ? zg_b2b_opt( 'tag_name' ) : 'Doručení pouze v ČR';
	$existing = get_term_by( 'name', $name, 'product_tag' );
	if ( $existing ) {
		update_option( 'zg_b2b_tag_id', (int) $existing->term_id );
		return (int) $existing->term_id;
	}
	$r = wp_insert_term( $name, 'product_tag', array( 'description' => 'Produkty doručované pouze v České republice (spravuje plugin Bombora B2B).' ) );
	if ( is_wp_error( $r ) ) {
		return 0;
	}
	update_option( 'zg_b2b_tag_id', (int) $r['term_id'] );
	return (int) $r['term_id'];
}

/**
 * Přejmenuje štítek. Pokud už jiný štítek s tímto názvem existuje, plugin začne
 * používat ten a produkty ze starého štítku na něj převede.
 *
 * @return string Zpráva pro administraci.
 */
function zg_b2b_rename_tag( $new_name ) {
	$id = zg_b2b_ensure_tag();
	if ( ! $id ) {
		return '';
	}
	$term = get_term( $id, 'product_tag' );
	if ( ! $term || is_wp_error( $term ) || $term->name === $new_name ) {
		return '';
	}
	$other = get_term_by( 'name', $new_name, 'product_tag' );
	if ( $other && (int) $other->term_id !== $id ) {
		$ids = get_objects_in_term( $id, 'product_tag' );
		foreach ( (array) $ids as $pid ) {
			wp_set_object_terms( (int) $pid, array( (int) $other->term_id ), 'product_tag', true );
			wp_remove_object_terms( (int) $pid, array( $id ), 'product_tag' );
		}
		update_option( 'zg_b2b_tag_id', (int) $other->term_id );
		return sprintf( 'Štítek „%s“ už existoval – plugin teď používá jej a převedl na něj %d produktů. Původní štítek „%s“ zůstal (prázdný), můžete ho smazat.', $new_name, count( (array) $ids ), $term->name );
	}
	$r = wp_update_term( $id, 'product_tag', array( 'name' => $new_name, 'slug' => sanitize_title( $new_name ) ) );
	if ( is_wp_error( $r ) ) {
		$r = wp_update_term( $id, 'product_tag', array( 'name' => $new_name ) );
	}
	return is_wp_error( $r ) ? 'Štítek se nepodařilo přejmenovat: ' . $r->get_error_message() : sprintf( 'Štítek přejmenován na „%s“.', $new_name );
}

function zg_b2b_tag_name() {
	$id   = zg_b2b_tag_id();
	$term = $id ? get_term( $id, 'product_tag' ) : null;
	return $term && ! is_wp_error( $term ) ? $term->name : (string) zg_b2b_opt( 'tag_name' );
}

/**
 * Stav B2B účtu: approved | pending | rejected | none.
 * Uživatelé registrovaní před aktivací pluginu jsou (volitelně) brány jako schválení.
 */
function zg_b2b_user_status( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return 'none';
	}
	$status = get_user_meta( $user_id, 'zg_b2b_status', true );
	if ( $status ) {
		return $status;
	}
	if ( user_can( $user_id, 'manage_woocommerce' ) ) {
		return 'approved';
	}
	if ( 'yes' === zg_b2b_opt( 'legacy_approved' ) ) {
		$user      = get_userdata( $user_id );
		$activated = (int) get_option( 'zg_b2b_activated' );
		if ( $user && $activated && strtotime( $user->user_registered . ' UTC' ) < $activated ) {
			return 'approved';
		}
	}
	return 'none';
}

function zg_b2b_can_see_prices() {
	static $cache = array();
	$uid = get_current_user_id();
	if ( ! isset( $cache[ $uid ] ) ) {
		if ( ! $uid ) {
			$can = false;
		} elseif ( current_user_can( 'manage_woocommerce' ) ) {
			$can = true;
		} else {
			$can = ( 'approved' === zg_b2b_user_status( $uid ) );
		}
		$cache[ $uid ] = (bool) apply_filters( 'zg_b2b_can_see_prices', $can, $uid );
	}
	return $cache[ $uid ];
}

/** Je aktuální jazyk angličtina (WPML / locale)? */
function zg_b2b_is_en() {
	$lang = apply_filters( 'wpml_current_language', null );
	if ( $lang ) {
		return 'en' === $lang;
	}
	return 0 === strpos( determine_locale(), 'en' );
}

function zg_b2b_notice_title() {
	return zg_b2b_is_en() && zg_b2b_opt( 'notice_title_en' ) ? zg_b2b_opt( 'notice_title_en' ) : zg_b2b_opt( 'notice_title' );
}

function zg_b2b_notice_text() {
	return zg_b2b_is_en() && zg_b2b_opt( 'notice_text_en' ) ? zg_b2b_opt( 'notice_text_en' ) : zg_b2b_opt( 'notice_text' );
}

/**
 * Spustí $callback v jazyce zákazníka (pro e-maily odesílané z administrace / cronu).
 * $lang = kód jazyka WPML ('en', 'cs').
 */
function zg_b2b_in_language( $lang, $callback ) {
	$switched = false;
	if ( $lang ) {
		do_action( 'wpml_switch_language', $lang );
		$locale   = 'en' === $lang ? 'en_US' : ( 'cs' === $lang ? 'cs_CZ' : '' );
		$switched = $locale && function_exists( 'switch_to_locale' ) ? switch_to_locale( $locale ) : false;
		if ( function_exists( 'zg_b2b_load_textdomain' ) ) {
			zg_b2b_load_textdomain();
		}
	}
	$result = call_user_func( $callback );
	if ( $lang ) {
		if ( $switched ) {
			restore_previous_locale();
		}
		do_action( 'wpml_switch_language', null );
		if ( function_exists( 'zg_b2b_load_textdomain' ) ) {
			zg_b2b_load_textdomain();
		}
	}
	return $result;
}

function zg_b2b_page_url( $page_id ) {
	$page_id = (int) apply_filters( 'wpml_object_id', (int) $page_id, 'page', true );
	return $page_id ? get_permalink( $page_id ) : '';
}

function zg_b2b_login_url() {
	return wc_get_page_permalink( 'myaccount' );
}

function zg_b2b_registration_url() {
	$url = zg_b2b_page_url( zg_b2b_opt( 'registration_page' ) );
	return $url ? $url : zg_b2b_login_url();
}

function zg_b2b_contact_email() {
	$email = zg_b2b_opt( 'notify_email' );
	return is_email( $email ) ? $email : get_option( 'admin_email' );
}

/** Výchozí jazyk WPML (nebo prázdné bez WPML). */
function zg_b2b_default_lang() {
	return (string) apply_filters( 'wpml_default_language', null );
}

/** ID produktu ve výchozím jazyce (štítek se spravuje na původních produktech). */
function zg_b2b_original_id( $id, $type = 'product' ) {
	$def = zg_b2b_default_lang();
	if ( ! $def ) {
		return (int) $id;
	}
	$orig = apply_filters( 'wpml_object_id', (int) $id, $type, true, $def );
	return $orig ? (int) $orig : (int) $id;
}

/** ID produktů se štítkem – přímo z DB, nezávisle na jazykových filtrech WPML. */
function zg_b2b_tagged_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$ids  = array();
	$tag  = zg_b2b_tag_id();
	$term = $tag ? get_term( $tag, 'product_tag' ) : null;
	if ( $term && ! is_wp_error( $term ) ) {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", (int) $term->term_taxonomy_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	return $ids;
}

/** Má produkt (nebo jeho rodič u variant, případně jeho původní jazyková verze) štítek? */
function zg_b2b_is_cz_only( $product ) {
	if ( is_numeric( $product ) ) {
		$product = wc_get_product( $product );
	}
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	$id  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	$ids = array_unique( array( (int) $id, zg_b2b_original_id( $id ) ) );
	return (bool) array_intersect( $ids, zg_b2b_tagged_ids() );
}

function zg_b2b_country_allowed( $country ) {
	$allowed = (array) zg_b2b_opt( 'allowed_countries' );
	return in_array( strtoupper( (string) $country ), $allowed, true );
}

function zg_b2b_country_name( $code ) {
	$countries = WC()->countries ? WC()->countries->get_countries() : array();
	return isset( $countries[ $code ] ) ? $countries[ $code ] : $code;
}

function zg_b2b_allowed_countries_label() {
	$names = array_map( 'zg_b2b_country_name', (array) zg_b2b_opt( 'allowed_countries' ) );
	return implode( ', ', $names );
}

/** Země doručení uložená v B2B účtu (shipping, jinak billing). */
function zg_b2b_account_country( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return '';
	}
	$c = get_user_meta( $user_id, 'shipping_country', true );
	if ( ! $c ) {
		$c = get_user_meta( $user_id, 'billing_country', true );
	}
	return strtoupper( (string) $c );
}

function zg_b2b_icon( $name ) {
	$icons = array(
		'truck'  => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/>',
		'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		'check'  => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		'arrow'  => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'alert'  => '<path d="M12 3l9.5 17h-19z"/><path d="M12 10v4M12 17.2v.3"/>',
		'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
		'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.8v.3"/>',
		'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
		'login'  => '<path d="M10 17l5-5-5-5M15 12H3M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
		'eye'    => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
	);
	if ( ! isset( $icons[ $name ] ) ) {
		return '';
	}
	return '<svg class="zg-b2b-icon zg-b2b-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>';
}

/** Tlačítko (registrace / přihlášení). */
function zg_b2b_button( $url, $label, $variant = 'primary', $icon = 'arrow', $size = '' ) {
	$classes = 'zg-b2b-btn zg-b2b-btn--' . sanitize_html_class( $variant );
	if ( $size ) {
		$classes .= ' zg-b2b-btn--' . sanitize_html_class( $size );
	}
	return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span>' . zg_b2b_icon( $icon ) . '</a>';
}

/** Informační box o doručení pouze v ČR. */
function zg_b2b_notice_box( $title, $text, $variant = 'info', $icon = 'truck' ) {
	return '<div class="zg-b2b-notice zg-b2b-notice--' . esc_attr( $variant ) . '" role="note">'
		. '<span class="zg-b2b-notice__icon">' . zg_b2b_icon( $icon ) . '</span>'
		. '<span class="zg-b2b-notice__body"><strong class="zg-b2b-notice__title">' . esc_html( $title ) . '</strong>'
		. '<span class="zg-b2b-notice__text">' . wp_kses_post( $text ) . '</span></span>'
		. '</div>';
}

function zg_b2b_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
}

/** Jednoduchý limit počtu požadavků na IP. Vrací true, pokud je limit překročen. */
function zg_b2b_rate_limited( $bucket, $max, $window ) {
	$key   = 'zg_b2b_rl_' . md5( $bucket . '|' . zg_b2b_client_ip() );
	$count = (int) get_transient( $key );
	if ( $count >= $max ) {
		return true;
	}
	set_transient( $key, $count + 1, $window );
	return false;
}

function zg_b2b_send_mail( $to, $subject, $heading, $html ) {
	$headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . zg_b2b_contact_email() );
	if ( function_exists( 'WC' ) && WC()->mailer() ) {
		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $html );
		return $mailer->send( $to, $subject, $message, $headers );
	}
	return wp_mail( $to, $subject, $html, $headers );
}
