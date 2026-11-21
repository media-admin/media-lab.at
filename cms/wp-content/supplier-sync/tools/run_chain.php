<?php
/**
 * Importkette eines Lieferanten: Sync, WP All Import, SKU-Zuordnung, Varianten-Import, Feed-Abgleich (Schritte: config/chain.php).
 * Gedacht für den Cronjob auf dem Server (ohne SSH, ohne installiertes WP-CLI) und für den Handbetrieb lokal.
 *
 * Aufruf im Ordner supplier-sync:
 *   php tools/run_chain.php midocean
 *   php tools/run_chain.php midocean dry-run        # zeigt nur die Befehle, startet nichts
 *   php tools/run_chain.php midocean from=varianten-import   # ab diesem Schritt (zum Beispiel nach einem Abbruch)
 *   php tools/run_chain.php midocean only=feed-abgleich      # nur dieser Schritt
 *   php tools/run_chain.php midocean force          # Plausibilitätsprüfung des Feeds überspringen
 *   php tools/run_chain.php midocean wait=3         # höchstens 3 Stunden auf eine laufende Kette warten (Standard 6)
 *
 * Schutz:
 *   - Eine Sperrdatei (logs/chain.lock) sorgt dafür, dass nie zwei Ketten gleichzeitig laufen. Eine zweite Kette wartet.
 *   - Bei einem Fehler bricht die Kette ab (kein Import mit einem defekten Feed, kein Feed-Abgleich nach einem abgebrochenen Import).
 *   - Nach dem Sync: Feed-Datei muss neu und nicht leer sein und darf nicht unter 80 % der Größe des letzten Laufs liegen.
 *   - Meldungen wie "Allowed memory size" oder "Fatal error" in der Ausgabe eines Schritts zählen als Fehler, auch wenn der Exit-Code 0 ist.
 *
 * WP-CLI: Umgebungsvariable WP_CLI_BIN, sonst bin/wp-cli.phar neben sync.php (wird mit PHP gestartet), sonst "wp" aus dem PATH.
 * Ausgabe der Schritte: logs/chain/<lieferant>_<Datum>.log. Letzter Stand: logs/chain_last_status.json.
 * Rückgabecodes: 0 in Ordnung, 1 Schritt fehlgeschlagen, 2 Feed unplausibel, 3 Angaben falsch oder Sperre nicht erhalten.
 */

if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 404 );
    exit;
}

date_default_timezone_set( 'Europe/Vienna' );

$base = dirname( __DIR__ );          // .../supplier-sync
$cms  = dirname( $base, 2 );         // .../cms (WordPress)

// --- Angaben --------------------------------------------------------------------------------------------------------
$key      = '';
$dry      = false;
$force    = false;
$waitH    = 6.0;
$fromStep = '';
$onlyStep = '';
foreach ( array_slice( $argv ?? [], 1 ) as $a ) {
    if ( $a === 'dry-run' ) {
        $dry = true;
    } elseif ( $a === 'force' ) {
        $force = true;
    } elseif ( preg_match( '/^wait=(\d+(?:\.\d+)?)$/', $a, $m ) ) {
        $waitH = (float) $m[1];
    } elseif ( preg_match( '/^from=([a-z0-9-]+)$/', $a, $m ) ) {
        $fromStep = $m[1];
    } elseif ( preg_match( '/^only=([a-z0-9-]+)$/', $a, $m ) ) {
        $onlyStep = $m[1];
    } elseif ( preg_match( '/^[a-z0-9_]+$/', $a ) && $key === '' ) {
        $key = $a;
    } else {
        fwrite( STDERR, "Unbekannte Angabe: {$a}\n" );
        exit( 3 );
    }
}

$configFile = getenv( 'ML_CHAIN_CONFIG' ) ?: $base . '/config/chain.php'; // ML_CHAIN_CONFIG nur für Tests
$chains     = is_file( $configFile ) ? require $configFile : [];
if ( $key === '' || ! isset( $chains[ $key ] ) ) {
    fwrite( STDERR, 'Lieferant fehlt oder unbekannt. Verfügbar: ' . implode( ', ', array_keys( $chains ) ) . "\n" );
    fwrite( STDERR, "Beispiel: php tools/run_chain.php midocean [dry-run] [from=<schritt>] [only=<schritt>] [force] [wait=6]\n" );
    exit( 3 );
}
$chain = $chains[ $key ];
$steps = $chain['steps'];
$names = array_column( $steps, 'name' );
foreach ( [ 'from' => $fromStep, 'only' => $onlyStep ] as $what => $n ) {
    if ( $n !== '' && ! in_array( $n, $names, true ) ) {
        fwrite( STDERR, "Schritt {$what}={$n} gibt es nicht. Verfügbar: " . implode( ', ', $names ) . "\n" );
        exit( 3 );
    }
}

// --- Programme ------------------------------------------------------------------------------------------------------
$php    = PHP_BINARY;
$ini    = php_ini_loaded_file();
$phpArg = $ini ? [ '-c', $ini ] : [];
$memory = (string) ( getenv( 'ML_CHAIN_MEMORY' ) ?: '1024M' );

$wpBin = getenv( 'WP_CLI_BIN' ) ?: ( is_file( $base . '/bin/wp-cli.phar' ) ? $base . '/bin/wp-cli.phar' : 'wp' );
$wp    = substr( $wpBin, -5 ) === '.phar' ? array_merge( [ $php ], $phpArg, [ '-d', 'memory_limit=' . $memory, $wpBin ] ) : [ $wpBin ];

// --- Logs und Zustand -----------------------------------------------------------------------------------------------
$logDir = $base . '/logs/chain';
if ( ! is_dir( $logDir ) ) {
    @mkdir( $logDir, 0775, true );
}
$logFile = $logDir . '/' . $key . '_' . date( 'Y-m-d_His' ) . '.log';

$say = static function ( string $msg ) use ( $logFile ): void {
    $line = '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n";
    echo $line;
    @file_put_contents( $logFile, $line, FILE_APPEND );
};

$stateFile = $base . '/logs/chain_state.json';
$readState = static function () use ( $stateFile ): array {
    $j = is_file( $stateFile ) ? json_decode( (string) file_get_contents( $stateFile ), true ) : null;
    return is_array( $j ) ? $j : [];
};
$writeState = static function ( array $state ) use ( $stateFile ): void {
    @file_put_contents( $stateFile, json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
};
$statusFile = $base . '/logs/chain_last_status.json';
$setStatus  = static function ( string $status, string $failed = '' ) use ( $statusFile, $key ): void {
    $all = is_file( $statusFile ) ? json_decode( (string) file_get_contents( $statusFile ), true ) : [];
    $all = is_array( $all ) ? $all : [];
    $all[ $key ] = [ 'status' => $status, 'schritt' => $failed, 'zeit' => date( 'c' ) ];
    @file_put_contents( $statusFile, json_encode( $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
};

$say( sprintf( '=== Kette %s (%s)%s ===', $key, $chain['label'] ?? $key, $dry ? ' [Trockenlauf]' : '' ) );

// --- Sperre: nie zwei Ketten gleichzeitig ---------------------------------------------------------------------------
$lock = null;
if ( ! $dry ) {
    $lock = fopen( $base . '/logs/chain.lock', 'c' );
    $deadline = time() + (int) ( $waitH * 3600 );
    $announced = false;
    while ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
        if ( ! $announced ) {
            $say( sprintf( 'Eine andere Kette läuft noch, warte bis zu %s Stunden ...', rtrim( rtrim( number_format( $waitH, 1, '.', '' ), '0' ), '.' ) ) );
            $announced = true;
        }
        if ( time() >= $deadline ) {
            $say( 'Sperre nicht erhalten, Kette abgebrochen.' );
            $setStatus( 'abgebrochen (Sperre)' );
            exit( 3 );
        }
        sleep( max( 1, min( 30, $deadline - time() ) ) );
    }
    if ( $announced ) {
        $say( 'Sperre erhalten, Start.' );
    }
}

// --- Befehle bauen --------------------------------------------------------------------------------------------------
$command = static function ( array $step ) use ( $php, $phpArg, $memory, $base, $cms, $wp ): array {
    if ( $step['type'] === 'php' ) {
        return array_merge( [ $php ], $phpArg, [ '-d', 'memory_limit=' . $memory, $base . '/' . $step['script'] ], $step['args'] ?? [] );
    }
    if ( $step['type'] === 'wpai' ) {
        return array_merge( $wp, [ '--path=' . $cms, 'all-import', 'run', (string) (int) $step['id'] ] );
    }
    throw new RuntimeException( 'Unbekannter Schritt-Typ: ' . $step['type'] );
};
$show = static fn( array $cmd ): string => implode( ' ', array_map( 'escapeshellarg', $cmd ) );

/** Zeilen der Parent-Feed-Datei ohne Kopfzeile (Zeilenumbrüche in Feldern zählen nicht mit). */
$countRows = static function ( string $file ): int {
    $fh = @fopen( $file, 'r' );
    if ( ! $fh ) {
        return -1;
    }
    $n = -1;
    while ( fgetcsv( $fh, 0, ',', '"', '\\' ) !== false ) {
        $n++;
    }
    fclose( $fh );
    return max( $n, 0 );
};

// --- Ablauf ---------------------------------------------------------------------------------------------------------
$started   = time();
$reached   = $fromStep === '';
$exit      = 0;
$failedAt  = '';

foreach ( $steps as $i => $step ) {
    $name = $step['name'];
    if ( $onlyStep !== '' && $name !== $onlyStep ) {
        continue;
    }
    if ( ! $reached ) {
        if ( $name !== $fromStep ) {
            continue;
        }
        $reached = true;
    }

    $cmd = $command( $step );
    $say( sprintf( 'Schritt %d/%d %s: %s', $i + 1, count( $steps ), $name, $show( $cmd ) ) );
    if ( $dry ) {
        continue;
    }

    $t0     = time();
    $offset = is_file( $logFile ) ? (int) filesize( $logFile ) : 0;
    $proc   = proc_open(
        $cmd,
        [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $logFile, 'a' ], 2 => [ 'file', $logFile, 'a' ] ],
        $pipes,
        $base
    );
    if ( ! is_resource( $proc ) ) {
        $say( "FEHLER {$name}: Prozess ließ sich nicht starten." );
        $exit = 1;
        $failedAt = $name;
        break;
    }
    $code = proc_close( $proc );
    clearstatcache( true, $logFile );
    $output = (string) file_get_contents( $logFile, false, null, $offset );
    $dur    = time() - $t0;

    $problem = '';
    if ( $code !== 0 ) {
        $problem = "Exit-Code {$code}";
    } elseif ( preg_match( '/Allowed memory size|PHP Fatal error|Fatal error:|Uncaught [A-Za-z\\\\]+(?:Exception|Error)/', $output, $m ) ) {
        $problem = 'Meldung in der Ausgabe: ' . $m[0];
    }
    if ( $problem !== '' ) {
        $say( sprintf( 'FEHLER %s nach %s: %s. Kette abgebrochen (Ausgabe: %s)', $name, gmdate( 'H:i:s', $dur ), $problem, $logFile ) );
        $exit = 1;
        $failedAt = $name;
        break;
    }
    $say( sprintf( 'OK %s (%s)', $name, gmdate( 'H:i:s', $dur ) ) );

    // Plausibilität des Feeds nach dem Sync
    if ( ! empty( $step['feed'] ) ) {
        $feedPath = $base . '/feeds/' . $step['feed'];
        $rows     = $countRows( $feedPath );
        $state    = $readState();
        $prev     = (int) ( $state[ $key ]['feed_rows'] ?? 0 );
        $why      = '';
        if ( $rows < 0 || ! is_file( $feedPath ) ) {
            $why = 'Feed-Datei fehlt';
        } elseif ( filemtime( $feedPath ) < $t0 - 1 ) {
            $why = 'Feed-Datei ist nicht neu (vom Sync nicht geschrieben)';
        } elseif ( $rows === 0 ) {
            $why = 'Feed-Datei enthält keine Produkte';
        } elseif ( $prev > 0 && $rows < (int) floor( $prev * 0.8 ) ) {
            $why = "Feed hat nur {$rows} Produkte, beim letzten Lauf waren es {$prev} (unter 80 %)";
        }
        if ( $why !== '' && ! $force ) {
            $say( "FEEDPRÜFUNG: {$why}. Kette abgebrochen, nichts importiert. Mit force trotzdem fortsetzen." );
            $exit = 2;
            $failedAt = $name;
            break;
        }
        $say( "Feed: {$rows} Produkte" . ( $prev > 0 ? " (letzter Lauf {$prev})" : '' ) . ( $why !== '' ? " [trotz Warnung: {$why}]" : '' ) );
        $state[ $key ]['feed_rows'] = $rows;
        $state[ $key ]['feed_time'] = date( 'c' );
        $writeState( $state );
    }
}

$total = gmdate( 'H:i:s', time() - $started );
if ( $dry ) {
    $say( 'Trockenlauf beendet, nichts gestartet.' );
    exit( 0 );
}
if ( $exit === 0 ) {
    $say( "=== Kette {$key} fertig in {$total} ===" );
    $setStatus( 'ok' );
} else {
    $say( "=== Kette {$key} ABGEBROCHEN bei Schritt {$failedAt} nach {$total} ===" );
    $setStatus( $exit === 2 ? 'abgebrochen (Feedprüfung)' : 'fehlgeschlagen', $failedAt );
}
exit( $exit );
