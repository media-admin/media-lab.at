<?php
/**
 * Auswahl + Preisrechner: bereitet die Daten für assets/js/calculator.js auf.
 *
 * Sicherheit: Der Faktor des Lieferanten wird HIER auf alle Preise gerechnet. Der Browser bekommt nur
 * Verkaufspreise. Ist "Preise anzeigen" aus, enthält die Ausgabe gar keine Preisfelder.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Calculator {

	/** Rundet auf 4 Stellen (Faktor ist bereits eingerechnet). */
	private static function r( $value, float $factor ): ?float {
		return $value === null ? null : round( (float) $value * $factor, 4 );
	}

	public static function markup( array $data, array $pricelist, bool $show, float $factor, float $handling, int $product_id = 0 ): string {
		$payload = self::payload( $data, $pricelist, $show, $factor, $handling );
		if ( empty( $payload['positions'] ) ) {
			return '';
		}
		$payload['productId'] = $product_id;
		// Farbwert -> Farbcode der Varianten (Positionsbilder je Farbe), nur wenn ein Produkt Farblisten hat
		$has_colors = false;
		foreach ( $payload['positions'] as $pos ) {
			if ( ! empty( $pos['colors'] ) ) {
				$has_colors = true;
				break;
			}
		}
		if ( $has_colors && $product_id && class_exists( 'ML_Deco_Frontend' ) ) {
			$map = ML_Deco_Frontend::color_map( $product_id );
			if ( $map ) {
				$payload['colorMap']  = (object) $map;
				$payload['colorAttr'] = 'attribute_' . ML_Deco_Frontend::color_attribute();
			}
		}

		$json = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$qty  = (string) apply_filters( 'ml_deco_qty_selector', '.mlw-wishlist-qty__input, .configurator-number__input[x-model\\.number*="quantity"], form.cart input.qty, input[name="quantity"]' );

		return '<section class="mld-calc" data-qty-selector="' . esc_attr( $qty ) . '">'
			. '<h3 class="mld__h">' . esc_html__( 'Veredelung wählen', 'media-lab-ml-decoration' ) . '</h3>'
			. '<div class="mld-calc__ui"></div>'
			. '<noscript><p class="mld__note">' . esc_html__( 'Für die Auswahl wird JavaScript benötigt. Die Möglichkeiten finden Sie unten im Überblick.', 'media-lab-ml-decoration' ) . '</p></noscript>'
			. '<script type="application/json" class="mld-calc__data">' . $json . '</script>'
			. '</section>';
	}

	public static function payload( array $data, array $pricelist, bool $show, float $factor, float $handling ): array {
		$techs     = [];
		$positions = [];

		foreach ( (array) ( $data['positions'] ?? [] ) as $position ) {
			$entries = [];
			foreach ( (array) ( $position['techniques'] ?? [] ) as $pt ) {
				$id   = (string) ( $pt['id'] ?? '' );
				$tech = $pricelist['techniques'][ $id ] ?? null;
				if ( ! is_array( $tech ) ) {
					continue;
				}
				if ( ! isset( $techs[ $id ] ) ) {
					$techs[ $id ] = self::technique_payload( $tech, $show, $factor );
				}
				$entries[] = [ 'id' => $id, 'maxColours' => (int) ( $pt['max_colours'] ?? 0 ) ];
			}
			if ( ! $entries ) {
				continue;
			}
			$positions[] = [
				'id'         => (string) ( $position['id'] ?? '' ),
				'label'      => ML_Deco_Render::position_label( $position ),
				'size'       => ML_Deco_Render::size_label( $position ),
				'maxArea'    => isset( $position['max_area'] ) ? (float) $position['max_area'] : 0.0,
				'image'      => (string) ( $position['image'] ?? '' ),
				// Farbcodes mit eigenem Positionsbild (MidOcean): das Bild einer Farbe ergibt sich aus dem Standardbild
				'colors'     => array_values( array_map( 'strval', (array) ( $position['colors'] ?? [] ) ) ),
				'techniques' => $entries,
			];
		}

		// Einheitliche Grundfarbe der Positionsbilder: Die Lieferantendaten listen die Farben je Position in anderer
		// Reihenfolge, das erste Bild jeder Position hätte sonst je Position eine andere Produktfarbe (mal schwarz, mal weiß).
		// Gewählt wird die erste Farbe (nach der Reihenfolge der ersten Position), zu der JEDE Position mit Farbliste ein Bild hat.
		$common = null;
		foreach ( $positions as $p ) {
			if ( count( $p['colors'] ) > 1 ) {
				$common = $common === null ? $p['colors'] : array_values( array_intersect( $common, $p['colors'] ) );
			}
		}

		return [
			'showPrices'   => $show,
			'format'       => self::price_format(),
			'handling'     => $show ? round( $handling * $factor, 4 ) : 0,
			'techniques'   => (object) $techs,
			'positions'    => $positions,
			'defaultColor' => $common ? (string) $common[0] : '',
			'i18n'         => self::strings( $show ),
		];
	}

	// ── Berechnung (Spiegel von assets/js/calculator.js, arbeitet auf dem Payload) ──────────────

	/** Passende Mengenstaffel, oder null (über der letzten Makito-Staffel: Preis auf Anfrage). */
	public static function find_scale( array $range, int $qty ): ?array {
		$scales = array_values( (array) ( $range['scales'] ?? [] ) );
		$found  = null;
		foreach ( $scales as $s ) {
			if ( $qty >= (int) ( $s['qty'] ?? 1 ) ) {
				$found = $s;
			}
		}
		if ( $found === null ) {
			$found = $scales[0] ?? null;
		}
		if ( $found === null ) {
			return null;
		}
		$last = $scales[ count( $scales ) - 1 ];
		if ( $found === $last && isset( $last['upto'] ) && $last['upto'] !== null && $qty > (int) $last['upto'] ) {
			return null;
		}
		return $found;
	}

	public static function unit_price( array $tech, array $scale, int $colours, float $area ): float {
		$price = (float) ( $scale['price'] ?? 0 );
		$unit  = $price;
		if ( array_key_exists( 'cm', $scale ) ) {
			$unit = $price + (float) $scale['cm'] * $area;
		} elseif ( ( $tech['pricing'] ?? '' ) === 'area' ) {
			$unit = $price * $area;
		}
		if ( $colours > 1 && ! empty( $scale['next'] ) ) {
			$unit += (float) $scale['next'] * ( $colours - 1 );
		}
		$min = (float) ( $tech['minUnit'] ?? 0 );
		if ( $min > 0 ) {
			$unit = max( $unit, $min );
		}
		return $unit;
	}

	/**
	 * Eine Linie (Position + Technik) berechnen. Werte sind bereits mit dem Faktor verrechnet.
	 *
	 * @return array{onRequest:bool, unit:float, setup:float, running:float, total:float, minJob:bool, minQty:int}
	 */
	public static function compute_line( array $tech, array $range, int $qty, int $colours, float $area, bool $repeat, float $handling ): array {
		$res   = [ 'onRequest' => false, 'unit' => 0.0, 'setup' => 0.0, 'running' => 0.0, 'total' => 0.0, 'minJob' => false, 'minQty' => 0 ];
		$scale = self::find_scale( $range, $qty );
		if ( $scale === null ) {
			$res['onRequest'] = true;
			return $res;
		}

		$res['unit']    = self::unit_price( $tech, $scale, $colours, $area ) + $handling;
		$res['running'] = $qty * $res['unit'];

		// Mindestauftragswert (Makito-Tarif): gilt je Farbe. Jede Farbe kostet mindestens minJob,
		// die erste Farbe mit dem Preis, jede weitere mit dem Preis "weitere Farbe".
		$minJob = (float) ( $tech['minJob'] ?? 0 );
		if ( $minJob > 0 ) {
			if ( $colours > 1 && ! empty( $scale['next'] ) ) {
				$first   = $qty * ( self::unit_price( $tech, $scale, 1, $area ) + $handling );
				$more    = $qty * (float) $scale['next'];
				$running = max( $first, $minJob ) + ( $colours - 1 ) * max( $more, $minJob );
			} else {
				$running = max( $res['running'], $minJob );
			}
			if ( $running > $res['running'] + 1e-9 ) {
				$res['minJob']  = true;
				$res['running'] = $running;
			}
		}

		// Klischee/Einrichtung: bei Techniken mit "je Farbe" (Makito-Tarif "CLICHÉ X COLOR") für jede Farbe
		$res['setup'] = $repeat ? (float) ( $tech['setupRepeat'] ?? 0 ) : (float) ( $tech['setup'] ?? 0 );
		if ( ! empty( $tech['setupPerColour'] ) && $colours > 1 ) {
			$res['setup'] *= $colours;
		}
		$res['total'] = round( $res['running'], 2 ) + round( $res['setup'], 2 );

		if ( ! empty( $tech['minQty'] ) && $qty < (int) $tech['minQty'] ) {
			$res['minQty'] = (int) $tech['minQty'];
		}
		return $res;
	}

	/**
	 * Darstellung der Beträge wie im restlichen Shop (WooCommerce-Einstellungen):
	 * Währungssymbol, Position, Dezimal- und Tausendertrenner.
	 */
	private static function price_format(): array {
		$decode = static fn( string $v ): string => html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return [
			'pattern'  => function_exists( 'get_woocommerce_price_format' ) ? $decode( (string) get_woocommerce_price_format() ) : "%2\$s\u{00A0}%1\$s",
			'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? $decode( (string) get_woocommerce_currency_symbol() ) : '€',
			'decimal'  => function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : ',',
			'thousand' => function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : '.',
		];
	}

	private static function technique_payload( array $tech, bool $show, float $factor ): array {
		$payload = [
			'name'       => ML_Deco_Render::technique_name( $tech ),
			'fullColour' => ML_Deco_Render::is_full_colour( ML_Deco_Render::technique_name( $tech ) ),
			'pricing'    => (string) ( $tech['pricing'] ?? '' ),
		];
		if ( ! $show ) {
			return $payload;
		}

		$ranges = [];
		foreach ( (array) ( $tech['ranges'] ?? [] ) as $range ) {
			$scales = [];
			foreach ( (array) ( $range['scales'] ?? [] ) as $s ) {
				$scale = [ 'qty' => (int) ( $s['qty'] ?? 1 ), 'price' => self::r( $s['price'] ?? 0, $factor ) ];
				if ( array_key_exists( 'upto', $s ) ) {
					$scale['upto'] = $s['upto'] === null ? null : (int) $s['upto'];
				}
				if ( array_key_exists( 'next', $s ) ) {
					$scale['next'] = self::r( $s['next'], $factor );
				}
				if ( array_key_exists( 'cm', $s ) ) {
					$scale['cm'] = self::r( $s['cm'], $factor );   // Schlüssel muss erhalten bleiben (Makito-Formel)
				}
				$scales[] = $scale;
			}
			$ranges[] = [
				'from'   => isset( $range['from'] ) ? (float) $range['from'] : null,
				'to'     => isset( $range['to'] ) ? (float) $range['to'] : null,
				'scales' => $scales,
			];
		}

		return $payload + [
			'nextColour' => ! empty( $tech['next_colour'] ),
			'setupPerColour' => ! empty( $tech['setup_per_colour'] ),
			'setup'      => self::r( $tech['setup'] ?? 0, $factor ),
			'setupRepeat'=> self::r( $tech['setup_repeat'] ?? 0, $factor ),
			'minJob'     => self::r( $tech['min_job'] ?? 0, $factor ),
			'minQty'     => ! empty( $tech['min_qty'] ) ? (int) $tech['min_qty'] : 0,
			'minUnit'    => self::r( $tech['min_unit_price'] ?? 0, $factor ),
			'ranges'     => $ranges,
		];
	}

	public static function payload_strings(): array {
		return self::strings( true );
	}

	private static function strings( bool $show ): array {
		return [
			'hint'        => $show
				? __( 'Wählen Sie je Position eine Technik. Der Preis ist eine Schätzung (netto).', 'media-lab-ml-decoration' )
				: __( 'Wählen Sie je Position eine Technik. Die Preise erhalten Sie mit Ihrer Anfrage.', 'media-lab-ml-decoration' ),
			'none'        => __( '– keine Veredelung –', 'media-lab-ml-decoration' ),
			'technique'   => __( 'Technik', 'media-lab-ml-decoration' ),
			'colours'     => __( 'Farben', 'media-lab-ml-decoration' ),
			'motifSize'   => __( 'Motivgröße', 'media-lab-ml-decoration' ),
			'motifArea'   => __( 'Motivfläche (cm²)', 'media-lab-ml-decoration' ),
			'quantity'    => __( 'Menge (Stück)', 'media-lab-ml-decoration' ),
			'repeat'      => __( 'Wiederholungsauftrag (Einrichtung günstiger)', 'media-lab-ml-decoration' ),
			'colourOne'   => __( '1 Farbe', 'media-lab-ml-decoration' ),
			'colourMany'  => __( '%d Farben', 'media-lab-ml-decoration' ),
			'areaTo'      => __( 'bis %s cm²', 'media-lab-ml-decoration' ),
			'areaBetween' => __( '%1$s bis %2$s cm²', 'media-lab-ml-decoration' ),
			'areaFrom'    => __( 'ab %s cm²', 'media-lab-ml-decoration' ),
			'pick'        => __( 'Noch keine Veredelung gewählt.', 'media-lab-ml-decoration' ),
			'selection'   => __( 'Ihre Auswahl', 'media-lab-ml-decoration' ),
			'colPos'      => __( 'Position / Technik', 'media-lab-ml-decoration' ),
			'colUnit'     => __( 'Pro Stück', 'media-lab-ml-decoration' ),
			'colSetup'    => __( 'Einrichtung', 'media-lab-ml-decoration' ),
			'colTotal'    => __( 'Summe', 'media-lab-ml-decoration' ),
			'onRequest'   => __( 'Preis auf Anfrage', 'media-lab-ml-decoration' ),
			'total'       => __( 'Veredelung gesamt (netto, ca.)', 'media-lab-ml-decoration' ),
			'average'     => __( 'Durchschnitt pro Stück: %s', 'media-lab-ml-decoration' ),
			'minQty'      => __( 'Mindestmenge für diese Technik: %s Stück.', 'media-lab-ml-decoration' ),
			'minJob'      => __( 'Mindestauftragswert angewendet.', 'media-lab-ml-decoration' ),
			'maxArea'     => __( 'Preis bei Motivfläche %s cm².', 'media-lab-ml-decoration' ),
			'sendNote'    => __( 'Ihre Auswahl wird mit Ihrer Anfrage übermittelt.', 'media-lab-ml-decoration' ),
			'zoomImage'   => __( 'Druckposition „%s“ vergrößern', 'media-lab-ml-decoration' ),
			'resetPos'    => __( 'Zurücksetzen', 'media-lab-ml-decoration' ),
			'resetPosFor' => __( 'Veredelung für „%s“ zurücksetzen', 'media-lab-ml-decoration' ),
			'resetAll'    => __( 'Auswahl zurücksetzen', 'media-lab-ml-decoration' ),
		];
	}
}
