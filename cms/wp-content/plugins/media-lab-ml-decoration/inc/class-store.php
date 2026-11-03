<?php
/**
 * Datenzugriff: Lieferanten, Preislisten (Option) und Produktdaten (Custom Field _ml_decoration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Store {

	const OPT_PRICELIST = 'ml_deco_pricelist_';
	const META_PRODUCT  = '_ml_decoration';

	/** @var array<string, array|null> */
	private static array $cache = [];
	/** @var array<string, bool> */
	private static array $checked = [];

	/**
	 * Lieferanten. "code" ist das Kürzel aus dem Sync (Meta _ml_supplier_code).
	 * Per Filter erweiterbar.
	 *
	 * @return array<string, array{label:string, code:string}>
	 */
	public static function suppliers(): array {
		return apply_filters( 'ml_deco_suppliers', [
			'midocean' => [ 'label' => 'MidOcean', 'code' => 'DIM' ],
			'makito'   => [ 'label' => 'Makito',   'code' => 'TKM' ],
		] );
	}

	public static function supplier_key_for_product( int $product_id ): ?string {
		$code = trim( (string) get_post_meta( $product_id, '_ml_supplier_code', true ) );
		if ( $code === '' ) {
			return null;
		}
		foreach ( self::suppliers() as $key => $supplier ) {
			if ( strcasecmp( $code, $supplier['code'] ) === 0 || strcasecmp( $code, $key ) === 0 ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Produktdaten: ['manipulation' => ?string, 'template' => ?string, 'positions' => [...]]
	 */
	public static function product_data( int $product_id ): ?array {
		$raw = get_post_meta( $product_id, self::META_PRODUCT, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			// Falls ein Import-Schritt Backslashes entfernt/ergänzt hat
			$data = json_decode( wp_unslash( $raw ), true );
		}
		return ( is_array( $data ) && ! empty( $data['positions'] ) ) ? $data : null;
	}

	/**
	 * Preisliste eines Lieferanten (normalisiertes Format aus supplier-sync) oder null.
	 * Liest bei Bedarf automatisch die Datei aus feeds/ nach (max. alle 15 Minuten geprüft).
	 */
	public static function pricelist( string $key ): ?array {
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		$stored = get_option( self::OPT_PRICELIST . $key, null );

		if ( empty( self::$checked[ $key ] ) ) {
			self::$checked[ $key ] = true;
			if ( false === get_transient( 'ml_deco_chk_' . $key ) ) {
				set_transient( 'ml_deco_chk_' . $key, 1, 15 * MINUTE_IN_SECONDS );
				$file = self::feed_file( $key );
				if ( is_readable( $file ) ) {
					$mtime = (int) filemtime( $file );
					if ( ! is_array( $stored ) || (int) ( $stored['file_mtime'] ?? 0 ) < $mtime ) {
						if ( self::import_file( $key, $file ) === true ) {
							$stored = get_option( self::OPT_PRICELIST . $key, null );
						}
					}
				}
			}
		}

		self::$cache[ $key ] = ( is_array( $stored ) && ! empty( $stored['data']['techniques'] ) ) ? $stored['data'] : null;
		return self::$cache[ $key ];
	}

	public static function feed_file( string $key ): string {
		$dir = apply_filters( 'ml_deco_feeds_dir', WP_CONTENT_DIR . '/supplier-sync/feeds' );
		return trailingslashit( $dir ) . $key . '_decoration_pricelist.json';
	}

	/** @return true|string true bei Erfolg, sonst Fehlermeldung */
	public static function import_file( string $key, string $path ) {
		$json = file_get_contents( $path );
		if ( $json === false ) {
			return __( 'Datei konnte nicht gelesen werden.', 'media-lab-ml-decoration' );
		}
		return self::import_json( $key, $json, (int) filemtime( $path ), 'file' );
	}

	/** @return true|string true bei Erfolg, sonst Fehlermeldung */
	public static function import_json( string $key, string $json, int $mtime, string $source ) {
		$suppliers = self::suppliers();
		if ( ! isset( $suppliers[ $key ] ) ) {
			return __( 'Unbekannter Lieferant.', 'media-lab-ml-decoration' );
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['techniques'] ) || ! is_array( $data['techniques'] ) ) {
			return __( 'Keine gültige Veredelungs-Preisliste (Schlüssel "techniques" fehlt).', 'media-lab-ml-decoration' );
		}
		$fileSupplier = (string) ( $data['supplier'] ?? '' );
		if ( $fileSupplier !== '' && strcasecmp( $fileSupplier, $suppliers[ $key ]['code'] ) !== 0 ) {
			return sprintf(
				/* translators: 1: Lieferantenkürzel in der Datei, 2: erwartetes Kürzel */
				__( 'Die Datei gehört zu Lieferant "%1$s", erwartet wurde "%2$s".', 'media-lab-ml-decoration' ),
				$fileSupplier,
				$suppliers[ $key ]['code']
			);
		}

		update_option( self::OPT_PRICELIST . $key, [
			'data'       => $data,
			'file_mtime' => $mtime,
			'imported'   => time(),
			'source'     => $source,
		], false );

		unset( self::$cache[ $key ] );
		return true;
	}

	/** Metadaten der gespeicherten Preisliste (ohne die Daten selbst). */
	public static function status( string $key ): array {
		$stored = get_option( self::OPT_PRICELIST . $key, null );
		if ( ! is_array( $stored ) || empty( $stored['data'] ) ) {
			return [];
		}
		return [
			'imported'   => (int) ( $stored['imported'] ?? 0 ),
			'source'     => (string) ( $stored['source'] ?? '' ),
			'valid_from' => (string) ( $stored['data']['valid_from'] ?? '' ),
			'techniques' => count( (array) ( $stored['data']['techniques'] ?? [] ) ),
		];
	}

	/** Anzahl Produkte mit Veredelungsdaten je Lieferant (nur für die Admin-Seite). */
	public static function product_count( string $key ): int {
		global $wpdb;
		$suppliers = self::suppliers();
		if ( ! isset( $suppliers[ $key ] ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} d
			 INNER JOIN {$wpdb->postmeta} s ON s.post_id = d.post_id AND s.meta_key = '_ml_supplier_code'
			 WHERE d.meta_key = %s AND d.meta_value <> '' AND s.meta_value IN (%s, %s)",
			self::META_PRODUCT,
			$suppliers[ $key ]['code'],
			$key
		) );
	}
}
