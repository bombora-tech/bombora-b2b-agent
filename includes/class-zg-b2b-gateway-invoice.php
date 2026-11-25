<?php
/**
 * Platební metoda „Platba na fakturu (proforma)“ pro schválené B2B partnery.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Gateway_Invoice extends WC_Payment_Gateway {

	const ID = 'zg_b2b_invoice';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'Platba na fakturu (B2B proforma)';
		$this->method_description = 'Po objednávce se v Eurofaktuře vystaví proforma faktura a zákazníkovi se pošle e-mailem. Objednávka čeká na úhradu (stav „Čeká na vyřízení“). Po vystavení faktury v Eurofaktuře ji plugin dohledá a nahradí proformu v Můj účet. Dostupné jen schváleným velkoobchodním partnerům.';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$en                = function_exists( 'zg_b2b_is_en' ) && ! is_admin() && zg_b2b_is_en();
		$this->title       = $en && $this->get_option( 'title_en' ) ? $this->get_option( 'title_en' ) : $this->get_option( 'title' );
		$this->description = $en && $this->get_option( 'description_en' ) ? $this->get_option( 'description_en' ) : $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => 'Povolit',
				'type'    => 'checkbox',
				'label'   => 'Povolit platbu na fakturu (proforma)',
				'default' => 'no',
			),
			'title'        => array(
				'title'   => 'Název v pokladně',
				'type'    => 'text',
				'default' => 'Platba na fakturu (proforma)',
			),
			'description'  => array(
				'title'   => 'Popis v pokladně',
				'type'    => 'textarea',
				'default' => 'Po odeslání objednávky vám e-mailem pošleme proforma fakturu. Zboží expedujeme po připsání platby na náš účet. Daňový doklad vystavíme po úhradě.',
			),
			'title_en'     => array(
				'title'   => 'Název v pokladně (EN)',
				'type'    => 'text',
				'default' => 'Payment by invoice (proforma)',
			),
			'description_en' => array(
				'title'   => 'Popis v pokladně (EN)',
				'type'    => 'textarea',
				'default' => 'After placing the order we will e-mail you a proforma invoice. We ship the goods once the payment has been credited to our account. The tax invoice is issued after payment.',
			),
			'who'          => array(
				'title'   => 'Komu nabízet',
				'type'    => 'select',
				'default' => 'approved',
				'options' => array(
					'approved' => 'Všem schváleným B2B partnerům',
					'allowed'  => 'Jen partnerům s povolenou platbou na fakturu (nastavení v profilu uživatele)',
				),
			),
			'due_days'     => array(
				'title'             => 'Splatnost proformy (dny)',
				'type'              => 'number',
				'default'           => '5',
				'custom_attributes' => array( 'min' => 1, 'max' => 90 ),
			),
			'account'      => array(
				'title'       => 'Číslo účtu (CZK)',
				'type'        => 'text',
				'default'     => '',
				'description' => 'Např. 123456789/0100',
			),
			'iban'         => array(
				'title'   => 'IBAN',
				'type'    => 'text',
				'default' => '',
			),
			'swift'        => array(
				'title'   => 'BIC/SWIFT',
				'type'    => 'text',
				'default' => '',
			),
			'account_eur'  => array(
				'title'       => 'Účet pro platby v EUR (IBAN)',
				'type'        => 'text',
				'default'     => '',
				'description' => 'Zobrazí se u objednávek v EUR. Prázdné = použije se IBAN výše.',
			),
			'vs_source'    => array(
				'title'       => 'Variabilní symbol',
				'type'        => 'select',
				'default'     => 'proforma',
				'options'     => array(
					'proforma' => 'Číslo proformy z Eurofaktury (jen číslice)',
					'order'    => 'Číslo objednávky',
				),
				'description' => 'Musí odpovídat tomu, podle čeho párujete platby v Eurofaktuře (VS uvedený na PDF proformy).',
			),
		);
	}

	/** Je metoda dostupná pro aktuálního zákazníka? */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}
		if ( ! is_user_logged_in() || ! zg_b2b_can_see_prices() ) {
			return false;
		}
		if ( 'allowed' === $this->get_option( 'who' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return 'yes' === get_user_meta( get_current_user_id(), 'zg_b2b_invoice_allowed', true );
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$due   = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * max( 1, (int) $this->get_option( 'due_days', 5 ) ) );
		$order->update_meta_data( '_zg_ef_due_date', $due );
		$order->save();

		// Proforma: zkusit hned (PDF se přiloží k e-mailu o přijetí objednávky);
		// při chybě se naplánuje opakování na pozadí a proforma přijde samostatným e-mailem.
		ZG_B2B_EF_Documents::create_proforma( $order_id, 1, false );

		// Na úhradu čeká → stav „Čeká na vyřízení“ (WooCommerce při něm odečte sklad a pošle e-mail).
		$order = wc_get_order( $order_id );
		$order->update_status( 'on-hold', 'Čeká na úhradu proformy (platba na fakturu).' );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/** Platební údaje pro zobrazení (děkovací stránka, Můj účet, e-maily). */
	public function payment_details( $order ) {
		$eur     = 'EUR' === $order->get_currency();
		$iban    = $eur && $this->get_option( 'account_eur' ) ? $this->get_option( 'account_eur' ) : $this->get_option( 'iban' );
		$pnum    = $order->get_meta( '_zg_ef_proforma_number' );
		$vs      = 'order' === $this->get_option( 'vs_source' ) ? preg_replace( '/\D/', '', $order->get_order_number() ) : ( $pnum ? preg_replace( '/\D/', '', $pnum ) : '' );
		$due     = $order->get_meta( '_zg_ef_due_date' );
		$details = array();
		$details[ __( 'Částka', 'zg-b2b' ) ] = wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
		if ( ! $eur && $this->get_option( 'account' ) ) {
			$details[ __( 'Číslo účtu', 'zg-b2b' ) ] = $this->get_option( 'account' );
		}
		if ( $iban ) {
			$details['IBAN'] = $iban;
		}
		if ( $this->get_option( 'swift' ) ) {
			$details['BIC/SWIFT'] = $this->get_option( 'swift' );
		}
		$details[ __( 'Variabilní symbol', 'zg-b2b' ) ] = $vs ? $vs : __( 'uveden na proformě', 'zg-b2b' );
		if ( $due ) {
			$details[ __( 'Splatnost', 'zg-b2b' ) ] = date_i18n( 'j. n. Y', strtotime( $due ) );
		}
		return $details;
	}

	public function details_html( $order ) {
		$rows = '';
		foreach ( $this->payment_details( $order ) as $label => $value ) {
			$rows .= '<tr><th>' . esc_html( $label ) . '</th><td><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		return '<table class="zg-b2b-paytable">' . $rows . '</table>';
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		echo '<section class="zg-b2b-paybox">';
		echo '<h2 class="zg-b2b-paybox__title">' . esc_html__( 'Platba na fakturu', 'zg-b2b' ) . '</h2>';
		echo $this->details_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ZG_B2B_EF_Documents::documents_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="zg-b2b-paybox__note">' . esc_html__( 'Objednávku expedujeme po připsání platby. Po úhradě vystavíme fakturu (daňový doklad) – najdete ji v sekci Můj účet → Objednávky.', 'zg-b2b' ) . '</p>';
		echo '</section>';
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || self::ID !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) ) {
			return;
		}
		$details = $this->payment_details( $order );
		$note    = $order->get_meta( '_zg_ef_proforma_number' )
			? __( 'Proformu faktury najdete v příloze tohoto e-mailu a také v sekci Můj účet. Objednávku expedujeme po připsání platby.', 'zg-b2b' )
			: __( 'Proformu faktury vám pošleme samostatným e-mailem a najdete ji také v sekci Můj účet. Objednávku expedujeme po připsání platby.', 'zg-b2b' );
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Platba na fakturu', 'zg-b2b' ) . "\n";
			foreach ( $details as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}
			echo esc_html( $note ) . "\n\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Platba na fakturu', 'zg-b2b' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;margin-bottom:12px">';
		foreach ( $details as $label => $value ) {
			echo '<tr><th style="text-align:left;border-bottom:1px solid #e5e5e5">' . esc_html( $label ) . '</th><td style="border-bottom:1px solid #e5e5e5"><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		echo '</table><p>' . esc_html( $note ) . '</p>';
	}

	public static function instance() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ self::ID ] ) ? $gateways[ self::ID ] : new self();
	}
}
