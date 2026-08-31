<?php
return [
    'cotton-classics' => [
        'name'             => 'Cotton Classics',
        'adapter'          => 'CottonClassicsAdapter',
        'supplier_code'    => 'LCC',
        'api_url'          => '', // TODO: API-Endpoint eintragen
        'api_key'          => '', // TODO
        'enabled'          => false, // Preise teils per Mail laut Notion — Sonderfall im Adapter behandeln
        'category_mapping' => [],
    ],
    'midocean' => [
        'name'             => 'MidOcean',
        'adapter'          => 'MidOceanAdapter',
        'supplier_code'    => 'MDO',
        'api_url'          => '', // TODO
        'api_key'          => '', // TODO
        'enabled'          => false,
        'category_mapping' => [],
    ],
    'makito' => [
        'name'             => 'Makito',
        'adapter'          => 'MakitoAdapter',
        'supplier_code'    => 'MKT',
        'api_url'          => '', // TODO
        'api_key'          => '', // TODO
        'enabled'          => false,
        'category_mapping' => [],
    ],
];
