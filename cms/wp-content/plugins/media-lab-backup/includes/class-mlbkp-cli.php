<?php
defined( 'ABSPATH' ) || exit;

/**
 * MLBKP_CLI
 *
 * WP-CLI-Befehle für Media Lab Backup.
 *
 * Verwendung:
 *   wp mlbkp backup [--type=<type>]
 *   wp mlbkp status
 *   wp mlbkp test
 *   wp mlbkp logs [--limit=<n>]
 */
class MLBKP_CLI {

    /**
     * Führt ein Backup aus.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Backup-Typ. Mögliche Werte: full, database, wpcontent, wpcore.
     * ---
     * default: full
     * options:
     *   - full
     *   - database
     *   - wpcontent
     *   - wpcore
     * ---
     *
     * ## EXAMPLES
     *
     *   wp mlbkp backup
     *   wp mlbkp backup --type=database
     *   wp mlbkp backup --type=wpcontent
     *
     * @when after_wp_load
     */
    public function backup( array $args, array $assoc_args ): void {
        $type = $assoc_args['type'] ?? 'full';

        $allowed = [ 'full', 'database', 'wpcontent', 'wpcore' ];
        if ( ! in_array( $type, $allowed, true ) ) {
            WP_CLI::error( "Ungültiger Backup-Typ: {$type}. Erlaubt: " . implode( ', ', $allowed ) );
        }

        WP_CLI::log( "▶ Starte Backup [Typ: {$type}] …" );

        $runner = new MLBKP_Backup_Runner();
        $result = $runner->run( $type, 'cron' );

        foreach ( $result['log'] as $line ) {
            WP_CLI::log( $line );
        }

        if ( $result['success'] ) {
            WP_CLI::success( 'Backup erfolgreich abgeschlossen.' );
        } else {
            WP_CLI::error( 'Backup fehlgeschlagen: ' . $result['message'] );
        }
    }

    /**
     * Zeigt den aktuellen Backup-Status an.
     *
     * ## EXAMPLES
     *
     *   wp mlbkp status
     *
     * @when after_wp_load
     */
    public function status( array $args, array $assoc_args ): void {
        $settings = mlbkp_get_settings();
        $last     = MLBKP_Logger::get_last_successful();
        $next     = MLBKP_Scheduler::get_next_run();

        WP_CLI\Utils\format_items( 'table', [
            [ 'Einstellung' => 'Site',                   'Wert' => get_site_url() ],
            [ 'Einstellung' => 'SFTP-Host',              'Wert' => $settings['sftp_host'] ?: '(nicht konfiguriert)' ],
            [ 'Einstellung' => 'Authentifizierung',      'Wert' => $settings['sftp_auth_method'] === 'key' ? 'SSH-Key' : 'Passwort' ],
            [ 'Einstellung' => 'Zeitplan',               'Wert' => $settings['schedule'] === 'none' ? 'Deaktiviert' : ucfirst( $settings['schedule'] ) ],
            [ 'Einstellung' => 'Nächstes Backup',        'Wert' => $next ?? '—' ],
            [ 'Einstellung' => 'Aufbewahrung',           'Wert' => $settings['retention_count'] . ' Backups' ],
            [ 'Einstellung' => 'Letztes Backup',         'Wert' => $last ? wp_date( 'd.m.Y H:i', strtotime( $last['finished_at'] ) ) : '—' ],
            [ 'Einstellung' => 'Letzte Backup-Größe',    'Wert' => $last ? MLBKP_Logger::format_bytes( (int) $last['file_size'] ) : '—' ],
            [ 'Einstellung' => 'phpseclib',              'Wert' => class_exists( 'phpseclib3\Net\SFTP' ) ? '✅ Installiert' : '❌ Nicht installiert' ],
        ], [ 'Einstellung', 'Wert' ] );
    }

    /**
     * Testet die SFTP-Verbindung zur Storage Box.
     *
     * ## EXAMPLES
     *
     *   wp mlbkp test
     *
     * @when after_wp_load
     */
    public function test( array $args, array $assoc_args ): void {
        $settings = mlbkp_get_settings();

        WP_CLI::log( "🔌 Teste Verbindung zu {$settings['sftp_host']}:{$settings['sftp_port']} …" );

        $result = MLBKP_SFTP::test_connection( $settings );

        if ( $result === true ) {
            WP_CLI::success( 'SFTP-Verbindung erfolgreich.' );
        } else {
            WP_CLI::error( 'Verbindungsfehler: ' . $result );
        }
    }

    /**
     * Zeigt das Backup-Protokoll an.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Anzahl der anzuzeigenden Einträge.
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Ausgabeformat.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *   wp mlbkp logs
     *   wp mlbkp logs --limit=50
     *   wp mlbkp logs --format=json
     *
     * @when after_wp_load
     */
    public function logs( array $args, array $assoc_args ): void {
        $limit  = (int) ( $assoc_args['limit'] ?? 20 );
        $format = $assoc_args['format'] ?? 'table';

        $logs = MLBKP_Logger::get_logs( $limit );

        if ( empty( $logs ) ) {
            WP_CLI::log( 'Noch keine Backups ausgeführt.' );
            return;
        }

        $rows = array_map( static function ( $log ) {
            return [
                'ID'          => $log['id'],
                'Datum'       => wp_date( 'd.m.Y H:i', strtotime( $log['started_at'] ) ),
                'Typ'         => $log['backup_type'],
                'Status'      => $log['status'],
                'Größe'       => $log['file_size'] ? MLBKP_Logger::format_bytes( (int) $log['file_size'] ) : '—',
                'Dauer'       => MLBKP_Logger::format_duration( isset( $log['duration_sec'] ) ? (int) $log['duration_sec'] : null ),
                'Auslöser'    => $log['triggered_by'],
                'Fehler'      => mb_strimwidth( $log['error_message'] ?? '', 0, 60, '…' ),
            ];
        }, $logs );

        WP_CLI\Utils\format_items( $format, $rows, [ 'ID', 'Datum', 'Typ', 'Status', 'Größe', 'Dauer', 'Auslöser', 'Fehler' ] );
    }
}
