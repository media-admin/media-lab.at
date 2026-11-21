<?php
/** Aufruf: php tests/feed_missing_rules_test.php (ohne Composer, prüft FeedMissingRules) */
require __DIR__ . '/../src/Services/FeedMissingRules.php';
use SupplierSync\Services\FeedMissingRules;

$fail  = 0;
$check = function ( string $what, $got, $want ) use ( &$fail ) {
    if ( $got !== $want ) { $fail++; echo "FEHLER $what: " . var_export( $got, true ) . ' statt ' . var_export( $want, true ) . "\n"; }
    else { echo "ok     $what\n"; }
};

$pub = static function ( int $n, array $inhouseIds = [] ): array {
    $out = [];
    for ( $i = 1; $i <= $n; $i++ ) {
        $out[ $i ] = [ 'sku' => 'MO' . $i, 'title' => 'Produkt ' . $i, 'inhouse' => in_array( $i, $inhouseIds, true ) ];
    }
    return $out;
};
$feed = static function ( array $nums ): array {
    $out = [];
    foreach ( $nums as $n ) { $out[ 'MO' . $n ] = true; }
    return $out;
};

// 100 veröffentlicht, 2 fehlen (Grenze 5): MO7 ohne Hausbestand, MO9 mit Hausbestand
$all = range( 1, 100 );
$r = FeedMissingRules::evaluate( $feed( array_values( array_diff( $all, [ 7, 9 ] ) ) ), $pub( 100, [ 9 ] ), 5.0 );
$check( 'Grenze bei 100 Produkten und 5 %', $r['limit'], 5 );
$check( 'nicht blockiert', $r['blocked'], false );
$check( 'fehlend: 7 und 9', array_keys( $r['missing'] ), [ 7, 9 ] );
$check( 'Entwurf: nur 7 (ohne Hausbestand)', array_keys( $r['draft'] ), [ 7 ] );
$check( 'bleibt sichtbar: 9 (Hausbestand)', array_keys( $r['keep'] ), [ 9 ] );

// genau an der Grenze (5 von 100) ist erlaubt, 6 sind zu viel
$r5 = FeedMissingRules::evaluate( $feed( array_values( array_diff( $all, [ 1, 2, 3, 4, 5 ] ) ) ), $pub( 100 ), 5.0 );
$check( '5 von 100 fehlen: erlaubt', $r5['blocked'], false );
$r6 = FeedMissingRules::evaluate( $feed( array_values( array_diff( $all, [ 1, 2, 3, 4, 5, 6 ] ) ) ), $pub( 100 ), 5.0 );
$check( '6 von 100 fehlen: blockiert', $r6['blocked'], true );

// leerer Feed: blockiert
$empty = FeedMissingRules::evaluate( [], $pub( 100 ), 5.0 );
$check( 'leerer Feed blockiert', $empty['blocked'], true );
$check( 'leerer Feed: Grund genannt', $empty['reason'] !== '', true );

// Hausbestand zählt bei der Schutzgrenze mit (defekter Feed)
$rin = FeedMissingRules::evaluate( $feed( range( 11, 100 ) ), $pub( 100, range( 1, 10 ) ), 5.0 );
$check( '10 fehlen, alle mit Hausbestand: trotzdem blockiert', $rin['blocked'], true );

// kleiner Lieferant: Untergrenze 3
$small = FeedMissingRules::evaluate( $feed( [ 4, 5, 6, 7, 8, 9, 10 ] ), $pub( 10 ), 5.0 );
$check( 'kleiner Lieferant: Grenze mindestens 3', $small['limit'], 3 );
$check( '3 von 10 fehlen: erlaubt', $small['blocked'], false );
$small4 = FeedMissingRules::evaluate( $feed( [ 5, 6, 7, 8, 9, 10 ] ), $pub( 10 ), 5.0 );
$check( '4 von 10 fehlen: blockiert', $small4['blocked'], true );

// nichts veröffentlicht, Feed leer: kein Fehler
$none = FeedMissingRules::evaluate( [], [], 5.0 );
$check( 'nichts im Shop, nichts im Feed: nicht blockiert', $none['blocked'], false );

// Abgleich ohne Leerzeichen-Unterschiede
$sp = FeedMissingRules::evaluate( [ '25.0553' => true ], [ 1 => [ 'sku' => ' 25.0553 ', 'title' => 'T', 'inhouse' => false ] ], 5.0 );
$check( 'Artikelnummer mit Leerzeichen wird erkannt', $sp['missing'], [] );

// wieder im Feed
$re = FeedMissingRules::reappeared( $feed( [ 7 ] ), [ 7 => [ 'sku' => 'MO7', 'title' => 'a' ], 8 => [ 'sku' => 'MO8', 'title' => 'b' ] ] );
$check( 'wieder im Feed: nur 7', array_keys( $re ), [ 7 ] );

exit( $fail ? 1 : 0 );
