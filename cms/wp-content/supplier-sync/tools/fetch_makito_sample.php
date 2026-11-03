<?php
/**
 * Holt einen Makito-XML-Feed und speichert Struktur-Überblick plus
 * Beispieldatensätze, damit wir das Format sehen.
 *
 * Ablegen unter: cms/wp-content/supplier-sync/tools/fetch_makito_sample.php
 *
 * Aufruf (im Ordner supplier-sync):
 *   MAKITO_PSZINTERNAL=DEIN_TOKEN php -d memory_limit=2G tools/fetch_makito_sample.php <Feed> [Suchbegriff] [Sprache]
 *
 * Beispiele:
 *   MAKITO_PSZINTERNAL=xxx php -d memory_limit=2G tools/fetch_makito_sample.php ItemPrintingFile.php
 *   MAKITO_PSZINTERNAL=xxx php -d memory_limit=2G tools/fetch_makito_sample.php PrintJobsPrices.php
 *   MAKITO_PSZINTERNAL=xxx php -d memory_limit=2G tools/fetch_makito_sample.php ItemPrintingFile.php 1001 ale
 *
 * <Feed>        Dateiname laut Makito-Mail, z. B. ItemPrintingFile.php
 * [Suchbegriff] optional: Artikelnummer, deren Datensatz zusätzlich gespeichert wird
 * [Sprache]     optional: ldl-Parameter (esp, ing, por, ita, ale, hol, fra), "ale" = Deutsch.
 *               Nur ItemDataFile.php und ItemPrintingFile.php unterstützen ihn.
 *
 * Ergebnis liegt in tools/samples/:
 *   makito_*_sample.txt -> Überblick + wenige Datensätze, den schickst du mir
 *   makito_*_full.xml   -> komplette Datei (nicht schicken, nicht ins Git!)
 */

$feed   = $argv[1] ?? null;
$needle = $argv[2] ?? null;
$ldl    = $argv[3] ?? null;
$token  = getenv('MAKITO_PSZINTERNAL');

$isLocal = $feed !== null && is_file($feed); // nur zum Testen mit einer lokalen XML-Datei

function cut(string $s, int $n): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

if (!$feed || (!$isLocal && !$token)) {
    fwrite(STDERR, "Aufruf: MAKITO_PSZINTERNAL=... php -d memory_limit=2G tools/fetch_makito_sample.php <Feed> [Suchbegriff] [Sprache]\n");
    exit(1);
}

$dir = __DIR__ . '/samples';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$safe     = preg_replace('/[^a-z0-9]+/i', '_', basename($feed, '.php'));
$fullPath = $isLocal ? $feed : "{$dir}/makito_{$safe}_full.xml";

// 1. Download direkt in eine Datei (die Feeds können groß sein)
if (!$isLocal) {
    $url = "http://print.makito.es:8080/user/xml/{$feed}?pszinternal=" . urlencode($token)
         . ($ldl ? '&ldl=' . urlencode($ldl) : '');

    $fp = fopen($fullPath, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 600,
    ]);
    $ok   = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code >= 400) {
        // URL (mit Token) wird bewusst nicht ausgegeben
        fwrite(STDERR, "HTTP-Fehler {$code}: " . substr((string) file_get_contents($fullPath), 0, 300) . "\n");
        exit(1);
    }
}

$size = filesize($fullPath);
echo "Datei: " . number_format($size / 1024, 0) . " KB\n";

$head = (string) file_get_contents($fullPath, false, null, 0, 600);
if (!str_contains($head, '<')) {
    echo "Antwort sieht nicht nach XML aus. Anfang:\n" . substr($head, 0, 300) . "\n";
    exit(1);
}

// 2. Streaming-Parser: Struktur zählen, je Elementname 2 Beispiele, plus Treffer zum Suchbegriff
$r = new XMLReader();
if (!$r->open($fullPath, null, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE)) {
    echo "XML konnte nicht geöffnet werden.\n";
    exit(1);
}

$root    = null;
$counts  = [];
$samples = [];
$hits    = [];
$cap     = 8000; // Zeichen pro Datensatz

while ($r->read() && $r->nodeType !== XMLReader::ELEMENT) {
    // zum Wurzelelement vorspulen
}
$root = $r->name;
$r->read();

while (true) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->depth === 1) {
        $name = $r->name;
        $counts[$name] = ($counts[$name] ?? 0) + 1;

        $needsSample = count($samples[$name] ?? []) < 2;
        $needsHit    = $needle !== null && count($hits) < 2;

        if ($needsSample || $needsHit) {
            $xml = $r->readOuterXml();
            if ($needsSample) {
                $samples[$name][] = cut($xml, $cap);
            }
            if ($needsHit && str_contains($xml, $needle)) {
                $hits[] = cut($xml, $cap);
            }
        }
        if (!$r->next()) {
            break;
        }
    } else {
        if (!$r->read()) {
            break;
        }
    }
}
$r->close();

// 3. Ausgabe
$out  = "Wurzelelement: <{$root}>\n";
$out .= "Datensätze unter der Wurzel (Elementname => Anzahl):\n";
foreach ($counts as $n => $c) {
    $out .= "  {$n} => {$c}\n";
}
$out .= "\n===== Je Elementname die ersten Datensätze =====\n";
foreach ($samples as $n => $list) {
    foreach ($list as $i => $xml) {
        $out .= "\n--- {$n} #" . ($i + 1) . " ---\n{$xml}\n";
    }
}
if ($needle !== null) {
    $out .= "\n===== Treffer für '{$needle}' (" . count($hits) . ") =====\n";
    foreach ($hits as $i => $xml) {
        $out .= "\n--- Treffer #" . ($i + 1) . " ---\n{$xml}\n";
    }
}

$samplePath = "{$dir}/makito_{$safe}_sample.txt";
file_put_contents($samplePath, $out);

echo "Datensätze: " . json_encode($counts) . "\n";
if ($needle !== null) {
    echo "Treffer für '{$needle}': " . count($hits) . "\n";
}
echo "Ausschnitt: {$samplePath}\n";
