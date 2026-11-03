<?php
/**
 * HTML-Ausgabe des Veredelungs-Tabs.
 *
 * Preise erscheinen NUR, wenn der Schalter des Lieferanten aktiv ist. Bei "aus" werden
 * Einkaufspreise gar nicht erst in den HTML-Code geschrieben (kein CSS-Verstecken).
 * Alle Preise = Einkaufspreis x Faktor des Lieferanten.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Render {

	/** Bekannte MidOcean-Positionsbegriffe (Rest bleibt unverändert, nur Schreibweise). */
	private const POSITION_TOKENS = [
		'FRONT'  => 'Vorderseite',
		'BACK'   => 'Rückseite',
		'SIDE'   => 'Seite',
		'TOP'    => 'Oben',
		'BOTTOM' => 'Unten',
		'LEFT'   => 'Links',
		'RIGHT'  => 'Rechts',
		'CHEST'  => 'Brust',
		'ARM'    => 'Ärmel',
		'LID'    => 'Deckel',
		'INSIDE' => 'Innenseite',
		'POCKET' => 'Tasche',
		'UPPER'  => 'oben',
		'LOWER'  => 'unten',
		'360'    => '360°',
	];

	/** Gibt es für dieses Produkt etwas anzuzeigen? */
	public static function available( int $product_id ): bool {
		if ( ! ML_Deco_Settings::enabled() ) {
			return false;
		}
		$key = ML_Deco_Store::supplier_key_for_product( $product_id );
		return $key !== null
			&& ML_Deco_Store::product_data( $product_id ) !== null
			&& ML_Deco_Store::pricelist( $key ) !== null;
	}

	public static function render( int $product_id ): string {
		if ( ! self::available( $product_id ) ) {
			return '';
		}

		$key       = (string) ML_Deco_Store::supplier_key_for_product( $product_id );
		$data      = (array) ML_Deco_Store::product_data( $product_id );
		$pricelist = (array) ML_Deco_Store::pricelist( $key );
		$show      = ML_Deco_Settings::show_prices( $key );
		$factor    = ML_Deco_Settings::factor( $key );

		$handling = 0.0;
		if ( $show && apply_filters( 'ml_deco_include_handling', true, $product_id, $key ) ) {
			$handling = ML_Deco_Calc::handling( $pricelist, $data['manipulation'] ?? null );
		}

		ob_start();
		echo '<div class="mld">';

		$printed = 0;
		foreach ( (array) $data['positions'] as $position ) {
			$techs = [];
			foreach ( (array) ( $position['techniques'] ?? [] ) as $pt ) {
				$tech = $pricelist['techniques'][ $pt['id'] ?? '' ] ?? null;
				if ( is_array( $tech ) ) {
					$techs[] = [ 'tech' => $tech, 'max_colours' => (int) ( $pt['max_colours'] ?? 0 ) ];
				}
			}
			if ( ! $techs ) {
				continue;
			}
			self::render_position( $position, $techs, $show, $factor, $handling );
			$printed++;
		}

		if ( $printed === 0 ) {
			ob_end_clean();
			return '';
		}

		$note = $show ? ML_Deco_Settings::price_note() : ML_Deco_Settings::noprice_note();
		if ( $note !== '' ) {
			echo '<p class="mld__note">' . esc_html( $note ) . '</p>';
		}

		echo '</div>';
		return (string) ob_get_clean();
	}

	private static function render_position( array $position, array $techs, bool $show, float $factor, float $handling ): void {
		$label = self::position_label( $position );
		$size  = self::size_label( $position );
		$area  = isset( $position['max_area'] ) ? (float) $position['max_area'] : 0.0;

		echo '<section class="mld__position">';
		echo '<div class="mld__pos-head">';
		if ( ! empty( $position['image'] ) ) {
			echo '<img class="mld__img" loading="lazy" src="' . esc_url( $position['image'] ) . '" alt="' . esc_attr( $label ) . '">';
		}
		echo '<div>';
		echo '<h4 class="mld__pos-title">' . esc_html( $label ) . '</h4>';
		if ( $size !== '' ) {
			echo '<p class="mld__meta">' . esc_html( sprintf( __( 'Max. Druckfläche: %s', 'media-lab-ml-decoration' ), $size ) ) . '</p>';
		}
		echo '</div></div>';

		echo '<div class="mld__techs">';
		foreach ( $techs as $entry ) {
			self::render_technique( $entry['tech'], $entry['max_colours'], $area, $show, $factor, $handling );
		}
		echo '</div></section>';
	}

	private static function render_technique( array $tech, int $max_colours, float $area, bool $show, float $factor, float $handling ): void {
		$name    = self::technique_name( $tech );
		$colours = '';
		if ( $max_colours === 1 ) {
			$colours = __( '1 Farbe', 'media-lab-ml-decoration' );
		} elseif ( $max_colours > 1 ) {
			$colours = sprintf( __( 'bis %d Farben', 'media-lab-ml-decoration' ), $max_colours );
		}

		if ( ! $show ) {
			echo '<div class="mld__tech mld__tech--plain"><span class="mld__tech-name">' . esc_html( $name ) . '</span>';
			if ( $colours !== '' ) {
				echo '<span class="mld__tech-colours">' . esc_html( $colours ) . '</span>';
			}
			echo '</div>';
			return;
		}

		echo '<details class="mld__tech"><summary><span class="mld__tech-name">' . esc_html( $name ) . '</span>';
		if ( $colours !== '' ) {
			echo '<span class="mld__tech-colours">' . esc_html( $colours ) . '</span>';
		}
		echo '</summary><div class="mld__tech-body">';

		$ranges = ML_Deco_Calc::relevant_ranges( $tech, $area > 0 ? $area : null );
		$multi  = count( $ranges ) > 1;
		foreach ( $ranges as $range ) {
			self::render_table( $tech, $range, $area, $factor, $handling, $multi );
		}

		self::render_facts( $tech, $factor );
		echo '</div></details>';
	}

	private static function render_table( array $tech, array $range, float $area, float $factor, float $handling, bool $multi ): void {
		$rows     = ML_Deco_Calc::merge_rows( ML_Deco_Calc::rows( $tech, $range, $area, $handling ), $factor );
		$withNext = ! empty( $tech['next_colour'] );
		$caption  = $multi ? ML_Deco_Calc::range_label( $range ) : '';

		// Preis hängt von der Fläche ab: sagen, für welche Fläche der Preis gilt
		$isAreaPrice = ( $tech['pricing'] ?? '' ) === 'area';
		$priceHead   = $isAreaPrice && $area > 0
			? sprintf( __( 'Preis pro Stück (bei %s cm²)', 'media-lab-ml-decoration' ), number_format_i18n( $area, floor( $area ) === $area ? 0 : 1 ) )
			: __( 'Preis pro Stück', 'media-lab-ml-decoration' );

		echo '<div class="mld__scroll"><table class="mld__table">';
		if ( $caption !== '' ) {
			echo '<caption>' . esc_html( $caption ) . '</caption>';
		}
		echo '<thead><tr><th scope="col">' . esc_html__( 'Menge', 'media-lab-ml-decoration' ) . '</th>';
		echo '<th scope="col">' . esc_html( $priceHead ) . '</th>';
		if ( $withNext ) {
			echo '<th scope="col">' . esc_html__( 'je weitere Farbe', 'media-lab-ml-decoration' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( self::qty_label( $row['from'], $row['to'] ) ) . '</td>';
			echo '<td>' . self::money( $row['unit'] * $factor ) . '</td>'; // wc_price liefert sicheres HTML
			if ( $withNext ) {
				echo '<td>' . ( $row['next'] !== null ? '+ ' . self::money( $row['next'] * $factor ) : '&ndash;' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_facts( array $tech, float $factor ): void {
		$facts = [];
		if ( ( $tech['setup'] ?? 0 ) > 0 ) {
			$facts[] = sprintf( __( 'Einrichtung (einmalig): %s', 'media-lab-ml-decoration' ), self::money( $tech['setup'] * $factor ) );
		}
		if ( ( $tech['setup_repeat'] ?? 0 ) > 0 ) {
			$facts[] = sprintf( __( 'Bei Wiederholung: %s', 'media-lab-ml-decoration' ), self::money( $tech['setup_repeat'] * $factor ) );
		}
		if ( ! empty( $tech['min_job'] ) ) {
			$facts[] = sprintf( __( 'Mindestauftragswert: %s', 'media-lab-ml-decoration' ), self::money( $tech['min_job'] * $factor ) );
		}
		if ( ! empty( $tech['min_qty'] ) ) {
			$facts[] = sprintf( __( 'Mindestmenge: %s Stk.', 'media-lab-ml-decoration' ), esc_html( number_format_i18n( (int) $tech['min_qty'] ) ) );
		}
		if ( $facts ) {
			// Beträge kommen als sicheres wc_price-HTML, Text selbst stammt aus unseren Übersetzungen
			echo '<p class="mld__facts">' . implode( ' &middot; ', $facts ) . '</p>';
		}
	}

	// ── Hilfsfunktionen ────────────────────────────────────────────────────────

	public static function money( float $value ): string {
		$value = round( $value, 3 );
		$cents = $value * 100;
		$dec   = abs( $cents - round( $cents ) ) < 0.0001 ? 2 : 3; // 0,105 € nicht auf 0,11 € runden
		return function_exists( 'wc_price' )
			? wc_price( $value, [ 'decimals' => $dec ] )
			: esc_html( number_format_i18n( $value, $dec ) . ' €' );
	}

	private static function qty_label( int $from, ?int $to ): string {
		if ( $to === null ) {
			return sprintf( __( 'ab %s', 'media-lab-ml-decoration' ), number_format_i18n( $from ) );
		}
		if ( $to <= $from ) {
			return number_format_i18n( $from );
		}
		return number_format_i18n( $from ) . ' – ' . number_format_i18n( $to );
	}

	private static function technique_name( array $tech ): string {
		$names = (array) ( $tech['name'] ?? [] );
		$name  = (string) ( $names['de'] ?? $names['en'] ?? '' );
		return $name !== '' ? $name : __( 'Veredelung', 'media-lab-ml-decoration' );
	}

	private static function position_label( array $position ): string {
		$name = trim( (string) ( $position['name'] ?? '' ) );
		if ( $name !== '' ) {
			return $name; // Makito liefert fertige deutsche Namen
		}
		$id    = trim( (string) ( $position['id'] ?? '' ) );
		$parts = preg_split( '/\s+/', $id ) ?: [];
		$out   = [];
		foreach ( $parts as $part ) {
			$up    = strtoupper( $part );
			$out[] = self::POSITION_TOKENS[ $up ] ?? ( ctype_digit( $part ) ? $part : ucfirst( strtolower( $part ) ) );
		}
		$label = trim( implode( ' ', $out ) );
		return (string) apply_filters( 'ml_deco_position_label', $label !== '' ? $label : $id, $position );
	}

	private static function size_label( array $position ): string {
		$w = (float) ( $position['w'] ?? 0 );
		$h = (float) ( $position['h'] ?? 0 );
		if ( $w <= 0 || $h <= 0 ) {
			return '';
		}
		$fmt  = static fn( float $v ): string => number_format_i18n( $v, floor( $v ) === $v ? 0 : 1 );
		$unit = (string) ( $position['unit'] ?? 'mm' );
		if ( ( $position['shape'] ?? '' ) === 'Ellipse' && abs( $w - $h ) < 0.01 ) {
			return '⌀ ' . $fmt( $w ) . ' ' . $unit;
		}
		return $fmt( $w ) . ' × ' . $fmt( $h ) . ' ' . $unit;
	}
}
