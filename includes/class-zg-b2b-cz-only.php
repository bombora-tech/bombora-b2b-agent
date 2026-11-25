<?php
/**
 * Lihoviny a pivo: doručení pouze v povolených zemích (výchozí ČR).
 *
 * Vrstvy: štítek produktu → upozornění na kartě a ve výpisu → řádek v košíku →
 * živé upozornění v pokladně → tvrdá validace objednávky.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_CZ_Only {

	private static $printed = array();

	public static function init() {
		// Automatické přiřazení štítku podle kategorií.
		add_action( 'woocommerce_new_product', array( __CLASS__, 'maybe_tag' ), 20 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'maybe_tag' ), 20 );

		// Informace pro zákazníka.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_notice' ), 31 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'loop_badge' ), 15 );
		add_shortcode( 'zg_cz_only_notice', array( __CLASS__, 'shortcode_notice' ) );
		add_shortcode( 'zg_cz_only_badge', array( __CLASS__, 'shortcode_badge' ) );
		// Design „varianta C“ (snippet se shortcody zgb_*): vložit upozornění do jeho výstupu.
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'inject_zgb' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_action( 'woocommerce_review_order_before_cart_contents', array( __CLASS__, 'checkout_notice' ) );

		// Omezení nákupu a validace.
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Štítek
	 * ------------------------------------------------------------------- */

	public static function expanded_categories( $cat_ids ) {
		$all = array();
		foreach ( (array) $cat_ids as $id ) {
			$id    = (int) $id;
			$all[] = $id;
			$kids  = get_term_children( $id, 'product_cat' );
			if ( ! is_wp_error( $kids ) ) {
				$all = array_merge( $all, array_map( 'intval', $kids ) );
			}
		}
		return array_values( array_unique( $all ) );
	}

	public static function maybe_tag( $product_id ) {
		$cats = (array) zg_b2b_opt( 'cz_categories' );
		if ( ! $cats || wp_is_post_revision( $product_id ) ) {
			return;
		}
		$product_id   = zg_b2b_original_id( $product_id );
		$product_cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $product_cats ) || ! $product_cats ) {
			return;
		}
		$tag = zg_b2b_tag_id();
		if ( $tag && array_intersect( array_map( 'intval', $product_cats ), self::expanded_categories( $cats ) ) ) {
			wp_set_object_terms( $product_id, array( $tag ), 'product_tag', true );
		}
	}

	private static function products_in( $cat_ids ) {
		if ( ! $cat_ids ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'suppress_filters' => true, // bez jazykového filtru WPML
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => array_map( 'intval', (array) $cat_ids ),
						'include_children' => true,
					),
				),
			)
		);
	}

	/** Přiřadí štítek všem produktům ve vybraných kategoriích. Vrací počet produktů. */
	public static function bulk_assign( $cat_ids ) {
		$tag = zg_b2b_ensure_tag();
		if ( ! $tag ) {
			return 0;
		}
		$ids = self::products_in( $cat_ids );
		foreach ( $ids as $id ) {
			wp_set_object_terms( $id, array( $tag ), 'product_tag', true );
		}
		return count( $ids );
	}

	/**
	 * Odebere štítek produktům v odškrtnutých kategoriích – kromě produktů,
	 * které jsou zároveň v některé kategorii, jež zůstala zaškrtnutá.
	 * Vrací počet produktů, kterým byl štítek odebrán.
	 */
	public static function bulk_remove( $removed_cats, $kept_cats ) {
		$tag = zg_b2b_tag_id();
		if ( ! $tag || ! $removed_cats ) {
			return 0;
		}
		$keep  = self::expanded_categories( $kept_cats );
		$count = 0;
		foreach ( self::products_in( $removed_cats ) as $id ) {
			$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $cats ) && array_intersect( array_map( 'intval', $cats ), $keep ) ) {
				continue;
			}
			if ( has_term( $tag, 'product_tag', $id ) ) {
				wp_remove_object_terms( $id, array( $tag ), 'product_tag' );
				$count++;
			}
		}
		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Pomocné
	 * ------------------------------------------------------------------- */

	/** Přihlášený zákazník má v účtu doručovací zemi mimo povolené? */
	public static function customer_blocked() {
		// Od verze 1.3.3 se tlačítko neskrývá – zákazník zemi zvolí v okně při přidání do košíku.
		if ( true ) {
			return false;
		}
		$country = zg_b2b_account_country();
		return $country && ! zg_b2b_country_allowed( $country );
	}

	/** Názvy produktů v košíku s omezením doručení. */
	private static function cart_items() {
		$names = array();
		if ( ! WC()->cart ) {
			return $names;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['data'] ) && zg_b2b_is_cz_only( $item['data'] ) ) {
				$names[ $item['data']->get_id() ] = $item['data']->get_name();
			}
		}
		return $names;
	}

	private static function names_list( $names ) {
		return '<strong>' . implode( '</strong>, <strong>', array_map( 'esc_html', $names ) ) . '</strong>';
	}

	private static function session_country() {
		return WC()->customer ? strtoupper( (string) WC()->customer->get_shipping_country() ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Karta produktu a výpis
	 * ------------------------------------------------------------------- */

	/**
	 * Je zvolená země doručení povolená (nebo zatím neznámá)? Pak se žádné upozornění
	 * nezobrazuje – zákazník s doručením v ČR o omezení vůbec neví.
	 */
	public static function country_ok() {
		$country = class_exists( 'ZG_B2B_Country_Prompt' ) ? ZG_B2B_Country_Prompt::current_country() : '';
		return ! $country || zg_b2b_country_allowed( $country );
	}

	public static function notice_html( $product ) {
		if ( ! $product || self::country_ok() || ! zg_b2b_is_cz_only( $product ) ) {
			return '';
		}
		if ( self::customer_blocked() ) {
			$text = sprintf(
				/* translators: 1: země z účtu, 2: povolené země, 3: e-mail */
				__( 'Vaše doručovací adresa je v zemi %1$s. Tento produkt doručujeme pouze do: %2$s, proto ho nelze objednat. Pro doručení na českou adresu nás kontaktujte na %3$s.', 'zg-b2b' ),
				esc_html( zg_b2b_country_name( zg_b2b_account_country() ) ),
				esc_html( zg_b2b_allowed_countries_label() ),
				'<a href="mailto:' . esc_attr( zg_b2b_contact_email() ) . '">' . esc_html( zg_b2b_contact_email() ) . '</a>'
			);
			return zg_b2b_notice_box( zg_b2b_notice_title(), $text, 'error', 'alert' );
		}
		return zg_b2b_notice_box( zg_b2b_notice_title(), esc_html( zg_b2b_notice_text() ), 'info', 'truck' );
	}

	public static function single_notice() {
		global $product;
		if ( ! $product instanceof WC_Product || isset( self::$printed[ $product->get_id() ] ) ) {
			return;
		}
		$html = self::notice_html( $product );
		if ( $html ) {
			self::$printed[ $product->get_id() ] = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno v notice_html
		}
	}

	public static function shortcode_notice( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'zg_cz_only_notice' );
		$product = $atts['id'] ? wc_get_product( (int) $atts['id'] ) : wc_get_product( get_the_ID() );
		if ( ! $product ) {
			return '';
		}
		self::$printed[ $product->get_id() ] = true;
		return self::notice_html( $product );
	}

	public static function badge_html( $product ) {
		if ( ! $product || self::country_ok() || ! zg_b2b_is_cz_only( $product ) ) {
			return '';
		}
		return '<span class="zg-b2b-badge" title="' . esc_attr( zg_b2b_notice_text() ) . '">' . zg_b2b_icon( 'truck' ) . '<span>' . esc_html__( 'Doručení pouze v ČR', 'zg-b2b' ) . '</span></span>';
	}

	/** Permalinky produktů se štítkem v aktuálním jazyce (pro řádky výpisu). */
	private static function tagged_permalinks() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map  = array();
		$lang = apply_filters( 'wpml_current_language', null );
		foreach ( zg_b2b_tagged_ids() as $id ) {
			$tid = $lang ? (int) apply_filters( 'wpml_object_id', $id, 'product', true, $lang ) : $id;
			if ( 'product' !== get_post_type( $tid ) ) {
				continue;
			}
			$map[ untrailingslashit( get_permalink( $tid ) ) ] = true;
		}
		return $map;
	}

	public static function short_badge() {
		return '<span class="zg-b2b-badge zg-b2b-badge--sm" title="' . esc_attr( zg_b2b_notice_text() ) . '">' . zg_b2b_icon( 'truck' ) . '<span>' . esc_html__( 'Pouze ČR', 'zg-b2b' ) . '</span></span>';
	}

	/**
	 * Výstup shortcodů zgb_single / zgb_home / zgb_archive:
	 * - karta produktu: box „Doručení pouze v ČR“ pod ceny (před řádek s platebními podmínkami),
	 * - řádky produktů: odznak „Pouze ČR“ za stav skladu.
	 */
	public static function inject_zgb( $output, $tag ) {
		if ( ! is_string( $output ) || 0 !== strpos( $tag, 'zgb_' ) ) {
			return $output;
		}
		if ( 'zgb_single' === $tag && false === strpos( $output, 'zg-b2b-notice' ) ) {
			$product = wc_get_product( get_queried_object_id() );
			$notice  = $product ? self::notice_html( $product ) : '';
			if ( $notice ) {
				self::$printed[ $product->get_id() ] = true;
				$anchor = '<p class="zgb-meta zgb-terms">';
				if ( false !== strpos( $output, $anchor ) ) {
					$output = preg_replace( '/' . preg_quote( $anchor, '/' ) . '/', $notice . $anchor, $output, 1 );
				} elseif ( false !== strpos( $output, '<div class="zgb-buy-r">' ) ) {
					$output = preg_replace( '/<div class="zgb-buy-r">/', $notice . '<div class="zgb-buy-r">', $output, 1 );
				}
			}
		}
		if ( false !== strpos( $output, 'class="zgb-row"' ) && ! self::country_ok() && zg_b2b_tagged_ids() ) {
			$map    = self::tagged_permalinks();
			$output = preg_replace_callback(
				'#<article class="zgb-row".*?</article>#s',
				function ( $m ) use ( $map ) {
					if ( ! preg_match( '#<a class="zgb-nm" href="([^"]+)"#', $m[0], $h ) || ! isset( $map[ untrailingslashit( html_entity_decode( $h[1] ) ) ] ) ) {
						return $m[0];
					}
					$badge = self::short_badge();
					if ( preg_match( '#<span class="zgb-tag[^"]*">.*?</span>#s', $m[0] ) ) {
						return preg_replace( '#(<span class="zgb-tag[^"]*">.*?</span>)#s', '$1 ' . $badge, $m[0], 1 );
					}
					return preg_replace( '#(<div class="zgb-meta">)#', '$1' . $badge . ' ', $m[0], 1 );
				},
				$output
			);
		}
		return $output;
	}

	public static function loop_badge() {
		global $product;
		echo self::badge_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function shortcode_badge( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'zg_cz_only_badge' );
		$product = $atts['id'] ? wc_get_product( (int) $atts['id'] ) : wc_get_product( get_the_ID() );
		return self::badge_html( $product );
	}

	public static function purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $purchasable;
		}
		if ( self::customer_blocked() && zg_b2b_is_cz_only( $product ) ) {
			return false;
		}
		return $purchasable;
	}

	/* ---------------------------------------------------------------------
	 * Košík a pokladna
	 * ------------------------------------------------------------------- */

	/** Řádek „Doručení: pouze v ČR“ u položky v košíku, mini-košíku i pokladně. */
	public static function item_data( $data, $cart_item ) {
		if ( ! self::country_ok() && ! empty( $cart_item['data'] ) && zg_b2b_is_cz_only( $cart_item['data'] ) ) {
			$data[] = array(
				'key'   => __( 'Doručení', 'zg-b2b' ),
				'value' => sprintf( __( 'pouze %s', 'zg-b2b' ), zg_b2b_allowed_countries_label() ),
			);
		}
		return $data;
	}

	/** Chyba v košíku (jen na stránce košíku – v pokladně by skryla formulář). */
	public static function check_cart() {
		if ( ! is_cart() ) {
			return;
		}
		$items   = self::cart_items();
		$country = self::session_country();
		if ( $items && $country && ! zg_b2b_country_allowed( $country ) ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: produkty, 2: země, 3: povolené země */
					__( 'Produkty %1$s nelze doručit do země %2$s – lihoviny a pivo doručujeme pouze do: %3$s. Zvolte doručovací adresu v ČR, nebo produkty odeberte z košíku.', 'zg-b2b' ),
					self::names_list( $items ),
					esc_html( zg_b2b_country_name( $country ) ),
					esc_html( zg_b2b_allowed_countries_label() )
				),
				'error'
			);
		}
	}

	/** Upozornění v rekapitulaci objednávky – obnovuje se při změně země (AJAX). */
	public static function checkout_notice() {
		$items = self::cart_items();
		if ( ! $items ) {
			return;
		}
		$country = self::session_country();
		if ( $country && ! zg_b2b_country_allowed( $country ) ) {
			$html = zg_b2b_notice_box(
				__( 'Tuto objednávku nelze doručit', 'zg-b2b' ),
				sprintf(
					/* translators: 1: produkty, 2: země, 3: povolené země */
					__( '%1$s doručujeme pouze do: %3$s. Zvolená země doručení je %2$s – změňte doručovací adresu, nebo produkty odeberte z košíku.', 'zg-b2b' ),
					self::names_list( $items ),
					esc_html( zg_b2b_country_name( $country ) ),
					esc_html( zg_b2b_allowed_countries_label() )
				),
				'error',
				'alert'
			);
		} else {
			return; // povolená země → žádné upozornění
		}
		echo '<tr class="zg-b2b-cz-row"><td colspan="2">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Tvrdá kontrola při odeslání objednávky. */
	public static function validate( $data, $errors ) {
		$items = self::cart_items();
		if ( ! $items ) {
			return;
		}
		$country = ! empty( $data['ship_to_different_address'] ) && ! empty( $data['shipping_country'] )
			? $data['shipping_country']
			: ( isset( $data['billing_country'] ) ? $data['billing_country'] : '' );
		$country = strtoupper( (string) $country );

		if ( $country && ! zg_b2b_country_allowed( $country ) ) {
			$errors->add(
				'zg_b2b_cz_only',
				sprintf(
					/* translators: 1: produkty, 2: země, 3: povolené země */
					__( 'Objednávku nelze odeslat: %1$s doručujeme pouze do: %3$s (zvolená země doručení: %2$s). Změňte doručovací adresu, nebo produkty odeberte z košíku.', 'zg-b2b' ),
					self::names_list( $items ),
					esc_html( zg_b2b_country_name( $country ) ),
					esc_html( zg_b2b_allowed_countries_label() )
				)
			);
		}
	}
}
