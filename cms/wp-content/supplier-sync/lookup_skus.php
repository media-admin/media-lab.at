<?php
/* ============================================================
 * FILE: lookup_skus.php
 *
 * Läuft NACH dem Parent-Produkt-Import (WP All Import), VOR dem
 * Varianten-Import. Liest die tatsächlich vergebenen ML-SKUs aus der
 * Datenbank und reichert den Varianten-Feed um eine Spalte
 * "parent_current_sku" an - genau der Wert, den WP All Import beim
 * Varianten-Import als "SKU Element für Eltern" zum Matchen braucht.
 *
 * Aufruf: php lookup_skus.php <supplier_key>
 * Beispiel: php lookup_skus.php midocean
 * ============================================================ */

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use League\Csv\Reader;
use League\Csv\Writer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use SupplierSync\Services\DatabaseClient;

// .env laden
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function ml_env(string $key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    return $value !== false && $value !== null ? $value : $default;
}

$logger = new Logger('lookup-skus');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/lookup_skus.log', Logger::INFO));

$supplierKey = $argv[1] ?? null;
if (!$supplierKey) {
    fwrite(STDERR, "Nutzung: php lookup_skus.php <supplier_key>\n");
    fwrite(STDERR, "Beispiel: php lookup_skus.php midocean\n");
    exit(1);
}

$suppliers = require __DIR__ . '/config/suppliers.php';
if (!isset($suppliers[$supplierKey])) {
    fwrite(STDERR, "Unbekannter supplier_key '{$supplierKey}'. Verfügbar: " . implode(', ', array_keys($suppliers)) . "\n");
    exit(1);
}

// Supplier-Code (z.B. 'DIM') aus der Adapter-Konfiguration ableiten.
// Wir nutzen dieselbe Konvention wie im Adapter: Token steht im
// Klassennamen-Kontext, aber sicherer ist es, ihn explizit in
// config/suppliers.php zu hinterlegen (siehe Hinweis unten).
$supplierCode = $suppliers[$supplierKey]['supplier_code'] ?? null;
if (!$supplierCode) {
    fwrite(STDERR, "config/suppliers.php: Bitte 'supplier_code' (z.B. 'DIM') für '{$supplierKey}' ergänzen.\n");
    exit(1);
}

try {
    $db = new DatabaseClient(
        ml_env('DB_HOST', '127.0.0.1'),
        (int) ml_env('DB_PORT', 3306),
        ml_env('DB_NAME'),
        ml_env('DB_USER'),
        ml_env('DB_PASS'),
        ml_env('DB_TABLE_PREFIX', 'wp_')
    );

    $logger->info("Lade aktuelle SKUs für Supplier-Code '{$supplierCode}' aus der Datenbank...");
    $skuMap = $db->getCurrentSkusBySupplier($supplierCode);
    $logger->info("Gefunden: " . count($skuMap) . " Parent-Produkte mit zugewiesener SKU.");

    if (count($skuMap) === 0) {
        $logger->warning("Keine Parent-SKUs gefunden. Wurde der Parent-Import bereits ausgeführt?");
    }

    // Varianten-Feed einlesen
    $inputPath = __DIR__ . "/feeds/{$supplierKey}_variants.csv";
    if (!file_exists($inputPath)) {
        throw new \RuntimeException("Varianten-Feed nicht gefunden: {$inputPath}");
    }

    $reader = Reader::createFromPath($inputPath, 'r');
    $reader->setHeaderOffset(0);
    $header = $reader->getHeader();

    $outputPath = __DIR__ . "/feeds/{$supplierKey}_variants_enriched.csv";
    $writer = Writer::createFromPath($outputPath, 'w+');
    $writer->insertOne([...$header, 'parent_current_sku']);

    $matched = 0;
    $unmatched = 0;

    foreach ($reader->getRecords() as $record) {
        // parent_import_uid hat das Format "DIM|AR1249" - wir brauchen
        // nur den Teil nach dem Trennzeichen (= supplier_sku).
        $parentImportUid = $record['parent_import_uid'] ?? '';
        $parts = explode('|', $parentImportUid, 2);
        $supplierSku = $parts[1] ?? '';

        $currentSku = $skuMap[$supplierSku] ?? null;

        if ($currentSku === null) {
            $unmatched++;
            $logger->warning("Keine aktuelle SKU gefunden für parent_import_uid '{$parentImportUid}' (supplier_sku '{$supplierSku}') - Zeile wird übersprungen.");
            continue; // Variante ohne bekannten Parent NICHT in den Output schreiben
        }

        $matched++;
        $writer->insertOne([...array_values($record), $currentSku]);
    }

    $logger->info("Fertig: {$matched} Varianten zugeordnet, {$unmatched} übersprungen (kein Parent gefunden).");
    $logger->info("Angereicherter Feed geschrieben: {$outputPath}");

    echo "Fertig! {$matched} zugeordnet, {$unmatched} übersprungen. Siehe logs/lookup_skus.log für Details.\n";
    echo "Output: {$outputPath}\n";

} catch (\Throwable $e) {
    $logger->error('Fehler: ' . $e->getMessage());
    $logger->error($e->getTraceAsString());
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}