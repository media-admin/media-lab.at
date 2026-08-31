<?php
return [
    'cotton-classics' => [
        'name'             => 'Cotton Classics',
        'adapter'          => 'CottonClassicsAdapter',
        'supplier_code'    => 'LCC',
        'api_url'          => '', // TODO: Datei-basiert (CSV/XLSX), kein API-Endpoint
        'api_key'          => $_ENV['COTTON_CLASSICS_API_KEY'] ?? '',
        'enabled'          => false, // Preise teils per Mail — Sonderfall im Adapter behandeln
        'category_mapping' => [],
    ],
    'midocean' => [
        'name'             => 'MidOcean',
        'adapter'          => 'MidOceanAdapter',
        'supplier_code'    => 'DIM',
        'api_url'          => 'https://api.midocean.com/gateway/products/2.0',
        'stock_api_url'    => 'https://api.midocean.com/gateway/stock/2.0',
        'api_key'          => $_ENV['MIDOCEAN_API_KEY'] ?? '',
        'language'         => 'de',
        'enabled'          => true,
        'category_mapping' => [],
    ],
    'makito' => [
        'name'             => 'Makito',
        'adapter'          => 'MakitoAdapter',
        'supplier_code'    => 'TKM',
        'api_url'          => '', // TODO: XML-Feeds, kein REST-Endpoint
        'api_key'          => $_ENV['MAKITO_API_KEY'] ?? '',
        'enabled'          => false,
        'category_mapping' => [],
    ],
];
