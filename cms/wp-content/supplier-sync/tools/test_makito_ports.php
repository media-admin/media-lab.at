<?php
/**
 * Makito-Portcheck fuer supplier-sync (nur Kommandozeile, rein lesend).
 *
 * Ablage:  cms/wp-content/supplier-sync/tools/test_makito_ports.php
 * Aufruf:  Hetzner konsoleH -> Cronjob Manager -> Interpreter "PHP 8.4",
 *          Skript = absoluter Pfad zu dieser Datei, Intervall "Manuell"
 *          (oder einmalig per Freitext) -> danach logs/test_makito_ports.log lesen.
 *          Lokal:  php tools/test_makito_ports.php [Feed.php]
 *
 * Hintergrund: Hetzner blockiert ausgehend Port 8080, die Makito-Feeds
 * liegen aber auf print.makito.es:8080. Das Skript prueft, ob derselbe
 * Feed auch ueber Port 80 (http) oder Port 443 (https) antwortet.
 *
 * Was es macht: ruft den Feed (Standard: allstockfile.php, der kleinste)
 * ueber http:80, https:443 und zur Kontrolle http:8080 ab, liest aber nur
 * die ersten 4 KB und bricht dann ab. Es wird nichts gespeichert und
 * nichts importiert.
 *
 * Ausgabe: HTTP-Status, Content-Type, ob die Antwort wie XML aussieht,
 * die tatsaechliche Ziel-Adresse nach Weiterleitungen (OHNE Query, also
 * ohne Token) und die Dauer. Der Token wird nie ausgegeben; er wird auch
 * aus Fehlermeldungen und Antwortauszuegen entfernt.
 * Ueber den Browser ist das Skript nicht aufrufbar.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Vienna');

$base = is_file(__DIR__ . '/sync.php') ? __DIR__ : dirname(__DIR__);
$lines = [];

function out(string $line = ''): void {
    global $lines;
    $lines[] = $line;
    echo $line . PHP_EOL;
}

// --- .env laden (nur den Token) ---------------------------------------
$token = '';
$autoload = $base . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
    if (class_exists(\Dotenv\Dotenv::class)) {
        \Dotenv\Dotenv::createImmutable($base)->safeLoad();
    }
}
$token = (string) ($_ENV['MAKITO_CUSTOMER_TOKEN'] ?? getenv('MAKITO_CUSTOMER_TOKEN') ?: '');
if ($token === '') {
    // Ersatzweg ohne Composer: .env selbst lesen
    $envFile = $base . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            if (preg_match('/^\s*MAKITO_CUSTOMER_TOKEN\s*=\s*(.*)$/', $l, $m)) {
                $token = trim($m[1], " \t\"'");
            }
        }
    }
}

/** Token aus beliebigem Text entfernen. */
function mask(string $s): string {
    global $token;
    if ($token !== '') {
        $s = str_replace([$token, urlencode($token)], '***', $s);
    }
    return $s;
}

/** Ziel-Adresse ohne Query/Token darstellen. */
function plainUrl(string $url): string {
    $p = parse_url($url);
    if (!$p) {
        return '(unlesbar)';
    }
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    return ($p['scheme'] ?? '?') . '://' . ($p['host'] ?? '?') . $port . ($p['path'] ?? '');
}

$feed = $argv[1] ?? 'allstockfile.php';
if (!preg_match('/^[A-Za-z0-9_]+\.php$/', $feed)) {
    out('Ungueltiger Feed-Name: nur Dateiname wie allstockfile.php erlaubt.');
    exit(1);
}

out('Makito-Portcheck, ' . date('Y-m-d H:i:s T'));
out('Ordner: ' . $base);
out('Feed:   /user/xml/' . $feed . '  (nur die ersten 4 KB werden gelesen)');
out('Token in .env: ' . ($token !== '' ? 'gesetzt' : 'FEHLT'));
out();
if ($token === '') {
    out('Ohne MAKITO_CUSTOMER_TOKEN ist kein Test moeglich.');
    exit(1);
}

$tests = [
    ['http',  80,   'Port 80 (http)'],
    ['https', 443,  'Port 443 (https)'],
    ['http',  8080, 'Port 8080 (Kontrolle, vermutlich gesperrt)'],
];

$summary = [];

function probe(string $scheme, int $port, string $feed, string $token, bool $verifyTls): array {
    $defaultPort = $scheme === 'https' ? 443 : 80;
    $host = 'print.makito.es' . ($port === $defaultPort ? '' : ':' . $port);
    $url  = "{$scheme}://{$host}/user/xml/{$feed}?pszinternal=" . urlencode($token);

    $body = '';
    $limit = 4096;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_HTTPHEADER     => ['Accept: application/xml, text/xml, */*'],
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, $limit) {
            $body .= $chunk;
            // Abbruch nach 4 KB: -1 liefert absichtlich "write error" (errno 23)
            return strlen($body) >= $limit ? -1 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $info  = [
        'code'      => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'ctype'     => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'effective' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'redirects' => (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
        'time'      => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        'errno'     => $errno,
        'error'     => $error,
        'body'      => $body,
        'ip'        => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
    ];
    curl_close($ch);
    return $info;
}

foreach ($tests as [$scheme, $port, $label]) {
    out("== {$label} ==");
    $verify = true;
    $r = probe($scheme, $port, $feed, $token, true);

    // Zertifikatsproblem nur zur Diagnose ohne Pruefung wiederholen
    if ($scheme === 'https' && in_array($r['errno'], [35, 51, 58, 60, 77, 82, 83, 90, 91], true)) {
        out('TLS-Pruefung fehlgeschlagen: ' . mask($r['error']));
        out('Wiederhole nur zur Diagnose OHNE Zertifikatspruefung ...');
        $r = probe($scheme, $port, $feed, $token, false);
        $verify = false;
    }

    $aborted = ($r['errno'] === 23 && strlen($r['body']) >= 4096); // gewollter Abbruch
    $connectOk = $r['errno'] === 0 || $aborted;

    if (!$connectOk) {
        out('KEINE ANTWORT: curl ' . $r['errno'] . ' ' . mask($r['error']));
        $summary[] = [$label, 'FEHLER (curl ' . $r['errno'] . ')'];
        out();
        continue;
    }

    $head   = ltrim($r['body']);
    $isXml  = $head !== '' && ($head[0] === '<');
    $status = 'HTTP ' . $r['code'];
    out('Status:       ' . $status . ($verify ? '' : '  (Zertifikat NICHT geprueft)'));
    out('Content-Type: ' . ($r['ctype'] ?: '(keiner)'));
    out('Ziel-Adresse: ' . plainUrl($r['effective']) . ($r['redirects'] ? ' (nach ' . $r['redirects'] . ' Weiterleitung(en))' : ''));
    out('Server-IP:    ' . $r['ip']);
    out('Gelesen:      ' . strlen($r['body']) . ' Bytes in ' . round($r['time'], 2) . ' s');
    out('Sieht nach XML aus: ' . ($isXml ? 'JA' : 'NEIN'));
    if ($r['code'] >= 400 || !$isXml) {
        $excerpt = preg_replace('/\s+/', ' ', mask(substr($r['body'], 0, 200)));
        out('Antwort-Anfang: ' . $excerpt);
    } else {
        $excerpt = preg_replace('/\s+/', ' ', mask(substr($head, 0, 120)));
        out('XML-Anfang:     ' . $excerpt);
    }
    $ok = ($r['code'] >= 200 && $r['code'] < 300 && $isXml);
    $summary[] = [$label, $ok ? 'FUNKTIONIERT' : $status . ($isXml ? '' : ', kein XML')];
    out();
}

out('== Ergebnis ==');
foreach ($summary as [$label, $result]) {
    out(str_pad($label, 48) . $result);
}
out();
out('Hinweis: gelesen werden nur 4 KB, es wird nichts gespeichert.');
out('Bei Makito-Rate-Limit nicht oefter als noetig wiederholen.');

$logDir = is_dir($base . '/logs') ? $base . '/logs' : $base;
@file_put_contents($logDir . '/test_makito_ports.log', implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
out('Log: ' . $logDir . '/test_makito_ports.log');
