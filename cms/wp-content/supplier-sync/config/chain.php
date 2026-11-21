<?php
/**
 * Importkette je Lieferant (Entscheidungen Markus Tritremmel, 21.11.2026), gestartet mit tools/run_chain.php <lieferant>.
 *
 * Die Schritte laufen nacheinander, bei einem Fehler bricht die Kette ab. Typen:
 *   php   Skript im Ordner supplier-sync mit PHP ('script', 'args')
 *   wpai  WP All Import über WP-CLI: wp all-import run <id> ('id')
 *
 * 'feed' am Sync-Schritt: Dateiname der Parent-Feed-Datei in feeds/. Nach dem Schritt wird geprüft, dass sie neu ist, nicht leer und nicht
 * wesentlich kleiner als beim letzten Lauf (Schutz vor einem defekten Feed, Angabe force überspringt das).
 *
 * Import-IDs (WP All Import, Stand 27.10.2026): 1 MidOcean Eltern, 9 MidOcean Varianten, 5 Makito Eltern, 8 Makito Varianten,
 * 11 Cotton Classics Eltern, 13 Cotton Classics Varianten. Die IDs stehen in der Datenbank des jeweiligen Systems und können dort abweichen,
 * vor dem ersten Lauf auf einem neuen System prüfen (wp all-import list).
 */
return [
    'midocean' => [
        'label' => 'MidOcean',
        'steps' => [
            ['name' => 'sync',             'type' => 'php',  'script' => 'sync.php',                'args' => ['midocean'], 'feed' => 'midocean_products.csv'],
            ['name' => 'eltern-import',    'type' => 'wpai', 'id' => 1],
            ['name' => 'sku-zuordnung',    'type' => 'php',  'script' => 'lookup_skus.php',         'args' => ['midocean']],
            ['name' => 'varianten-import', 'type' => 'wpai', 'id' => 9],
            ['name' => 'feed-abgleich',    'type' => 'php',  'script' => 'tools/feed_missing.php',  'args' => ['midocean', 'apply']],
        ],
    ],

    'makito' => [
        'label' => 'Makito',
        'steps' => [
            ['name' => 'sync',             'type' => 'php',  'script' => 'sync.php',                'args' => ['makito'], 'feed' => 'makito_products.csv'],
            ['name' => 'eltern-import',    'type' => 'wpai', 'id' => 5],
            ['name' => 'sku-zuordnung',    'type' => 'php',  'script' => 'lookup_skus.php',         'args' => ['makito']],
            ['name' => 'varianten-import', 'type' => 'wpai', 'id' => 8],
            ['name' => 'feed-abgleich',    'type' => 'php',  'script' => 'tools/feed_missing.php',  'args' => ['makito', 'apply']],
        ],
    ],

    // Nur von Hand, wenn eine neue Cotton-Datei vorliegt. Der Varianten-Import 13 dauert rund 3 Tage und gehört bewusst nicht in die Kette
    // (bei Bedarf einzeln: wp all-import run 13). age=0, weil die Cotton-Datei nicht täglich erneuert wird.
    'cotton_classics' => [
        'label' => 'Cotton Classics',
        'steps' => [
            ['name' => 'sync',             'type' => 'php',  'script' => 'sync.php',                'args' => ['cotton_classics'], 'feed' => 'cotton_classics_products.csv'],
            ['name' => 'eltern-import',    'type' => 'wpai', 'id' => 11],
            ['name' => 'feed-abgleich',    'type' => 'php',  'script' => 'tools/feed_missing.php',  'args' => ['cotton_classics', 'apply', 'age=0']],
        ],
    ],
];
