<?php
require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use SupplierSync\Services\ApiClient;
use SupplierSync\Services\FeedGenerator;

// Lock verhindert überlappende Läufe (wichtig für den ML-SKU-Counter, siehe Notion-Doku)
$lockFile = __DIR__ . '/sync.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Sync läuft bereits — Abbruch.\n");
    exit(1);
}

$logger = new Logger('supplier-sync');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/sync.log', Logger::INFO));

$suppliers = require __DIR__ . '/config/suppliers.php';
$apiClient = new ApiClient($logger);
$feedGenerator = new FeedGenerator();

// Bewusst sequenziell (foreach, kein Parallelisieren) — siehe Notion-Entscheidung
foreach ($suppliers as $key => $config) {
    if (!$config['enabled']) {
        $logger->info("Skipping disabled supplier: {$config['name']}");
        continue;
    }

    try {
        $logger->info("Starting sync for: {$config['name']}");

        $adapterClass = "SupplierSync\\Adapters\\{$config['adapter']}";
        $adapter = new $adapterClass($config, $apiClient);

        $products = $adapter->fetchProducts();
        $logger->info("Fetched " . count($products) . " products from {$config['name']}");

        $feedPath = __DIR__ . "/feeds/{$key}.csv";
        $feedGenerator->generateCSV($products, $feedPath);
        $logger->info("Generated feed: $feedPath");

    } catch (\Exception $e) {
        $logger->error("Error syncing {$config['name']}: " . $e->getMessage());
    }
}

$logger->info("Sync completed");

flock($lock, LOCK_UN);
fclose($lock);
