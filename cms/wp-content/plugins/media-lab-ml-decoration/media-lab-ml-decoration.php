<?php
/**
 * Plugin Name: Media Lab ML Decoration
 * Description: Veredelungsmöglichkeiten und Veredelungspreise (MidOcean, Makito) auf der Produktseite. Eigener Preisfaktor und Preis-Schalter je Lieferant. Projekt-Plugin für at.media-lab.
 * Version: 1.7.1
 * Author: Media Lab
 * Text Domain: media-lab-ml-decoration
 * Requires PHP: 8.0
 *
 * Datenfluss:
 *   supplier-sync (CLI)  ->  feeds/<lieferant>_products.csv        Spalte decoration_json
 *                            feeds/<lieferant>_decoration_pricelist.json
 *   WP All Import        ->  Custom Field _ml_decoration           pro Produkt: Positionen + Techniken
 *   dieses Plugin        ->  liest die Preisliste (Option), rechnet Faktor drauf, zeigt Tab "Veredelung"
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ML_DECO_VERSION', '1.7.1' );
define( 'ML_DECO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ML_DECO_URL', plugin_dir_url( __FILE__ ) );

require_once ML_DECO_PATH . 'inc/class-store.php';
require_once ML_DECO_PATH . 'inc/class-calc.php';
require_once ML_DECO_PATH . 'inc/class-settings.php';
require_once ML_DECO_PATH . 'inc/class-render.php';
require_once ML_DECO_PATH . 'inc/class-calculator.php';
require_once ML_DECO_PATH . 'inc/class-selection.php';
require_once ML_DECO_PATH . 'inc/class-frontend.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	ML_Deco_Settings::init();
	ML_Deco_Frontend::init();
	ML_Deco_Selection::init();
} );
