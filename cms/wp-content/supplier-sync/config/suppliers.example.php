<?php
return [
    'midocean' => [
        'name' => 'MidOcean',
        'adapter' => 'MidOceanAdapter',
        'api_key' => 'YOUR_API_KEY_HERE',
        'use_test_environment' => false,
        'language' => 'de',
        'enabled' => true,
        'category_mapping' => [
            // Wird sp\u00e4ter bef\u00fcllt, sobald wir die finale
            // Kategoriestruktur des Shops kennen. Bis dahin bleibt
            // die MidOcean-Kategorie 1:1 erhalten (Fallback in
            // AbstractAdapter::mapCategory()).
        ],
    ],

    // 'cotton_classics' => [...],  // folgt sp\u00e4ter
    // 'makito' => [...],           // folgt sp\u00e4ter
];
