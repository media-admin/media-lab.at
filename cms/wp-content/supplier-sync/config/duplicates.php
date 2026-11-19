<?php
/**
 * Dubletten zwischen Lieferanten (Entscheidung Markus Tritremmel, 19.11.2026: Cotton Classics hat Vorrang).
 *
 * Führen zwei Lieferanten denselben Artikel, bleibt das Produkt des bevorzugten Lieferanten ("prefer"), das des anderen ("drop")
 * kommt nicht mehr in den Feed (sync.php) und wird im Shop mit tools/dedupe_suppliers.php auf Entwurf gesetzt.
 *
 * Ein Artikel ist derselbe, wenn der Schlüssel gleich ist. Der Schlüssel ist die erste Zifferngruppe der Lieferanten-Artikelnummer
 * ohne führende Nullen (Regex je Lieferantencode, Gruppe 1). Beispiel SOL'S: Cotton "25.0553" und MidOcean "S00553" ergeben beide 553.
 * "brand" gilt für den verdrängten Lieferanten (Vergleich ohne Groß-/Kleinschreibung, mit angeglichenen Apostrophen).
 *
 * Neue Marke: Eintrag ergänzen, danach "wp eval-file ... tools/dedupe_suppliers.php" (ohne "apply" nur Bericht).
 */
return [
    [
        'brand'  => "SOL's Collection",
        'prefer' => 'LCC',
        'drop'   => 'DIM',
        'key'    => [
            'LCC' => '/^25\.(\d+)$/',
            'DIM' => '/^S(\d+)$/',
        ],
    ],
    // NEOBLU: folgt, sobald die Artikelnummern beider Lieferanten bekannt sind.
];
