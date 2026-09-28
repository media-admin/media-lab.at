<?php
/**
 * Baut die Inventur-Exportliste (Excel) - v3: Einkaufs- UND Verkaufspreis,
 * einfache Produkte inklusive, Preise als Zahlen.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

$tsvPath   = __DIR__ . '/cotton_inventory_export.tsv';
$csvPath   = __DIR__ . '/IST-Inventurliste_2022_zum_17_01_2023.csv';
$timestamp = date('Y-m-d_H-i-s');
$outPath   = __DIR__ . "/{$timestamp}_Inventur_2026_Export.xlsx";

if (!file_exists($tsvPath)) { fwrite(STDERR, "Fehlt: $tsvPath\n"); exit(1); }
if (!file_exists($csvPath)) { fwrite(STDERR, "Fehlt: $csvPath\n"); exit(1); }

/**
 * Kürzt einen Text auf ca. $maxLength Zeichen, ohne mitten im Wort
 * abzuschneiden. mysql --batch escaped eingebettete Newlines als
 * literales "\n" im Tab-Output - das ersetzen wir durch ein Leerzeichen.
 */
function build_excerpt(string $longText, int $maxLength = 150): string {
    $clean = str_replace(['\\n', '\\r', "\n", "\r"], ' ', $longText);
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    if (mb_strlen($clean) <= $maxLength) return $clean;

    $cut = mb_substr($clean, 0, $maxLength);
    $lastSpace = mb_strrpos($cut, ' ');
    if ($lastSpace !== false) $cut = mb_substr($cut, 0, $lastSpace);
    return $cut . '…';
}

/**
 * Cotton Classics befüllt den Produkttitel uneinheitlich als
 * "Hersteller | Code" ODER "Hersteller | Marketingname" - der Teil nach
 * "|" ist NICHT zuverlässig die echte Herstellernummer.
 */
function extract_manufacturer_code(string $title): string {
    $parts = explode('|', $title, 2);
    return isset($parts[1]) ? trim($parts[1]) : '';
}

/** "NULL"/leer -> '', numerisch -> float (damit Excel Zahlen erkennt). */
function to_number(string $value) {
    $value = trim($value);
    if ($value === '' || $value === 'NULL') return '';
    return is_numeric($value) ? (float) $value : $value;
}

/* ------------------------------------------------------------------ *
 *  1. Cotton-Classics-Zeilen aus der wp-db-query-TSV einlesen
 * ------------------------------------------------------------------ */

$cottonRows = [];
$fh = fopen($tsvPath, 'r');
$header = fgetcsv($fh, 0, "\t", "\x01", ""); // lieferanten_sku, produkttitel, beschreibung, ml_sku, farbe, groesse, verkaufspreis, einkaufspreis, kategorien
while (($cols = fgetcsv($fh, 0, "\t", "\x01", "")) !== false) {
    if (count($cols) < 9) continue;
    [$lieferantenSku, $produkttitel, $beschreibung, $mlSku, $farbe, $groesse, $verkaufspreis, $einkaufspreis, $kategorien] = $cols;

    $farbe      = ($farbe === 'NULL') ? '' : $farbe;
    $groesse    = ($groesse === 'NULL') ? '' : $groesse;
    $kategorien = ($kategorien === 'NULL') ? '' : $kategorien;

    $variante = trim($farbe . ($groesse !== '' ? ' / ' . $groesse : ''));
    $marke = trim(explode('|', $produkttitel)[0]);

    $cottonRows[] = [
        $lieferantenSku,
        extract_manufacturer_code($produkttitel),
        $mlSku,
        $produkttitel,
        $variante,
        $marke,
        $kategorien,
        build_excerpt($beschreibung),
        to_number($einkaufspreis),
        to_number($verkaufspreis),
    ];
}
fclose($fh);

echo "Cotton Classics: " . count($cottonRows) . " Zeilen eingelesen.\n";

/* ------------------------------------------------------------------ *
 *  2. Referenzlieferanten aus der historischen Liste extrahieren
 * ------------------------------------------------------------------ */

$referenceSuppliers = [
    'Waniek', 'Falk & Ross', 'MAPROM',
    'Axpol', 'REDA', 'Paul Stricker', 'L-Shop-Team',
];
$referenceRows = [];

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh, 0, ';');
while (($cols = fgetcsv($fh, 0, ';')) !== false) {
    $lieferant = trim($cols[0] ?? '');
    if ($lieferant === '') continue;

    $matches = false;
    foreach ($referenceSuppliers as $needle) {
        if (stripos($lieferant, $needle) !== false) { $matches = true; break; }
    }
    if (!$matches) continue;

    $referenceRows[] = [$lieferant, $cols[1] ?? '', $cols[2] ?? '', $cols[3] ?? '', $cols[6] ?? ''];
}
fclose($fh);

echo "Referenzliste (7 Lieferanten): " . count($referenceRows) . " Zeilen eingelesen.\n";

/* ------------------------------------------------------------------ *
 *  3. Excel-Datei schreiben (11 Spalten)
 * ------------------------------------------------------------------ */

$headerStyle          = new Style(fontBold: true, backgroundColor: 'DCDCDC');
$referenceHeaderStyle = new Style(fontBold: true, fontItalic: true, backgroundColor: 'E6E6E6');
$referenceRowStyle    = new Style(fontItalic: true, fontColor: '5A5A5A');

$writer = new Writer();
$writer->openToFile($outPath);

$writer->addRow(Row::fromValuesWithStyle(
    ['Lieferanten-SKU', 'Herstellernummer (aus Titel, teils Marketingname statt Code)', 'ML-SKU', 'Produkttitel', 'Variante', 'Marke', 'Kategorien', 'Beschreibung (Auszug)', 'Einkaufspreis (_ml_cost_price)', 'Verkaufspreis (_price)', 'Gezählte Menge'],
    $headerStyle
));

foreach ($cottonRows as $r) {
    $writer->addRow(Row::fromValues(array_merge($r, [''])));
}

$writer->addRow(Row::fromValues(array_fill(0, 11, '')));

$writer->addRow(Row::fromValuesWithStyle(
    ['Referenz ohne Abgleich - bitte manuell nachschlagen:'],
    $referenceHeaderStyle
));
$writer->addRow(Row::fromValuesWithStyle(
    ['Lieferant', 'Artikel-Nummer', 'Hersteller', 'Artikel', 'Stückpreis (2022)', '', '', '', '', '', 'Gezählte Menge'],
    $referenceHeaderStyle
));

foreach ($referenceRows as $r) {
    $writer->addRow(Row::fromValuesWithStyle(
        [$r[0], $r[1], $r[2], $r[3], $r[4], '', '', '', '', '', ''],
        $referenceRowStyle
    ));
}

$writer->close();

echo "\nFertig: $outPath\n";
echo "Cotton Classics: " . count($cottonRows) . " Zeilen | Referenz: " . count($referenceRows) . " Zeilen\n";
