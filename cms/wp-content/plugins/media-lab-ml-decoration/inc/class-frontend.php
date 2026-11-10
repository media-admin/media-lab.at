<?php
/**
 * Frontend: Tab "Veredelung" auf der WooCommerce-Produktseite, Shortcode, CSS.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Frontend {

	/**
	 * Farbcode der Variante aus der Lieferanten-SKU (Variationsfeld _ml_supplier_variant_sku, zum Beispiel "MO6112-03"
	 * ergibt "03"). Fehlt das Feld, bleibt es beim Standardbild.
	 *
	 * @param array                $data      Variationsdaten für das Frontend.
	 * @param WC_Product           $product   Variables Produkt.
	 * @param WC_Product_Variation $variation Die Variante.
	 */
	public static function variation_color( $data, $product, $variation ) {
		if ( ! is_array( $data ) || ! is_object( $variation ) || ! method_exists( $variation, 'get_id' ) ) {
			return $data;
		}
		$known = ( is_object( $product ) && method_exists( $product, 'get_id' ) ) ? self::known_codes( (int) $product->get_id() ) : [];
		$code  = self::color_code( (string) get_post_meta( $variation->get_id(), '_ml_supplier_variant_sku', true ), $known );
		if ( $code !== '' ) {
			$data['mld_color'] = $code;
		}
		return $data;
	}

	/**
	 * Farbcode aus der Lieferanten-SKU. Normalfall: der Teil direkt nach dem Produktcode ("MO6112-03" ergibt "03",
	 * "S11500-12-M" ergibt "12"). Enthält der Produktcode selbst einen Bindestrich ("MO1001 -16GB-07"), gilt der erste
	 * Teil nach dem Produktcode, der in den Farbcodes der Veredelung des Produkts vorkommt ($known); sonst der zweite Teil.
	 *
	 * @param string[] $known Farbcodes aus den Positionsbildern des Produkts (leer = unbekannt).
	 */
	public static function color_code( string $sku, array $known = [] ): string {
		$parts = explode( '-', trim( $sku ) );
		if ( count( $parts ) < 2 || $parts[1] === '' ) {
			return '';
		}
		if ( $known ) {
			foreach ( array_slice( $parts, 1 ) as $part ) {
				if ( in_array( $part, $known, true ) ) {
					return $part;
				}
			}
		}
		return $parts[1];
	}

	/**
	 * Alle Farbcodes, zu denen die Veredelung des Produkts Positionsbilder hat (MidOcean).
	 *
	 * @return string[]
	 */
	public static function known_codes( int $product_id ): array {
		static $cache = [];
		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}
		$codes = [];
		$data  = class_exists( 'ML_Deco_Store' ) ? ML_Deco_Store::product_data( $product_id ) : null;
		foreach ( (array) ( $data['positions'] ?? [] ) as $pos ) {
			foreach ( (array) ( $pos['colors'] ?? [] ) as $c ) {
				$codes[ (string) $c ] = true;
			}
		}
		return $cache[ $product_id ] = array_map( 'strval', array_keys( $codes ) );
	}

	/**
	 * Zuordnung Farbwert (Slug des Farbattributs) zu Farbcode für ein variables Produkt, zum Beispiel
	 * { "deep-black": "DB", "white": "WH" }. Damit wechseln die Positionsbilder schon, wenn nur die Farbe gewählt ist
	 * (WooCommerce meldet eine Variante erst, wenn alle Felder gewählt sind, also auch die Größe).
	 *
	 * @return array<string,string>
	 */
	public static function color_map( int $product_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return [];
		}
		$ids = array_map( 'intval', (array) $product->get_children() );
		if ( ! $ids ) {
			return [];
		}
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}
		$attr  = 'attribute_' . self::color_attribute();
		$known = self::known_codes( $product_id );
		$map   = [];
		foreach ( $ids as $vid ) {
			$slug = (string) get_post_meta( $vid, $attr, true );
			$code = self::color_code( (string) get_post_meta( $vid, '_ml_supplier_variant_sku', true ), $known );
			if ( $slug !== '' && $code !== '' && ! isset( $map[ $slug ] ) ) {
				$map[ $slug ] = $code;
			}
		}
		return $map;
	}

	/** Attribut, das die Produktfarbe trägt (Standard pa_color, anpassbar). */
	public static function color_attribute(): string {
		return (string) apply_filters( 'ml_deco_color_attribute', 'pa_color' );
	}

	public static function init(): void {
		add_filter( 'woocommerce_product_tabs', [ __CLASS__, 'tabs' ], 25 );
		// Farbcode der gewählten Variante (MidOcean) an die Variationsdaten hängen: die Positionsbilder folgen der Farbe
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'variation_color' ], 10, 3 );
		add_shortcode( 'ml_decoration', [ __CLASS__, 'shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function tabs( array $tabs ): array {
		$id = (int) get_the_ID();
		if ( ! $id || ! ML_Deco_Render::available( $id ) ) {
			return $tabs;
		}
		$tabs['ml_decoration'] = [
			'title'    => ML_Deco_Settings::tab_title(),
			'priority' => 25,
			'callback' => static function () use ( $id ) {
				echo ML_Deco_Render::render( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- Render escaped intern
			},
		];
		return $tabs;
	}

	/** [ml_decoration id="123"] - ohne id das aktuelle Produkt. Für Themes ohne Tabs. */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'ml_decoration' );
		$id   = (int) $atts['id'] ?: (int) get_the_ID();
		if ( ! $id ) {
			return '';
		}
		self::enqueue_assets();
		return ML_Deco_Render::render( $id );
	}

	public static function assets(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			self::enqueue_assets();
		}
	}

	private static function enqueue_assets(): void {
		wp_enqueue_style( 'ml-deco', ML_DECO_URL . 'assets/css/decoration.css', [], ML_DECO_VERSION );
		wp_enqueue_script( 'ml-deco', ML_DECO_URL . 'assets/js/decoration.js', [], ML_DECO_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_enqueue_script( 'ml-deco-calc', ML_DECO_URL . 'assets/js/calculator.js', [], ML_DECO_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_localize_script( 'ml-deco-calc', 'mldCalc', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( ML_Deco_Selection::NONCE_ACTION ),
		] );
		wp_localize_script( 'ml-deco', 'mldL10n', [
			'close'    => __( 'Schließen', 'media-lab-ml-decoration' ),
			'fallback' => __( 'Druckposition', 'media-lab-ml-decoration' ),
		] );
	}
}
