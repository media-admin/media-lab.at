<?php
/**
 * Logo-Dateien und die Anfrage: Mail, Admin-Ansicht, Download, Aufbewahrung.
 *
 * Ablauf beim Absenden: ML_Deco_Selection hängt die Datei-IDs an items[].attachments der Anfrage (Filter mlw_before_save_inquiry).
 * Die Inquiry-Engine (media-lab-woocommerce) entfernt den Marker _mlw_pending_upload, setzt _mlw_inquiry_id und post_parent
 * und schickt die Dateien als Anhang der Admin-Mail. Hier ergänzt:
 *  - Mail: Der Anhang trägt den ursprünglichen Dateinamen (statt des Zufallsnamens). In der Produktliste beider Mails steht nur der
 *    Dateiname, kein Link (die Datei liegt in einem geschützten Ordner), Filter mlw_inquiry_attachment_html (media-lab-woocommerce 2.34.0).
 *  - Admin: Kasten "Logo-Dateien" in der Anfrage mit Vorschau und geschütztem Download (Berechtigung: Anfrage bearbeiten).
 *  - Aufbewahrung: Dateien abgesendeter Anfragen werden nach 2 Jahren gelöscht (täglicher Cron, Filter ml_deco_logo_retention_days),
 *    ebenso beim endgültigen Löschen der Anfrage. Nicht abgesendete Uploads räumt der Cron von media-lab-woocommerce nach 30 Tagen auf.
 *  - Datenschutz: Textvorschlag für die Datenschutzerklärung (Einstellungen > Datenschutz).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Inquiry {

	const CRON_HOOK      = 'mld_logo_retention';
	const RETENTION_DAYS = 730; // 2 Jahre
	const META_LOGOS     = '_mld_logo_ids';
	const META_DELETED   = '_mld_logos_deleted';
	const INQUIRY_CPT    = 'mlw_inquiry';

	/** @var array<string,string> Pfad => ursprünglicher Dateiname (nur im aktuellen Request, für den Mail-Anhang) */
	private static $names = [];

	public static function init(): void {
		add_filter( 'mlw_inquiry_attachment_html', [ __CLASS__, 'mail_line' ], 10, 3 );
		add_filter( 'wp_mail', [ __CLASS__, 'mail_attachment_names' ] );
		add_action( 'mlw_after_save_inquiry', [ __CLASS__, 'after_save' ], 20, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete_inquiry' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_action( 'wp_ajax_mld_logo_download', [ __CLASS__, 'ajax_download' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_retention' ] );
		add_action( 'admin_init', [ __CLASS__, 'privacy_content' ] );
	}

	public static function retention_days(): int {
		return max( 30, (int) apply_filters( 'ml_deco_logo_retention_days', self::RETENTION_DAYS ) );
	}

	// ── Anfrage absenden ──────────────────────────────────────────────────────

	/** Merkt sich Pfad und Dateiname der Logo-Dateien, die an die Anfrage gehängt werden (für den Mail-Anhang). */
	public static function remember( array $ids ): void {
		foreach ( $ids as $id ) {
			$path = (string) get_attached_file( (int) $id );
			if ( $path !== '' ) {
				self::$names[ $path ] = ML_Deco_Files::name( (int) $id );
			}
		}
	}

	/**
	 * Anhang der Admin-Mail mit dem ursprünglichen Dateinamen (wp_mail ab WordPress 6.0: Schlüssel = Dateiname). Doppelte Pfade entfallen.
	 *
	 * @param mixed $args wp_mail-Argumente.
	 */
	public static function mail_attachment_names( $args ) {
		if ( ! self::$names || ! is_array( $args ) || empty( $args['attachments'] ) ) {
			return $args;
		}
		$list = is_array( $args['attachments'] ) ? $args['attachments'] : explode( "\n", str_replace( "\r\n", "\n", (string) $args['attachments'] ) );
		$out  = [];
		$seen = [];
		foreach ( $list as $key => $path ) {
			$path = (string) $path;
			if ( $path === '' || isset( $seen[ $path ] ) ) {
				continue;
			}
			$seen[ $path ] = true;
			if ( ! isset( self::$names[ $path ] ) ) {
				is_string( $key ) ? $out[ $key ] = $path : $out[] = $path;
				continue;
			}
			$name = self::$names[ $path ];
			$base = $name;
			for ( $i = 2; isset( $out[ $name ] ); $i++ ) {
				$name = $i . '_' . $base;
			}
			$out[ $name ] = $path;
		}
		$args['attachments'] = $out;
		return $args;
	}

	/**
	 * Produktliste in beiden Mails: nur der Dateiname, kein Link (geschützter Ordner).
	 *
	 * @param mixed $line   Standard-Zeile.
	 * @param int   $att_id Attachment-ID.
	 */
	public static function mail_line( $line, $att_id = 0, $item = [] ) {
		$att_id = (int) $att_id;
		if ( $att_id <= 0 || ML_Deco_Files::status( $att_id, false ) !== 'ok' ) {
			return $line;
		}
		return '📎 ' . esc_html( ML_Deco_Files::name( $att_id ) ) . '<br>';
	}

	/**
	 * Nach dem Speichern: Logo-Dateien aus den Einträgen der Anfrage nehmen und stattdessen an der Anfrage vermerken. Die
	 * "Dateien"-Zeile der Starter-Kit-Admin-Ansicht erwartet URLs und würde die Datei-IDs falsch darstellen; unser Kasten
	 * "Logo-Dateien" zeigt sie richtig. Die Zuordnung steckt außerdem in _mlw_inquiry_id am Attachment.
	 *
	 * @param int   $inquiry_id Anfrage.
	 * @param mixed $data       Daten der Anfrage.
	 */
	public static function after_save( $inquiry_id, $data = [] ): void {
		$inquiry_id = (int) $inquiry_id;
		if ( ! $inquiry_id || ! is_array( $data ) ) {
			return;
		}
		$logos = [];
		foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
			foreach ( (array) ( $item['attachments'] ?? [] ) as $id ) {
				if ( ML_Deco_Files::status( (int) $id, false ) === 'ok' ) {
					$logos[ (int) $id ] = (int) $id;
				}
			}
		}
		if ( ! $logos ) {
			return;
		}
		$items = get_post_meta( $inquiry_id, 'mlw_inquiry_items', true );
		if ( is_array( $items ) ) {
			foreach ( $items as &$it ) {
				if ( is_array( $it ) && ! empty( $it['attachments'] ) ) {
					$it['attachments'] = array_values( array_diff( array_map( 'intval', (array) $it['attachments'] ), $logos ) );
				}
			}
			unset( $it );
			update_post_meta( $inquiry_id, 'mlw_inquiry_items', $items );
		}
		update_post_meta( $inquiry_id, self::META_LOGOS, array_values( $logos ) );
	}

	// ── Admin: Kasten und Download ────────────────────────────────────────────

	/** @return int[] Logo-Dateien einer Anfrage */
	public static function files_of( int $inquiry_id ): array {
		$q = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => ML_Deco_Files::META_LOGO, 'value' => '1' ],
				[ 'key' => '_mlw_inquiry_id', 'value' => $inquiry_id, 'type' => 'NUMERIC' ],
			],
		] );
		return array_map( 'intval', $q->posts );
	}

	public static function meta_box(): void {
		add_meta_box( 'mld_logos', __( 'Logo-Dateien (Veredelung)', 'media-lab-ml-decoration' ), [ __CLASS__, 'render_box' ], self::INQUIRY_CPT, 'normal', 'default' );
	}

	public static function render_box( $post ): void {
		$inquiry_id = (int) $post->ID;
		$ids        = self::files_of( $inquiry_id );
		if ( ! $ids ) {
			$deleted = (int) get_post_meta( $inquiry_id, self::META_DELETED, true );
			echo '<p>' . esc_html( $deleted
				? sprintf( /* translators: %s: Datum */ __( 'Die Logo-Dateien wurden am %s nach Ablauf der Aufbewahrungsfrist gelöscht.', 'media-lab-ml-decoration' ), wp_date( get_option( 'date_format' ), $deleted ) )
				: __( 'Zu dieser Anfrage wurden keine Logo-Dateien hochgeladen (Logos kommen dann per Mail).', 'media-lab-ml-decoration' ) ) . '</p>';
			return;
		}
		$until = (int) get_post_time( 'U', true, $inquiry_id ) + self::retention_days() * DAY_IN_SECONDS;
		echo '<table class="widefat striped"><tbody>';
		foreach ( $ids as $id ) {
			$d    = ML_Deco_Files::describe( $id );
			$dl   = add_query_arg( [ 'action' => 'mld_logo_download', 'id' => $id, 'nonce' => wp_create_nonce( 'mld_logo_dl_' . $id ) ], admin_url( 'admin-ajax.php' ) );
			$thumb = $d['preview'] !== ''
				? '<img src="' . esc_url( $d['preview'] ) . '" alt="" style="width:64px;height:64px;object-fit:contain;background:#f6f7f7;border:1px solid #dcdcde">'
				: '<span style="display:inline-block;width:64px;line-height:64px;text-align:center;font-weight:600;background:#f6f7f7;border:1px solid #dcdcde">' . esc_html( strtoupper( $d['ext'] ) ) . '</span>';
			echo '<tr><td style="width:72px">' . $thumb . '</td><td><strong>' . esc_html( $d['name'] ) . '</strong><br>' // phpcs:ignore WordPress.Security.EscapeOutput -- oben escaped
				. esc_html( strtoupper( $d['ext'] ) . ( $d['sizeLabel'] !== '' ? ' · ' . $d['sizeLabel'] : '' ) ) . '</td>'
				. '<td style="text-align:right"><a class="button" href="' . esc_url( $dl ) . '">' . esc_html__( 'Herunterladen', 'media-lab-ml-decoration' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: Datum */ __( 'Aufbewahrung: Die Dateien werden am %s automatisch gelöscht.', 'media-lab-ml-decoration' ), wp_date( get_option( 'date_format' ), $until ) ) ) . '</p>';
	}

	/** Geschützter Download für Redakteure: Berechtigung = Anfrage bearbeiten dürfen. */
	public static function ajax_download(): void {
		$id = (int) ( $_GET['id'] ?? 0 );
		if ( ! $id || ! wp_verify_nonce( (string) ( $_GET['nonce'] ?? '' ), 'mld_logo_dl_' . $id ) ) {
			wp_die( esc_html__( 'Der Link ist abgelaufen. Bitte die Anfrage neu laden.', 'media-lab-ml-decoration' ), '', [ 'response' => 403 ] );
		}
		$inquiry_id = (int) get_post_meta( $id, '_mlw_inquiry_id', true );
		if ( ! $inquiry_id || ! current_user_can( 'edit_post', $inquiry_id ) || ML_Deco_Files::status( $id, false ) !== 'ok' ) {
			wp_die( esc_html__( 'Kein Zugriff auf diese Datei.', 'media-lab-ml-decoration' ), '', [ 'response' => 403 ] );
		}
		$path = (string) get_attached_file( $id );
		if ( $path === '' || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Die Datei ist nicht mehr vorhanden.', 'media-lab-ml-decoration' ), '', [ 'response' => 404 ] );
		}
		$ext  = strtolower( (string) get_post_meta( $id, ML_Deco_Files::META_EXT, true ) );
		$name = ML_Deco_Files::name( $id );
		nocache_headers();
		header( 'Content-Type: ' . ( ML_Deco_Files::MIMES[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Content-Disposition: attachment; filename="' . str_replace( [ '"', "\r", "\n" ], '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// ── Aufbewahrung ──────────────────────────────────────────────────────────

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Löscht Logo-Dateien abgesendeter Anfragen, die älter als die Aufbewahrungsfrist sind (Stapel je Lauf). */
	public static function run_retention(): void {
		$cutoff = time() - self::retention_days() * DAY_IN_SECONDS;
		$q      = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'date_query'     => [ [ 'before' => gmdate( 'Y-m-d H:i:s', $cutoff ), 'column' => 'post_date_gmt' ] ],
			'meta_query'     => [
				[ 'key' => ML_Deco_Files::META_LOGO, 'value' => '1' ],
				[ 'key' => '_mlw_inquiry_id', 'compare' => 'EXISTS' ],
			],
		] );
		foreach ( $q->posts as $id ) {
			$inquiry_id = (int) get_post_meta( $id, '_mlw_inquiry_id', true );
			// maßgeblich ist das Datum der Anfrage (der Upload kann Tage früher liegen)
			if ( $inquiry_id && get_post( $inquiry_id ) && (int) get_post_time( 'U', true, $inquiry_id ) >= $cutoff ) {
				continue;
			}
			wp_delete_attachment( (int) $id, true );
			if ( $inquiry_id ) {
				update_post_meta( $inquiry_id, self::META_DELETED, time() );
			}
		}
	}

	/** Anfrage endgültig gelöscht: ihre Logo-Dateien mitlöschen. */
	public static function on_delete_inquiry( $post_id ): void {
		if ( get_post_type( $post_id ) !== self::INQUIRY_CPT ) {
			return;
		}
		foreach ( self::files_of( (int) $post_id ) as $id ) {
			wp_delete_attachment( $id, true );
		}
	}

	// ── Datenschutz ───────────────────────────────────────────────────────────

	public static function privacy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days  = (int) round( self::retention_days() / 365 );
		$years = $days >= 1 ? sprintf( _n( '%d Jahr', '%d Jahre', $days, 'media-lab-ml-decoration' ), $days ) : sprintf( __( '%d Tage', 'media-lab-ml-decoration' ), self::retention_days() );
		$text  = '<h3>' . esc_html__( 'Logo- und Motiv-Upload bei Veredelungsanfragen', 'media-lab-ml-decoration' ) . '</h3>'
			. '<p>' . esc_html__( 'Wenn Sie bei der Auswahl einer Veredelung ein Logo oder Motiv hochladen, speichern wir die Datei auf unserem Server. Wir verwenden sie ausschließlich, um Ihre Anfrage zu bearbeiten und ein Angebot zu erstellen. Die Datei ist nicht öffentlich abrufbar; Zugriff haben nur Sie in Ihrer Sitzung und unsere Mitarbeiter, die Anfragen bearbeiten.', 'media-lab-ml-decoration' ) . '</p>'
			. '<p>' . esc_html( sprintf( /* translators: %s: Aufbewahrungsdauer */ __( 'Dateien, die Sie hochladen, aber nicht mit einer Anfrage absenden, löschen wir nach 30 Tagen automatisch. Mit einer Anfrage abgesendete Dateien löschen wir spätestens nach %s oder früher, wenn Sie uns darum bitten. Rechtsgrundlage ist die Durchführung vorvertraglicher Maßnahmen auf Ihre Anfrage (Art. 6 Abs. 1 lit. b DSGVO).', 'media-lab-ml-decoration' ), $years ) ) . '</p>'
			. '<p>' . esc_html__( 'Bitte laden Sie keine Dateien hoch, die personenbezogene Daten Dritter oder Rechte Dritter verletzende Inhalte enthalten.', 'media-lab-ml-decoration' ) . '</p>';
		wp_add_privacy_policy_content( 'Media Lab ML Decoration', wp_kses_post( wpautop( $text, false ) ) );
	}
}
