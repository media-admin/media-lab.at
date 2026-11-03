<?php
/**
 * Preisberechnung (rein rechnerisch, ohne WordPress-Abhängigkeit).
 *
 * Datenformat siehe supplier-sync: Services/MidOceanDecorations.php und MakitoDecorations.php.
 *
 *   Technik: pricing, next_colour, setup, setup_repeat, min_job, min_qty, min_unit_price,
 *            ranges[ {from, to, scales[ {qty, upto, price, next, cm} ]} ]
 *
 * Stückpreis:
 *   - positions / colours / area_range / colour_area_range:  price + next * (Farben - 1)
 *   - Makito (Staffel hat Schlüssel "cm"):                   price + cm * Fläche_cm2 (+ next * (Farben - 1))
 *   - MidOcean "area" (Staffel ohne "cm"):                   price * Fläche_cm2
 *   Danach mindestens min_unit_price.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Calc {

	public static function unit_price( array $tech, array $scale, int $colours = 1, float $area_cm2 = 0.0 ): float {
		$price = (float) ( $scale['price'] ?? 0 );
		$unit  = $price;

		if ( array_key_exists( 'cm', $scale ) ) {
			$unit = $price + (float) $scale['cm'] * $area_cm2;       // Makito
		} elseif ( ( $tech['pricing'] ?? '' ) === 'area' ) {
			$unit = $price * $area_cm2;                               // MidOcean "Area"
		}

		if ( $colours > 1 && ! empty( $scale['next'] ) ) {
			$unit += (float) $scale['next'] * ( $colours - 1 );
		}

		$min = (float) ( $tech['min_unit_price'] ?? 0 );
		if ( $min > 0 ) {
			$unit = max( $unit, $min );
		}
		return $unit;
	}

	/**
	 * Bei Flächenstufen nur die Stufen, die auf dieser Position überhaupt erreichbar sind
	 * (Untergrenze der Stufe liegt unter der maximalen Druckfläche).
	 */
	public static function relevant_ranges( array $tech, ?float $max_area ): array {
		$ranges = array_values( (array) ( $tech['ranges'] ?? [] ) );
		if ( ! in_array( $tech['pricing'] ?? '', [ 'area_range', 'colour_area_range' ], true ) || ! $max_area ) {
			return $ranges;
		}
		$out = array_values( array_filter( $ranges, static function ( $r ) use ( $max_area ) {
			return ! isset( $r['from'] ) || (float) $r['from'] < $max_area;
		} ) );
		return $out ?: array_slice( $ranges, 0, 1 );
	}

	/**
	 * Zeilen für die Preistabelle einer Flächenstufe.
	 *
	 * @return array<int, array{from:int, to:?int, unit:float, next:?float}>
	 */
	public static function rows( array $tech, array $range, float $area_cm2, float $handling = 0.0 ): array {
		$scales = array_values( (array) ( $range['scales'] ?? [] ) );
		$rows   = [];
		foreach ( $scales as $i => $scale ) {
			$from = (int) ( $scale['qty'] ?? 1 );
			$to   = isset( $scale['upto'] ) ? (int) $scale['upto'] : null;
			if ( $to === null && isset( $scales[ $i + 1 ] ) ) {
				$to = (int) $scales[ $i + 1 ]['qty'] - 1;
			}
			$rows[] = [
				'from' => $from,
				'to'   => $to,
				'unit' => self::unit_price( $tech, $scale, 1, $area_cm2 ) + $handling,
				'next' => ! empty( $scale['next'] ) ? (float) $scale['next'] : null,
			];
		}
		return $rows;
	}

	/**
	 * Fasst aufeinanderfolgende Mengenstufen mit gleichem (angezeigtem) Preis zusammen,
	 * z. B. 2.500 / 5.000 / 10.000 / 20.000 zu "ab 2.500". Verglichen wird nach Anwendung des Faktors.
	 *
	 * @param array<int, array{from:int, to:?int, unit:float, next:?float}> $rows
	 * @return array<int, array{from:int, to:?int, unit:float, next:?float}>
	 */
	public static function merge_rows( array $rows, float $factor = 1.0 ): array {
		$same = static fn( ?float $a, ?float $b ): bool => abs( round( (float) $a * $factor, 3 ) - round( (float) $b * $factor, 3 ) ) < 0.0005;

		$out = [];
		foreach ( $rows as $row ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && $same( $out[ $last ]['unit'], $row['unit'] ) && $same( $out[ $last ]['next'], $row['next'] ) ) {
				$out[ $last ]['to'] = $row['to'];
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/** Handling-Zuschlag pro Stück (MidOcean: print_manipulation A-E, Z). */
	public static function handling( array $pricelist, ?string $code ): float {
		if ( ! $code ) {
			return 0.0;
		}
		return (float) ( $pricelist['manipulations'][ $code ]['price'] ?? 0 );
	}

	public static function range_label( array $range ): string {
		$from = isset( $range['from'] ) ? (float) $range['from'] : null;
		$to   = isset( $range['to'] ) ? (float) $range['to'] : null;
		$fmt  = static fn( float $v ): string => number_format_i18n( $v, floor( $v ) === $v ? 0 : 1 );

		if ( $to !== null && ( $from === null || $from <= 0 ) ) {
			return sprintf( __( 'Druckfläche bis %s cm²', 'media-lab-ml-decoration' ), $fmt( $to ) );
		}
		if ( $to !== null ) {
			return sprintf( __( 'Druckfläche %1$s bis %2$s cm²', 'media-lab-ml-decoration' ), $fmt( (float) $from ), $fmt( $to ) );
		}
		if ( $from !== null && $from > 0 ) {
			return sprintf( __( 'Druckfläche ab %s cm²', 'media-lab-ml-decoration' ), $fmt( $from ) );
		}
		return '';
	}
}
