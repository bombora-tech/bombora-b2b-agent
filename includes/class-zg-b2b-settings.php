<?php
/**
 * Nastavení: Bombora → B2B Agent.
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Settings {

	private static $synced = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Nastavení pod menu „Bombora“ (Bombora Manager). Bez Manageru jako záloha
	 * pod WooCommerce. Slug `zg-b2b` zůstává (odkazy admin.php?page=zg-b2b).
	 */
	public static function menu() {
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['bombora'] ) ? 'bombora' : 'woocommerce';
		add_submenu_page( $parent, 'Bombora B2B Agent', 'B2B Agent', 'manage_woocommerce', 'zg-b2b', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( 'zg_b2b', 'zg_b2b_settings', array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/** Kategorie předvybrané podle názvu/slugu (pivo, lihoviny, brandy, chacha…). */
	public static function suggested_categories() {
		$prefixes = array( 'piv', 'beer', 'brandy', 'chach', 'cach', 'čač', 'lihovin', 'destil', 'vodk', 'whisk', 'rum', 'gin', 'liker', 'likér', 'tikhari', 'spirit', 'tvrd', 'palen', 'koňak', 'konak' );
		$terms    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$ids      = array();
		if ( is_wp_error( $terms ) ) {
			return $ids;
		}
		foreach ( $terms as $t ) {
			$tokens = preg_split( '/[\s\-_,\/]+/u', mb_strtolower( $t->slug . ' ' . $t->name ) );
			foreach ( $tokens as $tok ) {
				foreach ( $prefixes as $p ) {
					if ( '' !== $tok && 0 === mb_strpos( $tok, $p ) ) {
						$ids[] = (int) $t->term_id;
						continue 3;
					}
				}
			}
		}
		return $ids;
	}

	public static function sanitize( $in ) {
		$d   = zg_b2b_defaults();
		$eu  = zg_b2b_eu_countries();
		$out = array();

		$in  = is_array( $in ) ? $in : array();
		$old = zg_b2b_settings();

		if ( empty( $in['cz_categories_present'] ) ) {
			// Formulář bez seznamu kategorií (jiný zdroj uložení) → kategorie neměnit.
			$out['cz_categories'] = array_map( 'intval', (array) $old['cz_categories'] );
		} else {
			$cats = array_filter( array_map( 'absint', isset( $in['cz_categories'] ) ? (array) $in['cz_categories'] : array() ) );
			// Vždy ID kategorií ve výchozím jazyce WPML.
			$out['cz_categories'] = array_values( array_unique( array_map( function ( $c ) { return zg_b2b_original_id( $c, 'product_cat' ); }, $cats ) ) );
		}
		$allowed                  = array_values( array_intersect( isset( $in['allowed_countries'] ) ? array_map( 'strtoupper', (array) $in['allowed_countries'] ) : array(), $eu ) );
		$out['allowed_countries'] = $allowed ? $allowed : array( 'CZ' );
		$out['block_foreign_customers'] = empty( $in['block_foreign_customers'] ) ? 'no' : 'yes';
		$out['notice_title']      = isset( $in['notice_title'] ) && '' !== trim( $in['notice_title'] ) ? sanitize_text_field( $in['notice_title'] ) : $d['notice_title'];
		$out['notice_text']       = isset( $in['notice_text'] ) && '' !== trim( $in['notice_text'] ) ? sanitize_textarea_field( $in['notice_text'] ) : $d['notice_text'];
		$out['notice_title_en']   = isset( $in['notice_title_en'] ) && '' !== trim( $in['notice_title_en'] ) ? sanitize_text_field( $in['notice_title_en'] ) : $d['notice_title_en'];
		$out['notice_text_en']    = isset( $in['notice_text_en'] ) && '' !== trim( $in['notice_text_en'] ) ? sanitize_textarea_field( $in['notice_text_en'] ) : $d['notice_text_en'];
		$out['registration_page'] = isset( $in['registration_page'] ) ? absint( $in['registration_page'] ) : 0;
		$out['terms_page']        = isset( $in['terms_page'] ) ? absint( $in['terms_page'] ) : 0;
		$out['notify_email']      = isset( $in['notify_email'] ) && is_email( $in['notify_email'] ) ? sanitize_email( $in['notify_email'] ) : $d['notify_email'];
		$out['ico_meta']          = isset( $in['ico_meta'] ) && '' !== sanitize_key( $in['ico_meta'] ) ? sanitize_key( $in['ico_meta'] ) : $d['ico_meta'];
		$out['dic_meta']          = isset( $in['dic_meta'] ) && '' !== sanitize_key( $in['dic_meta'] ) ? sanitize_key( $in['dic_meta'] ) : $d['dic_meta'];
		$out['legacy_approved']   = empty( $in['legacy_approved'] ) ? 'no' : 'yes';
		$out['hidden_account_items'] = array_values( array_intersect( array( 'dashboard', 'downloads', 'payment-methods', 'edit-address' ), isset( $in['hidden_account_items'] ) ? (array) $in['hidden_account_items'] : array() ) );
		$out['locked_fields']     = array_values( array_intersect( array( 'company', 'ico', 'dic', 'address' ), isset( $in['locked_fields'] ) ? (array) $in['locked_fields'] : array() ) );
		$reg                      = array_values( array_intersect( $eu, isset( $in['reg_countries'] ) ? array_map( 'strtoupper', (array) $in['reg_countries'] ) : array() ) );
		$out['reg_countries']     = $reg ? $reg : array( 'CZ' );

		// Eurofaktura.
		$out['ef_endpoint']       = isset( $in['ef_endpoint'] ) && '' !== trim( $in['ef_endpoint'] ) ? esc_url_raw( trim( $in['ef_endpoint'] ) ) : $d['ef_endpoint'];
		$out['ef_username']       = isset( $in['ef_username'] ) ? sanitize_text_field( $in['ef_username'] ) : '';
		$out['ef_secret']         = isset( $in['ef_secret'] ) && '' !== trim( $in['ef_secret'] ) ? sanitize_text_field( $in['ef_secret'] ) : $old['ef_secret'];
		$out['ef_token']          = isset( $in['ef_token'] ) ? sanitize_text_field( $in['ef_token'] ) : '';
		$out['ef_quote_prefix']   = isset( $in['ef_quote_prefix'] ) && preg_match( '/^[A-Za-z]+$/', $in['ef_quote_prefix'] ) ? $in['ef_quote_prefix'] : $d['ef_quote_prefix'];
		$out['ef_quote_status']   = isset( $in['ef_quote_status'] ) && preg_match( '/^[A-Za-z]+$/', $in['ef_quote_status'] ) ? $in['ef_quote_status'] : $d['ef_quote_status'];
		$out['ef_send_sku']       = empty( $in['ef_send_sku'] ) ? 'no' : 'yes';
		$out['ef_field_dic']      = isset( $in['ef_field_dic'] ) ? preg_replace( '/[^A-Za-z]/', '', $in['ef_field_dic'] ) : '';
		$out['ef_field_ico']      = isset( $in['ef_field_ico'] ) ? preg_replace( '/[^A-Za-z]/', '', $in['ef_field_ico'] ) : '';
		$out['ef_auto_paid']      = empty( $in['ef_auto_paid'] ) ? 'no' : 'yes';
		$out['ef_invoice_meta']   = isset( $in['ef_invoice_meta'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $in['ef_invoice_meta'] ) : '';
		$out['ef_invoice_date_meta'] = isset( $in['ef_invoice_date_meta'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $in['ef_invoice_date_meta'] ) : '';

		$out['tag_name'] = isset( $in['tag_name'] ) && '' !== trim( $in['tag_name'] ) ? sanitize_text_field( $in['tag_name'] ) : $d['tag_name'];

		// Při uložení z administrace: přejmenovat štítek, přidat ho k zaškrtnutým kategoriím
		// a odebrat produktům v kategoriích, které byly odškrtnuty.
		if ( isset( $_POST['option_page'] ) && 'zg_b2b' === $_POST['option_page'] && ! self::$synced ) { // phpcs:ignore WordPress.Security.NonceVerification -- ověřeno přes options.php
			self::$synced = true; // WordPress může sanitize zavolat 2× – synchronizovat jen jednou.
			$msgs = array();
			$ren  = zg_b2b_rename_tag( $out['tag_name'] );
			if ( $ren ) {
				$msgs[] = $ren;
			}
			$old_cats = array_map( 'intval', (array) $old['cz_categories'] );
			$removed  = array_values( array_diff( $old_cats, $out['cz_categories'] ) );
			if ( $removed ) {
				$n      = ZG_B2B_CZ_Only::bulk_remove( $removed, $out['cz_categories'] );
				$msgs[] = sprintf( 'Štítek odebrán %d produktům v odškrtnutých kategoriích.', $n );
			}
			if ( $out['cz_categories'] ) {
				$n      = ZG_B2B_CZ_Only::bulk_assign( $out['cz_categories'] );
				$msgs[] = sprintf( 'Štítek přiřazen %d produktům v zaškrtnutých kategoriích.', $n );
			}
			set_transient( 'zg_b2b_assign_result', $msgs, 120 );
		}

		return $out;
	}

	private static function category_tree( $selected, $parent = 0, $depth = 0 ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $parent, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return;
		}
		foreach ( $terms as $t ) {
			printf(
				'<label style="display:block;margin:3px 0 3px %dpx"><input type="checkbox" name="zg_b2b_settings[cz_categories][]" value="%d" %s> %s <span style="color:#888">(%d)</span></label>',
				(int) ( $depth * 22 ),
				(int) $t->term_id,
				checked( in_array( (int) $t->term_id, $selected, true ), true, false ),
				esc_html( $t->name ),
				(int) $t->count
			);
			self::category_tree( $selected, $t->term_id, $depth + 1 );
		}
	}

	private static function country_checks( $name, $selected ) {
		foreach ( zg_b2b_eu_countries() as $code ) {
			printf(
				'<label style="display:inline-block;min-width:190px;margin:2px 0"><input type="checkbox" name="zg_b2b_settings[%s][]" value="%s" %s> %s</label>',
				esc_attr( $name ),
				esc_attr( $code ),
				checked( in_array( $code, $selected, true ), true, false ),
				esc_html( zg_b2b_country_name( $code ) )
			);
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s           = zg_b2b_settings();
		$never_saved = false === get_option( 'zg_b2b_settings', false );
		$selected    = $never_saved ? self::suggested_categories() : array_map( 'intval', (array) $s['cz_categories'] );
		$tag_id      = zg_b2b_tag_id();
		$term        = $tag_id ? get_term( $tag_id, 'product_tag' ) : null;
		$tagged      = $term && ! is_wp_error( $term ) ? (int) $term->count : 0;
		$assigned    = get_transient( 'zg_b2b_assign_result' );
		if ( false !== $assigned ) {
			delete_transient( 'zg_b2b_assign_result' );
		}
		$activated = (int) get_option( 'zg_b2b_activated' );
		?>
		<div class="wrap">
			<h1>Bombora B2B Agent</h1>

			<?php if ( false !== $assigned ) : ?>
				<div class="notice notice-success"><?php foreach ( (array) $assigned as $m ) : ?><p><?php echo esc_html( $m ); ?></p><?php endforeach; ?></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'zg_b2b' ); ?>

				<h2>1. Lihoviny a pivo – doručení pouze v ČR</h2>
				<p>
					Štítek <strong><?php echo esc_html( zg_b2b_tag_name() ); ?></strong> má nyní
					<?php if ( $term && ! is_wp_error( $term ) ) : ?><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&product_tag=' . $term->slug ) ); ?>"><?php endif; ?><strong><?php echo (int) $tagged; ?> produktů</strong><?php echo $term ? '</a>' : ''; ?>.
					Štítek lze přidat nebo odebrat i ručně u každého produktu.
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zg-tag-name">Název štítku</label></th>
						<td><input id="zg-tag-name" class="regular-text" type="text" name="zg_b2b_settings[tag_name]" value="<?php echo esc_attr( zg_b2b_tag_name() ); ?>">
							<p class="description">Přejmenuje štítek u všech produktů (název i slug). Pokud zadáte název jiného existujícího štítku, plugin začne používat ten a produkty na něj převede.</p></td>
					</tr>
					<tr>
						<th scope="row">Kategorie se štítkem</th>
						<td>
							<?php if ( $never_saved ) : ?>
								<p style="background:#fff8e5;border-left:4px solid #dba617;padding:8px 12px;margin:0 0 10px">Kategorie jsou <strong>předvybrané automaticky</strong> podle názvu (pivo, brandy, chacha, lihoviny…). Zkontrolujte je a uložte.</p>
							<?php endif; ?>
							<input type="hidden" name="zg_b2b_settings[cz_categories_present]" value="1">
							<div style="max-height:320px;overflow:auto;border:1px solid #dcdcde;background:#fff;padding:10px 14px;border-radius:4px">
								<?php
								// Kategorie vždy ve výchozím jazyce (nezávisle na jazyku administrace).
								$zg_cur = apply_filters( 'wpml_current_language', null );
								$zg_def = zg_b2b_default_lang();
								if ( $zg_def && $zg_cur !== $zg_def ) {
									do_action( 'wpml_switch_language', $zg_def );
								}
								self::category_tree( $selected );
								if ( $zg_def && $zg_cur !== $zg_def ) {
									do_action( 'wpml_switch_language', $zg_cur );
								}
								?>
							</div>
							<p class="description">Po uložení dostanou štítek všechny produkty v zaškrtnutých kategoriích (včetně podkategorií); nové a upravené produkty v nich ho dostanou automaticky. Při odškrtnutí kategorie se štítek jejím produktům odebere – kromě produktů, které jsou zároveň v jiné zaškrtnuté kategorii. Štítek se spravuje na českých produktech; anglické verze (WPML) se řídí jimi automaticky.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Povolené země doručení</th>
						<td><?php self::country_checks( 'allowed_countries', (array) $s['allowed_countries'] ); ?>
							<p class="description">Do kterých zemí lze produkty se štítkem doručit. Standardně pouze Česko.</p></td>
					</tr>
					<tr>
						<th scope="row">Zákazníci</th>
						<td><p class="description" style="margin:0">Když má zákazník v košíku zvolenou jinou zemi než povolenou, po kliknutí na „Přidat do košíku“ u produktu se štítkem se otevře okno s volbou země doručení. Česko → země se nastaví v košíku i pokladně a zboží se přidá; jiná země → upozornění, že lihoviny tam neprodáváme.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="zg-notice-title">Nadpis upozornění</label></th>
						<td><input id="zg-notice-title" class="regular-text" type="text" name="zg_b2b_settings[notice_title]" value="<?php echo esc_attr( $s['notice_title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="zg-notice-text">Text upozornění</label></th>
						<td><textarea id="zg-notice-text" class="large-text" rows="2" name="zg_b2b_settings[notice_text]"><?php echo esc_textarea( $s['notice_text'] ); ?></textarea>
							<p class="description">Zobrazuje se na kartě produktu (česká verze).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="zg-notice-title-en">Upozornění – EN</label></th>
						<td><input id="zg-notice-title-en" class="regular-text" type="text" name="zg_b2b_settings[notice_title_en]" value="<?php echo esc_attr( $s['notice_title_en'] ); ?>">
							<textarea class="large-text" rows="2" name="zg_b2b_settings[notice_text_en]" style="margin-top:6px"><?php echo esc_textarea( $s['notice_text_en'] ); ?></textarea>
							<p class="description">Nadpis a text pro anglickou verzi webu.</p></td>
					</tr>
				</table>

				<h2>2. Ceny a registrace partnerů</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Stránka s registrací</th>
						<td><?php wp_dropdown_pages( array( 'name' => 'zg_b2b_settings[registration_page]', 'selected' => (int) $s['registration_page'], 'show_option_none' => '— vyberte —', 'option_none_value' => 0 ) ); ?>
							<p class="description">Stránka, kde je vložen shortcode <code>[zg_b2b_registration]</code>. Na ni vedou všechna tlačítka „Registrovat firmu“.</p></td>
					</tr>
					<tr>
						<th scope="row">Obchodní podmínky (VOP)</th>
						<td><?php wp_dropdown_pages( array( 'name' => 'zg_b2b_settings[terms_page]', 'selected' => (int) $s['terms_page'], 'show_option_none' => '— vyberte —', 'option_none_value' => 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="zg-email">E-mail pro nové registrace</label></th>
						<td><input id="zg-email" class="regular-text" type="email" name="zg_b2b_settings[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
							<p class="description">Sem chodí oznámení o nových registracích; zároveň je to kontaktní adresa v e-mailech zákazníkům.</p></td>
					</tr>
					<tr>
						<th scope="row">Meta klíče IČO / DIČ</th>
						<td><input class="regular-text" style="max-width:180px" type="text" name="zg_b2b_settings[ico_meta]" value="<?php echo esc_attr( $s['ico_meta'] ); ?>">
							<input class="regular-text" style="max-width:180px" type="text" name="zg_b2b_settings[dic_meta]" value="<?php echo esc_attr( $s['dic_meta'] ); ?>">
							<p class="description">Musí odpovídat polím IČO/DIČ v pokladně a fakturaci (výchozí <code>billing_ic</code> / <code>billing_dic</code>), aby se údaje v pokladně předvyplnily.</p></td>
					</tr>
					<tr>
						<th scope="row">Údaje, které zákazník nemůže měnit</th>
						<td>
							<?php
							$lf = (array) $s['locked_fields'];
							foreach ( array( 'company' => 'Název firmy', 'ico' => 'IČO', 'dic' => 'DIČ', 'address' => 'Sídlo (fakturační adresa)' ) as $k => $l ) {
								printf( '<label style="margin-right:18px"><input type="checkbox" name="zg_b2b_settings[locked_fields][]" value="%s" %s> %s</label>', esc_attr( $k ), checked( in_array( $k, $lf, true ), true, false ), esc_html( $l ) );
							}
							?>
							<p class="description">Zamčené v Můj účet → Adresy i v pokladně (objednávka vždy dostane údaje z účtu). Měníte je vy v Uživatelé → profil zákazníka → Fakturační adresa zákazníka.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Skrýt v menu Můj účet</th>
						<td>
							<?php
							$hi = (array) $s['hidden_account_items'];
							foreach ( array( 'downloads' => 'Stahování', 'payment-methods' => 'Způsoby platby', 'dashboard' => 'Nástěnka' ) as $k => $l ) {
								printf( '<label style="margin-right:18px"><input type="checkbox" name="zg_b2b_settings[hidden_account_items][]" value="%s" %s> %s</label>', esc_attr( $k ), checked( in_array( $k, $hi, true ), true, false ), esc_html( $l ) );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">Stávající zákazníci</th>
						<td><label><input type="checkbox" name="zg_b2b_settings[legacy_approved]" value="1" <?php checked( 'yes', $s['legacy_approved'] ); ?>> Účty založené před aktivací pluginu<?php echo $activated ? ' (' . esc_html( wp_date( 'j. n. Y H:i', $activated ) ) . ')' : ''; ?> považovat za schválené</label></td>
					</tr>
					<tr>
						<th scope="row">Země sídla v registraci</th>
						<td><?php self::country_checks( 'reg_countries', (array) $s['reg_countries'] ); ?></td>
					</tr>
				</table>


				<h2 id="zg-ef">3. Eurofaktura – platba na fakturu (proforma)</h2>
				<p>Platební metodu zapnete a nastavíte (splatnost, účet, VS) ve <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=zg_b2b_invoice' ) ); ?>">WooCommerce → Nastavení → Platby → Platba na fakturu</a>.
				Údaje API lze místo zde zadat bezpečněji do <code>wp-config.php</code>: <code>ZG_EF_ENDPOINT</code>, <code>ZG_EF_USERNAME</code>, <code>ZG_EF_SECRET</code>, <code>ZG_EF_TOKEN</code>.</p>
				<p style="background:#fff8e5;border-left:4px solid #dba617;padding:8px 12px">Doporučení: založte pro web <strong>samostatného API uživatele</strong>. Eurofaktura nepovoluje souběžné požadavky jednoho API uživatele – sdílení s vaší stávající synchronizací skladu by vedlo k chybám.</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row">API URL</th><td><input class="regular-text" type="url" name="zg_b2b_settings[ef_endpoint]" value="<?php echo esc_attr( $s['ef_endpoint'] ); ?>"><p class="description">Ověřte v Eurofaktuře → Nastavení → API (testovací konzole ukazuje přesnou adresu pro vaši organizaci).</p></td></tr>
					<tr><th scope="row">API uživatel</th><td><input class="regular-text" type="text" name="zg_b2b_settings[ef_username]" value="<?php echo esc_attr( $s['ef_username'] ); ?>" autocomplete="off"><?php echo defined( 'ZG_EF_USERNAME' ) ? ' <em>(přepsáno v wp-config.php)</em>' : ''; ?></td></tr>
					<tr><th scope="row">API secret key</th><td><input class="regular-text" type="password" name="zg_b2b_settings[ef_secret]" value="" placeholder="<?php echo $s['ef_secret'] ? '•••••••• (uloženo – vyplňte jen pro změnu)' : ''; ?>" autocomplete="new-password"></td></tr>
					<tr><th scope="row">API token organizace</th><td><input class="regular-text" type="text" name="zg_b2b_settings[ef_token]" value="<?php echo esc_attr( $s['ef_token'] ); ?>" autocomplete="off"></td></tr>
					<tr><th scope="row">Typ dokladu proforma</th><td><input class="regular-text" style="max-width:180px" type="text" name="zg_b2b_settings[ef_quote_prefix]" value="<?php echo esc_attr( $s['ef_quote_prefix'] ); ?>"> stav <input class="regular-text" style="max-width:120px" type="text" name="zg_b2b_settings[ef_quote_status]" value="<?php echo esc_attr( $s['ef_quote_status'] ); ?>">
						<p class="description">Prefix metod API: <code>SalesQuote</code> → SalesQuoteCreate / SalesQuoteGetPDF (nabídka/předfaktura). Stav <code>Issued</code> = vystavený doklad s číslem, <code>Draft</code> = koncept.</p></td></tr>
					<tr><th scope="row">Pole odběratele</th><td>DIČ → <input type="text" style="width:170px" name="zg_b2b_settings[ef_field_dic]" value="<?php echo esc_attr( $s['ef_field_dic'] ); ?>"> &nbsp; IČO → <input type="text" style="width:170px" name="zg_b2b_settings[ef_field_ico]" value="<?php echo esc_attr( $s['ef_field_ico'] ); ?>">
						<p class="description">Názvy polí v API Eurofaktury. Prázdné = neposílat. Pokud API hlásí neznámé pole, upravte zde.</p></td></tr>
					<tr><th scope="row">Položky</th><td><label><input type="checkbox" name="zg_b2b_settings[ef_send_sku]" value="1" <?php checked( 'yes', $s['ef_send_sku'] ); ?>> Posílat i kód produktu (SKU) – jen pokud SKU odpovídají kódům v Eurofaktuře</label>
						<p class="description">Ceny se vždy posílají z objednávky (bez DPH, po slevách), aby částka proformy přesně odpovídala objednávce.</p></td></tr>
					<tr><th scope="row">Po nalezení faktury</th><td><label><input type="checkbox" name="zg_b2b_settings[ef_auto_paid]" value="1" <?php checked( 'yes', $s['ef_auto_paid'] ); ?>> Označit objednávku jako zaplacenou (→ „Zpracovává se“, zákazníkovi odejde e-mail s fakturou v příloze)</label>
						<p class="description">Faktury se dohledávají automaticky každou hodinu (u objednávek do 60 dnů), nebo ručně tlačítkem v detailu objednávky.</p></td></tr>
					<tr><th scope="row">Faktury ze stávajícího napojení</th><td>meta klíč čísla faktury <input type="text" style="width:170px" name="zg_b2b_settings[ef_invoice_meta]" value="<?php echo esc_attr( $s['ef_invoice_meta'] ); ?>"> datum <input type="text" style="width:170px" name="zg_b2b_settings[ef_invoice_date_meta]" value="<?php echo esc_attr( $s['ef_invoice_date_meta'] ); ?>">
						<p class="description">Volitelné: pokud vaše současné API napojení ukládá číslo faktury do meta objednávky, zadejte klíč – faktura půjde stáhnout v Můj účet u všech objednávek.</p></td></tr>
				</table>

				<?php submit_button( 'Uložit nastavení' ); ?>
			</form>

			<h3>Test Eurofaktura API</h3>
			<?php $test = get_transient( 'zg_b2b_ef_test_result' ); if ( false !== $test ) : delete_transient( 'zg_b2b_ef_test_result' ); ?>
				<pre style="max-height:420px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;border-radius:4px;max-width:900px;white-space:pre-wrap"><?php echo esc_html( $test ); ?></pre>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zg_b2b_ef_test">
				<?php wp_nonce_field( 'zg_b2b_ef_test' ); ?>
				<p>Metoda <input type="text" name="zg_ef_test_method" value="SalesQuoteList" style="width:220px">
				parametry (JSON) <input type="text" name="zg_ef_test_params" value="{}" style="width:340px">
				<button class="button">Zavolat API</button></p>
				<p class="description">Ověří přihlášení a ukáže surovou odpověď (užitečné pro kontrolu názvů polí). Podrobný log: WooCommerce → Stav → Logy → <code>zg-b2b-eurofaktura</code>.</p>
			</form>

			<h2>Shortcody pro Bricks</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><td><code>[zg_b2b_registration]</code></td><td>Registrační formulář (stránka registrace).</td></tr>
					<tr><td><code>[zg_b2b_register_button]</code></td><td>Tlačítko „Registrovat firmu“. Parametry: <code>text="…"</code>, <code>variant="primary|outline|light"</code>, <code>size="sm|lg"</code>. Přihlášeným se nezobrazí.</td></tr>
					<tr><td><code>[zg_b2b_price_gate]</code></td><td>Box „Velkoobchodní ceny pro registrované partnery“ s tlačítky – na kartu produktu místo ceny/košíku (jen pro nepřihlášené).</td></tr>
					<tr><td><code>[zg_cz_only_notice]</code></td><td>Upozornění „Doručení pouze v ČR“ na kartě produktu (jen u produktů se štítkem).</td></tr>
					<tr><td><code>[zg_cz_only_badge]</code></td><td>Malý štítek „Pouze ČR“ do výpisu produktů / Query loop.</td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
