<?php
/**
 * Produkte, die der Lieferant aus dem Feed genommen hat: Bericht, auf Entwurf setzen, rückgängig machen.
 * Regel (Entscheidung Markus Tritremmel, 21.11.2026): Fehlt ein veröffentlichtes Produkt im Feed, bleibt es sichtbar, solange es
 * Hausbestand (stock_inhouse, am Produkt oder an einer Variante) hat. Ohne Hausbestand wird es auf Entwurf gesetzt, nie gelöscht.
 * Taucht es im Feed wieder auf, wird es nicht automatisch veröffentlicht (nur Hinweis im Bericht).
 * Schutzgrenze: Fehlen mehr als 5 % der veröffentlichten Produkte des Lieferanten (zum Beispiel bei einem defekten Feed), ändert das
 * Werkzeug nichts und beendet sich mit Code 2.
 *
 * Grundlage ist die vom Sync erzeugte Datei feeds/<lieferant>_products.csv, also genau das, was der Import bekommt (inklusive
 * Dubletten-Filter). Es ruft keine Lieferanten-API auf.
 *
 * Aufruf ohne WP-CLI (auch auf Production, Cronjob mit PHP 8.4), im Ordner supplier-sync:
 *   php tools/feed_missing.php midocean            # nur Bericht, ändert nichts
 *   php tools/feed_missing.php midocean apply      # fehlende Produkte ohne Hausbestand auf Entwurf setzen
 *   php tools/feed_missing.php midocean restore    # alle damit auf Entwurf gesetzten Produkte des Lieferanten wieder veröffentlichen
 * Aufruf mit WP-CLI im Ordner cms/:
 *   wp eval-file wp-content/supplier-sync/tools/feed_missing.php midocean [apply|restore]
 *
 * Lieferant: midocean, makito oder cotton_classics (Name der Feed-Datei).
 * Weitere Angaben (als Wort, ohne --):
 *   max=5     Schutzgrenze in Prozent (Standard 5)
 *   age=36    Der Feed darf höchstens so viele Stunden alt sein (Standard 36, 0 = nicht prüfen, zum Beispiel für Cotton Classics,
 *             dessen Datei von Hand kommt)
 *
 * Gesetzt wird nur der Status auf Entwurf und das Feld _ml_feed_missing (Datum). Die Varianten bleiben unverändert (ein Entwurf als
 * Parent blendet sie im Shop aus). Liste: supplier-sync/logs/feed_missing_<lieferant>_<Datum>.csv, Verlauf: logs/feed_missing.log
 * Rückgabecodes: 0 in Ordnung, 1 Fehler, 2 Schutzgrenze greift (nichts geändert).
 */

$ml_via_wp_cli = defined( 'ABSPATH' );

if ( ! $ml_via_wp_cli ) {
    if ( PHP_SAPI !== 'cli' ) {
        http_response_code( 404 );
        exit;
    }
    $wpLoad = dirname( __DIR__, 3 ) . '/wp-load.php';
    if ( ! is_file( $wpLoad ) ) {
        fwrite( STDERR, "wp-load.php nicht gefunden: {$wpLoad}\n" );
        exit( 1 );
    }
    @ini_set( 'memory_limit', '1024M' );
    if ( empty( $_SERVER['HTTP_HOST'] ) ) {
        $_SERVER['HTTP_HOST'] = getenv( 'ML_SITE_HOST' ) ?: 'localhost';
    }
    define( 'WP_USE_THEMES', false );
    require $wpLoad;
}

require_once __DIR__ . '/../src/Services/FeedMissingRules.php';

use SupplierSync\Services\FeedMissingRules;

// Eigener Funktionsbereich, damit keine Variablen in den globalen Bereich von WordPress gelangen
$ml_exit = ( static function ( array $args ): int {

    global $wpdb;

    $tz  = new DateTimeZone( 'Europe/Vienna' );
    $now = static fn( string $format ): string => ( new DateTimeImmutable( 'now', $tz ) )->format( $format );

    $logFile = __DIR__ . '/../logs/feed_missing.log';
    if ( ! is_dir( dirname( $logFile ) ) ) {
        @mkdir( dirname( $logFile ), 0775, true );
    }
    $say = static function ( string $msg ) use ( $logFile, $now ): void {
        echo $msg . "\n";
        @file_put_contents( $logFile, '[' . $now( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", FILE_APPEND );
    };

    // --- Angaben lesen ---------------------------------------------------------------------------------------------
    $mode       = 'report';
    $key        = '';
    $maxPercent = 5.0;
    $maxAge     = 36;
    foreach ( $args as $a ) {
        $a = (string) $a;
        if ( $a === 'apply' || $a === 'restore' ) {
            $mode = $a;
        } elseif ( preg_match( '/^max=(\d+(?:\.\d+)?)$/', $a, $m ) ) {
            $maxPercent = (float) $m[1];
        } elseif ( preg_match( '/^age=(\d+)$/', $a, $m ) ) {
            $maxAge = (int) $m[1];
        } elseif ( preg_match( '/^[a-z0-9_]+$/', $a ) && $key === '' ) {
            $key = $a;
        } else {
            $say( "Unbekannte Angabe: {$a}" );
            return 1;
        }
    }
    if ( $key === '' ) {
        $say( 'Lieferant fehlt. Beispiel: php tools/feed_missing.php midocean [apply|restore] [max=5] [age=36]' );
        return 1;
    }

    // --- Feed lesen ------------------------------------------------------------------------------------------------
    $feedFile = __DIR__ . "/../feeds/{$key}_products.csv";
    if ( ! is_file( $feedFile ) ) {
        $say( "Feed-Datei fehlt: feeds/{$key}_products.csv (zuerst php sync.php {$key})" );
        return 1;
    }
    $ageHours = ( time() - (int) filemtime( $feedFile ) ) / 3600;
    if ( $mode === 'apply' && $maxAge > 0 && $ageHours > $maxAge ) {
        $say( sprintf( 'Feed-Datei ist %.1f Stunden alt (erlaubt %d). Nichts geändert. Zuerst php sync.php %s, oder age=0 angeben.', $ageHours, $maxAge, $key ) );
        return 2;
    }

    $fh = fopen( $feedFile, 'r' );
    $header = $fh ? fgetcsv( $fh, 0, ',', '"', '\\' ) : false;
    $iCode  = is_array( $header ) ? array_search( 'supplier_code', $header, true ) : false;
    $iSku   = is_array( $header ) ? array_search( 'supplier_sku', $header, true ) : false;
    if ( $iCode === false || $iSku === false ) {
        $say( "Feed-Datei {$key}_products.csv hat keine Spalten supplier_code und supplier_sku." );
        return 1;
    }
    $feedSkus = [];
    $codes    = [];
    while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
        if ( ! isset( $row[ $iCode ], $row[ $iSku ] ) || trim( $row[ $iSku ] ) === '' ) {
            continue;
        }
        $codes[ trim( $row[ $iCode ] ) ] = true;
        $feedSkus[ trim( $row[ $iSku ] ) ] = true;
    }
    fclose( $fh );
    if ( count( $codes ) !== 1 ) {
        $say( 'Die Feed-Datei muss genau einen Lieferanten-Code enthalten, gefunden: ' . ( $codes ? implode( ', ', array_keys( $codes ) ) : 'keinen' ) );
        return 1;
    }
    $code = (string) array_key_first( $codes );

    // --- Shop lesen ------------------------------------------------------------------------------------------------
    /** @return array<int,array{sku:string,title:string}> Produkte des Lieferanten mit dem Status $status (und optional Markierung) */
    $products = static function ( string $status, bool $marked ) use ( $wpdb, $code ): array {
        $markSql = $marked ? "JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_ml_feed_missing'" : '';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, s.meta_value AS sku
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ml_supplier_code' AND m.meta_value = %s
             JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ml_supplier_sku'
             {$markSql}
             WHERE p.post_type = 'product' AND p.post_status = %s",
            $code, $status
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->ID ] = [ 'sku' => (string) $r->sku, 'title' => (string) $r->post_title ];
        }
        return $out;
    };

    $say( sprintf( '=== Feed-Abgleich %s (%s), Modus %s, %s ===', $key, $code, $mode, $now( 'Y-m-d H:i' ) ) );

    // --- Rückgängig machen -----------------------------------------------------------------------------------------
    if ( $mode === 'restore' ) {
        $drafted = $products( 'draft', true );
        foreach ( array_keys( $drafted ) as $id ) {
            wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
            delete_post_meta( $id, '_ml_feed_missing' );
        }
        $say( count( $drafted ) . " Produkte von {$code} wieder veröffentlicht." );
        return 0;
    }

    // --- Entscheiden -----------------------------------------------------------------------------------------------
    $published = $products( 'publish', false );

    // Hausbestand: am Produkt selbst (einfaches Produkt) oder an irgendeiner Variante
    if ( $published ) {
        foreach ( array_chunk( array_keys( $published ), 500 ) as $chunk ) {
            $in  = implode( ',', array_map( 'intval', $chunk ) );
            $ids = $wpdb->get_col(
                "SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID)
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'stock_inhouse' AND CAST(m.meta_value AS UNSIGNED) > 0
                 WHERE p.ID IN ({$in}) OR p.post_parent IN ({$in})"
            );
            foreach ( (array) $ids as $id ) {
                if ( isset( $published[ (int) $id ] ) ) {
                    $published[ (int) $id ]['inhouse'] = true;
                }
            }
        }
    }
    foreach ( $published as $id => $p ) {
        $published[ $id ]['inhouse'] = ! empty( $p['inhouse'] );
    }

    $res        = FeedMissingRules::evaluate( $feedSkus, $published, $maxPercent );
    $reappeared = FeedMissingRules::reappeared( $feedSkus, $products( 'draft', true ) );

    $say( sprintf(
        'Feed: %d Produkte. Shop: %d veröffentlicht. Fehlen im Feed: %d (davon %d mit Hausbestand, bleiben sichtbar). %s: %d. Schutzgrenze: %d.',
        count( $feedSkus ), count( $published ), count( $res['missing'] ), count( $res['keep'] ),
        $res['blocked'] ? 'Wären Entwurf, aber blockiert' : 'Entwurf', count( $res['draft'] ), $res['limit']
    ) );
    if ( $reappeared ) {
        $say( count( $reappeared ) . ' Produkte stehen wieder im Feed, sind aber noch Entwurf (werden nicht automatisch veröffentlicht, bitte prüfen).' );
    }

    // --- Liste schreiben -------------------------------------------------------------------------------------------
    $csv = [ [ 'aktion', 'produkt_id', 'lieferanten_sku', 'titel' ] ];
    $act = $mode === 'apply' ? 'auf Entwurf' : 'nur Bericht: würde auf Entwurf';
    foreach ( $res['draft'] as $id => $p ) {
        $csv[] = [ $res['blocked'] ? 'blockiert (Schutzgrenze)' : $act, $id, $p['sku'], $p['title'] ];
    }
    foreach ( $res['keep'] as $id => $p ) {
        $csv[] = [ 'bleibt sichtbar (Hausbestand)', $id, $p['sku'], $p['title'] ];
    }
    foreach ( $reappeared as $id => $p ) {
        $csv[] = [ 'wieder im Feed, noch Entwurf', $id, $p['sku'], $p['title'] ];
    }
    $listFile = __DIR__ . "/../logs/feed_missing_{$key}_" . $now( 'Y-m-d_His' ) . '.csv';
    if ( $h = @fopen( $listFile, 'w' ) ) {
        foreach ( $csv as $row ) {
            fputcsv( $h, $row, ',', '"', '\\' );
        }
        fclose( $h );
        $say( 'Liste: ' . realpath( $listFile ) );
    }
    if ( ! $res['blocked'] ) {
        foreach ( array_slice( $res['draft'], 0, 12, true ) as $id => $p ) {
            $say( sprintf( '  %-14s #%d "%s"', $p['sku'], $id, $p['title'] ) );
        }
        if ( count( $res['draft'] ) > 12 ) {
            $say( '  ... weitere in der Liste' );
        }
    }

    // --- Schutzgrenze ----------------------------------------------------------------------------------------------
    if ( $res['blocked'] ) {
        $say( 'SCHUTZGRENZE: ' . $res['reason'] . ' Nichts geändert.' );
        return 2;
    }

    if ( $mode !== 'apply' ) {
        $say( count( $res['draft'] ) . " Produkte würden auf Entwurf gesetzt. Nichts geändert. Zum Setzen: php tools/feed_missing.php {$key} apply" );
        return 0;
    }

    // --- Anwenden --------------------------------------------------------------------------------------------------
    $done = 0;
    $failed = 0;
    foreach ( array_keys( $res['draft'] ) as $id ) {
        $r = wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ], true );
        if ( is_wp_error( $r ) || ! $r ) {
            $failed++;
            $say( "Fehler bei Produkt #{$id}: " . ( is_wp_error( $r ) ? $r->get_error_message() : 'unbekannt' ) );
            continue;
        }
        update_post_meta( $id, '_ml_feed_missing', $now( 'Y-m-d' ) );
        $done++;
    }
    $say( "{$done} Produkte auf Entwurf gesetzt" . ( $failed ? ", {$failed} Fehler" : '' ) . ". Rückgängig: php tools/feed_missing.php {$key} restore" );

    return $failed ? 1 : 0;

} )( $ml_via_wp_cli ? ( isset( $args ) && is_array( $args ) ? $args : [] ) : array_slice( $argv ?? [], 1 ) );

if ( ! $ml_via_wp_cli ) {
    exit( $ml_exit );
}
if ( $ml_exit !== 0 && class_exists( 'WP_CLI' ) ) {
    WP_CLI::halt( $ml_exit );
}
