<?php
defined( 'ABSPATH' ) || exit;

$settings = mlbkp_get_settings();
$has_sftp  = ! empty( $settings['sftp_host'] ) && ! empty( $settings['sftp_username'] );
$has_phpseclib = class_exists( 'phpseclib3\Net\SFTP' );
?>

<div class="mlb-run-page">

    <?php if ( ! $has_phpseclib ): ?>
    <div class="notice notice-error mlb-notice">
        <p>
            <strong>⚠ phpseclib ist nicht installiert.</strong><br>
            Führe im Plugin-Verzeichnis folgenden Befehl aus:<br>
            <code>cd <?php echo esc_html( MLBKP_PLUGIN_DIR ); ?> &amp;&amp; composer install</code>
        </p>
    </div>
    <?php endif; ?>

    <?php if ( ! $has_sftp ): ?>
    <div class="notice notice-warning mlb-notice">
        <p>
            <strong>ℹ SFTP nicht konfiguriert.</strong>
            Bitte zuerst die <a href="<?php echo esc_url( admin_url( 'admin.php?page=media-lab-backup&tab=settings' ) ); ?>">Einstellungen</a> ausfüllen.
        </p>
    </div>
    <?php endif; ?>

    <div class="mlb-card">
        <h2 class="mlb-card-title">▶ Manuelles Backup starten</h2>
        <p>Wähle den Backup-Typ und starte die Sicherung. Der Browser muss während des Vorgangs geöffnet bleiben.</p>

        <div class="mlb-backup-type-selector">

            <label class="mlb-type-option">
                <input type="radio" name="backup_type" value="full" checked />
                <div class="mlb-type-card">
                    <strong>Vollständiges Backup</strong>
                    <span>Datenbank + wp-content <?php echo ! empty( $settings['backup_wpcore'] ) ? '+ WP-Core' : ''; ?></span>
                </div>
            </label>

            <label class="mlb-type-option">
                <input type="radio" name="backup_type" value="database" />
                <div class="mlb-type-card">
                    <strong>Nur Datenbank</strong>
                    <span>SQL-Dump (schnell, klein)</span>
                </div>
            </label>

            <label class="mlb-type-option">
                <input type="radio" name="backup_type" value="wpcontent" />
                <div class="mlb-type-card">
                    <strong>Nur wp-content</strong>
                    <span>Uploads, Plugins, Themes</span>
                </div>
            </label>

            <?php if ( ! empty( $settings['backup_wpcore'] ) ): ?>
            <label class="mlb-type-option">
                <input type="radio" name="backup_type" value="wpcore" />
                <div class="mlb-type-card">
                    <strong>Nur WP-Core</strong>
                    <span>Vollständiges WordPress-Verzeichnis</span>
                </div>
            </label>
            <?php endif; ?>

        </div>

        <div class="mlb-run-actions">
            <button type="button" id="mlb-start-backup" class="button button-primary button-large"
                    <?php echo ( ! $has_phpseclib || ! $has_sftp ) ? 'disabled' : ''; ?>>
                ▶ Backup starten
            </button>
            <button type="button" id="mlb-cancel-backup" class="button button-secondary button-large mlb-cancel-btn" style="display:none;">
                ⏹ Abbrechen
            </button>
            <span id="mlb-run-status" class="mlb-inline-status"></span>
        </div>
    </div>

    <?php /* ─── Live-Log ─────────────────────────────────────────────────── */ ?>
    <div class="mlb-card" id="mlb-log-card" style="display: none;">
        <h2 class="mlb-card-title">📋 Live-Protokoll</h2>
        <div id="mlb-log-output" class="mlb-log-output"></div>
    </div>

</div>
