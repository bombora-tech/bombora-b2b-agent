<?php
/**
 * Proforma a faktura z Eurofaktury: vytvoření, PDF, dohledání faktury,
 * stažení v Můj účet, přílohy e-mailů a panel v detailu objednávky.
 *
 * Meta objednávky:
 *   _zg_ef_proforma_number / _date / _id / _file
 *   _zg_ef_invoice_number  / _date / _id / _file
 *   _zg_ef_due_date, _zg_ef_proforma_attempts
 *
 * Faktury a opravné daňové doklady vystavené přes plugin Eurofaktura.cz
 * (webhooky) načítá ZG_B2B_EF_Connector – mají přednost před proformou.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_EF_Documents {

	const MAX_ATTEMPTS = 5;
	const SYNC_HOOK    = 'zg_b2b_ef_sync_invoices';
	const CREATE_HOOK  = 'zg_b2b_ef_create_proforma';

	public static function init() {
		add_action( self::CREATE_HOOK, array( __CLASS__, 'create_proforma' ), 10, 2 );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync_invoices' ) );
		add_action( 'init', array( __CLASS__, 'schedule_sync' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_download' ), 30 );

		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'my_orders_actions' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details_box' ), 5 );
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'email_attachments' ), 20, 3 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 50 );
		add_action( 'admin_post_zg_b2b_ef_action', array( __CLASS__, 'admin_action' ) );
		add_action( 'admin_post_zg_b2b_ef_test', array( __CLASS__, 'admin_test' ) );
	}

	private static function quote_method( $suffix ) {
		$prefix = zg_b2b_opt( 'ef_quote_prefix' );
		return ( $prefix ? $prefix : 'SalesQuote' ) . $suffix;
	}

	/* ---------------------------------------------------------------------
	 * Vytvoření proformy
	 * ------------------------------------------------------------------- */

	private static function vat_rate( $net, $tax ) {
		$net = (float) $net;
		return abs( $net ) > 0.0001 ? round( (float) $tax / $net * 100 ) : 0;
	}

	/** Parametry dokladu pro Eurofakturu (lze upravit filtrem zg_b2b_ef_quote_params). */
	public static function build_params( WC_Order $order ) {
		$items    = array();
		$send_sku = 'yes' === zg_b2b_opt( 'ef_send_sku' );
		$currency = $order->get_currency();

		foreach ( $order->get_items() as $item ) {
			$qty = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$row = array(
				'description'   => $item->get_name(),
				'quantity'      => $qty,
				'unit'          => 'ks',
				'netPrice'      => round( (float) $item->get_total() / $qty, 4 ),
				'vatPercentage' => self::vat_rate( $item->get_total(), $item->get_total_tax() ),
				'currency'      => $currency,
			);
			$product = $item->get_product();
			if ( $send_sku && $product && $product->get_sku() ) {
				$row['productCode'] = $product->get_sku();
			}
			$items[] = $row;
		}
		foreach ( $order->get_items( 'shipping' ) as $ship ) {
			if ( (float) $ship->get_total() <= 0 ) {
				continue;
			}
			$items[] = array(
				'description'   => sprintf( __( 'Doprava: %s', 'zg-b2b' ), $ship->get_name() ),
				'quantity'      => 1,
				'unit'          => 'ks',
				'netPrice'      => round( (float) $ship->get_total(), 4 ),
				'vatPercentage' => self::vat_rate( $ship->get_total(), $ship->get_total_tax() ),
				'currency'      => $currency,
			);
		}
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( 0.0 === (float) $fee->get_total() ) {
				continue;
			}
			$items[] = array(
				'description'   => $fee->get_name(),
				'quantity'      => 1,
				'unit'          => 'ks',
				'netPrice'      => round( (float) $fee->get_total(), 4 ),
				'vatPercentage' => self::vat_rate( $fee->get_total(), $fee->get_total_tax() ),
				'currency'      => $currency,
			);
		}

		$company = $order->get_billing_company();
		$name    = $company ? $company : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$street  = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
		$cid     = $order->get_customer_id();
		$dic     = $cid ? get_user_meta( $cid, zg_b2b_opt( 'dic_meta' ), true ) : '';
		$ico     = $cid ? get_user_meta( $cid, zg_b2b_opt( 'ico_meta' ), true ) : '';
		$dic     = $order->get_meta( '_' . zg_b2b_opt( 'dic_meta' ) ) ? $order->get_meta( '_' . zg_b2b_opt( 'dic_meta' ) ) : $dic;
		$ico     = $order->get_meta( '_' . zg_b2b_opt( 'ico_meta' ) ) ? $order->get_meta( '_' . zg_b2b_opt( 'ico_meta' ) ) : $ico;

		$doc = array(
			'status'          => zg_b2b_opt( 'ef_quote_status' ) ? zg_b2b_opt( 'ef_quote_status' ) : 'Issued',
			'date'            => current_time( 'Y-m-d' ),
			'dateDue'         => $order->get_meta( '_zg_ef_due_date' ) ? $order->get_meta( '_zg_ef_due_date' ) : gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ),
			'currency'        => $currency,
			'buyerName'       => $name,
			'buyerStreet'     => $street,
			'buyerPostalCode' => $order->get_billing_postcode(),
			'buyerCity'       => $order->get_billing_city(),
			'buyerCountry'    => $order->get_billing_country(),
			'buyerEMail'      => $order->get_billing_email(),
			'buyerPhone'      => $order->get_billing_phone(),
			'orderReference'  => self::order_reference( $order ),
			'Items'           => $items,
		);
		$dic_field = zg_b2b_opt( 'ef_field_dic' );
		$ico_field = zg_b2b_opt( 'ef_field_ico' );
		if ( $dic && $dic_field ) {
			$doc[ $dic_field ] = $dic;
		}
		if ( $ico && $ico_field ) {
			$doc[ $ico_field ] = $ico;
		}
		if ( $order->has_shipping_address() ) {
			$doc['deliveryAddress'] = trim(
				implode(
					', ',
					array_filter(
						array(
							$order->get_shipping_company(),
							trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
							$order->get_shipping_address_1(),
							trim( $order->get_shipping_postcode() . ' ' . $order->get_shipping_city() ),
							$order->get_shipping_country(),
						)
					)
				)
			);
		}

		$key    = zg_b2b_opt( 'ef_quote_prefix' ) ? zg_b2b_opt( 'ef_quote_prefix' ) : 'SalesQuote';
		$params = array( $key => $doc );
		return apply_filters( 'zg_b2b_ef_quote_params', $params, $order );
	}

	public static function order_reference( $order ) {
		return 'VO-' . $order->get_order_number();
	}

	/**
	 * Vytvoří proformu v Eurofaktuře.
	 *
	 * @param int  $order_id
	 * @param int  $attempt     Pořadí pokusu (při chybě se plánuje další).
	 * @param bool $send_email  Poslat proformu samostatným e-mailem (při pokladně ne – přiloží se k e-mailu objednávky).
	 */
	public static function create_proforma( $order_id, $attempt = 1, $send_email = true ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_zg_ef_proforma_number' ) ) {
			return;
		}
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return;
		}
		// Proforma jen u platby na fakturu (zastaví i dřív naplánované opakované pokusy u jiných plateb).
		if ( ZG_B2B_Gateway_Invoice::ID !== $order->get_payment_method() ) {
			return;
		}

		$result = ZG_B2B_EF_Client::call( self::quote_method( 'Create' ), self::build_params( $order ) );
		$number = is_wp_error( $result ) ? null : ZG_B2B_EF_Client::find( $result, array( 'number', 'documentNumber' ) );

		if ( is_wp_error( $result ) || ! $number ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : 'odpověď neobsahuje číslo dokladu (viz log zg-b2b-eurofaktura)';
			$order->add_order_note( sprintf( 'Eurofaktura: proformu se nepodařilo vytvořit (pokus %d/%d): %s', $attempt, self::MAX_ATTEMPTS, $msg ) );
			$order->update_meta_data( '_zg_ef_proforma_attempts', $attempt );
			$order->save();
			if ( $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 300 * $attempt, self::CREATE_HOOK, array( 'order_id' => $order_id, 'attempt' => $attempt + 1 ), 'zg-b2b' );
			} elseif ( $attempt >= self::MAX_ATTEMPTS ) {
				zg_b2b_send_mail(
					zg_b2b_contact_email(),
					'Proforma se nevytvořila – objednávka #' . $order->get_order_number(),
					'Proforma se nevytvořila',
					'<p>Proformu k objednávce <strong>#' . esc_html( $order->get_order_number() ) . '</strong> se nepodařilo vytvořit ani po ' . self::MAX_ATTEMPTS . ' pokusech.</p><p>Poslední chyba: ' . esc_html( $msg ) . '</p><p><a href="' . esc_url( $order->get_edit_order_url() ) . '">Otevřít objednávku</a> – v panelu „Eurofaktura“ ji můžete vytvořit znovu, nebo zadat číslo ručně.</p>'
				);
			}
			return;
		}

		$date = ZG_B2B_EF_Client::find( $result, array( 'date', 'documentDate', 'dateOfIssue' ) );
		$id   = ZG_B2B_EF_Client::find( $result, array( 'documentID', 'documentId', 'id' ) );
		$order->update_meta_data( '_zg_ef_proforma_number', (string) $number );
		$order->update_meta_data( '_zg_ef_proforma_date', $date ? substr( (string) $date, 0, 10 ) : current_time( 'Y-m-d' ) );
		$order->update_meta_data( '_zg_ef_proforma_id', $id ? (string) $id : '' );
		$order->save();
		$order->add_order_note( 'Eurofaktura: vystavena proforma č. ' . $number . '.' );

		$file = self::ensure_pdf( $order, 'proforma' );
		if ( $send_email ) {
			zg_b2b_in_language(
				(string) $order->get_meta( 'wpml_language' ),
				function () use ( $order, $file ) {
					self::mail_proforma( $order, $file );
				}
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * PDF – úložiště a stažení z Eurofaktury
	 * ------------------------------------------------------------------- */

	private static function storage_dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'zg-b2b-docs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:disable WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
			// phpcs:enable
		}
		return $dir;
	}

	/** Vrátí cestu k PDF (stáhne z Eurofaktury, pokud ještě není uložené). */
	public static function ensure_pdf( WC_Order $order, $type ) {
		$file = $order->get_meta( '_zg_ef_' . $type . '_file' );
		if ( $file && file_exists( $file ) ) {
			return $file;
		}
		$number = $order->get_meta( '_zg_ef_' . $type . '_number' );
		if ( ! $number ) {
			return '';
		}
		$id     = $order->get_meta( '_zg_ef_' . $type . '_id' );
		$date   = $order->get_meta( '_zg_ef_' . $type . '_date' );
		$method = 'proforma' === $type ? self::quote_method( 'GetPDF' ) : 'SalesInvoiceGetPDF';
		$params = $id ? array( 'documentID' => $id ) : array_filter( array( 'number' => $number, 'date' => $date ) );

		$result = ZG_B2B_EF_Client::call( $method, $params, 45 );
		$pdf    = is_wp_error( $result ) ? null : ZG_B2B_EF_Client::find_pdf( $result );
		if ( ! $pdf ) {
			$order->add_order_note( 'Eurofaktura: PDF (' . $type . ' ' . $number . ') se nepodařilo stáhnout' . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '.' ) );
			return '';
		}
		$path = self::storage_dir() . '/' . $order->get_id() . '-' . $type . '-' . wp_generate_password( 24, false ) . '.pdf';
		file_put_contents( $path, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$order->update_meta_data( '_zg_ef_' . $type . '_file', $path );
		$order->save();
		return $path;
	}

	private static function filename( WC_Order $order, $type ) {
		$label = 'invoice' === $type ? __( 'Faktura', 'zg-b2b' ) : __( 'Proforma', 'zg-b2b' );
		return sanitize_file_name( $label . '-' . $order->get_meta( '_zg_ef_' . $type . '_number' ) . '.pdf' );
	}

	/** Který doklad zákazník právě vidí: faktura nahrazuje proformu. */
	public static function visible_type( WC_Order $order ) {
		$ef = ZG_B2B_EF_Connector::customer_docs( $order );
		if ( $ef && ( ZG_B2B_EF_Connector::has_invoice( $ef ) || ! $order->get_meta( '_zg_ef_proforma_number' ) ) ) {
			return 'ef';
		}
		self::import_existing_invoice_meta( $order );
		if ( $order->get_meta( '_zg_ef_invoice_number' ) ) {
			return 'invoice';
		}
		if ( $order->get_meta( '_zg_ef_proforma_number' ) && ! $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return 'proforma';
		}
		return '';
	}

	/** Číslo faktury uložené vaším stávajícím napojením (volitelné meta klíče). */
	private static function import_existing_invoice_meta( WC_Order $order ) {
		$key = zg_b2b_opt( 'ef_invoice_meta' );
		if ( ! $key || $order->get_meta( '_zg_ef_invoice_number' ) ) {
			return;
		}
		$num = $order->get_meta( $key );
		if ( ! $num ) {
			return;
		}
		$dkey = zg_b2b_opt( 'ef_invoice_date_meta' );
		$date = $dkey ? $order->get_meta( $dkey ) : '';
		$order->update_meta_data( '_zg_ef_invoice_number', (string) $num );
		$order->update_meta_data( '_zg_ef_invoice_date', $date ? substr( (string) $date, 0, 10 ) : '' );
		$order->save();
	}

	public static function download_url( WC_Order $order, $type, $doc_id = '' ) {
		return add_query_arg(
			array_filter(
				array(
					'zg_b2b_doc' => $type,
					'order'      => $order->get_id(),
					'key'        => $order->get_order_key(),
					'doc'        => $doc_id,
				)
			),
			home_url( '/' )
		);
	}

	/** Stažení PDF (jen vlastník objednávky nebo správce obchodu). */
	public static function maybe_download() {
		if ( empty( $_GET['zg_b2b_doc'] ) || empty( $_GET['order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$type  = in_array( $_GET['zg_b2b_doc'], array( 'invoice', 'ef' ), true ) ? sanitize_key( $_GET['zg_b2b_doc'] ) : 'proforma'; // phpcs:ignore WordPress.Security.NonceVerification
		$order = wc_get_order( absint( $_GET['order'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$allowed = $order && (
			current_user_can( 'manage_woocommerce' )
			|| ( is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id() && hash_equals( $order->get_order_key(), $key ) )
		);
		if ( ! $allowed ) {
			wp_die( esc_html__( 'Doklad není k dispozici. Přihlaste se prosím ke svému účtu.', 'zg-b2b' ), '', array( 'response' => 403 ) );
		}
		if ( 'ef' === $type ) {
			self::stream_ef_doc( $order, isset( $_GET['doc'] ) ? sanitize_text_field( wp_unslash( $_GET['doc'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && self::visible_type( $order ) !== $type ) {
			wp_die( esc_html__( 'Tento doklad už není k dispozici – stáhněte si prosím fakturu.', 'zg-b2b' ), '', array( 'response' => 404 ) );
		}
		$file = self::ensure_pdf( $order, $type );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_die( esc_html__( 'PDF se teď nepodařilo načíst. Zkuste to prosím za chvíli.', 'zg-b2b' ), '', array( 'response' => 503 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $order, $type ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * PDF dokladu z pluginu Eurofaktura.cz – jen doklad, který k objednávce
	 * opravdu patří (ID ze seznamu u objednávky), streamuje se bez ukládání.
	 */
	private static function stream_ef_doc( WC_Order $order, $doc_id ) {
		$doc = $doc_id ? ZG_B2B_EF_Connector::find_doc( $order, $doc_id ) : null;
		if ( ! $doc && $doc_id ) {
			ZG_B2B_EF_Connector::docs( $order, 1 ); // seznam mohl zastarat – jednou načíst znovu
			$doc = ZG_B2B_EF_Connector::find_doc( $order, $doc_id );
		}
		if ( ! $doc ) {
			wp_die( esc_html__( 'Doklad není k dispozici.', 'zg-b2b' ), '', array( 'response' => 404 ) );
		}
		$pdf = ZG_B2B_EF_Connector::pdf( $doc['id'] );
		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html__( 'PDF se teď nepodařilo načíst. Zkuste to prosím za chvíli.', 'zg-b2b' ), '', array( 'response' => 503 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . ZG_B2B_EF_Connector::filename( $doc ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Můj účet a děkovací stránka
	 * ------------------------------------------------------------------- */

	public static function my_orders_actions( $actions, $order ) {
		$type = self::visible_type( $order );
		if ( 'ef' === $type ) {
			foreach ( ZG_B2B_EF_Connector::customer_docs( $order ) as $d ) {
				if ( $d['cancelled'] ) {
					continue;
				}
				$actions[ 'zg_ef_' . md5( $d['id'] ) ] = array(
					'url'  => self::download_url( $order, 'ef', $d['id'] ),
					'name' => 'credit' === $d['kind'] ? __( 'Opravný doklad (PDF)', 'zg-b2b' ) : ( 'proforma' === $d['kind'] ? __( 'Proforma (PDF)', 'zg-b2b' ) : __( 'Faktura (PDF)', 'zg-b2b' ) ),
				);
			}
		} elseif ( $type ) {
			$actions[ 'zg_' . $type ] = array(
				'url'  => self::download_url( $order, $type ),
				'name' => 'invoice' === $type ? __( 'Faktura (PDF)', 'zg-b2b' ) : __( 'Proforma (PDF)', 'zg-b2b' ),
			);
		}
		return $actions;
	}

	public static function documents_html( WC_Order $order ) {
		$type = self::visible_type( $order );
		if ( ! $type ) {
			if ( ZG_B2B_Gateway_Invoice::ID === $order->get_payment_method() && ! $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
				return '<p class="zg-b2b-doc-pending">' . esc_html__( 'Proformu připravujeme – během několika minut vám ji pošleme e-mailem a objeví se zde.', 'zg-b2b' ) . '</p>';
			}
			return '';
		}
		if ( 'ef' === $type ) {
			$html = '';
			foreach ( ZG_B2B_EF_Connector::customer_docs( $order ) as $d ) {
				$html .= '<div class="zg-b2b-doc' . ( $d['cancelled'] ? ' zg-b2b-doc--cancelled' : '' ) . '">'
					. '<span class="zg-b2b-doc__icon">' . zg_b2b_icon( 'info' ) . '</span>'
					. '<span class="zg-b2b-doc__body"><strong>' . esc_html( ZG_B2B_EF_Connector::label( $d ) ) . '</strong><span>' . esc_html( ZG_B2B_EF_Connector::hint( $d ) ) . '</span></span>'
					. zg_b2b_button( self::download_url( $order, 'ef', $d['id'] ), __( 'Stáhnout PDF', 'zg-b2b' ), 'outline', 'arrow', 'sm' )
					. '</div>';
			}
			return $html;
		}
		$num   = $order->get_meta( '_zg_ef_' . $type . '_number' );
		$label = 'invoice' === $type
			? sprintf( __( 'Faktura č. %s', 'zg-b2b' ), $num )
			: sprintf( __( 'Proforma faktura č. %s', 'zg-b2b' ), $num );
		$hint  = 'invoice' === $type
			? __( 'Daňový doklad k objednávce.', 'zg-b2b' )
			: __( 'Po připsání platby ji nahradí faktura (daňový doklad).', 'zg-b2b' );
		return '<div class="zg-b2b-doc">'
			. '<span class="zg-b2b-doc__icon">' . zg_b2b_icon( 'info' ) . '</span>'
			. '<span class="zg-b2b-doc__body"><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( $hint ) . '</span></span>'
			. zg_b2b_button( self::download_url( $order, $type ), __( 'Stáhnout PDF', 'zg-b2b' ), 'outline', 'arrow', 'sm' )
			. '</div>';
	}

	public static function order_details_box( $order ) {
		if ( ! $order instanceof WC_Order || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		ZG_B2B_EF_Connector::docs( $order, 10 * MINUTE_IN_SECONDS ); // čerstvé doklady při zobrazení detailu
		$docs = self::documents_html( $order );
		$pay  = '';
		if ( ZG_B2B_Gateway_Invoice::ID === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
			$pay = '<h3 class="zg-b2b-paybox__title">' . esc_html__( 'Platební údaje', 'zg-b2b' ) . '</h3>' . ZG_B2B_Gateway_Invoice::instance()->details_html( $order );
		}
		if ( $docs || $pay ) {
			echo '<section class="zg-b2b-paybox"><h2 class="zg-b2b-paybox__title">' . esc_html__( 'Doklady', 'zg-b2b' ) . '</h2>' . $docs . $pay . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ---------------------------------------------------------------------
	 * E-maily
	 * ------------------------------------------------------------------- */

	public static function email_attachments( $attachments, $email_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $attachments;
		}
		if ( 'customer_on_hold_order' === $email_id && $order->get_meta( '_zg_ef_proforma_number' ) ) {
			$file = self::ensure_pdf( $order, 'proforma' );
			if ( $file ) {
				$attachments[] = $file;
			}
		}
		if ( in_array( $email_id, array( 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ), true ) && $order->get_meta( '_zg_ef_invoice_number' ) ) {
			$file = self::ensure_pdf( $order, 'invoice' );
			if ( $file ) {
				$attachments[] = $file;
			}
		}
		return $attachments;
	}

	private static function mail_proforma( WC_Order $order, $file ) {
		$gw   = ZG_B2B_Gateway_Invoice::instance();
		$html = '<p>' . sprintf( esc_html__( 'Dobrý den, v příloze posíláme proforma fakturu č. %1$s k objednávce č. %2$s.', 'zg-b2b' ), '<strong>' . esc_html( $order->get_meta( '_zg_ef_proforma_number' ) ) . '</strong>', esc_html( $order->get_order_number() ) ) . '</p>'
			. $gw->details_html( $order )
			. '<p>' . esc_html__( 'Objednávku expedujeme po připsání platby. Proformu najdete také v sekci Můj účet → Objednávky.', 'zg-b2b' ) . '</p>';
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . zg_b2b_contact_email() );
		$subject = sprintf( __( 'Proforma faktura k objednávce č. %s', 'zg-b2b' ), $order->get_order_number() );
		$mailer  = WC()->mailer();
		$mailer->send( $order->get_billing_email(), $subject, $mailer->wrap_message( __( 'Proforma faktura', 'zg-b2b' ), $html ), $headers, $file ? array( $file ) : array() );
		$order->add_order_note( 'Proforma odeslána zákazníkovi e-mailem.' );
	}

	/* ---------------------------------------------------------------------
	 * Dohledání faktury vystavené v Eurofaktuře
	 * ------------------------------------------------------------------- */

	public static function schedule_sync() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! ZG_B2B_EF_Client::configured() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::SYNC_HOOK, array(), 'zg-b2b' ) ) {
			as_schedule_recurring_action( time() + 600, HOUR_IN_SECONDS, self::SYNC_HOOK, array(), 'zg-b2b' );
		}
	}

	public static function sync_invoices() {
		$orders = wc_get_orders(
			array(
				'payment_method' => ZG_B2B_Gateway_Invoice::ID,
				'status'         => array( 'wc-on-hold', 'wc-processing', 'wc-completed' ),
				'date_created'   => '>' . ( time() - 60 * DAY_IN_SECONDS ),
				'limit'          => 50,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		foreach ( $orders as $order ) {
			if ( $order->get_meta( '_zg_ef_proforma_number' ) && ! $order->get_meta( '_zg_ef_invoice_number' ) ) {
				if ( ! self::invoice_from_connector( $order ) ) {
					self::find_invoice( $order );
				}
			}
		}
	}

	/**
	 * Hledá fakturu vystavenou z proformy: výpis faktur odběratele od data proformy,
	 * shoda podle čísla proformy nebo reference „VO-číslo objednávky“; jako záloha
	 * jediná faktura odběratele se stejnou částkou a měnou.
	 */
	public static function find_invoice( WC_Order $order ) {
		$pnum  = $order->get_meta( '_zg_ef_proforma_number' );
		$pdate = $order->get_meta( '_zg_ef_proforma_date' );
		$buyer = $order->get_billing_company() ? $order->get_billing_company() : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$params = apply_filters(
			'zg_b2b_ef_invoice_list_params',
			array_filter(
				array(
					'buyer'            => $buyer,
					'dateOfSupplyFrom' => $pdate,
				)
			),
			$order
		);
		$result = ZG_B2B_EF_Client::call( 'SalesInvoiceList', $params, 45 );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		$list    = ZG_B2B_EF_Client::find_list( $result );
		$needles = array( $pnum, self::order_reference( $order ) );
		$match   = null;
		$how     = '';

		foreach ( $list as $row ) {
			if ( ZG_B2B_EF_Client::contains_value( $row, $needles ) ) {
				$match = $row;
				$how   = 'reference';
				break;
			}
		}
		// Souhrn ve výpisu nemusí obsahovat referenci → detail max. 5 kandidátů.
		if ( ! $match ) {
			foreach ( array_slice( $list, 0, 5 ) as $row ) {
				$n = ZG_B2B_EF_Client::find( $row, array( 'number', 'documentNumber' ) );
				$d = ZG_B2B_EF_Client::find( $row, array( 'date', 'documentDate' ) );
				if ( ! $n ) {
					continue;
				}
				$detail = ZG_B2B_EF_Client::call( 'SalesInvoiceGet', array_filter( array( 'number' => $n, 'date' => $d ) ) );
				if ( ! is_wp_error( $detail ) && ZG_B2B_EF_Client::contains_value( $detail, $needles ) ) {
					$match = $row;
					$how   = 'reference';
					break;
				}
			}
		}
		if ( ! $match ) {
			$same = array();
			foreach ( $list as $row ) {
				$total = ZG_B2B_EF_Client::find( $row, array( 'totalAmount', 'total', 'amount', 'totalWithVat' ) );
				$cur   = ZG_B2B_EF_Client::find( $row, array( 'totalCurrency', 'currency' ) );
				if ( null !== $total && abs( (float) $total - (float) $order->get_total() ) < 0.01 && ( ! $cur || strtoupper( $cur ) === $order->get_currency() ) ) {
					$same[] = $row;
				}
			}
			if ( 1 === count( $same ) ) {
				$match = $same[0];
				$how   = 'amount';
			}
		}
		if ( ! $match ) {
			return false;
		}

		$number = ZG_B2B_EF_Client::find( $match, array( 'number', 'documentNumber' ) );
		if ( ! $number ) {
			return false;
		}
		self::set_invoice(
			$order,
			(string) $number,
			(string) ZG_B2B_EF_Client::find( $match, array( 'date', 'documentDate' ) ),
			(string) ZG_B2B_EF_Client::find( $match, array( 'documentID', 'documentId' ) ),
			'amount' === $how ? 'spárováno podle odběratele a částky' : 'spárováno podle čísla proformy / objednávky'
		);
		return true;
	}

	/** Faktura k proformě z pluginu Eurofaktura.cz (spolehlivější než hledání přes API). */
	public static function invoice_from_connector( WC_Order $order ) {
		if ( ! ZG_B2B_EF_Connector::available() ) {
			return false;
		}
		foreach ( ZG_B2B_EF_Connector::docs( $order, 25 * MINUTE_IN_SECONDS ) as $d ) {
			if ( 'invoice' === $d['kind'] && ! $d['cancelled'] && $d['number'] ) {
				self::set_invoice( $order, $d['number'], '', '', 'z pluginu Eurofaktura.cz', false );
				return true;
			}
		}
		return false;
	}

	/** Uloží fakturu k objednávce, stáhne PDF a volitelně označí objednávku jako zaplacenou. */
	public static function set_invoice( WC_Order $order, $number, $date = '', $id = '', $how = 'zadáno ručně', $fetch_pdf = true ) {
		$order->update_meta_data( '_zg_ef_invoice_number', $number );
		$order->update_meta_data( '_zg_ef_invoice_date', $date ? substr( $date, 0, 10 ) : '' );
		$order->update_meta_data( '_zg_ef_invoice_id', $id );
		$order->delete_meta_data( '_zg_ef_invoice_file' );
		$order->save();
		$order->add_order_note( 'Eurofaktura: faktura č. ' . $number . ' (' . $how . '). V Můj účet nahradila proformu.' );
		if ( $fetch_pdf ) {
			self::ensure_pdf( $order, 'invoice' );
		}

		if ( 'yes' === zg_b2b_opt( 'ef_auto_paid' ) && $order->has_status( 'on-hold' ) ) {
			// Faktura po zaplacení = platba přijata → „Zpracovává se“ (e-mail s fakturou v příloze).
			$order->payment_complete();
		}
	}

	/* ---------------------------------------------------------------------
	 * Administrace objednávky
	 * ------------------------------------------------------------------- */

	public static function add_meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'zg-b2b-ef', 'B2B doklady (Můj účet)',array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
		}
	}

	private static function action_link( WC_Order $order, $do, $label ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=zg_b2b_ef_action&do=' . $do . '&order=' . $order->get_id() ), 'zg_b2b_ef_' . $order->get_id() );
		return '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$p      = $order->get_meta( '_zg_ef_proforma_number' );
		$i      = $order->get_meta( '_zg_ef_invoice_number' );
		$is_inv = ZG_B2B_Gateway_Invoice::ID === $order->get_payment_method();

		if ( ZG_B2B_EF_Connector::available() ) {
			$ef      = ZG_B2B_EF_Connector::customer_docs( $order );
			$checked = (int) $order->get_meta( ZG_B2B_EF_Connector::META_CHECKED );
			echo '<p><strong>Doklady z Eurofaktura.cz</strong> <span class="description">(vidí zákazník)</span></p>';
			if ( $ef ) {
				echo '<ul style="margin:0 0 8px">';
				foreach ( $ef as $d ) {
					echo '<li>' . esc_html( ZG_B2B_EF_Connector::label( $d ) ) . ' – <a href="' . esc_url( self::download_url( $order, 'ef', $d['id'] ) ) . '">PDF</a></li>';
				}
				echo '</ul>';
			} else {
				echo '<p>—</p>';
			}
			echo '<p class="description">' . ( $checked ? 'Načteno ' . esc_html( wp_date( 'j. n. Y H:i', $checked ) ) : 'Zatím nenačteno' ) . '</p>';
			echo '<p>' . self::action_link( $order, 'efsync', 'Načíst doklady' ) . '</p><hr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( ! $is_inv && ! $p && ! $i ) {
			echo '<p class="description">Proforma se vytváří jen u platby na fakturu.</p>';
			return;
		}
		echo '<p><strong>Proforma:</strong> ' . ( $p ? esc_html( $p ) . ' <a href="' . esc_url( self::download_url( $order, 'proforma' ) ) . '">PDF</a>' : '—' ) . '</p>';
		echo '<p><strong>Faktura:</strong> ' . ( $i ? esc_html( $i ) . ' <a href="' . esc_url( self::download_url( $order, 'invoice' ) ) . '">PDF</a>' : '—' ) . '</p>';
		echo '<p style="display:flex;gap:6px;flex-wrap:wrap">';
		if ( ! $p ) {
			echo self::action_link( $order, 'create', 'Vytvořit proformu' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $p && ! $i ) {
			echo self::action_link( $order, 'find', 'Hledat fakturu' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $p || $i ) {
			echo self::action_link( $order, 'refresh', 'Obnovit PDF' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</p>';
		wp_nonce_field( 'zg_b2b_ef_meta', 'zg_b2b_ef_meta_nonce' );
		echo '<details' . ( $p ? '' : ' open' ) . '><summary>Zadat čísla ručně</summary>';
		echo '<p><label>Proforma č.<br><input type="text" name="zg_ef_proforma_number" value="' . esc_attr( $p ) . '" style="width:100%"></label></p>';
		echo '<p><label>Datum proformy<br><input type="date" name="zg_ef_proforma_date" value="' . esc_attr( $order->get_meta( '_zg_ef_proforma_date' ) ) . '" style="width:100%"></label></p>';
		echo '<p><label>Faktura č.<br><input type="text" name="zg_ef_invoice_number" value="' . esc_attr( $i ) . '" style="width:100%"></label></p>';
		echo '<p><label>Datum faktury<br><input type="date" name="zg_ef_invoice_date" value="' . esc_attr( $order->get_meta( '_zg_ef_invoice_date' ) ) . '" style="width:100%"></label></p>';
		echo '<p class="description">Uloží se s objednávkou. PDF se stáhne z Eurofaktury.</p></details>';
	}

	public static function save_meta_box( $order_id ) {
		if ( ! isset( $_POST['zg_b2b_ef_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zg_b2b_ef_meta_nonce'] ) ), 'zg_b2b_ef_meta' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$pn = isset( $_POST['zg_ef_proforma_number'] ) ? sanitize_text_field( wp_unslash( $_POST['zg_ef_proforma_number'] ) ) : '';
		$pd = isset( $_POST['zg_ef_proforma_date'] ) ? sanitize_text_field( wp_unslash( $_POST['zg_ef_proforma_date'] ) ) : '';
		$in = isset( $_POST['zg_ef_invoice_number'] ) ? sanitize_text_field( wp_unslash( $_POST['zg_ef_invoice_number'] ) ) : '';
		$id = isset( $_POST['zg_ef_invoice_date'] ) ? sanitize_text_field( wp_unslash( $_POST['zg_ef_invoice_date'] ) ) : '';

		if ( $pn !== (string) $order->get_meta( '_zg_ef_proforma_number' ) || $pd !== (string) $order->get_meta( '_zg_ef_proforma_date' ) ) {
			$order->update_meta_data( '_zg_ef_proforma_number', $pn );
			$order->update_meta_data( '_zg_ef_proforma_date', $pd );
			$order->update_meta_data( '_zg_ef_proforma_id', '' );
			$order->delete_meta_data( '_zg_ef_proforma_file' );
			$order->save();
		}
		if ( $in && ( $in !== (string) $order->get_meta( '_zg_ef_invoice_number' ) || $id !== (string) $order->get_meta( '_zg_ef_invoice_date' ) ) ) {
			self::set_invoice( $order, $in, $id, '', 'zadáno ručně' );
		} elseif ( ! $in && $order->get_meta( '_zg_ef_invoice_number' ) ) {
			$order->delete_meta_data( '_zg_ef_invoice_number' );
			$order->delete_meta_data( '_zg_ef_invoice_date' );
			$order->delete_meta_data( '_zg_ef_invoice_id' );
			$order->delete_meta_data( '_zg_ef_invoice_file' );
			$order->save();
		}
	}

	public static function admin_action() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'zg_b2b_ef_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		$order = wc_get_order( $order_id );
		$do    = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		if ( $order ) {
			if ( 'create' === $do ) {
				self::create_proforma( $order_id, 1, true );
			} elseif ( 'find' === $do ) {
				if ( ! self::find_invoice( $order ) ) {
					$order->add_order_note( 'Eurofaktura: faktura k proformě zatím nenalezena.' );
				}
			} elseif ( 'efsync' === $do ) {
				$r = ZG_B2B_EF_Connector::refresh( $order );
				if ( is_wp_error( $r ) ) {
					$order->add_order_note( 'Eurofaktura.cz: doklady se nepodařilo načíst – ' . $r->get_error_message() );
				}
			} elseif ( 'refresh' === $do ) {
				foreach ( array( 'proforma', 'invoice' ) as $t ) {
					$f = $order->get_meta( '_zg_ef_' . $t . '_file' );
					if ( $f && file_exists( $f ) ) {
						wp_delete_file( $f );
					}
					$order->delete_meta_data( '_zg_ef_' . $t . '_file' );
				}
				$order->save();
				self::ensure_pdf( $order, 'proforma' );
				self::ensure_pdf( $order, 'invoice' );
			}
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/** Test připojení z nastavení: zavolá metodu a zobrazí výsledek. */
	public static function admin_test() {
		check_admin_referer( 'zg_b2b_ef_test' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		$method = isset( $_POST['zg_ef_test_method'] ) ? preg_replace( '/[^A-Za-z]/', '', wp_unslash( $_POST['zg_ef_test_method'] ) ) : 'SalesInvoiceList';
		$params = isset( $_POST['zg_ef_test_params'] ) ? json_decode( wp_unslash( $_POST['zg_ef_test_params'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$result = ZG_B2B_EF_Client::call( $method ? $method : 'SalesInvoiceList', is_array( $params ) ? $params : array() );
		$out    = is_wp_error( $result ) ? 'CHYBA: ' . $result->get_error_message() : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$out    = preg_replace( '/"([A-Za-z0-9+\/=]{400,})"/', '"[base64 PDF…]"', (string) $out );
		set_transient( 'zg_b2b_ef_test_result', substr( $out, 0, 20000 ), 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=zg-b2b#zg-ef' ) );
		exit;
	}
}
