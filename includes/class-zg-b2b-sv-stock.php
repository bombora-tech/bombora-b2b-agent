<?php
/**
 * Modul: Lihoviny přes S&V STOCK (daňový sklad).
 *
 * Lihoviny pro další prodej skladuje a fakturuje zákazníkovi S&V STOCK s.r.o.
 * Bombora je prodává S&V za stejnou cenu jako v e-shopu (1:1).
 *
 *  1. Rozdělení objednávky (tlačítko v adminu): lihoviny z kategorií S&V se
 *     přesunou do propojené podobjednávky „Lihoviny – S&V STOCK“.
 *  2. Podobjednávka se NEPOSÍLÁ pluginem Eurofaktura.cz (webhook blokován) –
 *     fakturu Bombora → S&V vytvoří tlačítko přes Eurofaktura API (odběratel
 *     S&V, sklad S&V) a PDF přijde e-mailem ke kontrole.
 *  3. Zákazník u podobjednávky vidí jen faktury S&V nahrané adminem (PDF),
 *     nikdy doklady z Eurofaktury (to je faktura Bombora → S&V).
 *  4. Messenger: tlačítko „Objednat Messenger“ (importParcel), štítky
 *     (printLabels) a e-mail pro S&V se štítky v příloze.
 *
 * Meta objednávky:
 *   _zg_sv_order        yes = objednávka lihovin pro S&V
 *   _zg_sv_parent       ID původní objednávky / _zg_sv_child ID podobjednávky
 *   _zg_sv_invoices     faktury S&V pro zákazníka [ file, number, date ]
 *   _zg_sv_ef_invoice   faktura Bombora → S&V [ number, date, id ]
 *   _zg_sv_msg          Messenger [ id, tracking, url, date ]
 *
 * Vypnutí: nastavení B2B Agenta, nebo define( 'ZG_B2B_SV_DISABLE', true ).
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_SV_Stock {

	const MSG_PROD = 'https://api.messenger.cz/MessengerWeb/PublicREST/';
	const MSG_TEST = 'https://api-test.messenger.cz/MessengerWeb/PublicREST/';

	public static function enabled() {
		if ( defined( 'ZG_B2B_SV_DISABLE' ) && ZG_B2B_SV_DISABLE ) {
			return false;
		}
		return 'yes' === zg_b2b_opt( 'sv_module' );
	}

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_zg_b2b_sv', array( __CLASS__, 'admin_action' ) );
		add_action( 'admin_post_zg_b2b_sv_upload', array( __CLASS__, 'admin_upload' ) );
		add_filter( 'woocommerce_webhook_should_deliver', array( __CLASS__, 'block_ef_webhook' ), 20, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Pomocné
	 * ------------------------------------------------------------------- */

	public static function is_sv_order( $order ) {
		return self::enabled() && $order instanceof WC_Order && 'yes' === $order->get_meta( '_zg_sv_order' );
	}

	/** Patří položka objednávky S&V (kategorie lihovin v nastavení)? */
	public static function is_sv_item( WC_Order_Item_Product $item ) {
		$cats = array_map( 'intval', (array) zg_b2b_opt( 'sv_categories' ) );
		if ( ! $cats ) {
			return false;
		}
		$pid = $item->get_product_id(); // u variant rodičovský produkt
		if ( ! $pid ) {
			return false;
		}
		$pid   = zg_b2b_original_id( $pid, 'product' );
		$terms = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $t ) {
			$t = zg_b2b_original_id( $t, 'product_cat' );
			if ( in_array( (int) $t, $cats, true ) ) {
				return true;
			}
			foreach ( get_ancestors( $t, 'product_cat' ) as $a ) {
				if ( in_array( (int) $a, $cats, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function sv_items( WC_Order $order ) {
		$out = array();
		foreach ( $order->get_items() as $id => $item ) {
			if ( $item instanceof WC_Order_Item_Product && self::is_sv_item( $item ) ) {
				$out[ $id ] = $item;
			}
		}
		return $out;
	}

	private static function no_emails( $on ) {
		$ids = array( 'new_order', 'customer_processing_order', 'customer_on_hold_order', 'customer_completed_order', 'cancelled_order', 'customer_cancelled_order' );
		foreach ( $ids as $id ) {
			if ( $on ) {
				add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 999 );
			} else {
				remove_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 999 );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * 1. Rozdělení objednávky
	 * ------------------------------------------------------------------- */

	/**
	 * @return WC_Order|WP_Error  Objednávka lihovin (podobjednávka, nebo celá objednávka).
	 */
	public static function split( WC_Order $order ) {
		if ( self::is_sv_order( $order ) || $order->get_meta( '_zg_sv_child' ) ) {
			return new WP_Error( 'zg_sv', 'Objednávka už je rozdělená.' );
		}
		$sv = self::sv_items( $order );
		if ( ! $sv ) {
			return new WP_Error( 'zg_sv', 'Objednávka neobsahuje lihoviny z kategorií S&V STOCK (nastavení modulu).' );
		}

		// Celá objednávka jsou lihoviny → nerozdělovat, jen označit.
		if ( count( $sv ) === count( $order->get_items() ) ) {
			$order->update_meta_data( '_zg_sv_order', 'yes' );
			$order->save();
			$order->add_order_note( 'S&V STOCK: celá objednávka jsou lihoviny – vyřizuje S&V STOCK. Doklady z Eurofaktury se zákazníkovi nezobrazují; zkontrolujte, zda v Eurofaktuře nevznikl doklad na zákazníka.' );
			return $order;
		}

		self::no_emails( true );
		$child = new WC_Order();
		$child->set_parent_id( $order->get_id() );
		$child->set_customer_id( $order->get_customer_id() );
		$child->set_currency( $order->get_currency() );
		$child->set_prices_include_tax( $order->get_prices_include_tax() );
		$child->set_address( $order->get_address( 'billing' ), 'billing' );
		$child->set_address( $order->get_address( 'shipping' ), 'shipping' );
		$child->set_payment_method( $order->get_payment_method() );
		$child->set_payment_method_title( $order->get_payment_method_title() );
		$child->set_customer_note( $order->get_customer_note() );
		$child->set_created_via( 'zg-b2b-sv' );
		foreach ( $order->get_meta_data() as $m ) {
			$k = $m->key;
			if ( 0 === strpos( $k, '_zg_ef' ) || 0 === strpos( $k, '_zg_sv' ) || 0 === strpos( $k, '_eurofaktura' ) || '_order_number' === $k ) {
				continue;
			}
			$child->update_meta_data( $k, $m->value );
		}
		$child->update_meta_data( '_zg_sv_order', 'yes' ); // před prvním uložením → webhook Eurofaktury se nepošle
		$child->update_meta_data( '_zg_sv_parent', $order->get_id() );

		$names = array();
		foreach ( $sv as $id => $item ) {
			$new = new WC_Order_Item_Product();
			$new->set_props(
				array(
					'name'         => $item->get_name(),
					'product_id'   => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'quantity'     => $item->get_quantity(),
					'tax_class'    => $item->get_tax_class(),
					'subtotal'     => $item->get_subtotal(),
					'subtotal_tax' => $item->get_subtotal_tax(),
					'total'        => $item->get_total(),
					'total_tax'    => $item->get_total_tax(),
					'taxes'        => $item->get_taxes(),
				)
			);
			foreach ( $item->get_meta_data() as $m ) {
				$new->add_meta_data( $m->key, $m->value );
			}
			$child->add_item( $new );
			$names[] = $item->get_quantity() . '× ' . $item->get_name();
			$order->remove_item( $id );
		}

		// Doprava lihovin = Messenger (vlastní řádek, cena dle nastavení).
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( 'Messenger – S&V STOCK' );
		$ship->set_method_id( 'zg_sv_messenger' );
		$ship->set_total( 0 );
		if ( 'move' === zg_b2b_opt( 'sv_shipping' ) ) {
			foreach ( $order->get_items( 'shipping' ) as $sid => $s ) {
				$ship->set_total( $s->get_total() );
				$ship->set_taxes( $s->get_taxes() );
				$order->remove_item( $sid );
				break;
			}
		}
		$child->add_item( $ship );

		$child->calculate_totals( false );
		$child->save(); // nejdřív jako „pending“
		// Sklad už odečetla původní objednávka – podobjednávka ho nesmí odečíst znovu.
		$child->get_data_store()->set_stock_reduced( $child->get_id(), true );
		$child = wc_get_order( $child->get_id() );
		$child->set_status( $order->get_status() );
		if ( $order->get_date_paid() ) {
			$child->set_date_paid( $order->get_date_paid() );
		}
		$child->save();
		$child->add_order_note( 'S&V STOCK: lihoviny oddělené z objednávky #' . $order->get_order_number() . ' (' . implode( ', ', $names ) . ').' . ( $order->get_date_paid() ? ' Uhrazeno v rámci původní objednávky.' : '' ) );

		$order->calculate_totals( false );
		$order->update_meta_data( '_zg_sv_child', $child->get_id() );
		$order->save();
		$order->add_order_note( 'S&V STOCK: lihoviny přesunuty do objednávky #' . $child->get_order_number() . ' (' . implode( ', ', $names ) . ').' );
		self::no_emails( false );

		self::resync_parent( $order );
		return $child;
	}

	/** Po rozdělení aktualizovat původní objednávku v Eurofaktuře (stejné jako tlačítko „Synchronizovat“). */
	private static function resync_parent( WC_Order $order ) {
		if ( ! class_exists( 'Eurofaktura_http_client' ) || ! function_exists( 'eurofaktura_map_order_for_request' ) || ! get_option( 'eurofaktura_connected' ) ) {
			return;
		}
		try {
			$r   = Eurofaktura_http_client::instance()->post_request( 'order.synchronize', eurofaktura_map_order_for_request( $order ), 10 );
			$msg = $r ? json_decode( (string) $r->getBody(), true ) : null;
			$txt = is_array( $msg ) && ! empty( $msg['message'] ) ? $msg['message'] : ( $r ? 'HTTP ' . $r->getStatusCode() : 'bez odpovědi' );
		} catch ( \Throwable $e ) {
			$txt = $e->getMessage();
		}
		$order->add_order_note( 'Eurofaktura.cz – synchronizace po rozdělení: ' . $txt . ' Zkontrolujte, že doklad na zákazníka už lihoviny neobsahuje.' );
	}

	/** Plugin Eurofaktura.cz nesmí dostat objednávku lihovin (fakturovalo by se zákazníkovi). */
	public static function block_ef_webhook( $should, $webhook, $arg ) {
		if ( ! $should || ! $webhook || (int) get_option( 'eurofaktura_webhook_id' ) !== (int) $webhook->get_id() ) {
			return $should;
		}
		$order = is_numeric( $arg ) ? wc_get_order( $arg ) : null;
		return $order && self::is_sv_order( $order ) ? false : $should;
	}

	/* ---------------------------------------------------------------------
	 * 2. Faktura Bombora → S&V (Eurofaktura API)
	 * ------------------------------------------------------------------- */

	public static function create_ef_invoice( WC_Order $order ) {
		if ( $order->get_meta( '_zg_sv_ef_invoice' ) ) {
			return new WP_Error( 'zg_sv', 'Faktura pro S&V už existuje.' );
		}
		// Položky 1:1 z objednávky (cena bez DPH po slevách), bez dopravy.
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$qty = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$net = (float) $item->get_total();
			$row = array(
				'description'   => $item->get_name(),
				'quantity'      => $qty,
				'unit'          => 'ks',
				'netPrice'      => round( $net / $qty, 4 ),
				'vatPercentage' => abs( $net ) > 0.0001 ? round( (float) $item->get_total_tax() / $net * 100 ) : 0,
				'currency'      => $order->get_currency(),
			);
			$p = $item->get_product();
			if ( 'yes' === zg_b2b_opt( 'ef_send_sku' ) && $p && $p->get_sku() ) {
				$row['productCode'] = $p->get_sku();
			}
			$items[] = $row;
		}
		$doc = array(
			'date'     => current_time( 'Y-m-d' ),
			'currency' => $order->get_currency(),
			'Items'    => $items,
		);
		$doc['buyerName']       = (string) zg_b2b_opt( 'sv_name' );
		$doc['buyerStreet']     = trim( zg_b2b_opt( 'sv_street' ) . ' ' . zg_b2b_opt( 'sv_house' ) );
		$doc['buyerPostalCode'] = (string) zg_b2b_opt( 'sv_zip' );
		$doc['buyerCity']       = (string) zg_b2b_opt( 'sv_city' );
		$doc['buyerCountry']    = 'CZ';
		$doc['dateDue']         = gmdate( 'Y-m-d', time() + 14 * DAY_IN_SECONDS );
		$doc['status']          = 'Issued';
		$doc['orderReference']  = 'VO-' . $order->get_order_number() . '-SV';
		if ( zg_b2b_opt( 'ef_field_dic' ) && zg_b2b_opt( 'sv_dic' ) ) {
			$doc[ zg_b2b_opt( 'ef_field_dic' ) ] = zg_b2b_opt( 'sv_dic' );
		}
		if ( zg_b2b_opt( 'ef_field_ico' ) && zg_b2b_opt( 'sv_ico' ) ) {
			$doc[ zg_b2b_opt( 'ef_field_ico' ) ] = zg_b2b_opt( 'sv_ico' );
		}
		$wf = zg_b2b_opt( 'sv_ef_wh_field' );
		$wv = zg_b2b_opt( 'sv_ef_wh_value' );
		if ( $wf && $wv ) {
			if ( 'item:' === substr( $wf, 0, 5 ) ) { // sklad na položce
				foreach ( $doc['Items'] as &$it ) {
					$it[ substr( $wf, 5 ) ] = $wv;
				}
				unset( $it );
			} else {
				$doc[ $wf ] = $wv;
			}
		}
		$doc    = apply_filters( 'zg_b2b_sv_ef_invoice', $doc, $order );
		$result = ZG_B2B_EF_Client::call( 'SalesInvoiceCreate', array( 'SalesInvoice' => $doc ) );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'S&V STOCK: fakturu pro S&V se nepodařilo vytvořit – ' . $result->get_error_message() );
			return $result;
		}
		$number = (string) ZG_B2B_EF_Client::find( $result, array( 'number', 'documentNumber' ) );
		$date   = (string) ZG_B2B_EF_Client::find( $result, array( 'date', 'documentDate' ) );
		$id     = (string) ZG_B2B_EF_Client::find( $result, array( 'documentID', 'documentId' ) );
		$order->update_meta_data( '_zg_sv_ef_invoice', array( 'number' => $number, 'date' => substr( $date, 0, 10 ), 'id' => $id ) );
		$order->save();
		$order->add_order_note( 'S&V STOCK: v Eurofaktuře vystavena faktura pro S&V č. ' . $number . '.' );

		// PDF ke kontrole e-mailem.
		$pdf_res = ZG_B2B_EF_Client::call( 'SalesInvoiceGetPDF', $id ? array( 'documentID' => $id ) : array_filter( array( 'number' => $number, 'date' => substr( $date, 0, 10 ) ) ), 45 );
		$pdf     = is_wp_error( $pdf_res ) ? null : ZG_B2B_EF_Client::find_pdf( $pdf_res );
		$files   = array();
		if ( $pdf ) {
			$tmp = trailingslashit( get_temp_dir() ) . sanitize_file_name( 'Faktura-SV-' . $number . '.pdf' );
			file_put_contents( $tmp, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$files[] = $tmp;
		}
		$to   = zg_b2b_opt( 'sv_invoice_to' ) ? zg_b2b_opt( 'sv_invoice_to' ) : zg_b2b_contact_email();
		$html = '<p>Faktura č. <strong>' . esc_html( $number ) . '</strong> pro S&amp;V STOCK k objednávce lihovin <strong>#' . esc_html( $order->get_order_number() ) . '</strong> (zákazník: ' . esc_html( $order->get_billing_company() ) . ').</p>'
			. '<p>Po kontrole ji přepošlete S&amp;V STOCK. <a href="' . esc_url( $order->get_edit_order_url() ) . '">Otevřít objednávku</a></p>';
		WC()->mailer()->send( $to, 'Faktura pro S&V STOCK č. ' . $number . ' – objednávka #' . $order->get_order_number(), WC()->mailer()->wrap_message( 'Faktura pro S&V STOCK', $html ), array( 'Content-Type: text/html; charset=UTF-8' ), $files );
		foreach ( $files as $f ) {
			wp_delete_file( $f );
		}
		return $number;
	}

	/* ---------------------------------------------------------------------
	 * 3. Faktury S&V pro zákazníka (nahrané PDF)
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

	public static function invoices( WC_Order $order ) {
		$list = $order->get_meta( '_zg_sv_invoices' );
		return is_array( $list ) ? $list : array();
	}

	/** Odkazy pro zákazníka (sloupec Doklady / detail objednávky). */
	public static function doc_links( WC_Order $order ) {
		$links = array();
		foreach ( self::invoices( $order ) as $i => $inv ) {
			$links[] = array(
				'url'       => ZG_B2B_EF_Documents::download_url( $order, 'sv', (string) $i ),
				'label'     => trim( __( 'Faktura', 'zg-b2b' ) . ' ' . $inv['number'] ),
				'cancelled' => false,
				'hint'      => __( 'Faktura od S&V STOCK s.r.o. (lihoviny).', 'zg-b2b' ),
			);
		}
		return $links;
	}

	public static function stream_invoice( WC_Order $order, $index ) {
		$list = self::invoices( $order );
		$inv  = isset( $list[ (int) $index ] ) ? $list[ (int) $index ] : null;
		if ( ! $inv || empty( $inv['file'] ) || ! file_exists( $inv['file'] ) ) {
			wp_die( esc_html__( 'Doklad není k dispozici.', 'zg-b2b' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'Faktura-' . ( $inv['number'] ? $inv['number'] : $order->get_order_number() ) . '.pdf' ) . '"' );
		header( 'Content-Length: ' . filesize( $inv['file'] ) );
		readfile( $inv['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function admin_upload() {
		$order_id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		check_admin_referer( 'zg_b2b_sv_upload_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		$order = wc_get_order( $order_id );
		$back  = wp_get_referer() ? wp_get_referer() : admin_url();
		if ( ! $order || empty( $_FILES['zg_sv_pdf']['tmp_name'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		$tmp  = $_FILES['zg_sv_pdf']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$head = file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '%PDF' !== $head ) {
			$order->add_order_note( 'S&V STOCK: nahraný soubor není PDF – neuloženo.' );
			wp_safe_redirect( $back );
			exit;
		}
		$number = isset( $_POST['zg_sv_number'] ) ? sanitize_text_field( wp_unslash( $_POST['zg_sv_number'] ) ) : '';
		$path   = self::storage_dir() . '/' . $order->get_id() . '-sv-' . wp_generate_password( 24, false ) . '.pdf';
		move_uploaded_file( $tmp, $path );
		$list   = self::invoices( $order );
		$list[] = array( 'file' => $path, 'number' => $number, 'date' => current_time( 'Y-m-d' ) );
		$order->update_meta_data( '_zg_sv_invoices', $list );
		$order->save();
		$order->add_order_note( 'S&V STOCK: nahrána faktura S&V' . ( $number ? ' č. ' . $number : '' ) . ' – zákazník ji vidí v Můj účet.' );

		if ( ! empty( $_POST['zg_sv_mail'] ) ) {
			zg_b2b_in_language(
				(string) $order->get_meta( 'wpml_language' ),
				function () use ( $order, $number, $path ) {
					$html = '<p>' . sprintf( esc_html__( 'Dobrý den, v příloze posíláme fakturu %1$s k objednávce č. %2$s (lihoviny, fakturuje S&V STOCK s.r.o.).', 'zg-b2b' ), '<strong>' . esc_html( $number ) . '</strong>', esc_html( $order->get_order_number() ) ) . '</p>'
						. '<p>' . esc_html__( 'Fakturu najdete také v sekci Můj účet → Objednávky.', 'zg-b2b' ) . '</p>';
					$mailer = WC()->mailer();
					$mailer->send( $order->get_billing_email(), sprintf( __( 'Faktura k objednávce č. %s', 'zg-b2b' ), $order->get_order_number() ), $mailer->wrap_message( __( 'Faktura', 'zg-b2b' ), $html ), array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . zg_b2b_contact_email() ), array( $path ) );
				}
			);
			$order->add_order_note( 'S&V STOCK: faktura odeslána zákazníkovi e-mailem.' );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * 4. Messenger
	 * ------------------------------------------------------------------- */

	private static function msg_cred( $k ) {
		$const = array( 'customer' => 'ZG_MSG_CUSTOMER', 'password' => 'ZG_MSG_PASSWORD', 'import' => 'ZG_MSG_IMPORT_KEY' );
		return defined( $const[ $k ] ) ? constant( $const[ $k ] ) : (string) zg_b2b_opt( 'sv_msg_' . $k );
	}

	private static function msg_post( $path, array $body, $raw = false ) {
		$base                 = 'yes' === zg_b2b_opt( 'sv_msg_test' ) ? self::MSG_TEST : self::MSG_PROD;
		$body['customerNumber'] = (int) self::msg_cred( 'customer' );
		$body['password']     = self::msg_cred( 'password' );
		$r                    = wp_remote_post(
			$base . $path,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$b = (string) wp_remote_retrieve_body( $r );
		ZG_B2B_EF_Client::log( 'Messenger ' . $path . ' HTTP ' . wp_remote_retrieve_response_code( $r ) . ' ← ' . ( $raw ? strlen( $b ) . ' B' : substr( $b, 0, 2000 ) ) );
		if ( $raw ) {
			return $b;
		}
		$j = json_decode( $b, true );
		return is_array( $j ) ? $j : new WP_Error( 'zg_msg', 'Neplatná odpověď Messengeru (HTTP ' . wp_remote_retrieve_response_code( $r ) . ').' );
	}

	/** „Purkyňova 649/127“ → [ ulice, číslo ]. */
	public static function split_street( $line ) {
		$line = trim( (string) $line );
		if ( preg_match( '/^(.*\D)\s+(\d[\w\/\-]*)$/u', $line, $m ) ) {
			return array( trim( $m[1] ), $m[2] );
		}
		return array( $line, '' );
	}

	public static function order_weight( WC_Order $order ) {
		$kg = 0.0;
		foreach ( $order->get_items() as $item ) {
			$p = $item->get_product();
			if ( $p && $p->get_weight() ) {
				$kg += (float) wc_get_weight( (float) $p->get_weight(), 'kg' ) * $item->get_quantity();
			}
		}
		return max( 1, $kg );
	}

	public static function messenger_order( WC_Order $order ) {
		if ( $order->get_meta( '_zg_sv_msg' ) ) {
			return new WP_Error( 'zg_msg', 'Messenger už je objednaný.' );
		}
		if ( ! self::msg_cred( 'customer' ) || ! self::msg_cred( 'password' ) || ! self::msg_cred( 'import' ) ) {
			return new WP_Error( 'zg_msg', 'Chybí přístupové údaje Messenger (nastavení modulu S&V STOCK).' );
		}
		$has_ship = $order->has_shipping_address();
		$addr1    = $has_ship ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		list( $street, $no ) = self::split_street( $addr1 );
		$kg       = self::order_weight( $order );
		$max      = max( 1, (float) zg_b2b_opt( 'sv_msg_parcel_kg' ) );
		$count    = (int) ceil( $kg / $max );
		$per      = (int) ceil( $kg / $count );
		$baliky   = rtrim( str_repeat( $per . ',,,;', $count ), ';' ) . ';';
		$phone    = $has_ship && $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
		$company  = $has_ship ? $order->get_shipping_company() : $order->get_billing_company();
		$person   = $has_ship ? trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$body = array(
			'importKey'             => self::msg_cred( 'import' ),
			'firma_odkud'           => (string) zg_b2b_opt( 'sv_name' ),
			'ulice_odkud'           => (string) zg_b2b_opt( 'sv_street' ),
			'cislo_domu_odkud'      => (string) zg_b2b_opt( 'sv_house' ),
			'mesto_odkud'           => (string) zg_b2b_opt( 'sv_city' ),
			'psc_odkud'             => preg_replace( '/\s+/', '', (string) zg_b2b_opt( 'sv_zip' ) ),
			'kontaktni_osoba_odkud' => (string) zg_b2b_opt( 'sv_contact' ),
			'telefon_odkud'         => (string) zg_b2b_opt( 'sv_phone' ),
			'firma_kam'             => mb_substr( (string) $company, 0, 150 ),
			'kontaktni_osoba_kam'   => mb_substr( $person, 0, 150 ),
			'telefon_kam'           => mb_substr( (string) $phone, 0, 20 ),
			'ulice_kam'             => mb_substr( $street, 0, 150 ),
			'cislo_domu_kam'        => mb_substr( $no, 0, 10 ),
			'mesto_kam'             => $has_ship ? $order->get_shipping_city() : $order->get_billing_city(),
			'psc_kam'               => preg_replace( '/\s+/', '', $has_ship ? $order->get_shipping_postcode() : $order->get_billing_postcode() ),
			'email'                 => $order->get_billing_email(),
			'poznamka_kam'          => mb_substr( 'Objednávka ' . $order->get_order_number() . ( $order->get_customer_note() ? ' – ' . $order->get_customer_note() : '' ), 0, 250 ),
			'dobirka'               => 0,
			'typ_prepravy'          => (int) zg_b2b_opt( 'sv_msg_type' ),
			'hmotnost'              => (int) ceil( $kg ),
			'baliky'                => $baliky,
			'pocet_kusu'            => $count,
			'kontrola_veku'         => 'yes' === zg_b2b_opt( 'sv_msg_age' ) ? 1 : 0,
			'kod_zasilky'           => 'VO-' . $order->get_order_number(),
		);
		$body = apply_filters( 'zg_b2b_sv_messenger_body', $body, $order );
		$res  = self::msg_post( 'JSON/importParcel', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$r = isset( $res['importResult'] ) && is_array( $res['importResult'] ) ? $res['importResult'] : $res;
		if ( empty( $r['success'] ) || empty( $r['ids'][0] ) ) {
			$errs = array();
			foreach ( array( 'authentification', 'importKey', 'pairs' ) as $k ) {
				if ( isset( $r[ $k ]['zprava'] ) && $r[ $k ]['zprava'] ) {
					$errs[] = $r[ $k ]['zprava'];
				}
			}
			if ( ! empty( $r['dataErrors'] ) ) {
				$errs = array_merge( $errs, array_map( 'wp_json_encode', (array) $r['dataErrors'] ) );
			}
			$msg = $errs ? implode( '; ', $errs ) : 'zásilka nebyla přijata';
			$order->add_order_note( 'Messenger: objednávka se nepodařila – ' . $msg );
			return new WP_Error( 'zg_msg', $msg );
		}
		$data = array(
			'id'       => (string) $r['ids'][0],
			'tracking' => isset( $r['trackingCodes'][0] ) ? (string) $r['trackingCodes'][0] : '',
			'url'      => isset( $r['trackingURL'] ) ? (string) $r['trackingURL'] : '',
			'date'     => current_time( 'mysql' ),
		);
		$order->update_meta_data( '_zg_sv_msg', $data );
		$order->save();
		$order->add_order_note( 'Messenger: objednáno, č. ' . $data['id'] . ( $data['tracking'] ? ', zásilka ' . $data['tracking'] : '' ) . ', ' . $count . ' bal., ' . ceil( $kg ) . ' kg.' );
		if ( $data['url'] ) {
			$order->add_order_note( sprintf( __( 'Vaše zásilka (lihoviny) byla předána přepravci Messenger. Sledování: %s', 'zg-b2b' ), $data['url'] ), true );
		}
		self::mail_sv( $order );
		return $data;
	}

	/** Štítky PDF (může trvat pár minut po importu). */
	public static function labels_pdf( WC_Order $order ) {
		$msg = $order->get_meta( '_zg_sv_msg' );
		if ( empty( $msg['id'] ) ) {
			return new WP_Error( 'zg_msg', 'Messenger není objednaný.' );
		}
		$body = array( 'orderIds' => array( $msg['id'] ), 'printFromPosition' => '1' );
		if ( zg_b2b_opt( 'sv_msg_format' ) ) {
			$body['format'] = (int) zg_b2b_opt( 'sv_msg_format' );
		}
		$raw = self::msg_post( 'data/printLabels', $body, true );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( 0 === strpos( $raw, '%PDF' ) ) {
			return $raw;
		}
		$bin = base64_decode( trim( $raw, "\" \n" ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return $bin && 0 === strpos( $bin, '%PDF' ) ? $bin : new WP_Error( 'zg_msg', 'Štítky zatím nejsou k dispozici (zkuste za pár minut).' );
	}

	/** E-mail S&V STOCK: co vyskladnit, kam a štítky v příloze. */
	public static function mail_sv( WC_Order $order ) {
		$to = zg_b2b_opt( 'sv_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$msg  = $order->get_meta( '_zg_sv_msg' );
		$rows = '';
		foreach ( $order->get_items() as $item ) {
			$p     = $item->get_product();
			$rows .= '<tr><td>' . esc_html( $p ? $p->get_sku() : '' ) . '</td><td>' . esc_html( $item->get_name() ) . '</td><td style="text-align:right"><strong>' . (int) $item->get_quantity() . '</strong></td></tr>';
		}
		$addr = $order->has_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();
		$html = '<p>Prosíme o vyskladnění a předání kurýrovi Messenger.</p>'
			. '<p><strong>Objednávka:</strong> ' . esc_html( $order->get_order_number() ) . '<br><strong>Messenger:</strong> ' . esc_html( isset( $msg['id'] ) ? $msg['id'] : '—' ) . ( ! empty( $msg['tracking'] ) ? ' / ' . esc_html( $msg['tracking'] ) : '' ) . '</p>'
			. '<table cellpadding="6" style="border-collapse:collapse;width:100%" border="1"><tr><th>Kód</th><th>Produkt</th><th>Ks</th></tr>' . $rows . '</table>'
			. '<p><strong>Doručení:</strong><br>' . wp_kses_post( $addr ) . '<br>' . esc_html( $order->get_billing_phone() ) . '</p>'
			. '<p><strong>Fakturujte zákazníkovi:</strong><br>' . wp_kses_post( $order->get_formatted_billing_address() ) . '<br>' . esc_html( $order->get_billing_email() ) . '</p>'
			. '<p>Fakturu pro zákazníka prosíme poslat na ' . esc_html( zg_b2b_contact_email() ) . '.</p>';
		$files = array();
		$pdf   = self::labels_pdf( $order );
		if ( ! is_wp_error( $pdf ) ) {
			$tmp = trailingslashit( get_temp_dir() ) . 'Messenger-stitky-' . $order->get_order_number() . '.pdf';
			file_put_contents( $tmp, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$files[] = $tmp;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . zg_b2b_contact_email(), 'Cc: ' . zg_b2b_contact_email() );
		WC()->mailer()->send( $to, 'Objednávka lihovin #' . $order->get_order_number() . ' – Messenger ' . ( isset( $msg['id'] ) ? $msg['id'] : '' ), WC()->mailer()->wrap_message( 'Objednávka lihovin k expedici', $html ), $headers, $files );
		foreach ( $files as $f ) {
			wp_delete_file( $f );
		}
		$order->add_order_note( 'S&V STOCK: e-mail k expedici odeslán na ' . $to . ( $files ? ' (se štítky).' : ' (štítky zatím nebyly k dispozici – pošlete je tlačítkem „Poslat S&V znovu“).' ) );
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
			add_meta_box( 'zg-b2b-sv', 'S&V STOCK – lihoviny', array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
		}
	}

	private static function link( WC_Order $order, $do, $label, $primary = false ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=zg_b2b_sv&do=' . $do . '&order=' . $order->get_id() ), 'zg_b2b_sv_' . $order->get_id() );
		return '<a class="button' . ( $primary ? ' button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		// phpcs:disable WordPress.Security.EscapeOutput
		if ( ! self::is_sv_order( $order ) ) {
			$child = $order->get_meta( '_zg_sv_child' ) ? wc_get_order( $order->get_meta( '_zg_sv_child' ) ) : null;
			if ( $child ) {
				echo '<p>Lihoviny jsou v objednávce <a href="' . esc_url( $child->get_edit_order_url() ) . '"><strong>#' . esc_html( $child->get_order_number() ) . '</strong></a>.</p>';
				return;
			}
			$sv = self::sv_items( $order );
			if ( ! $sv ) {
				echo '<p class="description">Objednávka neobsahuje lihoviny S&amp;V STOCK.</p>';
				return;
			}
			echo '<p><strong>Lihoviny S&amp;V STOCK:</strong></p><ul style="margin:0 0 8px">';
			foreach ( $sv as $item ) {
				echo '<li>' . (int) $item->get_quantity() . '× ' . esc_html( $item->get_name() ) . '</li>';
			}
			echo '</ul><p>' . self::link( $order, 'split', count( $sv ) === count( $order->get_items() ) ? 'Označit jako objednávku S&V' : 'Rozdělit objednávku', true ) . '</p>';
			echo '<p class="description">Lihoviny se přesunou do nové objednávky stejného zákazníka (bez e-mailů zákazníkovi), původní objednávka se přepočítá a znovu synchronizuje s Eurofakturou.</p>';
			return;
		}

		$parent = $order->get_meta( '_zg_sv_parent' ) ? wc_get_order( $order->get_meta( '_zg_sv_parent' ) ) : null;
		if ( $parent ) {
			echo '<p>Odděleno z objednávky <a href="' . esc_url( $parent->get_edit_order_url() ) . '">#' . esc_html( $parent->get_order_number() ) . '</a>. Plugin Eurofaktura.cz tuto objednávku nedostává.</p>';
		}

		// Faktura Bombora → S&V.
		$ef = $order->get_meta( '_zg_sv_ef_invoice' );
		echo '<hr><p><strong>1. Faktura Bombora → S&amp;V</strong><br>';
		echo $ef ? 'č. ' . esc_html( $ef['number'] ) . ' (' . esc_html( $ef['date'] ) . ')' : self::link( $order, 'efinv', 'Vystavit v Eurofaktuře' );
		echo '</p>';

		// Messenger.
		$msg = $order->get_meta( '_zg_sv_msg' );
		echo '<hr><p><strong>2. Messenger</strong><br>';
		if ( $msg ) {
			echo 'č. ' . esc_html( $msg['id'] ) . ( $msg['tracking'] ? ' / ' . esc_html( $msg['tracking'] ) : '' ) . ( $msg['url'] ? ' – <a href="' . esc_url( $msg['url'] ) . '" target="_blank">sledovat</a>' : '' ) . '</p><p>';
			echo self::link( $order, 'labels', 'Štítky PDF' ) . ' ' . self::link( $order, 'mailsv', 'Poslat S&V znovu' );
		} else {
			echo self::link( $order, 'messenger', 'Objednat Messenger' ) . '<br><span class="description">' . esc_html( ceil( self::order_weight( $order ) ) ) . ' kg' . ( 'yes' === zg_b2b_opt( 'sv_msg_test' ) ? ' – TESTOVACÍ prostředí' : '' ) . '</span>';
		}
		echo '</p>';

		// Faktury S&V pro zákazníka.
		echo '<hr><p><strong>3. Faktura S&amp;V pro zákazníka</strong></p>';
		foreach ( self::invoices( $order ) as $i => $inv ) {
			echo '<p>' . esc_html( $inv['number'] ? $inv['number'] : 'bez čísla' ) . ' (' . esc_html( $inv['date'] ) . ') – <a href="' . esc_url( ZG_B2B_EF_Documents::download_url( $order, 'sv', (string) $i ) ) . '">PDF</a> ' . self::link( $order, 'del' . (int) $i, 'Smazat' ) . '</p>';
		}
		// Formulář nesmí být vnořený do formuláře objednávky → odešle se přes form="…".
		$fid = 'zg-sv-upload';
		echo '<p><label>Číslo faktury S&amp;V<br><input form="' . $fid . '" type="text" name="zg_sv_number" style="width:100%"></label></p>';
		echo '<p><input form="' . $fid . '" type="file" name="zg_sv_pdf" accept="application/pdf"></p>';
		echo '<p><label><input form="' . $fid . '" type="checkbox" name="zg_sv_mail" value="1" checked> Poslat zákazníkovi e-mailem</label></p>';
		echo '<p><button form="' . $fid . '" type="submit" class="button button-primary">Nahrát fakturu</button></p>';
		add_action(
			'admin_footer',
			function () use ( $order, $fid ) {
				echo '<form id="' . esc_attr( $fid ) . '" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:none">'
					. '<input type="hidden" name="action" value="zg_b2b_sv_upload"><input type="hidden" name="order" value="' . (int) $order->get_id() . '">'
					. wp_nonce_field( 'zg_b2b_sv_upload_' . $order->get_id(), '_wpnonce', true, false ) . '</form>';
			}
		);
		// phpcs:enable
	}

	public static function admin_action() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'zg_b2b_sv_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		$order = wc_get_order( $order_id );
		$do    = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$back  = wp_get_referer() ? wp_get_referer() : admin_url();
		if ( ! $order ) {
			wp_safe_redirect( $back );
			exit;
		}
		if ( 'split' === $do ) {
			$r = self::split( $order );
			if ( is_wp_error( $r ) ) {
				$order->add_order_note( 'S&V STOCK: ' . $r->get_error_message() );
			} elseif ( $r->get_id() !== $order->get_id() ) {
				$back = $r->get_edit_order_url();
			}
		} elseif ( 'efinv' === $do ) {
			self::create_ef_invoice( $order );
		} elseif ( 'messenger' === $do ) {
			self::messenger_order( $order );
		} elseif ( 'mailsv' === $do ) {
			self::mail_sv( $order );
		} elseif ( 'labels' === $do ) {
			$pdf = self::labels_pdf( $order );
			if ( ! is_wp_error( $pdf ) ) {
				nocache_headers();
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: inline; filename="Messenger-' . $order->get_order_number() . '.pdf"' );
				echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
				exit;
			}
			$order->add_order_note( 'Messenger štítky: ' . $pdf->get_error_message() );
		} elseif ( 0 === strpos( $do, 'del' ) ) {
			$i    = (int) substr( $do, 3 );
			$list = self::invoices( $order );
			if ( isset( $list[ $i ] ) ) {
				if ( ! empty( $list[ $i ]['file'] ) && file_exists( $list[ $i ]['file'] ) ) {
					wp_delete_file( $list[ $i ]['file'] );
				}
				$order->add_order_note( 'S&V STOCK: faktura ' . $list[ $i ]['number'] . ' smazána.' );
				unset( $list[ $i ] );
				$order->update_meta_data( '_zg_sv_invoices', array_values( $list ) );
				$order->save();
			}
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Nastavení (sekce 5)
	 * ------------------------------------------------------------------- */

	public static function defaults() {
		return array(
			'sv_module'        => 'no',
			'sv_categories'    => array(),
			'sv_shipping'      => 'keep',
			'sv_email'         => '',
			'sv_invoice_to'    => '',
			'sv_name'          => 'S & V STOCK s.r.o.',
			'sv_ico'           => '',
			'sv_dic'           => '',
			'sv_street'        => 'Milady Horákové',
			'sv_house'         => '2724',
			'sv_city'          => 'Kladno',
			'sv_zip'           => '27201',
			'sv_contact'       => '',
			'sv_phone'         => '',
			'sv_ef_wh_field'   => '',
			'sv_ef_wh_value'   => '',
			'sv_msg_customer'  => '',
			'sv_msg_password'  => '',
			'sv_msg_import'    => '',
			'sv_msg_type'      => '',
			'sv_msg_test'      => 'yes',
			'sv_msg_age'       => 'yes',
			'sv_msg_parcel_kg' => '8',
			'sv_msg_format'    => '',
		);
	}

	public static function sanitize( $in, $old ) {
		$d   = self::defaults();
		$out = array();
		$out['sv_module'] = defined( 'ZG_B2B_SV_DISABLE' ) && ZG_B2B_SV_DISABLE ? ( isset( $old['sv_module'] ) ? $old['sv_module'] : 'no' ) : ( empty( $in['sv_module'] ) ? 'no' : 'yes' );
		if ( empty( $in['sv_categories_present'] ) ) {
			$out['sv_categories'] = array_map( 'intval', (array) ( isset( $old['sv_categories'] ) ? $old['sv_categories'] : array() ) );
		} else {
			$cats                 = array_filter( array_map( 'absint', isset( $in['sv_categories'] ) ? (array) $in['sv_categories'] : array() ) );
			$out['sv_categories'] = array_values( array_unique( array_map( function ( $c ) { return zg_b2b_original_id( $c, 'product_cat' ); }, $cats ) ) );
		}
		$out['sv_shipping']   = isset( $in['sv_shipping'] ) && 'move' === $in['sv_shipping'] ? 'move' : 'keep';
		$out['sv_email']      = isset( $in['sv_email'] ) && is_email( $in['sv_email'] ) ? sanitize_email( $in['sv_email'] ) : '';
		$out['sv_invoice_to'] = isset( $in['sv_invoice_to'] ) && is_email( $in['sv_invoice_to'] ) ? sanitize_email( $in['sv_invoice_to'] ) : '';
		foreach ( array( 'sv_name', 'sv_ico', 'sv_dic', 'sv_street', 'sv_house', 'sv_city', 'sv_zip', 'sv_contact', 'sv_phone', 'sv_ef_wh_value', 'sv_msg_customer', 'sv_msg_import', 'sv_msg_type', 'sv_msg_parcel_kg', 'sv_msg_format' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : $d[ $k ];
		}
		$out['sv_ef_wh_field']  = isset( $in['sv_ef_wh_field'] ) ? preg_replace( '/[^A-Za-z:]/', '', $in['sv_ef_wh_field'] ) : '';
		$out['sv_msg_password'] = isset( $in['sv_msg_password'] ) && '' !== trim( $in['sv_msg_password'] ) ? sanitize_text_field( $in['sv_msg_password'] ) : ( isset( $old['sv_msg_password'] ) ? $old['sv_msg_password'] : '' );
		$out['sv_msg_test']     = empty( $in['sv_msg_test'] ) ? 'no' : 'yes';
		$out['sv_msg_age']      = empty( $in['sv_msg_age'] ) ? 'no' : 'yes';
		return $out;
	}

	private static function cat_tree( $selected, $parent = 0, $depth = 0 ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $parent, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return;
		}
		foreach ( $terms as $t ) {
			printf(
				'<label style="display:block;margin:3px 0 3px %dpx"><input type="checkbox" name="zg_b2b_settings[sv_categories][]" value="%d" %s> %s</label>',
				(int) ( $depth * 22 ),
				(int) $t->term_id,
				checked( in_array( (int) $t->term_id, $selected, true ), true, false ),
				esc_html( $t->name )
			);
			self::cat_tree( $selected, $t->term_id, $depth + 1 );
		}
	}

	public static function render_settings( $s ) {
		$f = function ( $key, $label, $desc = '', $type = 'text', $w = '' ) use ( $s ) {
			$val = 'password' === $type ? '' : ( isset( $s[ $key ] ) ? $s[ $key ] : '' );
			$ph  = 'password' === $type && ! empty( $s[ $key ] ) ? '•••••••• (uloženo – vyplňte jen pro změnu)' : '';
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input class="regular-text" ' . ( $w ? 'style="max-width:' . esc_attr( $w ) . '"' : '' ) . ' type="' . esc_attr( $type ) . '" name="zg_b2b_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $ph ) . '" autocomplete="off">' . ( $desc ? '<p class="description">' . wp_kses_post( $desc ) . '</p>' : '' ) . '</td></tr>';
		};
		$off = defined( 'ZG_B2B_SV_DISABLE' ) && ZG_B2B_SV_DISABLE;
		?>
		<h2 id="zg-sv">5. Modul: Lihoviny přes S&amp;V STOCK</h2>
		<p>Rozdělení objednávky s lihovinami (tlačítko v detailu objednávky), faktura Bombora → S&amp;V přes Eurofaktura API, nahrání faktury S&amp;V pro zákazníka a objednání Messengeru. Nouzové vypnutí: <code>define( 'ZG_B2B_SV_DISABLE', true );</code> ve <code>wp-config.php</code>.</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Modul</th><td><label><input type="checkbox" name="zg_b2b_settings[sv_module]" value="1" <?php checked( 'yes', $s['sv_module'] ); ?> <?php disabled( $off ); ?>> Zapnout modul</label><?php echo $off ? ' <em style="color:#b32d2e">(nouzově vypnuto ve wp-config.php)</em>' : ''; ?></td></tr>
			<tr><th scope="row">Kategorie lihovin S&amp;V</th><td>
				<input type="hidden" name="zg_b2b_settings[sv_categories_present]" value="1">
				<div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:8px 12px;background:#fff;max-width:520px"><?php self::cat_tree( array_map( 'intval', (array) $s['sv_categories'] ) ); ?></div>
				<p class="description">Produkty z těchto kategorií (i podkategorií) se při rozdělení přesunou do objednávky pro S&amp;V. Produkty druhého skladu, které prodává Bombora (vlastní spotřeba / dárky), sem nezařazujte.</p></td></tr>
			<tr><th scope="row">Poplatek za dopravu</th><td><select name="zg_b2b_settings[sv_shipping]">
				<option value="keep" <?php selected( 'keep', $s['sv_shipping'] ); ?>>Ponechat v původní objednávce (lihoviny: Messenger 0 Kč)</option>
				<option value="move" <?php selected( 'move', $s['sv_shipping'] ); ?>>Přesunout do objednávky lihovin</option>
			</select></td></tr>
			<?php
			$f( 'sv_email', 'E-mail S&V (expedice)', 'Sem odejde objednávka k vyskladnění se štítky Messenger (kopie vám).', 'email' );
			$f( 'sv_invoice_to', 'Faktura pro S&V ke kontrole', 'Kam poslat PDF faktury Bombora → S&amp;V. Prázdné = ' . esc_html( zg_b2b_contact_email() ) . '.', 'email' );
			$f( 'sv_name', 'S&V – název' );
			$f( 'sv_ico', 'S&V – IČO', '', 'text', '180px' );
			$f( 'sv_dic', 'S&V – DIČ', '', 'text', '180px' );
			$f( 'sv_street', 'S&V – ulice (vyzvednutí)' );
			$f( 'sv_house', 'S&V – číslo domu', '', 'text', '120px' );
			$f( 'sv_city', 'S&V – město' );
			$f( 'sv_zip', 'S&V – PSČ', '', 'text', '120px' );
			$f( 'sv_contact', 'S&V – kontaktní osoba', 'Pro kurýra Messenger (povinné).' );
			$f( 'sv_phone', 'S&V – telefon', 'Pro kurýra Messenger (povinné).' );
			$f( 'sv_ef_wh_field', 'Eurofaktura – pole skladu', 'Název pole skladu v API (dokladu), např. <code>warehouse</code>; pro sklad na položce prefix <code>item:</code>, např. <code>item:warehouse</code>. Ověřte v testu API metodou <code>SalesInvoiceGet</code> na existující faktuře ze skladu S&amp;V.', 'text', '240px' );
			$f( 'sv_ef_wh_value', 'Eurofaktura – sklad S&V', 'Kód/název skladu S&amp;V STOCK v Eurofaktuře.', 'text', '240px' );
			$f( 'sv_msg_customer', 'Messenger – zákaznické číslo', 'Lze i v wp-config: <code>ZG_MSG_CUSTOMER</code>, <code>ZG_MSG_PASSWORD</code>, <code>ZG_MSG_IMPORT_KEY</code>.', 'text', '180px' );
			$f( 'sv_msg_password', 'Messenger – heslo', '', 'password', '240px' );
			$f( 'sv_msg_import', 'Messenger – importKey', 'Přidělí Messenger po zapnutí importéru.', 'text', '240px' );
			$f( 'sv_msg_type', 'Messenger – typ přepravy', 'Číslo dohodnuté s Messengerem (<code>typ_prepravy</code>).', 'text', '120px' );
			$f( 'sv_msg_parcel_kg', 'Max. kg na balík', 'Počet balíků = hmotnost / tato hodnota (karton ≈ 8 kg).', 'text', '80px' );
			$f( 'sv_msg_format', 'Formát štítků', '2 = A4, 3 = termo 105×66, 4 = termo 100×150; prázdné = výchozí z Messengeru.', 'text', '80px' );
			?>
			<tr><th scope="row">Messenger – volby</th><td>
				<label><input type="checkbox" name="zg_b2b_settings[sv_msg_age]" value="1" <?php checked( 'yes', $s['sv_msg_age'] ); ?>> Kontrola věku při doručení</label><br>
				<label><input type="checkbox" name="zg_b2b_settings[sv_msg_test]" value="1" <?php checked( 'yes', $s['sv_msg_test'] ); ?>> Testovací prostředí (api-test.messenger.cz – nevznikne skutečná zásilka)</label></td></tr>
		</table>
		<?php
	}
}
