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
		$sku = trim( (string) get_post_meta( $variation->get_id(), '_ml_supplier_variant_sku', true ) );
		if ( $sku !== '' ) {
			$parts = explode( '-', $sku );
			if ( count( $parts ) >= 2 && $parts[1] !== '' ) {
				$data['mld_color'] = $parts[1];
			}
		}
		return $data;
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
