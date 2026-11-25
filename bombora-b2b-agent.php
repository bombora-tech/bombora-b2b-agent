<?php
/**
 * Plugin Name:       Bombora B2B Agent
 * Plugin URI:        https://github.com/bombora-tech/bombora-b2b-agent
 * Description:       Velkoobchod vo.zgruzie.cz – ceny jen pro schválené partnery, registrace firem s ověřením ARES/VIES a ručním schválením, lihoviny a pivo s doručením pouze v ČR, platba na fakturu s proformou z Eurofaktury, více doručovacích adres (pobočky) s výběrem v pokladně.
 * Version:           1.5.0
 * Author:            Bombora group s.r.o.
 * Author URI:        https://zgruzie.cz
 * Text Domain:       zg-b2b
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Update URI:        https://github.com/bombora-tech/bombora-b2b-agent
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'ZG_B2B_VERSION', '1.5.0' );
define( 'ZG_B2B_FILE', __FILE__ );
define( 'ZG_B2B_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZG_B2B_URL', plugin_dir_url( __FILE__ ) );
define( 'ZG_B2B_TAG', 'doruceni-pouze-v-cr' );

require_once ZG_B2B_DIR . 'includes/helpers.php';

register_activation_hook( __FILE__, 'zg_b2b_activate' );
function zg_b2b_activate() {
	if ( ! get_option( 'zg_b2b_activated' ) ) {
		add_option( 'zg_b2b_activated', time() );
	}
	zg_b2b_ensure_tag();
	update_option( 'zg_b2b_addr_flush_rewrite', 1 ); // Endpoint Můj účet → Doručovací adresy.
}

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>Bombora B2B Agent vyžaduje aktivní WooCommerce.</p></div>';
		} );
		return;
	}

	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-settings.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-cz-only.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-prices.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-registration.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-approval.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-account.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-country-prompt.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-ef-client.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-gateway-invoice.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-ef-documents.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-address-book.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-address-account.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-address-checkout.php';
	require_once ZG_B2B_DIR . 'includes/class-zg-b2b-address-admin.php';

	ZG_B2B_Settings::init();
	ZG_B2B_CZ_Only::init();
	ZG_B2B_Prices::init();
	ZG_B2B_Registration::init();
	ZG_B2B_Approval::init();
	ZG_B2B_Account::init();
	ZG_B2B_Country_Prompt::init();
	ZG_B2B_EF_Documents::init();
	ZG_B2B_Address_Account::init();
	ZG_B2B_Address_Checkout::init();
	ZG_B2B_Address_Admin::init();

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'ZG_B2B_Gateway_Invoice';
		return $gateways;
	} );
} );

add_action( 'admin_init', 'zg_b2b_ensure_tag' );

/*
 * Překlady: načítají se až po určení jazyka (WPML), tj. na 'wp' (frontend) a
 * 'admin_init' (administrace + AJAX), a znovu při switch_to_locale() (e-maily).
 */
function zg_b2b_load_textdomain() {
	$locale = determine_locale();
	$dir    = ZG_B2B_DIR . 'languages/';
	$file   = $dir . 'zg-b2b-' . $locale . '.mo';
	if ( ! file_exists( $file ) && 0 === strpos( $locale, 'en' ) ) {
		$file = $dir . 'zg-b2b-en_US.mo';
	}
	unload_textdomain( 'zg-b2b' );
	if ( file_exists( $file ) ) {
		load_textdomain( 'zg-b2b', $file, $locale );
	}
}
add_action( 'wp', 'zg_b2b_load_textdomain', 0 );
add_action( 'admin_init', 'zg_b2b_load_textdomain', 0 );
add_action( 'rest_api_init', 'zg_b2b_load_textdomain', 0 );
add_action( 'change_locale', 'zg_b2b_load_textdomain' );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'zg-b2b', ZG_B2B_URL . 'assets/css/zg-b2b.css', array(), ZG_B2B_VERSION );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=zg-b2b' ) ) . '">Nastavení</a>' );
	return $links;
} );
