<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use SupplierSync\Services\ApiClient;
use SupplierSync\Services\FeedGenerator;
use SupplierSync\Services\DatabaseClient;

// .env laden (nötig für Lieferanten-Zugangsdaten wie MAKITO_CUSTOMER_TOKEN)
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if (!function_exists('ml_env')) {
    /**
     * Liest eine Umgebungsvariable robust aus - $_ENV bleibt auf manchen
     * PHP-CLI-Setups leer (variables_order ohne "E" in php.ini), auch wenn
     * phpdotenv sie korrekt per putenv() gesetzt hat. getenv() funktioniert
     * davon unabhängig zuverlässig.
     */
    function ml_env(string $key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);
        return $value !== false && $value !== null && $value !== '' ? $value : $default;
    }
}

// Logging
$logger = new Logger('supplier-sync');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/sync.log', Logger::INFO));

// Config laden
$suppliers = require __DIR__ . '/config/suppliers.php';

// Services
$apiClient = new ApiClient($logger);
$feedGenerator = new FeedGenerator();

$onlySupplier = $argv[1] ?? null;

// Preiskalkulation Cotton Classics: VKEinzel aus dem Feed ist der Einkaufs-
// preis (siehe CottonClassicsAdapter), der Aufschlagsfaktor wird im WP-Admin
// gepflegt (WooCommerce -> Preiskalkulation) und hier direkt per PDO aus
// wp_options gelesen, da dieses Skript kein WordPress laedt. Nur laden, wenn
// Cotton Classics in diesem Lauf ueberhaupt verarbeitet wird.
$willProcessCottonClassics = isset($suppliers['cotton_classics'])
    && ($onlySupplier === null || $onlySupplier === 'cotton_classics')
    && $suppliers['cotton_classics']['enabled'];

if ($willProcessCottonClassics) {
    try {
        $db = new DatabaseClient(
            ml_env('DB_HOST', '127.0.0.1'),
            (int) ml_env('DB_PORT', 3306),
            ml_env('DB_NAME'),
            ml_env('DB_USER'),
            ml_env('DB_PASS'),
            ml_env('DB_TABLE_PREFIX', 'wp_')
        );
        $factorRaw = $db->getOption('ml_markup_factor_cotton_classics');
        $suppliers['cotton_classics']['markup_factor'] = $factorRaw !== null ? (float) $factorRaw : 1.0;
        $logger->info("Cotton-Classics-Aufschlagsfaktor geladen: {$suppliers['cotton_classics']['markup_factor']}");
    } catch (\Throwable $e) {
        $logger->warning("Konnte Aufschlagsfaktor nicht laden, verwende Fallback 1.0: " . $e->getMessage());
        $suppliers['cotton_classics']['markup_factor'] = 1.0;
    }
}

foreach ($suppliers as $key => $config) {
    if ($onlySupplier !== null && $key !== $onlySupplier) {
        continue;
    }
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