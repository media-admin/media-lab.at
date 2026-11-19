<?php
/**
 * Dubletten zwischen Lieferanten im Shop: Bericht und Bereinigung (Regeln: config/duplicates.php).
 *
 * Aufruf im Ordner cms/:
 *   wp eval-file wp-content/supplier-sync/tools/dedupe_suppliers.php           # nur Bericht, ändert nichts
 *   wp eval-file wp-content/supplier-sync/tools/dedupe_suppliers.php apply     # verdrängte Produkte auf Entwurf setzen
 *   wp eval-file wp-content/supplier-sync/tools/dedupe_suppliers.php restore   # alle damit auf Entwurf gesetzten wieder veröffentlichen
 *
 * "apply" setzt nur den Status auf Entwurf und merkt sich im Feld _ml_duplicate_of die Produkt-ID des bevorzugten Produkts.
 * Nichts wird gelöscht. Die Varianten bleiben unverändert (ein Entwurf als Parent blendet sie im Shop aus).
 * Ergebnisliste: supplier-sync/logs/dedupe_<Datum>.csv
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( "Nur über WP-CLI: wp eval-file ...\n" );
}

require_once __DIR__ . '/../src/Services/DuplicateRules.php';

use SupplierSync\Services\DuplicateRules;

// Eigener Funktionsbereich, damit keine Variablen (zum Beispiel $brands) in den globalen Bereich von WordPress gelangen
( static function ( array $args ) {

$mode  = in_array( 'apply', $args, true ) ? 'apply' : ( in_array( 'restore', $args, true ) ? 'restore' : 'report' );
$rules = new DuplicateRules( require __DIR__ . '/../config/duplicates.php' );

global $wpdb;

/** [ Produkt-ID => [ 'sku' => Lieferanten-Artikelnummer, 'title' => Titel ] ] der veröffentlichten Produkte eines Lieferanten */
$ml_products = static function ( string $code ) use ( $wpdb ): array {
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID, p.post_title, s.meta_value AS sku
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ml_supplier_code' AND m.meta_value = %s
         JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ml_supplier_sku'
         WHERE p.post_type = 'product' AND p.post_status = 'publish'",
        $code
    ) );
    $out = [];
    foreach ( (array) $rows as $r ) {
        $out[ (int) $r->ID ] = [ 'sku' => (string) $r->sku, 'title' => (string) $r->post_title ];
    }
    return $out;
};

if ( $mode === 'restore' ) {
    $ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ml_duplicate_of' WHERE p.post_type = 'product' AND p.post_status = 'draft'" );
    foreach ( $ids as $id ) {
        wp_update_post( [ 'ID' => (int) $id, 'post_status' => 'publish' ] );
        delete_post_meta( (int) $id, '_ml_duplicate_of' );
    }
    WP_CLI::success( count( $ids ) . ' Produkte wieder veröffentlicht.' );
    return;
}

$csv   = [ [ 'regel', 'schluessel', 'bevorzugt_id', 'bevorzugt_sku', 'bevorzugt_titel', 'verdraengt_id', 'verdraengt_sku', 'verdraengt_titel', 'aktion' ] ];
$total = 0;

foreach ( $rules->all() as $rule ) {
    $preferred = $ml_products( $rule['prefer'] );
    $candidates = $ml_products( $rule['drop'] );

    $byKey = [];
    foreach ( $preferred as $id => $p ) {
        $k = DuplicateRules::key( $rule, $rule['prefer'], $p['sku'] );
        if ( $k !== null && ! isset( $byKey[ $k ] ) ) {
            $byKey[ $k ] = $id;
        }
    }

    $pairs = [];
    foreach ( $candidates as $id => $c ) {
        $k = DuplicateRules::key( $rule, $rule['drop'], $c['sku'] );
        if ( $k === null || ! isset( $byKey[ $k ] ) ) continue;
        if ( ! empty( $rule['brand'] ) ) {
            $brand_names = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
            $match  = false;
            foreach ( (array) $brand_names as $b ) {
                if ( DuplicateRules::sameBrand( (string) $b, $rule['brand'] ) ) { $match = true; break; }
            }
            if ( ! $match ) continue;
        }
        $pairs[ $id ] = $k;
    }

    WP_CLI::log( sprintf( "Regel %s: %s bevorzugt (%d Produkte), %s verdrängt (%d Produkte) -> %d Dubletten",
        $rule['brand'] ?: '(alle Marken)', $rule['prefer'], count( $preferred ), $rule['drop'], count( $candidates ), count( $pairs ) ) );

    $shown = 0;
    foreach ( $pairs as $id => $k ) {
        $pid = $byKey[ $k ];
        if ( $shown++ < 12 ) {
            WP_CLI::log( sprintf( '  %-6s %s "%s"  <->  %s "%s"', $k, $preferred[ $pid ]['sku'], $preferred[ $pid ]['title'], $candidates[ $id ]['sku'], $candidates[ $id ]['title'] ) );
        }
        $action = 'nur Bericht';
        if ( $mode === 'apply' ) {
            wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
            update_post_meta( $id, '_ml_duplicate_of', $pid );
            $action = 'auf Entwurf';
        }
        $csv[] = [ $rule['brand'], $k, $pid, $preferred[ $pid ]['sku'], $preferred[ $pid ]['title'], $id, $candidates[ $id ]['sku'], $candidates[ $id ]['title'], $action ];
        $total++;
    }
}

$file = __DIR__ . '/../logs/dedupe_' . date( 'Y-m-d_His' ) . '.csv';
if ( ! is_dir( dirname( $file ) ) ) {
    @mkdir( dirname( $file ), 0775, true );
}
$h = @fopen( $file, 'w' );
if ( $h ) {
    foreach ( $csv as $row ) fputcsv( $h, $row );
    fclose( $h );
    WP_CLI::log( 'Liste: ' . realpath( $file ) );
}

if ( $mode === 'apply' ) {
    WP_CLI::success( $total . ' Dubletten auf Entwurf gesetzt. Rückgängig: wp eval-file wp-content/supplier-sync/tools/dedupe_suppliers.php restore' );
} else {
    WP_CLI::success( $total . ' Dubletten gefunden. Nichts geändert. Zum Setzen auf Entwurf: ... dedupe_suppliers.php apply' );
}

} )( isset( $args ) && is_array( $args ) ? $args : [] );
