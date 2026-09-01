<?php
require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use SupplierSync\Services\ApiClient;
use SupplierSync\Services\FeedGenerator;

// Logging
$logger = new Logger('supplier-sync');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/sync.log', Logger::INFO));

// Config laden
$suppliers = require __DIR__ . '/config/suppliers.php';

// Services
$apiClient = new ApiClient($logger);
$feedGenerator = new FeedGenerator();

foreach ($suppliers as $key => $config) {
    if (!$config['enabled']) {
        continue;
    }

    try {
        $logger->info("=== Starting sync for: {$config['name']} ===");

        // Adapter laden
        $adapterClass = "SupplierSync\\Adapters\\{$config['adapter']}";
        $adapter = new $adapterClass($config, $apiClient);
        $adapter->setLogger($logger);

        // Produkte abrufen (inkl. Stock + Preise, je nach Adapter)
        $products = $adapter->fetchProducts();
        $logger->info("Fetched " . count($products) . " products from {$config['name']}");

        $variantCount = array_sum(array_map(fn($p) => count($p->variants), $products));
        $logger->info("Total variants: {$variantCount}");

        // Feeds generieren (2 Dateien: Parent + Varianten)
        $parentFeedPath = __DIR__ . "/feeds/{$key}_products.csv";
        $variantFeedPath = __DIR__ . "/feeds/{$key}_variants.csv";

        $feedGenerator->generateCsv($products, $parentFeedPath, $variantFeedPath);

        $logger->info("Generated feeds: $parentFeedPath / $variantFeedPath");

    } catch (\Throwable $e) {
        $logger->error("Error syncing {$config['name']}: " . $e->getMessage());
        $logger->error($e->getTraceAsString());
    }
}

$logger->info("=== Sync completed ===");

echo "Fertig! Siehe logs/sync.log für Details.\n";
