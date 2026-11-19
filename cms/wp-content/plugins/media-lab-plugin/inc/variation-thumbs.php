<?php
/**
 * Varianten-Vorschaubilder in der Produktgalerie einschalten.
 *
 * Die Funktion steckt im geteilten Plugin media-lab-woocommerce (ab 2.36.0, inc/variation-color-thumbs.php) und ist dort
 * standardmäßig aus. Projektentscheidung (Markus, 19.11.2026): in diesem Projekt an. Unter der Galerie variabler Produkte
 * erscheint je Farbe ein Vorschaubild, ein Klick wählt die Farbe im Variantenformular.
 *
 * Weitere Einstellungen bei Bedarf hier (Filter siehe CHANGELOG von media-lab-woocommerce 2.36.0):
 *   add_filter( 'mlw_variation_color_thumbs_max', fn() => 24 );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'mlw_variation_color_thumbs', '__return_true' );
