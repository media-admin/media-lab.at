<?php
/**
 * WooCommerce AJAX Search Integration
 * 
 * Extends Core AJAX search with product data via filter hooks.
 */

if (!defined('ABSPATH')) exit;

/**
 * Add product post type to search
 */
add_filter('media_lab_ajax_search_post_types', function($post_types) {
    if (!in_array('product', $post_types)) {
        $post_types[] = 'product';
    }
    return $post_types;
});

/**
 * Add WooCommerce product data to search results
 */
add_filter('media_lab_ajax_search_result', function($result, $post_id, $post_type) {
    if ($post_type !== 'product') return $result;
    if (!function_exists('wc_get_product')) return $result;

    $product = wc_get_product($post_id);
    if (!$product) return $result;

    $result['price']         = $product->get_price_html();
    $result['regular_price'] = $product->get_regular_price();
    $result['sale_price']    = $product->get_sale_price();
    $result['sku']           = $product->get_sku();
    $result['stock_status']  = $product->get_stock_status();
    $result['in_stock']      = $product->is_in_stock();
    $result['is_on_sale']    = $product->is_on_sale();

    // Kurzfristig-lieferbar-Badge (media-lab-ml-sku) - dieselbe Logik wie
    // Einzelproduktseite/Shop-Grid, hier nur das "Kurzfristig lieferbar"-
    // Badge, nicht der generische "Lieferbar"-Text (siehe SCSS-Kommentar
    // zu .ml-availability--supplier: bewusst dezent/ausgeblendet dort).
    if ( function_exists( 'ml_get_grid_availability' ) ) {
        $availability = ml_get_grid_availability( $product );
        $result['availability_badge'] = ( $availability && $availability['status'] === 'in_house' )
            ? $availability['label']
            : null;
    }

    return $result;
}, 10, 3);
