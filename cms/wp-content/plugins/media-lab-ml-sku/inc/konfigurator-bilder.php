<?php
/**
 * Konfigurator: Bildwechsel mit der Farbauswahl.
 *
 * Bei konfigurierbaren Produkten (ACF is_configurable) ist die Farbwahl ein Schritt des Konfigurators (Alpine) und
 * kein WooCommerce-Varianten-Formular. Die Funktion "Variantenbilder bei der Farbwahl" von media-lab-woocommerce
 * greift dort deshalb nicht. Dieses Skript holt beim Klick auf eine Farbe die Bilddaten der ersten passenden
 * Variante über den vorhandenen Endpunkt mlw_variation_color_image (zwischengespeichert, kein neuer Server-Code)
 * und tauscht Hauptbild und erstes Vorschaubild der Galerie.
 *
 * Projektspezifisch: Farbattribut pa_color. Das Skript hängt am Handle "medialab-configurator" und läuft damit
 * nur auf konfigurierbaren Produktseiten.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
    if ( ! apply_filters( 'ml_sku_configurator_color_images', true ) ) return;

    $file = __DIR__ . '/../assets/konfigurator-bilder.js';
    wp_enqueue_script(
        'ml-sku-konfigurator-bilder',
        plugins_url( 'assets/konfigurator-bilder.js', dirname( __FILE__ ) ),
        [ 'jquery', 'medialab-configurator' ],   // fehlt der Konfigurator, wird das Skript nicht ausgegeben
        file_exists( $file ) ? (string) filemtime( $file ) : null,
        true
    );
    wp_localize_script( 'ml-sku-konfigurator-bilder', 'mlSkuKonfBilder', [
        'attribute' => 'attribute_pa_color',
    ] );
}, 30 );
