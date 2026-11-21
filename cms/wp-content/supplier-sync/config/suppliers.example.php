<?php
return [
    'midocean' => [
        'name' => 'MidOcean',
        'adapter' => 'MidOceanAdapter',
        'supplier_code' => 'DIM',
        'api_key' => 'YOUR_API_KEY_HERE',
        'use_test_environment' => false,
        'language' => 'de',
        'enabled' => true,
        'category_mapping' => [
            // Wird später befüllt, sobald wir die finale
            // Kategoriestruktur des Shops kennen. Bis dahin bleibt
            // die MidOcean-Kategorie 1:1 erhalten (Fallback in
            // AbstractAdapter::mapCategory()).
        ],
    ],

    'makito' => [
        'name' => 'Makito',
        'adapter' => 'MakitoAdapter',
        'supplier_code' => 'TKM',
        'api_url' => 'https://print.makito.es/user/xml/ItemDataFile.php?pszinternal=YOUR_TOKEN_HERE',
        'stock_api_url' => 'https://print.makito.es/user/xml/allstockfile.php?pszinternal=YOUR_TOKEN_HERE',
        'price_api_url' => 'https://print.makito.es/user/xml/PriceListFile.php?pszinternal=YOUR_TOKEN_HERE',
         'print_api_url' => ml_env('MAKITO_CUSTOMER_TOKEN')
            ? 'https://print.makito.es/user/xml/ItemPrintingFile.php?pszinternal=' . ml_env('MAKITO_CUSTOMER_TOKEN')
            : '',
        'print_price_api_url' => ml_env('MAKITO_CUSTOMER_TOKEN')
            ? 'https://print.makito.es/user/xml/PrintJobsPrices.php?pszinternal=' . ml_env('MAKITO_CUSTOMER_TOKEN')
            : '',
        'enabled' => true,
        'category_mapping' => [],
    ],

    // 'cotton_classics' => [...],  // folgt später
];