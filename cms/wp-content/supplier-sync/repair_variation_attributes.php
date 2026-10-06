<?php
/**
 * Variationsmerkmale an variablen Eltern-Produkten nachziehen.
 *
 * Problem: Die Varianten tragen attribute_pa_color / attribute_pa_size, die Eltern-Produkte haben aber keine
 * Merkmale mit "Für Variationen verwenden". WooCommerce baut Auswahlfelder nur aus den Merkmalen des Elternprodukts,
 * ohne sie gibt es kein Dropdown (und in den Variantendaten steht "attributes": []).
 *
 * Aufruf (aus dem Projekt-Root):
 *   wp eval-file cms/wp-content/supplier-sync/repair_variation_attributes.php dry            Trockenlauf, alle variablen Produkte
 *   wp eval-file cms/wp-content/supplier-sync/repair_variation_attributes.php dry 156319     Trockenlauf, ein Produkt
 *   wp eval-file cms/wp-content/supplier-sync/repair_variation_attributes.php run 156319     ein Produkt reparieren
 *   wp eval-file cms/wp-content/supplier-sync/repair_variation_attributes.php run            alle reparieren (mit Sicherung)
 *
 * Verhalten:
 *   - Merkmale und Begriffe werden aus den Varianten abgeleitet (nur veröffentlichte/private Varianten).
 *   - Bestehende Merkmale am Elternprodukt bleiben erhalten und werden nur ergänzt (is_variation = 1).
 *   - Neue Merkmale sind NICHT sichtbar (is_visible = 0), damit kein Tab "Zusätzliche Informationen" entsteht.
 *   - Begriffe werden nie neu angelegt. Fehlt ein Begriff, wird er gezählt und übersprungen.
 *   - Vor dem ersten Schreiben aller Produkte wird eine Sicherung der _product_attributes-Zeilen angelegt.
 *   - Idempotent: Produkte, die schon stimmen, werden nicht angefasst.
 */

global $wpdb;

$mode   = ( $args[0] ?? 'dry' ) === 'run' ? 'run' : 'dry';
$single = isset( $args[1] ) ? (int) $args[1] : 0;

/** Merkmals-Eintrag für ein Taxonomie-Merkmal, bestehende Werte bleiben, is_variation wird 1. */
function ml_attr_entry( array $existing_entry, string $tax, int $position ): array {
    $entry = array_merge(
        [ 'name' => $tax, 'value' => '', 'position' => $position, 'is_visible' => 0, 'is_variation' => 1, 'is_taxonomy' => 1 ],
        $existing_entry
    );
    $entry['name']         = $tax;
    $entry['is_variation'] = 1;
    $entry['is_taxonomy']  = 1;
    return $entry;
}

// ── 1) Variable Eltern-Produkte ──────────────────────────────────────────────
$sql = "SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
        JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
        JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = 'variable'
        WHERE p.post_type = 'product'";
if ( $single ) {
    $sql .= $wpdb->prepare( ' AND p.ID = %d', $single );
}
$sql .= ' ORDER BY p.ID';
$parents = array_map( 'intval', $wpdb->get_col( $sql ) );
WP_CLI::log( 'Variable Eltern-Produkte: ' . count( $parents ) . ( $single ? " (nur Produkt $single)" : '' ) );
if ( ! $parents ) {
    WP_CLI::error( 'Keine variablen Produkte gefunden.' );
}

// ── 2) Sicherung (nur beim Lauf über alle Produkte) ──────────────────────────
if ( $mode === 'run' && ! $single ) {
    $bak = $wpdb->prefix . 'bak_product_attributes_' . gmdate( 'Ymd' );
    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$bak}'" ) ) {
        $wpdb->query( "CREATE TABLE {$bak} AS SELECT * FROM {$wpdb->postmeta} WHERE meta_key = '_product_attributes'" );
        WP_CLI::log( "Sicherung angelegt: {$bak} (" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bak}" ) . ' Zeilen)' );
    } else {
        WP_CLI::log( "Sicherung {$bak} existiert bereits, wird nicht überschrieben." );
    }
}

// ── 3) Verarbeitung in Blöcken ───────────────────────────────────────────────
$stats = [
    'eltern'                     => 0,
    'ohne_merkmale_in_varianten' => 0,
    'zu_aendern'                 => 0,
    'bereits_korrekt'            => 0,
    'unbekannte_taxonomie'       => 0,
    'fehlende_begriffe'          => 0,
];
$missing    = [];   // "pa_color|slug" => Anzahl Produkte
$tax_count  = [];   // Taxonomie => Anzahl Produkte
$samples    = [];
$term_cache = [];   // "tax|slug" => term_id (0 = nicht vorhanden)

foreach ( array_chunk( $parents, 400 ) as $chunk ) {
    $in   = implode( ',', $chunk );
    $rows = $wpdb->get_results(
        "SELECT p.post_parent AS parent, m.meta_key AS k, m.meta_value AS v
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE p.post_type = 'product_variation'
           AND p.post_status IN ('publish','private')
           AND p.post_parent IN ($in)
           AND m.meta_key LIKE 'attribute\\_pa\\_%'
           AND m.meta_value <> ''"
    );

    $by = [];
    foreach ( $rows as $r ) {
        $tax = substr( $r->k, strlen( 'attribute_' ) );   // attribute_pa_color -> pa_color
        $by[ (int) $r->parent ][ $tax ][ $r->v ] = true;
    }

    update_meta_cache( 'post', $chunk );
    update_object_term_cache( $chunk, 'product' );

    foreach ( $chunk as $pid ) {
        $stats['eltern']++;
        $tax_slugs = $by[ $pid ] ?? [];
        if ( ! $tax_slugs ) {
            $stats['ohne_merkmale_in_varianten']++;
            continue;
        }

        $existing = get_post_meta( $pid, '_product_attributes', true );
        $existing = is_array( $existing ) ? $existing : [];
        ksort( $tax_slugs );

        $new        = $existing;
        $needed     = [];   // Taxonomie => [term_id, ...]
        $changed    = false;
        $has_missing = false;

        foreach ( $tax_slugs as $tax => $slug_set ) {
            if ( ! taxonomy_exists( $tax ) ) {
                $stats['unbekannte_taxonomie']++;
                continue;
            }
            $ids = [];
            foreach ( array_keys( $slug_set ) as $slug ) {
                $ck = $tax . '|' . $slug;
                if ( ! array_key_exists( $ck, $term_cache ) ) {
                    $term            = get_term_by( 'slug', $slug, $tax );
                    $term_cache[ $ck ] = $term ? (int) $term->term_id : 0;
                }
                if ( $term_cache[ $ck ] ) {
                    $ids[] = $term_cache[ $ck ];
                } else {
                    $missing[ $ck ] = ( $missing[ $ck ] ?? 0 ) + 1;
                    $has_missing    = true;
                }
            }
            if ( ! $ids ) {
                continue;
            }
            $needed[ $tax ] = $ids;

            // Merkmal am Elternprodukt: vorhanden und für Variationen aktiv?
            if ( empty( $existing[ $tax ]['is_variation'] ) ) {
                $new[ $tax ] = ml_attr_entry( $existing[ $tax ] ?? [], $tax, count( $new ) );
                $changed     = true;
            }
            // Begriffe am Elternprodukt zugewiesen?
            $assigned = wp_get_object_terms( $pid, $tax, [ 'fields' => 'ids' ] );
            $assigned = is_wp_error( $assigned ) ? [] : array_map( 'intval', $assigned );
            if ( array_diff( $ids, $assigned ) ) {
                $needed[ $tax ] = array_values( array_unique( array_merge( $assigned, $ids ) ) );
                $changed        = true;
            }
            $tax_count[ $tax ] = ( $tax_count[ $tax ] ?? 0 ) + 1;
        }

        if ( $has_missing ) {
            $stats['fehlende_begriffe']++;
        }
        if ( ! $changed ) {
            $stats['bereits_korrekt']++;
            continue;
        }

        $stats['zu_aendern']++;
        if ( count( $samples ) < 5 ) {
            $samples[] = $pid . ': ' . implode( ', ', array_map( function ( $t, $ids ) { return $t . ' (' . count( $ids ) . ')'; }, array_keys( $needed ), $needed ) );
        }

        if ( $mode === 'run' ) {
            foreach ( $needed as $tax => $term_ids ) {
                $have = wp_get_object_terms( $pid, $tax, [ 'fields' => 'ids' ] );
                $have = is_wp_error( $have ) ? [] : array_map( 'intval', $have );
                wp_set_object_terms( $pid, array_values( array_unique( array_merge( $have, array_map( 'intval', $term_ids ) ) ) ), $tax );
            }
            update_post_meta( $pid, '_product_attributes', $new );
            wc_delete_product_transients( $pid );
        }
    }

    WP_CLI::log( sprintf( '  %d / %d verarbeitet', $stats['eltern'], count( $parents ) ) );
}

// ── 4) Ergebnis ──────────────────────────────────────────────────────────────
WP_CLI::log( "\nModus: " . $mode . ( $mode === 'dry' ? ' (es wurde nichts geschrieben)' : '' ) );
WP_CLI::log( print_r( $stats, true ) );
WP_CLI::log( 'Taxonomien (Produkte): ' . json_encode( $tax_count ) );
WP_CLI::log( 'Beispiele: ' . implode( ' | ', $samples ) );
if ( $missing ) {
    arsort( $missing );
    WP_CLI::log( 'Begriffe ohne Eintrag (werden übersprungen): ' . count( $missing ) . ', häufigste: ' . json_encode( array_slice( $missing, 0, 10, true ) ) );
}
