<?php
/**
 * Baut die Inventur-Exportliste (Excel) - v2 mit Kategorien,
 * Beschreibungs-Auszug, Herstellernummer (Cotton Classics) und
 * erweiterter Referenzlieferanten-Liste.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

$tsvPath = __DIR__ . '/cotton_inventory_export.tsv';
$csvPath = __DIR__ . '/IST-Inventurliste_2022_zum_17_01_2023.csv';
$timestamp = date('Y-m-d_H-i-s');
$outPath = __DIR__ . "/{$timestamp}_Inventur_2026_Export.xlsx";

if (!file_exists($tsvPath)) { fwrite(STDERR, "Fehlt: $tsvPath\n"); exit(1); }
if (!file_exists($csvPath)) { fwrite(STDERR, "Fehlt: $csvPath\n"); exit(1); }

/**
 * Kürzt einen Text auf ca. $maxLength Zeichen, ohne mitten im Wort
 * abzuschneiden. mysql --batch escaped eingebettete Newlines als
 * literales "\n" (Backslash+n) im Tab-Output - das ersetzen wir hier
 * durch ein Leerzeichen, statt es als Text stehen zu lassen.
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
 * "Hersteller | Code" (z.B. "Result | R 121M") ODER als
 * "Hersteller | Marketingname" (z.B. "Atlantis | Zoom") - der Teil
 * nach "|" ist NICHT zuverlässig immer die echte Herstellernummer.
 * Wir geben ihn trotzdem aus, mit entsprechend ehrlichem Spaltenkopf.
 */
function extract_manufacturer_code(string $title): string {
    $parts = explode('|', $title, 2);
    return isset($parts[1]) ? trim($parts[1]) : '';
}

/* ------------------------------------------------------------------ *
 *  1. Cotton-Classics-Varianten aus der wp-db-query-TSV einlesen
 * ------------------------------------------------------------------ */

$cottonRows = [];
$fh = fopen($tsvPath, 'r');
$header = fgetcsv($fh, 0, "\t"); // lieferanten_sku, produkttitel, beschreibung, ml_sku, farbe, groesse, preis, kategorien
while (($cols = fgetcsv($fh, 0, "\t")) !== false) {
    if (count($cols) < 8) continue;
    [$lieferantenSku, $produkttitel, $beschreibung, $mlSku, $farbe, $groesse, $preis, $kategorien] = $cols;

    $farbe      = ($farbe === 'NULL') ? '' : $farbe;
    $groesse    = ($groesse === 'NULL') ? '' : $groesse;
    $kategorien = ($kategorien === 'NULL') ? '' : $kategorien;

    $variante = trim($farbe . ($groesse !== '' ? ' / ' . $groesse : ''));
    $marke = trim(explode('|', $produkttitel)[0]);
    $herstellerNummer = extract_manufacturer_code($produkttitel);
    $excerpt = build_excerpt($beschreibung);

    $cottonRows[] = [$lieferantenSku, $herstellerNummer, $mlSku, $produkttitel, $variante, $marke, $kategorien, $excerpt, $preis];
}
fclose($fh);

echo "Cotton Classics: " . count($cottonRows) . " Varianten eingelesen.\n";

/* ------------------------------------------------------------------ *
 *  2. Referenzlieferanten aus der historischen Liste extrahieren
 * ------------------------------------------------------------------ */

// Erweitert um Axpol/REDA/Paul Stricker/L-Shop-Team (Option A - keine
// automatische Abgleichung, siehe Notion für Begründung).
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
 *  3. Excel-Datei schreiben
 * ------------------------------------------------------------------ */

$headerStyle = new Style(fontBold: true, backgroundColor: 'DCDCDC');
$referenceHeaderStyle = new Style(fontBold: true, fontItalic: true, backgroundColor: 'E6E6E6');
$referenceRowStyle = new Style(fontItalic: true, fontColor: '5A5A5A');

$writer = new Writer();
$writer->openToFile($outPath);

$writer->addRow(Row::fromValuesWithStyle(
    ['Lieferanten-SKU', 'Herstellernummer (aus Titel, teils Marketingname statt Code)', 'ML-SKU', 'Produkttitel', 'Variante', 'Marke', 'Kategorien', 'Beschreibung (Auszug)', 'Stückpreis', 'Gezählte Menge'],
    $headerStyle
));

foreach ($cottonRows as $r) {
    [$lieferantenSku, $herstellerNummer, $mlSku, $produkttitel, $variante, $marke, $kategorien, $excerpt, $preis] = $r;
    $writer->addRow(Row::fromValues(
        [$lieferantenSku, $herstellerNummer, $mlSku, $produkttitel, $variante, $marke, $kategorien, $excerpt, $preis, '']
    ));
}

$writer->addRow(Row::fromValues(array_fill(0, 10, '')));

$writer->addRow(Row::fromValuesWithStyle(
    ['Referenz ohne Abgleich - bitte manuell nachschlagen:'],
    $referenceHeaderStyle
));
$writer->addRow(Row::fromValuesWithStyle(
    ['Lieferant', 'Artikel-Nummer', 'Hersteller', 'Artikel', 'Stückpreis (2022)', '', '', '', '', 'Gezählte Menge'],
    $referenceHeaderStyle
));

foreach ($referenceRows as $r) {
    $writer->addRow(Row::fromValuesWithStyle(
        [$r[0], $r[1], $r[2], $r[3], $r[4], '', '', '', '', ''],
        $referenceRowStyle
    ));
}

$writer->close();

echo "\nFertig: $outPath\n";
echo "Cotton Classics: " . count($cottonRows) . " Zeilen | Referenz: " . count($referenceRows) . " Zeilen\n";
