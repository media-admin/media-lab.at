<?php
/**
 * Server-Diagnose fuer supplier-sync (nur Kommandozeile, rein lesend).
 *
 * Ablage:  cms/wp-content/supplier-sync/tools/diagnose_server.php
 * Aufruf:  Hetzner konsoleH -> Cronjob Manager -> Interpreter "PHP 8.4",
 *          Skript = absoluter Pfad zu dieser Datei, Intervall "Manuell"
 *          (oder einmalig per Freitext) -> danach logs/diagnose.log lesen.
 *
 * Was es macht: zeigt PHP-Werte der Kommandozeile, vorhandene Dateien und
 * Erweiterungen, ob vendor/ und die Composer-Pakete da sind, ob die
 * Datenbankverbindung aus der .env klappt und ob die Lieferanten-Server
 * erreichbar sind (nur TCP-Verbindung, keine Feeds, keine Zugangsdaten).
 *
 * Es schreibt NICHTS ausser logs/diagnose.log und gibt keine Passwoerter,
 * Tokens oder Schluessel aus. Ueber den Browser ist es nicht aufrufbar.
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
function section(string $title): void {
    out();
    out('== ' . $title . ' ==');
}
function row(string $label, string $value, string $status = ''): void {
    out(sprintf('%-34s %s%s', $label, $value, $status !== '' ? '   [' . $status . ']' : ''));
}
function yn($b): string {
    return $b ? 'ja' : 'nein';
}
function ini(string $key): string {
    $v = ini_get($key);
    return $v === false ? '(nicht gesetzt)' : ($v === '' ? '(leer)' : $v);
}
function tcp(string $host, int $port, int $timeout = 8): string {
    $t = microtime(true);
    $ip = gethostbyname($host);
    if ($ip === $host) {
        return 'DNS FEHLER (kein Treffer)';
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms = (int) round((microtime(true) - $t) * 1000);
    if ($fp) {
        fclose($fp);
        return "verbunden ({$ip}, {$ms} ms)";
    }
    return "KEINE VERBINDUNG ({$ip}): " . trim("{$errno} {$errstr}");
}

out('supplier-sync Server-Diagnose, ' . date('Y-m-d H:i:s T'));
out('Ordner: ' . $base);

/* ---------------------------------------------------------------- PHP */
section('PHP (Kommandozeile)');
row('Version', PHP_VERSION);
row('SAPI', PHP_SAPI);
row('Binary', PHP_BINARY);
row('php.ini', php_ini_loaded_file() ?: '(keine)');
row('memory_limit', ini('memory_limit'));
row('max_execution_time', ini('max_execution_time') . ' (0 = unbegrenzt)');
row('variables_order', ini('variables_order'));
row('allow_url_fopen', ini('allow_url_fopen'));
row('open_basedir', ini('open_basedir'));
row('disable_functions', ini('disable_functions'));
row('default_socket_timeout', ini('default_socket_timeout'));
foreach (['exec', 'shell_exec', 'proc_open', 'popen'] as $fn) {
    row("Funktion {$fn}()", yn(function_exists($fn) && !in_array($fn, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)));
}
$before = ini('memory_limit');
@ini_set('memory_limit', '1024M');
$after = ini('memory_limit');
@ini_set('memory_limit', $before);
row('memory_limit zur Laufzeit anhebbar', "{$before} -> {$after}", $after === '1024M' ? 'OK' : 'GRENZE');
row('Benutzer / Arbeitsordner', get_current_user() . ' / ' . getcwd());
$free = @disk_free_space($base);
row('Freier Speicher im Ordner', $free ? round($free / 1073741824, 1) . ' GB' : '(unbekannt)');

section('PHP-Erweiterungen');
$need = ['curl', 'pdo_mysql', 'mbstring', 'xml', 'simplexml', 'dom', 'libxml', 'zip', 'ftp', 'openssl', 'json', 'fileinfo', 'iconv'];
foreach ($need as $ext) {
    row($ext, yn(extension_loaded($ext)), extension_loaded($ext) ? 'OK' : 'FEHLT');
}
foreach (['gd', 'intl', 'sodium'] as $ext) {
    row($ext . ' (optional)', yn(extension_loaded($ext)));
}

/* -------------------------------------------------------------- Dateien */
section('Dateien und Ordner');
$files = ['vendor/autoload.php', '.env', 'config/suppliers.php', 'sync.php', 'lookup_skus.php', 'download_cotton_images.php', 'tools/dedupe_suppliers.php', 'config/duplicates.php'];
foreach ($files as $f) {
    $ok = is_file($base . '/' . $f);
    row($f, $ok ? 'vorhanden' : 'FEHLT', $ok ? 'OK' : 'FEHLT');
}
$logDir = $base . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
foreach (['logs', 'feeds'] as $d) {
    $p = $base . '/' . $d;
    row($d . '/', is_dir($p) ? (is_writable($p) ? 'vorhanden, beschreibbar' : 'vorhanden, NICHT beschreibbar') : 'fehlt', is_dir($p) && is_writable($p) ? 'OK' : 'PRUEFEN');
}
// supplier-sync liegt in <wp-root>/wp-content/supplier-sync
$wpRoot = realpath($base . '/../..');
$wpLoad = $wpRoot ? $wpRoot . '/wp-load.php' : '';
row('WordPress wp-load.php', $wpLoad !== '' && is_file($wpLoad) ? 'gefunden (' . $wpRoot . ')' : 'nicht gefunden');
$wpConfig = $wpRoot && is_file($wpRoot . '/wp-config.php') ? $wpRoot . '/wp-config.php' : '';
row('wp-config.php', $wpConfig !== '' ? 'vorhanden' : 'FEHLT', $wpConfig !== '' ? 'OK' : 'FEHLT');
$plugins = realpath($base . '/../plugins');
if ($plugins) {
    foreach (glob($plugins . '/wp-all-import*', GLOB_ONLYDIR) ?: [] as $dir) {
        $main = glob($dir . '/*.php');
        $ver = '';
        foreach ($main ?: [] as $m) {
            $head = @file_get_contents($m, false, null, 0, 4096);
            if ($head && preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $head, $mm)) {
                $ver = trim($mm[1]);
                break;
            }
        }
        row(basename($dir), $ver !== '' ? 'Version ' . $ver : 'vorhanden');
    }
}

/* ------------------------------------------------------------------ .env */
section('.env (nur Schluesselnamen, keine Werte)');
$env = [];
if (is_file($base . '/.env')) {
    foreach (file($base . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || strpos($l, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
    row('Schluessel in der .env', implode(', ', array_keys($env)));
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_TABLE_PREFIX', 'MAKITO_CUSTOMER_TOKEN'] as $k) {
        $has = isset($env[$k]) && $env[$k] !== '';
        row($k, $has ? 'gesetzt' : 'fehlt oder leer', $has ? 'OK' : 'PRUEFEN');
    }
} else {
    out('Keine .env gefunden.');
}

/* --------------------------------------------------------------- Composer */
section('Composer-Pakete (vendor/)');
if (is_file($base . '/vendor/autoload.php')) {
    try {
        require $base . '/vendor/autoload.php';
        $classes = [
            'GuzzleHttp\\Client' => 'guzzle',
            'Monolog\\Logger' => 'monolog',
            'League\\Csv\\Writer' => 'league/csv',
            'Dotenv\\Dotenv' => 'phpdotenv',
            'League\\Flysystem\\Ftp\\FtpAdapter' => 'flysystem-ftp',
            'OpenSpout\\Writer\\XLSX\\Writer' => 'openspout',
            'SupplierSync\\Services\\DatabaseClient' => 'SupplierSync (src/)',
        ];
        foreach ($classes as $c => $label) {
            $ok = class_exists($c);
            row($label, $ok ? 'geladen' : 'FEHLT', $ok ? 'OK' : 'FEHLT');
        }
    } catch (Throwable $e) {
        out('Autoload-Fehler: ' . $e->getMessage());
    }
} else {
    out('vendor/ fehlt. Entweder den Ordner supplier-sync/vendor mit hochladen oder auf dem Server "composer install" ausfuehren (kein SSH: Ordner hochladen).');
}

/* ----------------------------------------------------------- Datenbank */
section('Datenbank');
if (extension_loaded('pdo_mysql') && !empty($env['DB_NAME'])) {
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = (int) ($env['DB_PORT'] ?? 3306);
    $pref = $env['DB_TABLE_PREFIX'] ?? 'wp_';
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$env['DB_NAME']};charset=utf8mb4", $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        row('Verbindung', 'OK (' . $pdo->query('SELECT VERSION()')->fetchColumn() . ')', 'OK');
        $has = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($pref . 'options'))->fetchColumn();
        row('Tabelle ' . $pref . 'options', $has ? 'vorhanden' : 'fehlt (Datenbank noch leer?)', $has ? 'OK' : 'PRUEFEN');
        if ($has) {
            $st = $pdo->prepare("SELECT option_name, option_value FROM {$pref}options WHERE option_name IN ('siteurl','home')");
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                row($k, $v);
            }
            $n = $pdo->query("SELECT COUNT(*) FROM {$pref}posts WHERE post_type='product' AND post_status='publish'")->fetchColumn();
            row('Veroeffentlichte Produkte', (string) $n);
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (!empty($env['DB_PASS'])) {
            $msg = str_replace($env['DB_PASS'], '***', $msg);
        }
        row('Verbindung', 'FEHLER: ' . $msg, 'FEHLER');
    }
} else {
    out('Uebersprungen (pdo_mysql oder DB_NAME fehlt).');
}

// Zweiter Test mit den Zugangsdaten aus der wp-config.php (Werte werden nicht ausgegeben)
if (!empty($wpConfig) && extension_loaded('pdo_mysql')) {
    $cfg = (string) @file_get_contents($wpConfig);
    $get = function (string $const) use ($cfg): string {
        return preg_match('/define\(\s*[\'"]' . $const . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $cfg, $m) ? $m[1] : '';
    };
    $w = ['DB_HOST' => $get('DB_HOST'), 'DB_NAME' => $get('DB_NAME'), 'DB_USER' => $get('DB_USER'), 'DB_PASS' => $get('DB_PASSWORD')];
    if ($w['DB_NAME'] !== '') {
        row('.env gleich wp-config (HOST/NAME/USER/PASS)',
            implode(' / ', array_map(fn($a, $b) => yn(($env[$a] ?? '') === $b), ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'], [$w['DB_HOST'], $w['DB_NAME'], $w['DB_USER'], $w['DB_PASS']])));
        try {
            $h = $w['DB_HOST'] ?: 'localhost';
            $port = 3306;
            if (strpos($h, ':') !== false) {
                [$h, $port] = explode(':', $h, 2);
                $port = (int) $port;
            }
            $pdo2 = new PDO("mysql:host={$h};port={$port};dbname={$w['DB_NAME']};charset=utf8mb4", $w['DB_USER'], $w['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            row('Verbindung mit wp-config-Daten', 'OK (' . $pdo2->query('SELECT VERSION()')->fetchColumn() . ')', 'OK');
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($w['DB_PASS'] !== '') {
                $msg = str_replace($w['DB_PASS'], '***', $msg);
            }
            row('Verbindung mit wp-config-Daten', 'FEHLER: ' . $msg, 'FEHLER');
        }
    } else {
        out('wp-config.php: DB_NAME nicht lesbar (Konstanten anders definiert?).');
    }
}

/* -------------------------------------------------------------- Netzwerk */
section('Netzwerk nach aussen (nur TCP-Verbindung)');
row('github.com:443 (Kontrolle)', tcp('github.com', 443));
row('api.midocean.com:443', tcp('api.midocean.com', 443));
row('print.makito.es:8080', tcp('print.makito.es', 8080));
row('print.makito.es:80', tcp('print.makito.es', 80));
row('print.makito.es:443', tcp('print.makito.es', 443));
row('portquiz.net:8080 (Kontrolle Port 8080)', tcp('portquiz.net', 8080));
row('ausgehende IP (laut ipify)', (function () {
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    if (!ini_get('allow_url_fopen') && extension_loaded('curl')) {
        $ch = curl_init('https://api.ipify.org');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r ?: '(nicht ermittelt)';
    }
    return @file_get_contents('https://api.ipify.org', false, $ctx) ?: '(nicht ermittelt)';
})());
row('service.cottonclassics.com:21', tcp('service.cottonclassics.com', 21));
if (extension_loaded('curl')) {
    $ch = curl_init('https://api.midocean.com/');
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 12, CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    row('HTTPS api.midocean.com (HEAD)', $err !== '' ? 'FEHLER: ' . $err : 'HTTP ' . $code . ' (jeder Code = TLS funktioniert)');
}
out('Hinweis: FTP-Datenkanal (passiv) und Feed-Abrufe mit Token werden hier bewusst nicht getestet.');

out();
out('Spitzenverbrauch dieses Laufs: ' . round(memory_get_peak_usage(true) / 1048576, 1) . ' MB');

@file_put_contents($logDir . '/diagnose.log', implode(PHP_EOL, $lines) . PHP_EOL);
out('Log geschrieben: ' . $logDir . '/diagnose.log');
