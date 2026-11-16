<?php
/**
 * Preisberechnung (rein rechnerisch, ohne WordPress-Abhängigkeit).
 *
 * Datenformat siehe supplier-sync: Services/MidOceanDecorations.php und MakitoDecorations.php.
 *
 *   Technik: pricing, next_colour, setup, setup_repeat, min_job, min_qty, min_unit_price,
 *            min_surcharge_below + min_surcharge (Mindermengen-Zuschlag, Cotton: einmal je Position, wenn Menge < Grenze),
 *            on_request (kein Vorab-Preis, Cotton Stick: "Preis auf Anfrage"; ranges ist dann nur ein Platzhalter),
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

	/** Zahl aus einer Eingabe (auch mit Komma als Dezimaltrenner), sonst 0. */
	public static function to_float( $value ): float {
		if ( ! is_scalar( $value ) ) {
			return 0.0;
		}
		$v = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	/** Auf eine Nachkommastelle runden (kaufmännisch, gleiche Formel wie calculator.js). */
	public static function round1( float $v ): float {
		return floor( $v * 10 + 0.5 + 1e-9 ) / 10;
	}

	/** Auf eine Nachkommastelle abrunden (gleiche Formel wie calculator.js). */
	public static function floor1( float $v ): float {
		return floor( $v * 10 + 1e-9 ) / 10;
	}

	/**
	 * Motivmaße (Breite x Höhe in cm) prüfen und die Fläche daraus berechnen. Gleiche Regel wie dims() in calculator.js:
	 * 1. je Seite mindestens 0,5 cm und höchstens das Maß der Position (auf 0,1 abgerundet), Seiten auf 0,1 gerundet;
	 * 2. ist die Fläche größer als die Maximalfläche der Position (z. B. rund), werden beide Seiten im gleichen
	 *    Verhältnis verkleinert (auf 0,1 abgerundet, damit die Maximalfläche nicht überschritten wird);
	 * 3. Fläche = Breite x Höhe der gerundeten Seiten, auf 0,1 gerundet.
	 * Das Ergebnis ist stabil: dieselbe Regel auf das Ergebnis angewandt ändert nichts. Beide Werte sind nötig, sonst null.
	 *
	 * @return array{w:float,h:float,area:float}|null
	 */
	public static function dims( $w, $h, float $max_w, float $max_h, float $max_area = 0.0 ): ?array {
		$w = self::to_float( $w );
		$h = self::to_float( $h );
		if ( $w <= 0 || $h <= 0 || $max_w <= 0 || $max_h <= 0 ) {
			return null;
		}
		$cap_w = max( 0.1, self::floor1( $max_w ) );
		$cap_h = max( 0.1, self::floor1( $max_h ) );
		$w     = min( max( self::round1( $w ), 0.5 ), $cap_w );
		$h     = min( max( self::round1( $h ), 0.5 ), $cap_h );
		if ( $max_area > 0 && $w * $h > $max_area + 1e-9 ) {
			$s = sqrt( $max_area / ( $w * $h ) );
			$w = max( 0.1, self::floor1( $w * $s ) );
			$h = max( 0.1, self::floor1( $h * $s ) );
		}
		return [ 'w' => $w, 'h' => $h, 'area' => self::round1( $w * $h ) ];
	}

	/** Erste Stufe, deren Obergrenze die Fläche abdeckt (Grenze inklusive); darüber die letzte Stufe. */
	public static function range_index_for_area( array $ranges, float $area ): int {
		$ranges = array_values( $ranges );
		foreach ( $ranges as $i => $r ) {
			$to = $r['to'] ?? null;
			if ( $to === null || $area <= (float) $to + 1e-9 ) {
				return (int) $i;
			}
		}
		return max( 0, count( $ranges ) - 1 );
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
