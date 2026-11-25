<?php
/**
 * Admin: show the customer's delivery addresses on the user profile screen
 * (the selected branch is shown on orders via the formatted shipping address).
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Address_Admin {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'user_profile' ), 30 );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_profile' ), 30 );
	}

	public static function user_profile( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$list = ZG_B2B_Address_Book::get_all( $user->ID );
		echo '<h2>' . esc_html__( 'Doručovací adresy', 'zg-b2b' ) . '</h2>';
		if ( ! $list ) {
			echo '<p>' . esc_html__( 'Zatím nemáte uloženou žádnou doručovací adresu.', 'zg-b2b' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Název adresy (např. název pobočky)', 'zg-b2b' ) . '</th>';
		echo '<th>' . esc_html__( 'Doručovací adresa', 'zg-b2b' ) . '</th>';
		echo '<th>' . esc_html__( 'Výchozí', 'zg-b2b' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $list as $address ) {
			echo '<tr><td><strong>' . esc_html( $address['label'] ) . '</strong></td>';
			echo '<td>' . wp_kses_post( ZG_B2B_Address_Book::format( $address['fields'] ) ) . '</td>';
			echo '<td>' . ( ! empty( $address['default'] ) ? '✓' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
