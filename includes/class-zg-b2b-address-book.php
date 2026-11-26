<?php
/**
 * Storage of saved delivery addresses (user meta).
 *
 * Structure of one address:
 * [
 *   'id'      => 'a1b2c3d4',
 *   'label'   => 'Restaurace Vinohrady',
 *   'default' => true|false,
 *   'fields'  => [ 'first_name' => '', 'last_name' => '', 'company' => '', 'address_1' => '', ... ],
 * ]
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_Address_Book {

	const META        = '_bombora_shipping_addresses';
	const META_SEEDED = '_bombora_shipping_addresses_seeded';

	/** Max. number of addresses per customer. */
	public static function max_addresses() {
		return (int) apply_filters( 'zg_b2b_addr_max_addresses', 30 );
	}

	/**
	 * Address field definitions without the "shipping_" prefix.
	 *
	 * @param string $country Country code used for locale-specific field rules.
	 * @return array
	 */
	public static function field_definitions( $country = '' ) {
		if ( ! $country ) {
			$country = WC()->countries->get_base_country();
		}
		$fields = WC()->countries->get_address_fields( $country, 'shipping_' );
		$out    = array();
		foreach ( $fields as $key => $def ) {
			$out[ substr( $key, 9 ) ] = $def;
		}
		// Telefon na místě doručení (pro dopravce) – samostatné pole jako ostatní.
		if ( ! isset( $out['phone'] ) ) {
			$out['phone'] = array(
				'type'         => 'tel',
				'label'        => __( 'Telefon pro doručení', 'zg-b2b' ),
				'required'     => false,
				'class'        => array( 'form-row-wide' ),
				'validate'     => array( 'phone' ),
				'autocomplete' => 'tel',
				'priority'     => 100,
			);
		}
		return apply_filters( 'zg_b2b_addr_field_definitions', $out, $country );
	}

	/** Field keys that are stored. */
	public static function field_keys() {
		return array_keys( self::field_definitions() );
	}

	/**
	 * All addresses of a user. On first call the address from the WooCommerce
	 * customer profile (registration) is imported as the default one.
	 */
	public static function get_all( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$list = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		if ( empty( $list ) && ! get_user_meta( $user_id, self::META_SEEDED, true ) ) {
			update_user_meta( $user_id, self::META_SEEDED, 1 );
			$seed = self::from_customer_profile( $user_id );
			if ( $seed ) {
				$list = array( $seed );
				update_user_meta( $user_id, self::META, $list );
			}
		}

		// Název pobočky = název firmy v doručovací adrese (i u adres uložených před 1.6.2).
		foreach ( $list as $i => $a ) {
			if ( isset( $a['label'], $a['fields'] ) && is_array( $a['fields'] ) ) {
				$list[ $i ]['fields']['company'] = $a['label'];
			}
		}
		return array_values( $list );
	}

	public static function get( $user_id, $id ) {
		foreach ( self::get_all( $user_id ) as $address ) {
			if ( $address['id'] === $id ) {
				return $address;
			}
		}
		return null;
	}

	public static function get_default( $user_id ) {
		$list = self::get_all( $user_id );
		foreach ( $list as $address ) {
			if ( ! empty( $address['default'] ) ) {
				return $address;
			}
		}
		return $list ? $list[0] : null;
	}

	/**
	 * Insert or update an address.
	 *
	 * @return string|WP_Error Address ID.
	 */
	public static function save( $user_id, array $address ) {
		$list = self::get_all( $user_id );
		$id   = isset( $address['id'] ) ? (string) $address['id'] : '';

		$address = array(
			'id'      => $id ? $id : self::new_id(),
			'label'   => isset( $address['label'] ) ? sanitize_text_field( $address['label'] ) : '',
			'default' => ! empty( $address['default'] ),
			'fields'  => self::sanitize_fields( isset( $address['fields'] ) ? (array) $address['fields'] : array() ),
		);

		if ( '' === $address['label'] ) {
			$address['label'] = self::fallback_label( $address['fields'] );
		}
		// Název pobočky = název firmy v doručovací adrese (štítek pro dopravce / WMS).
		$address['fields']['company'] = $address['label'];

		$found = false;
		foreach ( $list as $i => $existing ) {
			if ( $existing['id'] === $address['id'] ) {
				$list[ $i ] = $address;
				$found      = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( $id ) {
				return new WP_Error( 'zg_b2b_addr_not_found', __( 'Adresa nebyla nalezena.', 'zg-b2b' ) );
			}
			if ( count( $list ) >= self::max_addresses() ) {
				return new WP_Error( 'zg_b2b_addr_limit', __( 'Dosáhli jste maximálního počtu doručovacích adres.', 'zg-b2b' ) );
			}
			if ( empty( $list ) ) {
				$address['default'] = true;
			}
			$list[] = $address;
		}

		if ( $address['default'] ) {
			foreach ( $list as $i => $existing ) {
				$list[ $i ]['default'] = ( $existing['id'] === $address['id'] );
			}
		}

		update_user_meta( $user_id, self::META, array_values( $list ) );
		self::sync_profile( $user_id );
		return $address['id'];
	}

	public static function delete( $user_id, $id ) {
		$list    = self::get_all( $user_id );
		$was_def = false;
		foreach ( $list as $i => $existing ) {
			if ( $existing['id'] === $id ) {
				$was_def = ! empty( $existing['default'] );
				unset( $list[ $i ] );
			}
		}
		$list = array_values( $list );
		if ( $was_def && $list ) {
			$list[0]['default'] = true;
		}
		update_user_meta( $user_id, self::META, $list );
		self::sync_profile( $user_id );
	}

	public static function set_default( $user_id, $id ) {
		$list = self::get_all( $user_id );
		foreach ( $list as $i => $existing ) {
			$list[ $i ]['default'] = ( $existing['id'] === $id );
		}
		update_user_meta( $user_id, self::META, $list );
		self::sync_profile( $user_id );
	}

	/**
	 * Výchozí adresa = doručovací adresa v profilu WooCommerce (shipping_* meta),
	 * aby ji viděly ostatní části (Můj účet → Adresy, Eurofaktura, CZ-only, admin).
	 * Ukládá se po jednotlivých polích.
	 */
	public static function sync_profile( $user_id ) {
		$default = self::get_default( $user_id );
		if ( ! $default ) {
			return;
		}
		self::$syncing = true;
		foreach ( $default['fields'] as $key => $value ) {
			update_user_meta( $user_id, 'shipping_' . $key, $value );
		}
		self::$syncing = false;
	}

	/** Úprava doručovací adresy v Můj účet → Adresy přepíše výchozí adresu adresáře. */
	public static function on_profile_saved( $user_id, $load_address ) {
		if ( 'shipping' !== $load_address || self::$syncing ) {
			return;
		}
		$default = self::get_default( $user_id );
		$fields  = array();
		foreach ( self::field_keys() as $key ) {
			$fields[ $key ] = (string) get_user_meta( $user_id, 'shipping_' . $key, true );
		}
		if ( $default ) {
			$default['fields'] = $fields;
			self::save( $user_id, $default );
		} else {
			self::save( $user_id, array( 'default' => true, 'fields' => $fields ) );
		}
	}

	/** @var bool */
	private static $syncing = false;

	public static function sanitize_fields( array $fields ) {
		$out = array();
		foreach ( self::field_keys() as $key ) {
			$value       = isset( $fields[ $key ] ) ? $fields[ $key ] : '';
			$out[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( ! empty( $out['postcode'] ) && ! empty( $out['country'] ) ) {
			$out['postcode'] = wc_format_postcode( $out['postcode'], $out['country'] );
		}
		return $out;
	}

	/** Label shown when the customer did not enter one. */
	public static function fallback_label( array $fields ) {
		if ( ! empty( $fields['company'] ) ) {
			return $fields['company'];
		}
		$parts = array_filter( array(
			isset( $fields['company'] ) ? $fields['company'] : '',
			isset( $fields['city'] ) ? $fields['city'] : '',
		) );
		return $parts ? implode( ', ', $parts ) : __( 'Doručovací adresa', 'zg-b2b' );
	}

	/** HTML formatted address (one per line). */
	public static function format( array $fields, $with_company = false ) {
		if ( ! $with_company ) {
			unset( $fields['company'] ); // Název pobočky se zobrazuje jako nadpis.
		}
		return WC()->countries->get_formatted_address( $fields );
	}

	/** Single-line address for the checkout picker. */
	public static function format_inline( array $fields ) {
		$parts = array_filter( array(
			isset( $fields['address_1'] ) ? $fields['address_1'] : '',
			trim( ( isset( $fields['postcode'] ) ? $fields['postcode'] : '' ) . ' ' . ( isset( $fields['city'] ) ? $fields['city'] : '' ) ),
			( isset( $fields['country'] ) && WC()->countries->get_base_country() !== $fields['country'] && isset( WC()->countries->countries[ $fields['country'] ] ) )
				? WC()->countries->countries[ $fields['country'] ] : '',
		) );
		return implode( ', ', $parts );
	}

	/**
	 * Is the address (practically) the same place as the billing address?
	 */
	public static function same_as_billing( array $fields, array $billing ) {
		$norm = function ( $v ) {
			$v = remove_accents( (string) $v );
			return strtolower( preg_replace( '/[\s\.,\-]+/', '', $v ) );
		};
		foreach ( array( 'address_1', 'postcode', 'city', 'country' ) as $key ) {
			$a = isset( $fields[ $key ] ) ? $norm( $fields[ $key ] ) : '';
			$b = isset( $billing[ $key ] ) ? $norm( $billing[ $key ] ) : '';
			if ( $a !== $b ) {
				return false;
			}
		}
		return true;
	}

	/** Billing address of a customer as a plain array. */
	public static function billing_fields( $user_id ) {
		$customer = new WC_Customer( $user_id );
		return array(
			'company'   => $customer->get_billing_company(),
			'address_1' => $customer->get_billing_address_1(),
			'postcode'  => $customer->get_billing_postcode(),
			'city'      => $customer->get_billing_city(),
			'country'   => $customer->get_billing_country(),
		);
	}

	private static function from_customer_profile( $user_id ) {
		$customer = new WC_Customer( $user_id );
		if ( ! $customer->get_shipping_address_1() ) {
			return null;
		}
		$fields = array();
		foreach ( self::field_keys() as $key ) {
			$getter         = 'get_shipping_' . $key;
			$fields[ $key ] = is_callable( array( $customer, $getter ) )
				? (string) $customer->$getter()
				: (string) get_user_meta( $user_id, 'shipping_' . $key, true );
		}
		return array(
			'id'      => self::new_id(),
			'label'   => self::fallback_label( $fields ),
			'default' => true,
			'fields'  => $fields,
		);
	}

	private static function new_id() {
		return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 10 );
	}
}
