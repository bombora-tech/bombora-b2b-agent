<?php
/**
 * Doklady z oficiálního pluginu Eurofaktura.cz (WPWebShopPlugin).
 *
 * Plugin Eurofaktura si u objednávek nic neukládá – seznam dokladů i PDF
 * stahuje z konektoru Eurofaktury akcemi „order.refresh“ a „document.pdf“
 * (spojení = WooCommerce webhook + API klíč, které plugin sám nastavil).
 * Tady se ty samé akce volají na serveru a výsledek se ukládá do meta
 * objednávky, aby Můj účet nemusel při každém zobrazení čekat na Eurofakturu.
 *
 * Meta objednávky:
 *   _zg_ef_docs          seznam dokladů [ id, kind, number, title, cancelled ]
 *   _zg_ef_docs_checked  čas poslední synchronizace (timestamp)
 *
 * kind: invoice (faktura) | credit (opravný daňový doklad) | proforma
 * Výdejky, objednávky zákazníka apod. se zákazníkovi nezobrazují.
 *
 * Zapínatelný modul: Bombora → B2B Agent → „4. Modul: Doklady z Eurofaktura.cz“.
 * Nouzové vypnutí: define( 'ZG_B2B_EF_DOCS_DISABLE', true ); ve wp-config.php.
 * Pojistka: po 5 chybách Eurofaktury za sebou se volání na 30 min pozastaví.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_EF_Connector {

	const SYNC_HOOK    = 'zg_b2b_ef_docs_sync';
	const REFRESH_HOOK = 'zg_b2b_ef_docs_refresh';
	const META         = '_zg_ef_docs';
	const META_CHECKED = '_zg_ef_docs_checked';
	const FAILS        = 'zg_b2b_ef_docs_fails';
	const PAUSED       = 'zg_b2b_ef_docs_paused';
	const MAX_FAILS    = 5;

	/** Je modul zapnutý (nastavení a není nouzově vypnutý ve wp-config)? */
	public static function enabled() {
		if ( defined( 'ZG_B2B_EF_DOCS_DISABLE' ) && ZG_B2B_EF_DOCS_DISABLE ) {
			return false;
		}
		return 'yes' === zg_b2b_opt( 'ef_docs_module' );
	}

	public static function init() {
		if ( ! self::enabled() ) {
			add_action( 'init', array( __CLASS__, 'unschedule' ), 20 );
			return;
		}
		add_action( 'init', array( __CLASS__, 'schedule_sync' ), 20 );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync' ) );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh_by_id' ) );
		// Eurofaktura zpracovává webhook asynchronně → načíst doklady s odstupem.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'queue_refresh' ), 30 );
	}

	/** Vypnutý modul: zrušit naplánované úlohy (jednou). */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) && get_option( 'zg_b2b_ef_docs_scheduled' ) ) {
			as_unschedule_all_actions( self::SYNC_HOOK, array(), 'zg-b2b' );
			as_unschedule_all_actions( self::REFRESH_HOOK, null, 'zg-b2b' );
			delete_option( 'zg_b2b_ef_docs_scheduled' );
		}
	}

	/** Je plugin Eurofaktura.cz aktivní a připojený (a modul zapnutý)? */
	public static function available() {
		return self::enabled()
			&& class_exists( 'Eurofaktura_http_client' )
			&& function_exists( 'eurofaktura_map_order_for_request' )
			&& (bool) get_option( 'eurofaktura_connected' );
	}

	/* ---------------------------------------------------------------------
	 * Komunikace s konektorem Eurofaktury
	 * ------------------------------------------------------------------- */

	private static function request( $action, $contents, $timeout ) {
		if ( get_transient( self::PAUSED ) ) {
			return new WP_Error( 'zg_ef_paused', 'Modul je po opakovaných chybách Eurofaktury dočasně pozastaven.' );
		}
		try {
			$client = Eurofaktura_http_client::instance();
			if ( empty( $client->client ) ) {
				return new WP_Error( 'zg_ef_conn', 'Plugin Eurofaktura.cz nemá aktivní spojení (webhook / API klíč).' );
			}
			$response = $client->post_request( $action, $contents, $timeout );
		} catch ( \Throwable $e ) { // chyba v pluginu Eurofaktura nesmí shodit web
			$response = null;
			ZG_B2B_EF_Client::log( $action . ' – výjimka: ' . $e->getMessage(), 'error' );
		}
		if ( empty( $response ) || (int) $response->getStatusCode() >= 500 ) {
			self::failed( $action );
			return new WP_Error( 'zg_ef_conn', 'Eurofaktura neodpověděla (' . $action . ').' );
		}
		delete_option( self::FAILS );
		return $response;
	}

	/** Počítá chyby za sebou; po MAX_FAILS pozastaví volání na 30 min. */
	private static function failed( $action ) {
		$n = (int) get_option( self::FAILS ) + 1;
		update_option( self::FAILS, $n, false );
		if ( $n >= self::MAX_FAILS ) {
			set_transient( self::PAUSED, time(), 30 * MINUTE_IN_SECONDS );
			delete_option( self::FAILS );
			ZG_B2B_EF_Client::log( 'Doklady Eurofaktura.cz: ' . self::MAX_FAILS . ' chyb za sebou (poslední ' . $action . ') – volání pozastavena na 30 min.', 'error' );
		}
	}

	/** Stav modulu pro stránku nastavení. */
	public static function status_html() {
		if ( defined( 'ZG_B2B_EF_DOCS_DISABLE' ) && ZG_B2B_EF_DOCS_DISABLE ) {
			return '<span style="color:#b32d2e">● Nouzově vypnuto ve wp-config.php (ZG_B2B_EF_DOCS_DISABLE).</span>';
		}
		if ( ! self::enabled() ) {
			return '<span style="color:#787c82">● Modul je vypnutý.</span>';
		}
		if ( ! class_exists( 'Eurofaktura_http_client' ) ) {
			return '<span style="color:#b32d2e">● Plugin Eurofaktura.cz není aktivní – modul nic nedělá.</span>';
		}
		if ( ! get_option( 'eurofaktura_connected' ) ) {
			return '<span style="color:#b32d2e">● Plugin Eurofaktura.cz není připojený (WooCommerce → Nastavení → Eurofaktura).</span>';
		}
		$paused = (int) get_transient( self::PAUSED );
		if ( $paused ) {
			return '<span style="color:#dba617">● Pozastaveno po opakovaných chybách do ' . esc_html( wp_date( 'H:i', $paused + 30 * MINUTE_IN_SECONDS ) ) . ' (viz WooCommerce → Stav → Logy, zdroj zg-b2b-eurofaktura).</span>';
		}
		return '<span style="color:#00a32a">● Zapnuto, plugin Eurofaktura.cz je připojený.</span>';
	}

	/**
	 * Načte doklady objednávky z Eurofaktury (bez uložení).
	 *
	 * @return array|WP_Error
	 */
	public static function fetch( WC_Order $order ) {
		if ( ! self::available() ) {
			return new WP_Error( 'zg_ef_conn', 'Plugin Eurofaktura.cz není aktivní nebo připojený.' );
		}
		$response = self::request( 'order.refresh', eurofaktura_map_order_for_request( $order ), 8 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) $response->getStatusCode();
		try {
			$body = json_decode( (string) $response->getBody(), true );
		} catch ( \Throwable $e ) {
			$body = null;
		}
		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['data']['metaData'] ) || ! is_array( $body['data']['metaData'] ) ) {
			$msg = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : 'HTTP ' . $code;
			return new WP_Error( 'zg_ef_refresh', $msg );
		}
		return self::parse( $body['data']['metaData'] );
	}

	/** Z odpovědi „metaData“ vybere doklady s PDF a určí jejich druh. */
	public static function parse( array $meta ) {
		$docs = array();
		foreach ( $meta as $row ) {
			if ( ! is_array( $row ) || empty( $row['documentID'] ) ) {
				continue;
			}
			$has_pdf = false;
			foreach ( ( isset( $row['actionsLine1'] ) && is_array( $row['actionsLine1'] ) ? $row['actionsLine1'] : array() ) as $a ) {
				if ( is_array( $a ) && isset( $a['type'], $a['action'] ) && 'action' === $a['type'] && 'pdf' === $a['action'] ) {
					$has_pdf = true;
					break;
				}
			}
			if ( ! $has_pdf ) {
				continue; // např. sekce „Opravný daňový doklad“ jen s tlačítky Stornovat / Vytvořit
			}
			$title   = isset( $row['title'] ) ? trim( wp_strip_all_tags( (string) $row['title'] ) ) : '';
			$message = isset( $row['actionsLine1Message'] ) ? (string) $row['actionsLine1Message'] : '';
			$kind    = self::kind( $title );
			if ( ! $kind ) {
				continue;
			}
			$number = preg_match( '/#\s*([^\s#]+)/u', $title, $m ) ? $m[1] : '';
			$docs[] = array(
				'id'        => sanitize_text_field( (string) $row['documentID'] ),
				'kind'      => $kind,
				'number'    => sanitize_text_field( $number ),
				'title'     => sanitize_text_field( $title ),
				'cancelled' => (bool) preg_match( '/storn|cancel/iu', $title . ' ' . $message ),
			);
		}
		return $docs;
	}

	private static function kind( $title ) {
		if ( preg_match( '/opravn|dobropis|credit\s*note|vrubopis/iu', $title ) ) {
			return 'credit';
		}
		if ( preg_match( '/proforma|záloh|predfakt|předfakt|advance/iu', $title ) ) {
			return 'proforma';
		}
		if ( preg_match( '/faktur|invoice/iu', $title ) ) {
			return 'invoice';
		}
		return '';
	}

	/** Načte doklady z Eurofaktury a uloží je k objednávce. */
	public static function refresh( WC_Order $order ) {
		$docs = self::fetch( $order );
		if ( is_wp_error( $docs ) ) {
			ZG_B2B_EF_Client::log( 'order.refresh #' . $order->get_order_number() . ' – ' . $docs->get_error_message(), 'warning' );
			return $docs;
		}
		$order->update_meta_data( self::META, $docs );
		$order->update_meta_data( self::META_CHECKED, time() );
		$order->save_meta_data();
		return $docs;
	}

	public static function refresh_by_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::refresh( $order );
		}
	}

	/**
	 * Uložené doklady objednávky.
	 *
	 * @param int $max_age  Pokud jsou starší (s), načtou se znovu (0 = jen uložené).
	 */
	public static function docs( WC_Order $order, $max_age = 0 ) {
		if ( ! self::enabled() ) {
			return array(); // vypnutý modul = jako by doklady z Eurofaktura.cz neexistovaly
		}
		if ( $max_age && self::available() ) {
			$checked = (int) $order->get_meta( self::META_CHECKED );
			if ( time() - $checked > $max_age ) {
				$fresh = self::refresh( $order );
				if ( ! is_wp_error( $fresh ) ) {
					return $fresh;
				}
			}
		}
		$docs = $order->get_meta( self::META );
		return is_array( $docs ) ? $docs : array();
	}

	/** Doklady pro zákazníka: faktury a opravné doklady (proforma z Eurofaktura pluginu jen dokud není faktura). */
	public static function customer_docs( WC_Order $order, $max_age = 0 ) {
		if ( class_exists( 'ZG_B2B_SV_Stock' ) && ZG_B2B_SV_Stock::is_sv_order( $order ) ) {
			return array(); // objednávka lihovin: doklad v Eurofaktuře je faktura Bombora → S&V, zákazník ho nevidí
		}
		$docs    = self::docs( $order, $max_age );
		$invoice = self::has_invoice( $docs );
		$out     = array();
		foreach ( array( 'invoice', 'credit', 'proforma' ) as $kind ) {
			foreach ( $docs as $d ) {
				if ( $d['kind'] === $kind && ( 'proforma' !== $kind || ! $invoice ) ) {
					$out[] = $d;
				}
			}
		}
		return $out;
	}

	/** Obsahuje seznam fakturu (i stornovanou)? */
	public static function has_invoice( array $docs ) {
		foreach ( $docs as $d ) {
			if ( 'invoice' === $d['kind'] ) {
				return true;
			}
		}
		return false;
	}

	/** Najde doklad objednávky podle ID (jen mezi doklady pro zákazníka). */
	public static function find_doc( WC_Order $order, $doc_id ) {
		foreach ( self::customer_docs( $order ) as $d ) {
			if ( hash_equals( (string) $d['id'], (string) $doc_id ) ) {
				return $d;
			}
		}
		return null;
	}

	/**
	 * Stáhne PDF dokladu z Eurofaktury.
	 *
	 * @return string|WP_Error  Binární PDF.
	 */
	public static function pdf( $doc_id ) {
		if ( ! self::available() ) {
			return new WP_Error( 'zg_ef_conn', 'Plugin Eurofaktura.cz není aktivní nebo připojený.' );
		}
		$response = self::request( 'document.pdf', array( 'document_id' => (string) $doc_id ), 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		try {
			$body = (string) $response->getBody();
		} catch ( \Throwable $e ) {
			$body = '';
		}
		if ( 200 !== (int) $response->getStatusCode() || 0 !== strpos( $body, '%PDF' ) ) {
			ZG_B2B_EF_Client::log( 'document.pdf ' . $doc_id . ' – HTTP ' . $response->getStatusCode(), 'error' );
			return new WP_Error( 'zg_ef_pdf', 'PDF se nepodařilo stáhnout.' );
		}
		return $body;
	}

	/* ---------------------------------------------------------------------
	 * Texty pro zákazníka (CZ/EN přes překlady pluginu)
	 * ------------------------------------------------------------------- */

	public static function label( array $d ) {
		switch ( $d['kind'] ) {
			case 'credit':
				$label = sprintf( __( 'Opravný daňový doklad č. %s', 'zg-b2b' ), $d['number'] );
				break;
			case 'proforma':
				$label = sprintf( __( 'Proforma faktura č. %s', 'zg-b2b' ), $d['number'] );
				break;
			default:
				$label = sprintf( __( 'Faktura č. %s', 'zg-b2b' ), $d['number'] );
		}
		return $d['cancelled'] ? $label . ' – ' . __( 'stornováno', 'zg-b2b' ) : $label;
	}

	public static function hint( array $d ) {
		if ( $d['cancelled'] ) {
			return __( 'Doklad byl stornován a není platný.', 'zg-b2b' );
		}
		switch ( $d['kind'] ) {
			case 'credit':
				return __( 'Oprava (dobropis) k faktuře.', 'zg-b2b' );
			case 'proforma':
				return __( 'Po připsání platby ji nahradí faktura (daňový doklad).', 'zg-b2b' );
			default:
				return __( 'Daňový doklad k objednávce.', 'zg-b2b' );
		}
	}

	public static function filename( array $d ) {
		$base = 'credit' === $d['kind'] ? __( 'Opravny-doklad', 'zg-b2b' ) : ( 'proforma' === $d['kind'] ? __( 'Proforma', 'zg-b2b' ) : __( 'Faktura', 'zg-b2b' ) );
		return sanitize_file_name( $base . '-' . ( $d['number'] ? $d['number'] : $d['id'] ) . '.pdf' );
	}

	/* ---------------------------------------------------------------------
	 * Plánování synchronizace
	 * ------------------------------------------------------------------- */

	public static function queue_refresh( $order_id ) {
		if ( self::available() && function_exists( 'as_schedule_single_action' ) ) {
			as_unschedule_action( self::REFRESH_HOOK, array( 'order_id' => (int) $order_id ), 'zg-b2b' );
			as_schedule_single_action( time() + 120, self::REFRESH_HOOK, array( 'order_id' => (int) $order_id ), 'zg-b2b' );
		}
	}

	/** Objednávka, u které se doklady ještě nikdy nenačetly → načíst na pozadí (za 10 s). */
	public static function queue_first_check( WC_Order $order ) {
		if ( ! self::available() || ! function_exists( 'as_has_scheduled_action' ) || $order->get_meta( self::META_CHECKED ) ) {
			return;
		}
		$args = array( 'order_id' => (int) $order->get_id() );
		if ( ! as_has_scheduled_action( self::REFRESH_HOOK, $args, 'zg-b2b' ) ) {
			as_schedule_single_action( time() + 10, self::REFRESH_HOOK, $args, 'zg-b2b' );
		}
	}

	public static function schedule_sync() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! self::available() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::SYNC_HOOK, array(), 'zg-b2b' ) ) {
			as_schedule_recurring_action( time() + 300, 30 * MINUTE_IN_SECONDS, self::SYNC_HOOK, array(), 'zg-b2b' );
		}
		if ( ! get_option( 'zg_b2b_ef_docs_scheduled' ) ) {
			update_option( 'zg_b2b_ef_docs_scheduled', 1, false );
		}
	}

	/**
	 * Každých 30 min: objednávky za posledních 45 dní – bez faktury kontrola
	 * při každém běhu, s fakturou jednou za 6 h (opravné doklady, storno).
	 */
	public static function sync() {
		if ( ! self::available() ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'status'       => array( 'wc-on-hold', 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-cancelled' ),
				'date_created' => '>' . ( time() - 45 * DAY_IN_SECONDS ),
				'limit'        => 200,
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);
		$done = 0;
		foreach ( $orders as $order ) {
			if ( 'yes' === $order->get_meta( '_zg_sv_order' ) ) {
				continue;
			}
			$docs    = $order->get_meta( self::META );
			$checked = (int) $order->get_meta( self::META_CHECKED );
			$age     = is_array( $docs ) && self::has_invoice( $docs ) ? 6 * HOUR_IN_SECONDS : 25 * MINUTE_IN_SECONDS;
			if ( time() - $checked < $age ) {
				continue;
			}
			self::refresh( $order );
			if ( get_transient( self::PAUSED ) || ++$done >= 40 ) { // max 40 volání na jeden běh
				break;
			}
		}
	}
}
