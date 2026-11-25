<?php
/**
 * Schvalování B2B účtů v administraci (Uživatelé).
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Approval {

	public static function statuses() {
		return array(
			'pending'  => 'Čeká na schválení',
			'approved' => 'Schváleno',
			'rejected' => 'Zamítnuto',
		);
	}

	public static function init() {
		add_filter( 'manage_users_columns', array( __CLASS__, 'column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column_content' ), 10, 3 );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'views_users', array( __CLASS__, 'views' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'filter_users' ) );
		add_action( 'admin_post_zg_b2b_set_status', array( __CLASS__, 'handle_action' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ), 5 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu_bubble' ), 999 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_head-users.php', array( __CLASS__, 'admin_css' ) );
	}

	public static function pending_count() {
		$n = get_transient( 'zg_b2b_pending_count' );
		if ( false === $n ) {
			$q = new WP_User_Query(
				array(
					'meta_key'    => 'zg_b2b_status', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'      => 'ID',
					'number'      => 1,
					'count_total' => true,
				)
			);
			$n = (int) $q->get_total();
			set_transient( 'zg_b2b_pending_count', $n, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $n;
	}

	/** Změna stavu + e-mail o schválení. */
	public static function set_status( $user_id, $status ) {
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return;
		}
		$old = get_user_meta( $user_id, 'zg_b2b_status', true );
		update_user_meta( $user_id, 'zg_b2b_status', $status );
		update_user_meta( $user_id, 'zg_b2b_status_changed', time() );
		delete_transient( 'zg_b2b_pending_count' );

		if ( 'approved' === $status && 'approved' !== $old && ! get_user_meta( $user_id, 'zg_b2b_approved_mail', true ) ) {
			zg_b2b_in_language(
				(string) get_user_meta( $user_id, 'zg_b2b_lang', true ),
				function () use ( $user_id ) {
					self::mail_approved( $user_id );
				}
			);
			update_user_meta( $user_id, 'zg_b2b_approved_mail', time() );
		}
		do_action( 'zg_b2b_status_changed', $user_id, $status, $old );
	}

	private static function mail_approved( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$company = get_user_meta( $user_id, 'billing_company', true );
		$html    = '<p>' . sprintf( esc_html__( 'Dobrý den, %s,', 'zg-b2b' ), esc_html( $user->first_name ) ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: firma */
				esc_html__( 'velkoobchodní účet firmy %s je schválený. Po přihlášení uvidíte velkoobchodní ceny a můžete objednávat.', 'zg-b2b' ),
				'<strong>' . esc_html( $company ) . '</strong>'
			) . '</p>'
			. '<p><a href="' . esc_url( zg_b2b_login_url() ) . '" style="display:inline-block;padding:12px 22px;background:#7a1f2b;color:#fff;text-decoration:none;border-radius:999px;font-weight:600">' . esc_html__( 'Přihlásit se do velkoobchodu', 'zg-b2b' ) . '</a></p>'
			. '<p>' . esc_html__( 'Přihlašovací jméno je váš e-mail:', 'zg-b2b' ) . ' <strong>' . esc_html( $user->user_email ) . '</strong></p>'
			. '<p>' . sprintf(
				/* translators: %s: e-mail */
				esc_html__( 'Dotazy: %s', 'zg-b2b' ),
				'<a href="mailto:' . esc_attr( zg_b2b_contact_email() ) . '">' . esc_html( zg_b2b_contact_email() ) . '</a>'
			) . '</p>';
		zg_b2b_send_mail( $user->user_email, __( 'Váš velkoobchodní účet je schválený', 'zg-b2b' ), __( 'Účet schválen', 'zg-b2b' ), $html );
	}

	/* ---------------------------------------------------------------------
	 * Seznam uživatelů
	 * ------------------------------------------------------------------- */

	public static function column( $cols ) {
		$cols['zg_b2b'] = 'B2B účet';
		return $cols;
	}

	private static function badge( $status ) {
		$labels = self::statuses() + array( 'none' => '—' );
		return '<span class="zg-b2b-st zg-b2b-st--' . esc_attr( $status ) . '">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</span>';
	}

	public static function column_content( $out, $col, $user_id ) {
		if ( 'zg_b2b' !== $col ) {
			return $out;
		}
		$status = zg_b2b_user_status( $user_id );
		if ( 'none' === $status && ! get_user_meta( $user_id, 'billing_company', true ) ) {
			return '—';
		}
		$out  = self::badge( $status );
		$co   = get_user_meta( $user_id, 'billing_company', true );
		$ico  = get_user_meta( $user_id, zg_b2b_opt( 'ico_meta' ), true );
		$ares = get_user_meta( $user_id, 'zg_b2b_ares', true );
		$vies = get_user_meta( $user_id, 'zg_b2b_vies', true );
		if ( $co ) {
			$out .= '<br><strong>' . esc_html( $co ) . '</strong>';
		}
		if ( $ico ) {
			$out .= '<br>IČO ' . esc_html( $ico );
		}
		$flags = array();
		if ( $ares && 'na' !== $ares ) {
			$flags[] = ZG_B2B_Registration::flag_label( 'ares', $ares );
		}
		if ( $vies && 'na' !== $vies ) {
			$flags[] = ZG_B2B_Registration::flag_label( 'vies', $vies );
		}
		if ( $flags ) {
			$out .= '<br><small>' . esc_html( implode( ' · ', $flags ) ) . '</small>';
		}
		return $out;
	}

	private static function action_url( $user_id, $status ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=zg_b2b_set_status&user=' . (int) $user_id . '&status=' . $status ),
			'zg_b2b_status_' . (int) $user_id
		);
	}

	public static function row_actions( $actions, $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) || user_can( $user, 'manage_woocommerce' ) ) {
			return $actions;
		}
		$status = zg_b2b_user_status( $user->ID );
		if ( 'approved' !== $status ) {
			$actions['zg_b2b_approve'] = '<a style="color:#008a20;font-weight:600" href="' . esc_url( self::action_url( $user->ID, 'approved' ) ) . '">Schválit B2B</a>';
		}
		if ( 'pending' === $status || 'approved' === $status ) {
			$actions['zg_b2b_reject'] = '<a style="color:#b32d2e" href="' . esc_url( self::action_url( $user->ID, 'rejected' ) ) . '" onclick="return confirm(\'Opravdu zamítnout / zablokovat B2B účet?\')">Zamítnout</a>';
		}
		return $actions;
	}

	public static function handle_action() {
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		check_admin_referer( 'zg_b2b_status_' . $user_id );
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		self::set_status( $user_id, $status );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'users.php' );
		wp_safe_redirect( add_query_arg( 'zg_b2b_done', $status, remove_query_arg( 'zg_b2b_done', $back ) ) );
		exit;
	}

	public static function views( $views ) {
		$n       = self::pending_count();
		$current = isset( $_GET['zg_b2b_status'] ) && 'pending' === $_GET['zg_b2b_status']; // phpcs:ignore WordPress.Security.NonceVerification
		$views['zg_b2b_pending'] = '<a href="' . esc_url( admin_url( 'users.php?zg_b2b_status=pending' ) ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>Čeká na schválení B2B <span class="count">(' . (int) $n . ')</span></a>';
		return $views;
	}

	public static function filter_users( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'users.php' !== $pagenow || empty( $_GET['zg_b2b_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['zg_b2b_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( self::statuses()[ $status ] ) ) {
			$query->set( 'meta_key', 'zg_b2b_status' );
			$query->set( 'meta_value', $status );
		}
	}

	public static function menu_bubble() {
		global $menu;
		$n = self::pending_count();
		if ( ! $n || ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $k => $item ) {
			if ( isset( $item[2] ) && 'users.php' === $item[2] ) {
				$menu[ $k ][0] .= ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . (int) $n . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				break;
			}
		}
	}

	public static function notices() {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}
		if ( isset( $_GET['zg_b2b_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$done = sanitize_key( wp_unslash( $_GET['zg_b2b_done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$msg  = 'approved' === $done ? 'B2B účet byl schválen a zákazníkovi odešel e-mail.' : 'Stav B2B účtu byl změněn.';
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		$screen = get_current_screen();
		$n      = self::pending_count();
		if ( $n && $screen && in_array( $screen->id, array( 'dashboard', 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			echo '<div class="notice notice-warning"><p><strong>Velkoobchod:</strong> ' . (int) $n . ' nových registrací čeká na schválení. <a href="' . esc_url( admin_url( 'users.php?zg_b2b_status=pending' ) ) . '">Zobrazit</a></p></div>';
		}
	}

	public static function admin_css() {
		echo '<style>.column-zg_b2b{width:200px}.zg-b2b-st{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e}.zg-b2b-st--pending{background:#fcf0d0;color:#8a5a00}.zg-b2b-st--approved{background:#d7f5dd;color:#0a6b1f}.zg-b2b-st--rejected{background:#fbe0e0;color:#a10e0e}</style>';
	}

	/* ---------------------------------------------------------------------
	 * Profil uživatele
	 * ------------------------------------------------------------------- */

	public static function profile( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) || ! current_user_can( 'list_users' ) ) {
			return;
		}
		$status = zg_b2b_user_status( $user->ID );
		$types  = array(
			'horeca' => 'Restaurace, bar, hotel (HoReCa)',
			'retail' => 'Vinotéka, prodejna, e-shop',
			'gifts'  => 'Firemní dárky / vlastní spotřeba',
			'other'  => 'Jiné',
		);
		$bt     = get_user_meta( $user->ID, 'zg_b2b_business_type', true );
		$reg    = (int) get_user_meta( $user->ID, 'zg_b2b_registered_at', true );
		wp_nonce_field( 'zg_b2b_profile', 'zg_b2b_profile_nonce' );
		?>
		<h2>Velkoobchodní účet (B2B)</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="zg_b2b_status">Stav účtu</label></th>
				<td>
					<select name="zg_b2b_status" id="zg_b2b_status">
						<?php if ( 'none' === $status ) : ?><option value="">— bez stavu —</option><?php endif; ?>
						<?php foreach ( self::statuses() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Při změně na „Schváleno“ odejde zákazníkovi e-mail (jen poprvé).</p>
				</td>
			</tr>
			<tr>
				<th>Platba na fakturu</th>
				<td><label><input type="checkbox" name="zg_b2b_invoice_allowed" value="yes" <?php checked( 'yes', get_user_meta( $user->ID, 'zg_b2b_invoice_allowed', true ) ); ?>> Povolit (uplatní se, pokud je v nastavení platby zvoleno „Jen partnerům s povolenou platbou“)</label></td>
			</tr>
			<tr><th>IČO / DIČ</th><td><?php echo esc_html( get_user_meta( $user->ID, zg_b2b_opt( 'ico_meta' ), true ) ); ?> / <?php echo esc_html( get_user_meta( $user->ID, zg_b2b_opt( 'dic_meta' ), true ) ); ?></td></tr>
			<tr><th>Ověření</th><td>
				<?php echo esc_html( ZG_B2B_Registration::flag_label( 'ares', get_user_meta( $user->ID, 'zg_b2b_ares', true ) ) ); ?>
				<?php $an = get_user_meta( $user->ID, 'zg_b2b_ares_name', true ); echo $an ? ' – ' . esc_html( $an ) : ''; ?>
				<br><?php echo esc_html( ZG_B2B_Registration::flag_label( 'vies', get_user_meta( $user->ID, 'zg_b2b_vies', true ) ) ); ?>
			</td></tr>
			<tr><th>Typ podnikání</th><td><?php echo esc_html( isset( $types[ $bt ] ) ? $types[ $bt ] : '—' ); ?></td></tr>
			<tr><th>Poznámka z registrace</th><td><?php echo nl2br( esc_html( get_user_meta( $user->ID, 'zg_b2b_note', true ) ) ); ?></td></tr>
			<tr><th>Registrováno</th><td><?php echo $reg ? esc_html( wp_date( 'j. n. Y H:i', $reg ) ) : '—'; ?></td></tr>
		</table>
		<p class="description">Fakturační a doručovací adresa je níže v sekcích WooCommerce.</p>
		<?php
	}

	public static function save_profile( $user_id ) {
		if ( ! isset( $_POST['zg_b2b_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zg_b2b_profile_nonce'] ) ), 'zg_b2b_profile' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'list_users' ) ) {
			return;
		}
		update_user_meta( $user_id, 'zg_b2b_invoice_allowed', isset( $_POST['zg_b2b_invoice_allowed'] ) ? 'yes' : 'no' );
		$new = isset( $_POST['zg_b2b_status'] ) ? sanitize_key( wp_unslash( $_POST['zg_b2b_status'] ) ) : '';
		if ( $new && $new !== zg_b2b_user_status( $user_id ) ) {
			self::set_status( $user_id, $new );
		}
	}
}
