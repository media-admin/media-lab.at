<?php

/**
 * Liest eine Umgebungsvariable robust aus — $_ENV bleibt auf manchen PHP-CLI-
 * Setups leer (variables_order ohne "E" in php.ini, z.B. einige Homebrew-PHP-
 * Installationen), auch wenn phpdotenv sie korrekt per putenv() gesetzt hat.
 * getenv() funktioniert davon unabhängig zuverlässig.
 */
function ml_env(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

return [
    'cotton-classics' => [
        'name'             => 'Cotton Classics',
        'adapter'          => 'CottonClassicsAdapter',
        'supplier_code'    => 'LCC',
        'api_url'          => '', // TODO: Datei-basiert (CSV/XLSX), kein API-Endpoint
        'api_key'          => ml_env('COTTON_CLASSICS_API_KEY'),
        'enabled'          => false, // Preise teils per Mail — Sonderfall im Adapter behandeln
        'category_mapping' => [],
    ],
    'midocean' => [
        'name'             => 'MidOcean',
        'adapter'          => 'MidOceanAdapter',
        'supplier_code'    => 'DIM',
        'api_url'          => 'https://api.midocean.com/gateway/products/2.0',
        'stock_api_url'    => 'https://api.midocean.com/gateway/stock/2.0',
        'api_key'          => ml_env('MIDOCEAN_API_KEY'),
        'language'         => 'de',
        'enabled'          => true,
        'category_mapping' => [],
    ],
    'makito' => [
        'name'             => 'Makito',
        'adapter'          => 'MakitoAdapter',
        'supplier_code'    => 'TKM',
        'api_url'          => '', // TODO: XML-Feeds, kein REST-Endpoint
        'api_key'          => ml_env('MAKITO_API_KEY'),
        'enabled'          => false,
        'category_mapping' => [],
    ],
];
