<?php
/**
 * Eurofaktura (platforma e-računi) – API klient.
 *
 * Požadavek: POST JSON { username, secretKey, token, method, parameters }.
 * Jeden API uživatel nesmí posílat souběžné požadavky → zámek.
 * Odpovědi se parsují tolerantně (různé verze API vrací data v různém obalu)
 * a vše se loguje do WooCommerce → Stav → Logy (zdroj „zg-b2b-eurofaktura“).
 */

defined( 'ABSPATH' ) || exit;

class ZG_B2B_EF_Client {

	const LOG_SOURCE = 'zg-b2b-eurofaktura';

	/** Známé adresy JSON API Eurofaktury (první funkční se uloží). */
	const ENDPOINTS = array(
		'https://eurofaktura.cz/WebServicesCZ/API', // ověřeno: odpovídá JSON API (i chybou přihlášení)
		'https://eurofaktura.cz/WebServices/API',
	);

	/** Min. odstup požadavků v ms (limit Eurofaktury: průměrně 1 požadavek / s). */
	const MIN_GAP_MS = 1100;

	public static function credentials() {
		$endpoint = defined( 'ZG_EF_ENDPOINT' ) ? ZG_EF_ENDPOINT : (string) zg_b2b_opt( 'ef_endpoint' );
		if ( ! defined( 'ZG_EF_ENDPOINT' ) && 'https://eurofaktura.cz/rcz/WebServices/API' === $endpoint ) {
			$endpoint = self::ENDPOINTS[0]; // chybná výchozí hodnota z verze 1.23.0
		}
		$fix = defined( 'ZG_EF_ENDPOINT' ) ? null : get_option( 'zg_b2b_ef_endpoint_fix' );
		if ( is_array( $fix ) && isset( $fix['from'], $fix['to'] ) && $fix['from'] === $endpoint ) {
			$endpoint = $fix['to'];
		}
		return array_map( 'trim', array(
			'endpoint' => $endpoint,
			'username' => defined( 'ZG_EF_USERNAME' ) ? ZG_EF_USERNAME : (string) zg_b2b_opt( 'ef_username' ),
			'secret'   => defined( 'ZG_EF_SECRET' ) ? ZG_EF_SECRET : (string) zg_b2b_opt( 'ef_secret' ),
			'token'    => defined( 'ZG_EF_TOKEN' ) ? ZG_EF_TOKEN : (string) zg_b2b_opt( 'ef_token' ),
		) );
	}

	public static function configured() {
		$c = self::credentials();
		return $c['endpoint'] && $c['username'] && $c['secret'] && $c['token'];
	}

	public static function log( $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}

	/** Zámek proti souběžným požadavkům jednoho API uživatele. */
	private static function lock() {
		$deadline = microtime( true ) + 25;
		do {
			// add_option je atomické (unikátní klíč) – funguje jako mutex.
			if ( add_option( 'zg_b2b_ef_lock', time(), '', 'no' ) ) {
				return true;
			}
			$since = (int) get_option( 'zg_b2b_ef_lock' );
			if ( $since && time() - $since > 60 ) { // uvízlý zámek
				delete_option( 'zg_b2b_ef_lock' );
				continue;
			}
			usleep( 300000 );
		} while ( microtime( true ) < $deadline );
		return false;
	}

	private static function unlock() {
		delete_option( 'zg_b2b_ef_lock' );
	}

	/** Dodrží limit Eurofaktury (1 požadavek / s) – volá se uvnitř zámku. */
	private static function throttle() {
		$last = (float) get_option( 'zg_b2b_ef_last_call', 0 );
		$wait = $last + self::MIN_GAP_MS / 1000 - microtime( true );
		if ( $wait > 0 && $wait < 5 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}
		update_option( 'zg_b2b_ef_last_call', (string) microtime( true ), false );
	}

	/** Jeden HTTP požadavek (bez zámku). */
	private static function post( $endpoint, array $body, $timeout ) {
		self::throttle();
		return wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
	}

	/** Odpověď vypadá jako JSON API Eurofaktury (ne 404 / HTML stránka)? */
	private static function looks_like_api( $r ) {
		if ( is_wp_error( $r ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		return 404 !== $code && 405 !== $code && is_array( json_decode( (string) wp_remote_retrieve_body( $r ), true ) );
	}

	/**
	 * Zavolá metodu API.
	 *
	 * @return array|WP_Error  Rozbalený výsledek (result), nebo chyba.
	 */
	public static function call( $method, $parameters = array(), $timeout = 30 ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'zg_ef_config', 'Eurofaktura API není nastavené (Bombora → B2B Agent).' );
		}
		$c    = self::credentials();
		$body = array(
			'username'   => $c['username'],
			'secretKey'  => $c['secret'],
			'token'      => $c['token'],
			'method'     => $method,
			'parameters' => (object) $parameters,
		);

		if ( ! self::lock() ) {
			return new WP_Error( 'zg_ef_busy', 'Eurofaktura API je zaneprázdněné (jiný požadavek stále běží).' );
		}
		$r = self::post( $c['endpoint'], $body, $timeout );
		// Špatná adresa API (404 / HTML) → zkusit známé varianty, funkční uložit.
		if ( ! is_wp_error( $r ) && ! self::looks_like_api( $r ) && ! defined( 'ZG_EF_ENDPOINT' ) ) {
			foreach ( self::ENDPOINTS as $alt ) {
				if ( $alt === $c['endpoint'] ) {
					continue;
				}
				$try = self::post( $alt, $body, $timeout );
				if ( self::looks_like_api( $try ) ) {
					$r        = $try;
					// Mimo nastavení (to má sanitizaci celého formuláře): platí, dokud se URL v nastavení nezmění.
					update_option( 'zg_b2b_ef_endpoint_fix', array( 'from' => $c['endpoint'], 'to' => $alt ), false );
					self::log( 'Adresa API nefungovala (' . $c['endpoint'] . '), používá se funkční: ' . $alt, 'warning' );
					break;
				}
			}
		}
		self::unlock();

		if ( is_wp_error( $r ) ) {
			self::log( $method . ' – chyba spojení: ' . $r->get_error_message(), 'error' );
			return new WP_Error( 'zg_ef_http', $r->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $r );
		$raw  = (string) wp_remote_retrieve_body( $r );
		$json = json_decode( $raw, true );

		// Log bez binárních dat (PDF v base64).
		self::log( $method . ' HTTP ' . $code . ' ← ' . self::shorten( $raw ), $code >= 400 ? 'error' : 'debug' );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'zg_ef_parse', 'Neplatná odpověď Eurofaktury (HTTP ' . $code . ').' );
		}

		$data = isset( $json['response'] ) && is_array( $json['response'] ) ? $json['response'] : $json;
		$err  = self::extract_error( $data );
		if ( $err || $code >= 400 ) {
			$msg = $err ? $err : 'HTTP ' . $code;
			self::log( $method . ' – chyba: ' . $msg, 'error' );
			return new WP_Error( 'zg_ef_api', $msg );
		}
		return isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : $data;
	}

	private static function shorten( $raw ) {
		$raw = preg_replace( '/"([A-Za-z0-9+\/=]{400,})"/', '"[base64 ' . '…]"', $raw );
		return strlen( $raw ) > 4000 ? substr( $raw, 0, 4000 ) . '…' : $raw;
	}

	private static function extract_error( $data ) {
		$status = isset( $data['status'] ) && is_string( $data['status'] ) ? strtolower( $data['status'] ) : '';
		if ( in_array( $status, array( 'error', 'failed', 'fail' ), true ) ) {
			foreach ( array( 'description', 'message', 'error', 'errorMessage', 'result' ) as $k ) {
				if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
					return $data[ $k ];
				}
			}
			return 'Neznámá chyba API';
		}
		foreach ( array( 'error', 'errorMessage', 'errors' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				return is_string( $data[ $k ] ) ? $data[ $k ] : wp_json_encode( $data[ $k ] );
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Tolerantní čtení odpovědí
	 * ------------------------------------------------------------------- */

	/** První hodnota klíče (case-insensitive) kdekoli ve struktuře. */
	public static function find( $data, $keys ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$keys = array_map( 'strtolower', (array) $keys );
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), $keys, true ) && ( is_scalar( $v ) && '' !== (string) $v ) ) {
				return $v;
			}
		}
		foreach ( $data as $v ) {
			if ( is_array( $v ) ) {
				$hit = self::find( $v, $keys );
				if ( null !== $hit ) {
					return $hit;
				}
			}
		}
		return null;
	}

	/** Najde v odpovědi PDF (base64 řetězec začínající %PDF). */
	public static function find_pdf( $data ) {
		if ( is_string( $data ) ) {
			if ( strlen( $data ) > 200 ) {
				$bin = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				if ( false !== $bin && 0 === strpos( $bin, '%PDF' ) ) {
					return $bin;
				}
			}
			return null;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $v ) {
				$pdf = self::find_pdf( $v );
				if ( $pdf ) {
					return $pdf;
				}
			}
		}
		return null;
	}

	/** Seznam záznamů z odpovědi typu *List (první pole polí). */
	public static function find_list( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		if ( $is_list && isset( $data[0] ) && is_array( $data[0] ) ) {
			return $data;
		}
		foreach ( $data as $v ) {
			$l = self::find_list( $v );
			if ( $l ) {
				return $l;
			}
		}
		return array();
	}

	/** Prohledá strukturu, zda obsahuje některou z hodnot (např. číslo objednávky/proformy). */
	public static function contains_value( $data, $needles ) {
		$needles = array_filter( array_map( 'strval', (array) $needles ) );
		if ( is_scalar( $data ) ) {
			$s = (string) $data;
			foreach ( $needles as $n ) {
				if ( '' !== $n && ( $s === $n || preg_match( '/(?<![\w\/\-])' . preg_quote( $n, '/' ) . '(?![\w\/\-])/u', $s ) ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $v ) {
				if ( self::contains_value( $v, $needles ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
