/**
 * Konfigurator: Bildwechsel mit der Farbauswahl (siehe inc/konfigurator-bilder.php).
 * Verfügbar: configuratorData.ajax_url / .product_id (media-lab-woocommerce), mlSkuKonfBilder.attribute.
 */
( function ( $ ) {
    'use strict';
    if ( typeof configuratorData === 'undefined' || typeof mlSkuKonfBilder === 'undefined' ) return;

    var cache = {};   // Farbe -> Bilddaten oder null
    var token = 0;

    function fetchColor( value, done ) {
        if ( value in cache ) { done( cache[ value ] ); return; }
        $.ajax( {
            url: configuratorData.ajax_url,
            data: {
                action: 'mlw_variation_color_image',
                product_id: configuratorData.product_id,
                attribute: mlSkuKonfBilder.attribute,
                value: value,
            },
            dataType: 'json',
        } ).done( function ( res ) {
            cache[ value ] = res && res.success && res.data && res.data.image ? res.data.image : null;
            done( cache[ value ] );
        } ).fail( function () { done( null ); } );
    }

    function apply( image ) {
        if ( ! image || ! image.src ) return;
        var $gallery = $( '.woocommerce-product-gallery' ).first();
        var $wrap    = $gallery.find( '.woocommerce-product-gallery__image' ).first();
        var $img     = $wrap.find( 'img' ).first();
        if ( ! $img.length ) return;

        $img.attr( { src: image.src, alt: image.alt || '', title: image.title || '' } );
        if ( image.srcset ) { $img.attr( { srcset: image.srcset, sizes: image.sizes || '' } ); }
        else { $img.removeAttr( 'srcset' ).removeAttr( 'sizes' ); }
        if ( image.full_src ) {
            $img.attr( { 'data-src': image.full_src, 'data-large_image': image.full_src,
                         'data-large_image_width': image.full_src_w || '', 'data-large_image_height': image.full_src_h || '' } );
            $wrap.find( 'a' ).first().attr( 'href', image.full_src );
        }
        $wrap.attr( 'data-thumb', image.gallery_thumbnail_src || image.thumb_src || image.src );

        // erstes Vorschaubild mitziehen und zum ersten Bild springen (WooCommerce-Galerie mit Flexslider)
        var $thumb = $gallery.find( '.flex-control-nav li' ).first().find( 'img' );
        if ( $thumb.length ) {
            $thumb.attr( 'src', image.gallery_thumbnail_src || image.thumb_src || image.src );
            $thumb.trigger( 'click' );
        }
        // Zoom und Größen neu berechnen
        $gallery.trigger( 'woocommerce_gallery_init_zoom' );
        window.setTimeout( function () { $( window ).trigger( 'resize' ); }, 20 );
    }

    $( document ).on( 'change', '.configurator-field--color input[type="radio"]', function () {
        var value = String( $( this ).val() || '' );
        if ( ! value ) return;
        var mine = ++token;
        fetchColor( value, function ( image ) {
            if ( mine !== token ) return;   // inzwischen andere Farbe gewählt
            apply( image );
        } );
    } );
} )( jQuery );
