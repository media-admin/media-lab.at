<?php
/**
 * Entfernt Zeilen, die nur aus "NULL" bestehen, aus den Beschreibungen der Cotton-Classics-Produkte.
 * Cotton liefert "NULL" als Platzhalter, der Adapter hat ihn bisher in den Text uebernommen.
 *
 * Aufruf (aus dem Projekt-Root):
 *   wp eval-file cms/wp-content/supplier-sync/clean_null_descriptions.php dry     Trockenlauf
 *   wp eval-file cms/wp-content/supplier-sync/clean_null_descriptions.php run     bereinigen (mit Sicherungstabelle)
 *
 * Nur Zeilen, die exakt "NULL" lauten (Gross-/Kleinschreibung egal), werden entfernt, nie Woerter im Text.
 * Idempotent: ein zweiter Lauf findet nichts mehr.
 */
global $wpdb;
$mode = ( $args[0] ?? 'dry' ) === 'run' ? 'run' : 'dry';

$rows = $wpdb->get_results(
    "SELECT p.ID, p.post_content
     FROM {$wpdb->posts} p
     JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ml_supplier_code' AND s.meta_value = 'LCC'
     WHERE p.post_type = 'product' AND p.post_content LIKE '%NULL%'"
);

$changes = [];
foreach ( $rows as $r ) {
    $lines = preg_split( '/\R/', $r->post_content );
    $kept  = array_values( array_filter( $lines, function ( $l ) {
        return strcasecmp( trim( $l ), 'NULL' ) !== 0;
    } ) );
    if ( count( $kept ) === count( $lines ) ) {
        continue;
    }
    $changes[ (int) $r->ID ] = trim( implode( "\n", $kept ) );
}

WP_CLI::log( count( $rows ) . " Cotton-Produkte mit 'NULL' irgendwo im Text, davon mit einer NULL-Zeile: " . count( $changes ) );
$n = 0;
foreach ( $changes as $id => $text ) {
    if ( $n++ >= 4 ) { break; }
    WP_CLI::log( "  $id: " . mb_substr( str_replace( "\n", ' | ', $text ), 0, 100 ) . ( $text === '' ? '(leer)' : '' ) );
}

if ( $mode === 'dry' || ! $changes ) {
    WP_CLI::log( $mode === 'dry' ? 'Trockenlauf, nichts geschrieben.' : 'Nichts zu tun.' );
    return;
}

$bak = $wpdb->prefix . 'bak_post_content_null_' . gmdate( 'Ymd' );
if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$bak}'" ) ) {
    $wpdb->query( "CREATE TABLE {$bak} AS SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_keys( $changes ) ) . ')' );
    WP_CLI::log( "Sicherung angelegt: {$bak}" );
}
foreach ( $changes as $id => $text ) {
    $wpdb->update( $wpdb->posts, [ 'post_content' => $text ], [ 'ID' => $id ] );
    clean_post_cache( $id );
}
WP_CLI::log( 'Bereinigt: ' . count( $changes ) . ' Produkte.' );
