<?php
/** Aufruf: php tests/duplicate_rules_test.php (ohne Composer, prüft config/duplicates.php + DuplicateRules) */
require __DIR__ . '/../src/Services/DuplicateRules.php';
use SupplierSync\Services\DuplicateRules;

$rules = new DuplicateRules( require __DIR__ . '/../config/duplicates.php' );
$rule  = $rules->all()[0];
$fail  = 0;
$check = function ( string $what, $got, $want ) use ( &$fail ) {
    if ( $got !== $want ) { $fail++; echo "FEHLER $what: " . var_export( $got, true ) . ' statt ' . var_export( $want, true ) . "\n"; }
    else { echo "ok     $what\n"; }
};

$check( 'Cotton 25.0553 -> 553', DuplicateRules::key( $rule, 'LCC', '25.0553' ), '553' );
$check( 'MidOcean S00553 -> 553', DuplicateRules::key( $rule, 'DIM', 'S00553' ), '553' );
$check( 'MidOcean S11500 -> 11500', DuplicateRules::key( $rule, 'DIM', 'S11500' ), '11500' );
$check( 'Cotton 25.11500 -> 11500', DuplicateRules::key( $rule, 'LCC', '25.11500' ), '11500' );
$check( 'andere Marke bei Cotton (01.001T) -> null', DuplicateRules::key( $rule, 'LCC', '01.001T' ), null );
$check( 'MidOcean MO9275 -> null', DuplicateRules::key( $rule, 'DIM', 'MO9275' ), null );
$check( 'Nur Nullen -> null', DuplicateRules::key( $rule, 'DIM', 'S0000' ), null );
$check( 'Marke mit typografischem Apostroph', DuplicateRules::sameBrand( "SOL\u{2019}S Collection", "SOL's Collection" ), true );
$check( 'Marke mit HTML-Entity und Kleinschreibung', DuplicateRules::sameBrand( 'sol&#039;s collection', "SOL's Collection" ), true );
$check( 'andere Marke', DuplicateRules::sameBrand( 'NEOBLU', "SOL's Collection" ), false );

$mk = fn( $sku, $brand ) => (object) [ 'supplierSku' => $sku, 'brand' => $brand, 'importUid' => 'DIM|' . $sku ];
$products = [ $mk( 'S00553', "SOL's Collection" ), $mk( 'S00548', "SOL's Collection" ), $mk( 'S00571', 'NEOBLU' ), $mk( 'MO9275', '' ), $mk( 'S00573', '' ) ];
$res = $rules->filterProducts( $products, 'DIM', [ 'LCC' => [ '25.0553', '25.0571', '25.0573', '01.001T' ] ] );
$check( 'verdraengt: nur S00553 (SOL\'S mit Cotton-Partner)', array_map( fn( $h ) => $h['product']->supplierSku, $res['dropped'] ), [ 'S00553' ] );
$check( 'behalten: 4 (kein Partner, andere Marke, ohne Marke)', count( $res['kept'] ), 4 );

$none = $rules->filterProducts( $products, 'DIM', [ 'LCC' => [] ] );
$check( 'ohne Cotton-Produkte bleibt alles', count( $none['kept'] ), 5 );
$other = $rules->filterProducts( $products, 'TKM', [ 'LCC' => [ '25.0553' ] ] );
$check( 'anderer Lieferant bleibt unberuehrt', count( $other['kept'] ), 5 );

// NEOBLU (zweite Regel)
$neoblu = $rules->all()[1];
$check( 'NEOBLU Cotton 24.3162 -> 3162', DuplicateRules::key( $neoblu, 'LCC', '24.3162' ), '3162' );
$check( 'NEOBLU MidOcean N03162 -> 3162', DuplicateRules::key( $neoblu, 'DIM', 'N03162' ), '3162' );
$check( 'NEOBLU Cotton-Sondergroesse 24.162X -> null', DuplicateRules::key( $neoblu, 'LCC', '24.162X' ), null );
$nb = $rules->filterProducts(
    [ $mk( 'N03162', 'NEOBLU' ), $mk( 'N03168', 'NEOBLU' ), $mk( 'N03163', "SOL's Collection" ) ],
    'DIM',
    [ 'LCC' => [ '24.3162', '24.162X', '24.3163' ] ]
);
$check( 'NEOBLU: nur N03162 verdraengt (N03168 ohne Partner, N03163 andere Marke)', array_map( fn( $h ) => $h['product']->supplierSku, $nb['dropped'] ), [ 'N03162' ] );

exit( $fail ? 1 : 0 );
