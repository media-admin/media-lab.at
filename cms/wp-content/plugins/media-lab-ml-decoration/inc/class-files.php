<?php
/**
 * Logo-/Motiv-Upload je Veredelung: Besucher laden zu einer Druckposition eine Datei hoch.
 *
 * Ablauf: Upload per Ajax (mld_logo_upload, Nonce der Auswahl) -> Datei in einen geschützten Ordner mit Zufallsnamen ->
 * Attachment mit Marker (_mlw_pending_upload, _mlw_upload_timestamp), damit der Aufräum-Cron von media-lab-woocommerce
 * nicht abgesendete Uploads nach 30 Tagen löscht -> Besucher "besitzt" die Datei (Liste in Session bzw. User-Meta,
 * wie Wunschliste und Auswahl). In der Auswahl steht nur die Attachment-ID (lines[].file, siehe class-selection.php).
 * Beim Absenden einer Anfrage nimmt die Inquiry-Engine den Marker weg und hängt die Datei an die Anfrage.
 *
 * Vertrauen: Der Browser schickt die Datei und später nur die ID. Die ID zählt nur, wenn die Datei in der Besitzliste
 * des Besuchers steht (ML_Deco_Files::status()). Dateiname und Typ werden serverseitig geprüft (Endung + Dateikopf).
 *
 * Die Datei ist nie öffentlich: Ordner mit .htaccess (Apache), Zufallsname, Auslieferung nur über einen Handler mit
 * Rechteprüfung. Bei nginx muss der Ordner in der Serverkonfiguration gesperrt werden (siehe README).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Files {

	/** Gleicher Nonce wie die Auswahl (ML_Deco_Selection::NONCE_ACTION): Die Seite hat ihn schon. */
	const NONCE_ACTION  = 'mld_selection';
	const SESSION_KEY   = 'mld_files';
	const USER_META_KEY = 'ml_deco_files';
	const DIR_NAME      = 'ml-deco-logos';
	const MAX_OWNED     = 100;
	const MAX_MB        = 10;
	const RATE_LIMIT    = 60; // Uploads je IP und Stunde

	const META_LOGO = '_mld_logo';
	const META_NAME = '_mld_original_name';
	const META_EXT  = '_mld_ext';

	const EXTENSIONS = [ 'pdf', 'svg', 'ai', 'eps', 'png', 'jpg', 'jpeg' ];
	const MIMES      = [
		'pdf'  => 'application/pdf',
		'svg'  => 'image/svg+xml',
		'ai'   => 'application/illustrator',
		'eps'  => 'application/postscript',
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
	];

	public static function init(): void {
		foreach ( [ 'mld_logo_upload' => 'ajax_upload', 'mld_logo_delete' => 'ajax_delete' ] as $action => $method ) {
			add_action( "wp_ajax_{$action}", [ __CLASS__, $method ] );
			add_action( "wp_ajax_nopriv_{$action}", [ __CLASS__, $method ] );
		}
		// Wie Wunschliste und Auswahl: Gast-Besitzliste beim Login in die des Nutzers übernehmen
		add_action( 'wp_login', [ __CLASS__, 'merge_session_on_login' ], 10, 2 );
	}

	// ── Einstellungen ─────────────────────────────────────────────────────────

	/** Logo-Upload an/aus (Standard an). Aus: keine Logo-Zeilen, Dateien in der Auswahl werden ignoriert. */
	public static function enabled(): bool {
		return (bool) apply_filters( 'ml_deco_logo_upload_enabled', true );
	}

	public static function max_mb(): int {
		return max( 1, (int) apply_filters( 'ml_deco_logo_max_mb', self::MAX_MB ) );
	}

	/** Ordner der Dateien (nicht öffentlich). Per Filter verlegbar, zum Beispiel außerhalb des Webroots. */
	public static function dir(): string {
		$up = wp_upload_dir( null, false );
		return (string) apply_filters( 'ml_deco_logo_dir', trailingslashit( (string) $up['basedir'] ) . self::DIR_NAME );
	}

	// ── Ajax ──────────────────────────────────────────────────────────────────

	public static function ajax_upload(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::rate_ok() ) {
			self::fail( __( 'Zu viele Uploads in kurzer Zeit. Bitte versuchen Sie es später noch einmal.', 'media-lab-ml-decoration' ), 429 );
		}
		if ( count( self::owned() ) >= self::MAX_OWNED ) {
			self::fail( __( 'Es sind bereits sehr viele Dateien hochgeladen. Bitte entfernen Sie nicht benötigte Dateien.', 'media-lab-ml-decoration' ) );
		}

		$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification -- oben geprüft
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['error'], $file['size'], $file['name'] ) || is_array( $file['name'] ) ) {
			self::fail( __( 'Es wurde keine Datei empfangen.', 'media-lab-ml-decoration' ) );
		}

		$err = (int) $file['error'];
		if ( $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ) {
			self::fail( sprintf( /* translators: %d: Megabyte */ __( 'Die Datei ist zu groß (max. %d MB).', 'media-lab-ml-decoration' ), self::max_mb() ) );
		}
		if ( $err !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::fail( __( 'Der Upload ist fehlgeschlagen. Bitte versuchen Sie es noch einmal.', 'media-lab-ml-decoration' ) );
		}

		$size = (int) $file['size'];
		if ( $size <= 0 ) {
			self::fail( __( 'Die Datei ist leer.', 'media-lab-ml-decoration' ) );
		}
		if ( $size > self::max_mb() * MB_IN_BYTES ) {
			self::fail( sprintf( __( 'Die Datei ist zu groß (max. %d MB).', 'media-lab-ml-decoration' ), self::max_mb() ) );
		}

		$orig = substr( sanitize_file_name( wp_unslash( (string) $file['name'] ) ), 0, 120 );
		$ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::EXTENSIONS, true ) ) {
			self::fail( sprintf( /* translators: %s: Liste der Endungen */ __( 'Dieses Dateiformat ist nicht erlaubt. Erlaubt: %s.', 'media-lab-ml-decoration' ), strtoupper( implode( ', ', self::EXTENSIONS ) ) ) );
		}

		$check = self::inspect( (string) $file['tmp_name'], $ext );
		if ( $check !== true ) {
			self::fail( (string) $check );
		}

		$dir = self::ensure_dir();
		if ( $dir === '' ) {
			self::fail( __( 'Die Datei konnte nicht gespeichert werden.', 'media-lab-ml-decoration' ), 500 );
		}
		$path = $dir . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $path ) ) {
			self::fail( __( 'Die Datei konnte nicht gespeichert werden.', 'media-lab-ml-decoration' ), 500 );
		}
		@chmod( $path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$id = wp_insert_attachment( [
			'post_mime_type' => self::MIMES[ $ext ],
			'post_title'     => sanitize_text_field( pathinfo( $orig, PATHINFO_FILENAME ) ),
			'post_status'    => 'inherit',
			'post_parent'    => 0,
		], $path );
		if ( ! $id || is_wp_error( $id ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::fail( __( 'Die Datei konnte nicht gespeichert werden.', 'media-lab-ml-decoration' ), 500 );
		}
		$id = (int) $id;

		update_post_meta( $id, self::META_LOGO, 1 );
		update_post_meta( $id, self::META_NAME, $orig );
		update_post_meta( $id, self::META_EXT, $ext );
		// Marker des Aufräum-Crons (media-lab-woocommerce, class-upload-cleanup.php): nicht abgesendete Uploads entfallen nach 30 Tagen.
		// Beim Absenden entfernt die Inquiry-Engine den Marker.
		update_post_meta( $id, '_mlw_pending_upload', '1' );
		update_post_meta( $id, '_mlw_upload_timestamp', time() );

		self::add_owned( $id );
		wp_send_json_success( self::describe( $id ) );
	}

	/** Eigene, noch nicht abgesendete Datei wieder entfernen. */
	public static function ajax_delete(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$id = (int) ( $_POST['id'] ?? 0 );
		if ( self::status( $id ) !== 'ok' || get_post_meta( $id, '_mlw_inquiry_id', true ) ) {
			self::fail( __( 'Diese Datei kann nicht entfernt werden.', 'media-lab-ml-decoration' ), 403 );
		}
		wp_delete_attachment( $id, true );
		self::forget( $id );
		wp_send_json_success( [ 'id' => $id ] );
	}

	private static function fail( string $message, int $status = 400 ): void {
		wp_send_json_error( [ 'message' => $message ], $status );
	}

	// ── Prüfung ───────────────────────────────────────────────────────────────

	/**
	 * Prüft den Inhalt gegen die Endung (Dateikopf). Kein Parsen der Datei.
	 *
	 * @return true|string true = in Ordnung, sonst die Fehlermeldung für den Besucher
	 */
	public static function inspect( string $tmp, string $ext ) {
		$bad = __( 'Der Inhalt der Datei passt nicht zum Dateityp.', 'media-lab-ml-decoration' );
		$fh  = @fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $fh ) {
			return $bad;
		}
		$head = (string) fread( $fh, 4096 );
		fclose( $fh );

		$pdf = strpos( substr( $head, 0, 1024 ), '%PDF-' ) !== false;
		$ps  = strncmp( $head, '%!PS-Adobe', 10 ) === 0;

		switch ( $ext ) {
			case 'png':
				return strncmp( $head, "\x89PNG\r\n\x1a\n", 8 ) === 0 ? true : $bad;
			case 'jpg':
			case 'jpeg':
				return strncmp( $head, "\xFF\xD8\xFF", 3 ) === 0 ? true : $bad;
			case 'pdf':
				return $pdf ? true : $bad;
			case 'ai': // Illustrator: PDF-kompatibel oder PostScript
				return ( $pdf || $ps ) ? true : $bad;
			case 'eps': // PostScript oder EPS mit DOS-Vorspann
				return ( $ps || strncmp( $head, "\xC5\xD0\xD3\xC6", 4 ) === 0 ) ? true : $bad;
			case 'svg':
				return self::inspect_svg( $tmp );
		}
		return $bad;
	}

	/** SVG: muss ein <svg> enthalten und darf nichts Ausführbares tragen (Skripte, Ereignisse, javascript:). */
	private static function inspect_svg( string $tmp ) {
		$s = (string) file_get_contents( $tmp );
		if ( stripos( $s, '<svg' ) === false ) {
			return __( 'Der Inhalt der Datei passt nicht zum Dateityp.', 'media-lab-ml-decoration' );
		}
		if ( preg_match( '/<\s*(script|foreignObject|iframe|embed|object|audio|video)\b|[\s"\'\/]on[a-z]+\s*=|javascript\s*:|data\s*:\s*text\/html/i', $s ) ) {
			return __( 'Diese SVG-Datei enthält nicht zulässige Elemente. Bitte senden Sie das Logo als PDF oder PNG.', 'media-lab-ml-decoration' );
		}
		return true;
	}

	/** Grobe Bremse gegen Massen-Uploads: Anzahl je IP und Stunde (hinter einem Proxy teilen sich Besucher die Adresse, Filter anpassen). */
	private static function rate_ok(): bool {
		$limit = (int) apply_filters( 'ml_deco_logo_rate_limit', self::RATE_LIMIT );
		$key   = 'mld_up_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$n     = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	// ── Ordner ────────────────────────────────────────────────────────────────

	/** Legt den Ordner an und sperrt ihn (Apache: .htaccess, sonst index.php). @return string Pfad oder '' bei Fehler */
	public static function ensure_dir(): string {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "# Media Lab: Logo-Uploads sind nicht öffentlich\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			@chmod( $ht, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ix = $dir . '/index.php';
		if ( ! file_exists( $ix ) ) {
			file_put_contents( $ix, "<?php\n// Silence is golden.\n" );
			@chmod( $ix, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return rtrim( $dir, '/' );
	}

	// ── Zustand einer Datei ───────────────────────────────────────────────────

	/**
	 * @return string 'ok' = Logo-Datei dieses Plugins (und, wenn verlangt, im Besitz des Besuchers),
	 *                'gone' = Datei existiert nicht mehr (Aufräumen nach 30 Tagen),
	 *                'denied' = keine Logo-Datei oder gehört einem anderen Besucher
	 */
	public static function status( int $id, bool $check_owner = true ): string {
		if ( $id <= 0 || ! get_post( $id ) ) {
			return 'gone';
		}
		if ( get_post_type( $id ) !== 'attachment' || ! get_post_meta( $id, self::META_LOGO, true ) ) {
			return 'denied';
		}
		if ( $check_owner && ! self::is_owned( $id ) ) {
			return 'denied';
		}
		return 'ok';
	}

	/** Anzeigedaten einer Datei fürs Frontend und die Mail (nie der Pfad oder die URL). */
	public static function describe( int $id ): array {
		$path = (string) get_attached_file( $id );
		$size = ( $path !== '' && is_file( $path ) ) ? (int) filesize( $path ) : 0;
		return [
			'id'        => $id,
			'name'      => self::name( $id ),
			'ext'       => (string) get_post_meta( $id, self::META_EXT, true ),
			'size'      => $size,
			'sizeLabel' => $size > 0 ? size_format( $size, 1 ) : '',
		];
	}

	/** Ursprünglicher Dateiname des Besuchers (bereinigt). */
	public static function name( int $id ): string {
		$name = (string) get_post_meta( $id, self::META_NAME, true );
		return $name !== '' ? $name : __( 'Logo', 'media-lab-ml-decoration' );
	}

	// ── Besitzliste: gleicher Ort wie Wunschliste und Auswahl ─────────────────

	/** @return array<int,int> Attachment-ID => Zeitstempel */
	public static function owned(): array {
		$v = is_user_logged_in() ? get_user_meta( get_current_user_id(), self::USER_META_KEY, true ) : self::session_get();
		if ( ! is_array( $v ) ) {
			return [];
		}
		$out = [];
		foreach ( $v as $id => $ts ) {
			$out[ (int) $id ] = (int) $ts;
		}
		return $out;
	}

	public static function is_owned( int $id ): bool {
		return isset( self::owned()[ $id ] );
	}

	private static function add_owned( int $id ): void {
		$all        = self::owned();
		$all[ $id ] = time();
		foreach ( array_keys( $all ) as $k ) {
			if ( get_post_type( $k ) !== 'attachment' ) { // inzwischen gelöschte Dateien (Cron) fallen heraus
				unset( $all[ $k ] );
			}
		}
		if ( count( $all ) > self::MAX_OWNED ) {
			asort( $all );
			$all = array_slice( $all, -self::MAX_OWNED, null, true );
		}
		self::store( $all );
	}

	private static function forget( int $id ): void {
		$all = self::owned();
		unset( $all[ $id ] );
		self::store( $all );
	}

	private static function store( array $all ): void {
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_META_KEY, $all );
		} else {
			self::session_set( $all );
		}
	}

	/** Beim Login: Gast-Besitzliste (Session) in die Liste des Nutzers übernehmen. */
	public static function merge_session_on_login( $user_login, $user ): void {
		$session = self::session_get();
		if ( ! $session || ! ( $user instanceof WP_User ) ) {
			return;
		}
		$existing = get_user_meta( $user->ID, self::USER_META_KEY, true );
		$existing = is_array( $existing ) ? $existing : [];
		foreach ( $session as $id => $ts ) {
			$existing[ (int) $id ] = max( (int) $ts, (int) ( $existing[ (int) $id ] ?? 0 ) );
		}
		update_user_meta( $user->ID, self::USER_META_KEY, $existing );
		self::session_set( [] );
	}

	/** @return array<int,int> */
	private static function session_get(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return [];
		}
		$v = WC()->session->get( self::SESSION_KEY );
		return is_array( $v ) ? $v : [];
	}

	private static function session_set( array $all ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( self::SESSION_KEY, $all );
		// Wie bei der Wunschliste: bei reinen Ajax-Requests setzt WooCommerce den Session-Cookie nicht zuverlässig.
		if ( method_exists( WC()->session, 'set_customer_session_cookie' ) && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}
}
