<?php
/**
 * WooCommerce Theme Integration
 * 
 * @package Custom_Theme
 */

if (!defined('ABSPATH')) {
    exit;
}

// Gesamte Datei nur laden wenn WooCommerce aktiv ist
if ( ! class_exists( 'WooCommerce' ) ) return;

/**
 * Disable default WooCommerce styles
 */
add_filter('woocommerce_enqueue_styles', '__return_empty_array');

/**
 * Custom WooCommerce wrapper
 */
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

add_action('woocommerce_before_main_content', function() {
    echo '<div class="container"><div class="woocommerce-wrapper">';
});

add_action('woocommerce_after_main_content', function() {
    echo '</div></div>';
});

/**
 * Customize products per page
 */
add_filter('loop_shop_per_page', function($cols) {
    return 12; // 12 products per page
}, 20);

/**
 * Customize products per row
 */
add_filter('loop_shop_columns', function($cols) {
    return 3; // 3 columns
});

/**
 * Customize related products
 */
add_filter('woocommerce_output_related_products_args', function($args) {
    $args['posts_per_page'] = 3;
    $args['columns'] = 3;
    return $args;
});

/**
 * Remove breadcrumbs (we'll add custom ones)
 */
remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);

/**
 * Custom Add to Cart button text
 */
add_filter('woocommerce_product_single_add_to_cart_text', function($text) {
    return __('In den Warenkorb', 'custom-theme');
});
add_filter('woocommerce_product_add_to_cart_text', function($text, $product) {
    if (!$product || !is_a($product, 'WC_Product')) {
        return $text;
    }
    
    if ($product->is_type('simple')) {
        return __('Kaufen', 'custom-theme');
    }
    
    return $text;
}, 10, 2);

/**
 * Custom sale badge
 */
add_filter('woocommerce_sale_flash', function($html, $post, $product) {
    $percentage = '';
    
    if ($product->is_type('variable')) {
        $percentages = array();
        
        $prices = $product->get_variation_prices();
        
        foreach ($prices['price'] as $key => $price) {
            if ($prices['regular_price'][$key] !== $price) {
                $percentages[] = round((($prices['regular_price'][$key] - $price) / $prices['regular_price'][$key]) * 100);
            }
        }
        
        if (!empty($percentages)) {
            $percentage = max($percentages) . '%';
        }
    } elseif ($product->is_on_sale()) {
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        
        if ($regular_price && $sale_price) {
            $percentage = round((($regular_price - $sale_price) / $regular_price) * 100) . '%';
        }
    }
    
    return $percentage ? '<span class="onsale">-' . $percentage . '</span>' : $html;
}, 10, 3);

/**
 * Custom currency symbol
 */
add_filter('woocommerce_currency_symbol', function($currency_symbol, $currency) {
    switch ($currency) {
        case 'EUR':
            $currency_symbol = '€';
            break;
    }
    return $currency_symbol;
}, 10, 2);

/**
 * Remove default sorting dropdown (we'll add custom)
 */
remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);

/**
 * Remove result count
 */
remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);

/**
 * Custom pagination
 */
remove_action('woocommerce_after_shop_loop', 'woocommerce_pagination', 10);

add_action( 'woocommerce_after_shop_loop', function () {
    global $wp_query;

    if ( $wp_query->max_num_pages <= 1 ) return;

    echo '<nav class="woocommerce-pagination" aria-label="' . esc_attr__( 'Seitennavigation', 'custom-theme' ) . '">';

    echo paginate_links( [
        'base'      => esc_url_raw( str_replace( 999999999, '%#%', get_pagenum_link( 999999999, false ) ) ),
        'format'    => '?paged=%#%',
        'add_args'  => false,
        'current'   => max( 1, get_query_var( 'paged' ) ),
        'total'     => $wp_query->max_num_pages,
        // ✅ WCAG 2.4.4: Screenreader-Text für Pfeil-Links
        'prev_text' => '<span aria-hidden="true">&larr;</span><span class="screen-reader-text">' . __( 'Vorherige Seite', 'custom-theme' ) . '</span>',
        'next_text' => '<span aria-hidden="true">&rarr;</span><span class="screen-reader-text">' . __( 'Nächste Seite', 'custom-theme' ) . '</span>',
        'type'      => 'list',
        'end_size'  => 3,
        'mid_size'  => 3,
    ] );

    echo '</nav>';
} );

/**
 * Disable WooCommerce scripts on non-shop pages
 */
add_action('wp_enqueue_scripts', function() {
    if (function_exists("is_woocommerce") && !is_woocommerce() && !is_cart() && !is_checkout()) {
        // Dequeue WooCommerce styles
        wp_dequeue_style('woocommerce-general');
        wp_dequeue_style('woocommerce-layout');
        wp_dequeue_style('woocommerce-smallscreen');
        
        // Dequeue WooCommerce scripts
        wp_dequeue_script('wc-cart-fragments');
        wp_dequeue_script('woocommerce');
        wp_dequeue_script('wc-add-to-cart');
    }
}, 99);

/**
 * Optimize cart fragments
 */
add_filter('woocommerce_add_to_cart_fragments', function($fragments) {
    // Only update what's necessary
    ob_start();
    ?>
    <span class="cart-count"><?php echo WC()->cart->get_cart_contents_count(); ?></span>
    <?php
    $fragments['.cart-count'] = ob_get_clean();
    
    return $fragments;
});

/**
 * Disable cart fragments on non-WC pages
 */
add_action('wp_enqueue_scripts', function() {
    if (function_exists("is_woocommerce") && !is_woocommerce() && !is_cart() && !is_checkout()) {
        wp_dequeue_script('wc-cart-fragments');
    }
}, 100);

// Kein Standard-Sidebar-Fallback unter den WooCommerce-Archiven (Shop, Kategorie, Produktsuche):
// WooCommerce ruft get_sidebar('shop') auf, und ohne sidebar.php im Theme laedt WordPress theme-compat/sidebar.php
// (Suchfeld, "Seiten", "Archiv", "Kategorien" ungestylt unter den Produktlisten, dazu eine "deprecated"-Meldung).
// Eigene Sidebar-Systeme des Themes: ajax-filters__sidebar, media-lab-woocommerce filter-bar.php.
add_action( 'wp', function () {
    // Eigene sidebar.php des Themes (nicht locate_template: das findet auch wp-includes/theme-compat/sidebar.php)
    if ( ! file_exists( get_stylesheet_directory() . '/sidebar.php' ) && ! file_exists( get_template_directory() . '/sidebar.php' ) ) {
        remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
    }
} );


/**
 * Produktseite: Breadcrumb über dem Produkt (Shop › Hauptkategorie › Unterkategorie › Produkt).
 * Die Breadcrumb des Seitenkopfs bleibt entfernt (siehe oben); hier steht sie direkt vor dem Produkt. WooCommerce bildet den Pfad
 * aus den Elternkategorien der tiefsten Kategorie des Produkts.
 */
add_action( 'woocommerce_before_single_product', function () {
    if ( function_exists( 'woocommerce_breadcrumb' ) ) {
        woocommerce_breadcrumb();
    }
}, 5 );

add_filter( 'woocommerce_breadcrumb_defaults', function ( $defaults ) {
    $defaults['delimiter']   = ' <span class="separator" aria-hidden="true">›</span> ';
    $defaults['wrap_before'] = '<nav class="woocommerce-breadcrumb" aria-label="' . esc_attr__( 'Brotkrumen', 'custom-theme' ) . '">';
    $defaults['wrap_after']  = '</nav>';
    $defaults['home']        = __( 'Startseite', 'custom-theme' );
    return $defaults;
} );

/**
 * Produktseite: Zeile "Kategorie" in der Meta-Angabe als Pfad mit Links auf jede Ebene (Textilien › T-Shirts).
 * Nur innerhalb der Produkt-Zusammenfassung (Meta-Angabe); Oberkategorien, die nur der Pfad einer tieferen Kategorie des Produkts sind,
 * stehen nicht noch einmal einzeln da.
 */
add_filter( 'term_links-product_cat', function ( $links ) {
    if ( ! doing_action( 'woocommerce_single_product_summary' ) ) {
        return $links;
    }
    $post_id = get_the_ID();
    $terms   = $post_id ? get_the_terms( $post_id, 'product_cat' ) : false;
    if ( ! $terms || is_wp_error( $terms ) ) {
        return $links;
    }
    $default = (int) get_option( 'default_product_cat' );
    $out     = [];
    foreach ( $terms as $term ) {
        if ( (int) $term->term_id === $default ) {
            continue;
        }
        $is_ancestor = false;
        foreach ( $terms as $other ) {
            if ( $other->term_id !== $term->term_id && term_is_ancestor_of( $term->term_id, $other->term_id, 'product_cat' ) ) {
                $is_ancestor = true;
                break;
            }
        }
        if ( $is_ancestor ) {
            continue;
        }
        $chain = array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );   // Hauptkategorie zuerst
        $chain[] = $term->term_id;
        $parts   = [];
        foreach ( $chain as $term_id ) {
            $item = get_term( $term_id, 'product_cat' );
            $url  = $item && ! is_wp_error( $item ) ? get_term_link( $item ) : null;
            if ( $url && ! is_wp_error( $url ) ) {
                $parts[] = '<a href="' . esc_url( $url ) . '" rel="tag">' . esc_html( $item->name ) . '</a>';
            }
        }
        if ( $parts ) {
            $out[] = implode( ' <span class="mlw-cat-sep" aria-hidden="true">›</span> ', $parts );
        }
    }
    return $out ?: $links;
} );
