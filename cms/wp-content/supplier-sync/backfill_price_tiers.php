<?php
// Backfill _ml_price_tiers_raw fuer Cotton Classics aus feeds/cotton_classics_variants.csv
// Aufruf (WP-Root): wp eval-file cms/wp-content/supplier-sync/backfill_price_tiers.php [dry|run]
global $wpdb;
$mode = ( $args[0] ?? 'dry' ) === 'run' ? 'run' : 'dry';
$csv  = __DIR__ . '/feeds/cotton_classics_variants.csv';

$norm = function ( string $k ): string {
    $out = [];
    foreach ( explode( '|', $k ) as $pair ) {
        [ $a, $v ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
        $sv = sanitize_title( $v );
        if ( $sv === '' ) { continue; }
        $out[] = $a . '=' . $sv;
    }
    return implode( '|', $out );
};

// Parents: Style-Code -> Post-ID
$parents = [];
foreach ( $wpdb->get_results(
    "SELECT s.meta_value AS style, s.post_id AS id
     FROM {$wpdb->postmeta} s
     JOIN {$wpdb->postmeta} c ON c.post_id = s.post_id AND c.meta_key = '_ml_supplier_code' AND c.meta_value = 'LCC'
     WHERE s.meta_key = '_ml_supplier_sku'" ) as $r ) {
    $parents[ $r->style ] = (int) $r->id;
}
WP_CLI::log( 'Parents: ' . count( $parents ) );

// Varianten: Parent-ID -> normalisierter Key -> [Post-IDs]
$vars = [];
$n = 0;
foreach ( $wpdb->get_results(
    "SELECT p.post_parent AS parent, p.ID AS id, k.meta_value AS vkey
     FROM {$wpdb->posts} p
     JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_ml_variant_key'
     WHERE p.post_type = 'product_variation'
       AND p.post_parent IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ml_supplier_code' AND meta_value = 'LCC')" ) as $r ) {
    $vars[ (int) $r->parent ][ $norm( $r->vkey ) ][] = (int) $r->id;
    $n++;
}
WP_CLI::log( "Varianten mit _ml_variant_key: $n" );

$fh  = fopen( $csv, 'r' );
$ix  = array_flip( fgetcsv( $fh ) );
$st  = [ 'feed' => 0, 'ohne_tiers' => 0, 'parent_fehlt' => 0, 'simple_ok' => 0, 'var_ok' => 0,
         'var_kein_match' => 0, 'var_mehrdeutig_db' => 0, 'feed_doppelt' => 0, 'gleich' => 0, 'schreiben' => 0 ];
$seen = []; $ex = [];

while ( ( $row = fgetcsv( $fh ) ) !== false ) {
    $st['feed']++;
    $tiers = $row[ $ix['price_tiers'] ];
    if ( $tiers === '' ) { $st['ohne_tiers']++; continue; }
    $style = preg_replace( '/^LCC\|/', '', $row[ $ix['parent_import_uid'] ] );
    $pid   = $parents[ $style ] ?? null;
    if ( ! $pid ) { $st['parent_fehlt']++; if ( count( $ex ) < 5 ) { $ex[] = "parent? $style"; } continue; }

    if ( $row[ $ix['parent_has_variants'] ] === '0' ) {
        $target = $pid; $st['simple_ok']++;
    } else {
        $key = $norm( $row[ $ix['variant_key'] ] );
        $hit = $vars[ $pid ][ $key ] ?? null;
        if ( ! $hit ) {
            $st['var_kein_match']++;
            if ( count( $ex ) < 5 ) { $ex[] = "$style: feed=$key db=" . implode( ',', array_slice( array_keys( $vars[ $pid ] ?? [] ), 0, 3 ) ); }
            continue;
        }
        if ( count( $hit ) > 1 ) { $st['var_mehrdeutig_db']++; continue; }
        $target = $hit[0];
        if ( isset( $seen[ $target ] ) ) { $st['feed_doppelt']++; continue; }
        $seen[ $target ] = true;
        $st['var_ok']++;
    }
    if ( get_post_meta( $target, '_ml_price_tiers_raw', true ) === $tiers ) { $st['gleich']++; continue; }
    $st['schreiben']++;
    if ( $mode === 'run' ) { update_post_meta( $target, '_ml_price_tiers_raw', wp_slash( $tiers ) ); }
}
fclose( $fh );
WP_CLI::log( "Modus: $mode" );
WP_CLI::log( print_r( $st, true ) );
WP_CLI::log( 'Beispiele: ' . implode( ' | ', $ex ) );
