<?php
/* ============================================================
 * FILE: download_cotton_images.php
 *
 * Spiegelt die von CottonClassicsAdapter referenzierten Packshot-/
 * Picture-Dateien vom Cotton-Classics-FTP (kein öffentlicher HTTP-
 * Zugriff) lokal nach wp-content/uploads/, damit WP All Import sie
 * wie gewohnt per "Download images hosted elsewhere" abholen kann.
 *
 * Aufruf: php download_cotton_images.php
 * VOR dem WP-All-Import-Lauf ausführen (nach sync.php cotton_classics).
 * ============================================================ */

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use OpenSpout\Reader\XLSX\Reader;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function ml_env(string $key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    return $value !== false && $value !== null ? $value : $default;
}

$logger = new Logger('cotton-images');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/cotton_images.log', Logger::INFO));

$suppliers = require __DIR__ . '/config/suppliers.php';
$config = $suppliers['cotton_classics'] ?? null;
if (!$config) {
    fwrite(STDERR, "config/suppliers.php: 'cotton_classics' Eintrag fehlt.\n");
    exit(1);
}

$filePath = $config['file_path'] ?? '';
if (!$filePath || !is_file($filePath)) {
    fwrite(STDERR, "Cotton Classics Export-Datei nicht gefunden unter: {$filePath}\n");
    exit(1);
}

$localDir = rtrim((string) $config['image_local_dir'], '/');
if (!is_dir($localDir) && !mkdir($localDir, 0755, true)) {
    fwrite(STDERR, "Konnte Zielverzeichnis nicht anlegen: {$localDir}\n");
    exit(1);
}

$needed = [];
$reader = new Reader();
$reader->open($filePath);
foreach ($reader->getSheetIterator() as $sheet) {
    if (!in_array($sheet->getName(), ['SKU List', 'Style List'], true)) {
        continue;
    }
    $colIndex = $sheet->getName() === 'SKU List' ? 17 : 14;
    $headerSkipped = false;
    foreach ($sheet->getRowIterator() as $row) {
        if (!$headerSkipped) { $headerSkipped = true; continue; }
        $cells = $row->toArray();
        $filename = trim((string) ($cells[$colIndex] ?? ''));
        if ($filename !== '') {
            $needed[$filename] = true;
        }
    }
}
$reader->close();

$logger->info(count($needed) . " eindeutige Bilddateien in der Export-Datei referenziert.");

$toDownload = [];
foreach (array_keys($needed) as $filename) {
    if (!is_file($localDir . '/' . $filename)) {
        $toDownload[] = $filename;
    }
}

echo "Gesamt referenziert: " . count($needed) . "\n";
echo "Bereits lokal vorhanden: " . (count($needed) - count($toDownload)) . "\n";
echo "Werden jetzt heruntergeladen: " . count($toDownload) . "\n";

if (empty($toDownload)) {
    echo "Nichts zu tun - alle Bilder bereits lokal vorhanden.\n";
    exit(0);
}

$ftpOptions = FtpConnectionOptions::fromArray([
    'host'     => $config['ftp_host'],
    'username' => $config['ftp_user'],
    'password' => $config['ftp_pass'],
    'port'     => (int) $config['ftp_port'],
    'root'     => '/' . trim($config['ftp_image_root'], '/') . '/GUID/Alle_72dpi',
    'passive'  => true,
]);
$fs = new Filesystem(new FtpAdapter($ftpOptions));

$downloaded = 0;
$failed = 0;
foreach ($toDownload as $filename) {
    try {
        $stream = $fs->readStream($filename);
        file_put_contents($localDir . '/' . $filename, stream_get_contents($stream));
        fclose($stream);
        $downloaded++;
        if ($downloaded % 100 === 0) {
            echo "{$downloaded} / " . count($toDownload) . " heruntergeladen...\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        $logger->warning("Download fehlgeschlagen für '{$filename}': " . $e->getMessage());
    }
}

$logger->info("Fertig: {$downloaded} heruntergeladen, {$failed} fehlgeschlagen.");
echo "Fertig! {$downloaded} heruntergeladen, {$failed} fehlgeschlagen. Siehe logs/cotton_images.log für Details.\n";
