<?php
/**
 * Stav úhrady dokladů z Eurofaktury – jen ZOBRAZENÍ pro zákazníka.
 *
 * Platby se evidují a párují výhradně v Eurofaktuře. Tady se na pozadí
 * načte stav faktury (případně proformy, dokud faktura není) a uloží se
 * k objednávce, aby Můj účet nemusel čekat na Eurofakturu:
 *
 *   _zg_ef_pay = [ state, total, paid, left, due, currency, doc, kind, checked ]
 *
 * state: paid | partial | unpaid | overdue | online (zaplaceno bránou) | cod (dobírka, čeká)
 *
 * Zdroj dokladu: faktura z pluginu Eurofaktura.cz (ZG_B2B_EF_Connector),
 * faktura / proforma z platby na fakturu (ZG_B2B_EF_Documents).
 * API: SalesInvoiceGet / SalesQuoteGet → documentAmount, amountAlreadyPaid,
 * amountLeftToBePaid, paymentDueDate (u proformy validUntil).
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_EF_Payments {

	const META      = '_zg_ef_pay';
	const SYNC_HOOK = 'zg_b2b_ef_pay_sync';
	const ONE_HOOK  = 'zg_b2b_ef_pay_one';

	/** Platby, u kterých peníze přijímá platební brána (stav bere WooCommerce). */
	const ONLINE_METHODS_EXCLUDED = array( 'bacs', 'cheque', 'cod' );

	public static function enabled() {
		return 'yes' === zg_b2b_opt( 'ef_pay_status' ) && ZG_B2B_EF_Client::configured();
	}

	public static function init() {
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync' ) );
		add_action( self::ONE_HOOK, array( __CLASS__, 'refresh_by_id' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 25 );
		add_action( 'admin_post_zg_b2b_ef_pay', array( __CLASS__, 'admin_refresh' ) );

		if ( 'yes' !== zg_b2b_opt( 'ef_pay_status' ) ) {
			return;
		}
		add_filter( 'woocommerce_my_account_my_orders_columns', array( __CLASS__, 'columns' ), 40 );
		add_action( 'woocommerce_my_account_my_orders_column_zg-pay', array( __CLASS__, 'col' ) );
		add_action( 'woocommerce_before_account_orders', array( __CLASS__, 'summary' ), 5 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'detail' ), 4 );
		// Nová faktura / doklad → stav načíst brzy.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'queue' ), 40 );
	}

	/* ---------------------------------------------------------------------
	 * Zjištění stavu
	 * ------------------------------------------------------------------- */

	/** Které platby řeší brána (Comgate apod.) – tam stačí WooCommerce. */
	private static function is_online( WC_Order $order ) {
		$m = $order->get_payment_method();
		return $m && ZG_B2B_Gateway_Invoice::ID !== $m && ! in_array( $m, self::ONLINE_METHODS_EXCLUDED, true );
	}

	/** Doklad, jehož úhradu sledujeme: [ kind => invoice|proforma, number, id ]. */
	public static function target_doc( WC_Order $order ) {
		// 1) Faktura z pluginu Eurofaktura.cz (nestornovaná, poslední).
		if ( ZG_B2B_EF_Connector::enabled() ) {
			$inv = null;
			foreach ( ZG_B2B_EF_Connector::docs( $order ) as $d ) {
				if ( 'invoice' === $d['kind'] && ! $d['cancelled'] && $d['number'] ) {
					$inv = $d;
				}
			}
			if ( $inv ) {
				return array( 'kind' => 'invoice', 'number' => $inv['number'], 'id' => '' );
			}
		}
		// 2) Faktura / proforma z platby na fakturu.
		if ( $order->get_meta( '_zg_ef_invoice_number' ) ) {
			return array( 'kind' => 'invoice', 'number' => (string) $order->get_meta( '_zg_ef_invoice_number' ), 'id' => (string) $order->get_meta( '_zg_ef_invoice_id' ), 'date' => (string) $order->get_meta( '_zg_ef_invoice_date' ) );
		}
		if ( $order->get_meta( '_zg_ef_proforma_number' ) ) {
			return array( 'kind' => 'proforma', 'number' => (string) $order->get_meta( '_zg_ef_proforma_number' ), 'id' => (string) $order->get_meta( '_zg_ef_proforma_id' ), 'date' => (string) $order->get_meta( '_zg_ef_proforma_date' ) );
		}
		return null;
	}

	/**
	 * Načte stav z Eurofaktury a uloží ho k objednávce.
	 *
	 * @return array|WP_Error|null  null = objednávka stav platby nemá (S&V, zrušená, bez dokladu).
	 */
	public static function refresh( WC_Order $order ) {
		if ( class_exists( 'ZG_B2B_SV_Stock' ) && ZG_B2B_SV_Stock::is_sv_order( $order ) ) {
			return null; // lihoviny fakturuje S&V – Eurofaktura o platbě neví
		}
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'pending', 'checkout-draft' ) ) ) {
			return null;
		}
		if ( self::is_online( $order ) ) {
			$st = array(
				'state'    => $order->get_date_paid() ? 'online' : 'unpaid',
				'total'    => (float) $order->get_total(),
				'left'     => $order->get_date_paid() ? 0.0 : (float) $order->get_total(),
				'paid'     => $order->get_date_paid() ? (float) $order->get_total() : 0.0,
				'currency' => $order->get_currency(),
				'due'      => '',
				'paid_on'  => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d' ) : '',
				'doc'      => '',
				'kind'     => '',
				'checked'  => time(),
			);
			self::store( $order, $st );
			return $st;
		}

		$doc = self::target_doc( $order );
		if ( ! $doc ) {
			return null;
		}
		if ( 'proforma' === $doc['kind'] ) {
			$prefix = zg_b2b_opt( 'ef_quote_prefix' ) ? zg_b2b_opt( 'ef_quote_prefix' ) : 'SalesQuote';
			$method = $prefix . 'Get';
		} else {
			$method = 'SalesInvoiceGet';
		}
		$params = ! empty( $doc['id'] ) ? array( 'documentID' => $doc['id'] ) : array_filter( array( 'number' => $doc['number'], 'date' => isset( $doc['date'] ) ? $doc['date'] : '' ) );
		$res    = ZG_B2B_EF_Client::call( $method, $params );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$total = ZG_B2B_EF_Client::find( $res, array( 'documentAmount' ) );
		$paid  = ZG_B2B_EF_Client::find( $res, array( 'amountAlreadyPaid' ) );
		$left  = ZG_B2B_EF_Client::find( $res, array( 'amountLeftToBePaid' ) );
		$due   = ZG_B2B_EF_Client::find( $res, 'invoice' === $doc['kind'] ? array( 'paymentDueDate' ) : array( 'validUntil', 'paymentDueDate' ) );
		$cur   = ZG_B2B_EF_Client::find( $res, array( 'documentCurrency' ) );
		if ( null === $total && null === $left ) {
			return new WP_Error( 'zg_ef_pay', $method . ': odpověď neobsahuje částky (viz log zg-b2b-eurofaktura).' );
		}
		$total = (float) $total;
		$paid  = null !== $paid ? (float) $paid : max( 0, $total - (float) $left );
		$left  = null !== $left ? (float) $left : max( 0, $total - $paid );
		$due   = $due ? substr( (string) $due, 0, 10 ) : '';

		if ( $left <= 0.009 && $total > 0 ) {
			$state = 'paid';
		} elseif ( 'cod' === $order->get_payment_method() && $paid <= 0.009 ) {
			$state = 'cod';
		} elseif ( $due && $due < current_time( 'Y-m-d' ) ) {
			$state = 'overdue';
		} elseif ( $paid > 0.009 ) {
			$state = 'partial';
		} else {
			$state = 'unpaid';
		}
		$old = $order->get_meta( self::META );
		$st  = array(
			'state'    => $state,
			'total'    => $total,
			'paid'     => $paid,
			'left'     => max( 0, $left ),
			'currency' => $cur ? strtoupper( (string) $cur ) : $order->get_currency(),
			'due'      => $due,
			'paid_on'  => 'paid' === $state ? ( is_array( $old ) && ! empty( $old['paid_on'] ) && 'paid' === $old['state'] ? $old['paid_on'] : current_time( 'Y-m-d' ) ) : '',
			'doc'      => $doc['number'],
			'kind'     => $doc['kind'],
			'checked'  => time(),
		);
		self::store( $order, $st );
		return $st;
	}

	private static function store( WC_Order $order, array $st ) {
		$order->update_meta_data( self::META, $st );
		$order->save_meta_data();
	}

	public static function refresh_by_id( $order_id ) {
		$o = wc_get_order( $order_id );
		if ( $o && self::enabled() ) {
			self::refresh( $o );
		}
	}

	/** Uložený stav (bez volání Eurofaktury). */
	public static function get( WC_Order $order ) {
		$st = $order->get_meta( self::META );
		if ( ! is_array( $st ) || empty( $st['state'] ) ) {
			return null;
		}
		// Splatnost mohla uplynout od poslední kontroly.
		if ( in_array( $st['state'], array( 'unpaid', 'partial' ), true ) && ! empty( $st['due'] ) && $st['due'] < current_time( 'Y-m-d' ) ) {
			$st['state'] = 'overdue';
		}
		return $st;
	}

	/* ---------------------------------------------------------------------
	 * Plánování
	 * ------------------------------------------------------------------- */

	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! self::enabled() ) {
			if ( as_has_scheduled_action( self::SYNC_HOOK, array(), 'zg-b2b' ) ) {
				as_unschedule_all_actions( self::SYNC_HOOK, array(), 'zg-b2b' );
			}
			return;
		}
		if ( ! as_has_scheduled_action( self::SYNC_HOOK, array(), 'zg-b2b' ) ) {
			as_schedule_recurring_action( time() + 120, HOUR_IN_SECONDS, self::SYNC_HOOK, array(), 'zg-b2b' );
		}
	}

	public static function queue( $order_id ) {
		if ( self::enabled() && function_exists( 'as_schedule_single_action' ) ) {
			$args = array( 'order_id' => (int) $order_id );
			as_unschedule_action( self::ONE_HOOK, $args, 'zg-b2b' );
			as_schedule_single_action( time() + 300, self::ONE_HOOK, $args, 'zg-b2b' ); // doklady vznikají v Eurofaktuře s odstupem
		}
	}

	/**
	 * Každou hodinu: objednávky za 180 dní, které nejsou uhrazené (nebo stav nemají).
	 * Uhrazené se už nekontrolují. Max. 40 volání na běh (limit API 1 / s).
	 */
	public static function sync() {
		if ( ! self::enabled() ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'status'       => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-pending', 'wc-checkout-draft' ) ),
				'date_created' => '>' . ( time() - 180 * DAY_IN_SECONDS ),
				'limit'        => 300,
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);
		$calls = 0;
		foreach ( $orders as $order ) {
			$st = $order->get_meta( self::META );
			if ( is_array( $st ) && in_array( $st['state'], array( 'paid', 'online' ), true ) ) {
				continue;
			}
			if ( is_array( $st ) && ! empty( $st['checked'] ) && time() - (int) $st['checked'] < 50 * MINUTE_IN_SECONDS ) {
				continue;
			}
			$r = self::refresh( $order );
			if ( is_array( $r ) && in_array( $r['state'], array( 'online' ), true ) ) {
				continue; // bez volání API
			}
			if ( null !== $r && ++$calls >= 40 ) {
				break;
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Zobrazení (CZ/EN přes překlady pluginu)
	 * ------------------------------------------------------------------- */

	private static function money( $amount, $currency ) {
		return wc_price( $amount, array( 'currency' => $currency ) );
	}

	private static function date( $ymd ) {
		return $ymd ? date_i18n( 'j. n. Y', strtotime( $ymd ) ) : '';
	}

	/** [ label, css modifier, detail ] pro stav. */
	public static function describe( array $st ) {
		switch ( $st['state'] ) {
			case 'paid':
			case 'online':
				return array( __( 'Zaplaceno', 'zg-b2b' ), 'paid', ! empty( $st['paid_on'] ) ? self::date( $st['paid_on'] ) : '' );
			case 'overdue':
				/* translators: %s: due date */
				return array( __( 'Po splatnosti', 'zg-b2b' ), 'overdue', sprintf( __( 'splatnost %s', 'zg-b2b' ), self::date( $st['due'] ) ) );
			case 'partial':
				/* translators: %s: amount left to pay */
				return array( __( 'Částečně uhrazeno', 'zg-b2b' ), 'partial', sprintf( __( 'zbývá %s', 'zg-b2b' ), wp_strip_all_tags( self::money( $st['left'], $st['currency'] ) ) ) );
			case 'cod':
				return array( __( 'Dobírka', 'zg-b2b' ), 'cod', __( 'platba při převzetí', 'zg-b2b' ) );
			default:
				return array( __( 'Nezaplaceno', 'zg-b2b' ), 'unpaid', $st['due'] ? sprintf( __( 'splatnost %s', 'zg-b2b' ), self::date( $st['due'] ) ) : '' );
		}
	}

	public static function badge( array $st ) {
		list( $label, $mod, $detail ) = self::describe( $st );
		return '<span class="zg-b2b-pay zg-b2b-pay--' . esc_attr( $mod ) . '"><span class="zg-b2b-pay__label">' . esc_html( $label ) . '</span>'
			. ( $detail ? '<span class="zg-b2b-pay__detail">' . esc_html( $detail ) . '</span>' : '' ) . '</span>';
	}

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order-total' === $k ) {
				$out['zg-pay'] = __( 'Platba', 'zg-b2b' );
			}
		}
		if ( ! isset( $out['zg-pay'] ) ) {
			$out['zg-pay'] = __( 'Platba', 'zg-b2b' );
		}
		return $out;
	}

	public static function col( $order ) {
		$st = self::get( $order );
		echo $st ? self::badge( $st ) : '<span class="zg-b2b-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Neuhrazené částky zákazníka po měnách: [ CZK => [ left, overdue, count ] ]. */
	public static function open_balance( $customer_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id'  => (int) $customer_id,
				'status'       => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-pending', 'wc-checkout-draft' ) ),
				'date_created' => '>' . ( time() - 365 * DAY_IN_SECONDS ),
				'limit'        => -1,
			)
		);
		$sum = array();
		foreach ( $orders as $o ) {
			$st = self::get( $o );
			if ( ! $st || ! in_array( $st['state'], array( 'unpaid', 'partial', 'overdue' ), true ) || $st['left'] <= 0.009 ) {
				continue;
			}
			$c = $st['currency'];
			if ( ! isset( $sum[ $c ] ) ) {
				$sum[ $c ] = array( 'left' => 0.0, 'overdue' => 0.0, 'count' => 0 );
			}
			$sum[ $c ]['left'] += $st['left'];
			$sum[ $c ]['count']++;
			if ( 'overdue' === $st['state'] ) {
				$sum[ $c ]['overdue'] += $st['left'];
			}
		}
		return $sum;
	}

	public static function summary() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$sum = self::open_balance( get_current_user_id() );
		if ( ! $sum ) {
			return;
		}
		$parts = array();
		$over  = array();
		foreach ( $sum as $cur => $s ) {
			$parts[] = self::money( $s['left'], $cur );
			if ( $s['overdue'] > 0.009 ) {
				$over[] = self::money( $s['overdue'], $cur );
			}
		}
		echo '<div class="zg-b2b-balance' . ( $over ? ' zg-b2b-balance--overdue' : '' ) . '">'
			. '<span class="zg-b2b-balance__label">' . esc_html__( 'Neuhrazeno', 'zg-b2b' ) . '</span> '
			. '<strong class="zg-b2b-balance__amount">' . wp_kses_post( implode( ' + ', $parts ) ) . '</strong>'
			. ( $over ? ' <span class="zg-b2b-balance__overdue">' . esc_html__( 'z toho po splatnosti', 'zg-b2b' ) . ' <strong>' . wp_kses_post( implode( ' + ', $over ) ) . '</strong></span>' : '' )
			. '<span class="zg-b2b-balance__note">' . esc_html__( 'Stav plateb se aktualizuje přibližně každou hodinu.', 'zg-b2b' ) . '</span>'
			. '</div>';
	}

	public static function detail( $order ) {
		if ( ! $order instanceof WC_Order || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$st = self::get( $order );
		if ( ! $st ) {
			return;
		}
		$rows = '<tr><th>' . esc_html__( 'Stav platby', 'zg-b2b' ) . '</th><td>' . self::badge( $st ) . '</td></tr>';
		if ( in_array( $st['state'], array( 'unpaid', 'partial', 'overdue' ), true ) ) {
			$rows .= '<tr><th>' . esc_html__( 'Zbývá uhradit', 'zg-b2b' ) . '</th><td><strong>' . wp_kses_post( self::money( $st['left'], $st['currency'] ) ) . '</strong></td></tr>';
		}
		echo '<section class="zg-b2b-paystatus"><table class="woocommerce-table shop_table zg-b2b-paystatus__table"><tbody>' . $rows . '</tbody></table></section>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ---------------------------------------------------------------------
	 * Administrace
	 * ------------------------------------------------------------------- */

	public static function admin_box( WC_Order $order ) {
		if ( 'yes' !== zg_b2b_opt( 'ef_pay_status' ) ) {
			return '';
		}
		$st   = self::get( $order );
		$html = '<p><strong>Stav platby</strong> <span class="description">(z Eurofaktury, vidí zákazník)</span><br>';
		if ( $st ) {
			list( $label, , $detail ) = self::describe( $st );
			$html .= esc_html( $label . ( $detail ? ' – ' . $detail : '' ) );
			if ( $st['doc'] ) {
				$html .= '<br><span class="description">' . esc_html( ( 'proforma' === $st['kind'] ? 'Proforma ' : 'Faktura ' ) . $st['doc'] . ': uhrazeno ' . wp_strip_all_tags( self::money( $st['paid'], $st['currency'] ) ) . ' z ' . wp_strip_all_tags( self::money( $st['total'], $st['currency'] ) ) ) . '</span>';
			}
			$html .= '<br><span class="description">Načteno ' . esc_html( wp_date( 'j. n. Y H:i', (int) $st['checked'] ) ) . '</span>';
		} else {
			$html .= '—';
		}
		$url   = wp_nonce_url( admin_url( 'admin-post.php?action=zg_b2b_ef_pay&order=' . $order->get_id() ), 'zg_b2b_ef_pay_' . $order->get_id() );
		$html .= '</p><p><a class="button" href="' . esc_url( $url ) . '">Aktualizovat platbu</a></p><hr>';
		return $html;
	}

	public static function admin_refresh() {
		$id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'zg_b2b_ef_pay_' . $id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		$order = wc_get_order( $id );
		if ( $order ) {
			$r = self::refresh( $order );
			if ( is_wp_error( $r ) ) {
				$order->add_order_note( 'Eurofaktura: stav platby se nepodařilo načíst – ' . $r->get_error_message() );
			} elseif ( null === $r ) {
				$order->add_order_note( 'Eurofaktura: objednávka zatím nemá doklad, u kterého by šel stav platby zjistit.' );
			}
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function status_html() {
		if ( 'yes' !== zg_b2b_opt( 'ef_pay_status' ) ) {
			return '<span style="color:#787c82">● Vypnuto.</span>';
		}
		if ( ! ZG_B2B_EF_Client::configured() ) {
			return '<span style="color:#b32d2e">● Chybí údaje API (API username, API Password, API Web Services token) – stav platby se nenačítá.</span>';
		}
		$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( self::SYNC_HOOK, array(), 'zg-b2b' ) : false;
		return '<span style="color:#00a32a">● Zapnuto.</span>' . ( is_int( $next ) ? ' Další kontrola ' . esc_html( wp_date( 'j. n. H:i', $next ) ) . '.' : '' );
	}
}
