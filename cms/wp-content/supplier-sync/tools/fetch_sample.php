<?php
/**
 * Holt Rohdaten von einem MidOcean-Endpoint und speichert einen kleinen
 * Ausschnitt zu einem Produkt, damit wir die Datenstruktur sehen.
 *
 * Ablegen unter: cms/wp-content/supplier-sync/tools/fetch_sample.php
 *
 * Aufruf (im Ordner supplier-sync):
 *   MIDOCEAN_API_KEY=DEIN_KEY php -d memory_limit=2G tools/fetch_sample.php <endpoint> <master_code>
 *
 * Beispiel (Endpoint-Pfade stehen im MidOcean API Implementation Guide):
 *   MIDOCEAN_API_KEY=xxx php -d memory_limit=2G tools/fetch_sample.php print-pricelist/1.0 AR1804
 *
 * Ergebnis liegt in tools/samples/:
 *   *_sample.*  -> kleiner Ausschnitt, den du mir schicken kannst
 *   *_full.*    -> komplette Antwort (nicht schicken, nicht ins Git!)
 */

$endpoint = $argv[1] ?? null;
$needle   = $argv[2] ?? null;
$key      = getenv('MIDOCEAN_API_KEY');
$base     = 'https://api.midocean.com/gateway';

if (!$endpoint || !$needle || !$key) {
    fwrite(STDERR, "Aufruf: MIDOCEAN_API_KEY=... php -d memory_limit=2G tools/fetch_sample.php <endpoint> <master_code>\n");
    exit(1);
}

function http_get(string $url, array $headers = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        fwrite(STDERR, "HTTP-Fehler {$code}: " . substr((string) $body, 0, 300) . "\n");
        exit(1);
    }
    return $body;
}

// 1. Erste Antwort holen (mit API-Key)
$first = trim(http_get("{$base}/{$endpoint}", [
    "x-Gateway-APIKey: {$key}",
    'Accept: text/json',
]));

// 2. Presigned-URL-Muster erkennen (wie im MidOceanAdapter)
if (str_starts_with($first, 'http')) {
    $raw = http_get($first);
} else {
    $j = json_decode($first, true);
    if (is_array($j) && isset($j['preSignedUrl'])) {
        $raw = http_get($j['preSignedUrl']);
    } else {
        $raw = $first;
    }
}

// 3. Speichern
$dir = __DIR__ . '/samples';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$safe    = preg_replace('/[^a-z0-9]+/i', '_', $endpoint);
$decoded = json_decode($raw, true);
$isJson  = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
$ext     = $isJson ? 'json' : 'txt';

file_put_contents("{$dir}/{$safe}_full.{$ext}", $raw);
echo "Komplette Antwort: " . number_format(strlen($raw) / 1024, 0) . " KB gespeichert\n";

// 4. Ausschnitt zum Produkt
if ($isJson) {
    // ALLE Listen der obersten Ebene durchsuchen (z. B. "products" UND "printing_technique_descriptions")
    if (array_is_list($decoded)) {
        $lists = ['(root)' => $decoded];
    } else {
        echo "Top-Level-Keys: " . implode(', ', array_keys($decoded)) . "\n";
        $lists = array_filter($decoded, fn($v) => is_array($v) && array_is_list($v));
    }

    $result = [];
    foreach ($lists as $name => $list) {
        $hits = [];
        foreach ($list as $entry) {
            if (str_contains(json_encode($entry, JSON_UNESCAPED_UNICODE), $needle)) {
                $hits[] = $entry;
                if (count($hits) >= 2) {
                    break;
                }
            }
        }
        echo "{$name}: " . count($list) . " Einträge, Treffer für '{$needle}': " . count($hits) . "\n";
        if ($hits) {
            $result[$name] = $hits;
        } else {
            // Kein Treffer: die ersten Einträge als Beispiel für die Struktur mitschreiben
            $result[$name . '_beispiel'] = array_slice($list, 0, 3);
        }
    }

    $out = "{$dir}/{$safe}_sample.json";
    file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
} else {
    // Kein JSON (z. B. XML): Textfenster um die erste Fundstelle
    $pos = strpos($raw, $needle);
    if ($pos === false) {
        echo "Antwort ist kein JSON und '{$needle}' wurde nicht gefunden. Anfang der Antwort:\n" . substr($raw, 0, 500) . "\n";
        exit(0);
    }
    $out = "{$dir}/{$safe}_sample.txt";
    file_put_contents($out, substr($raw, max(0, $pos - 1500), 6000));
}

echo "Ausschnitt: {$out}\n";