<?php
/**
 * Einstellungen: WooCommerce -> Veredelung
 *
 * Optionen:
 *   ml_deco_enabled                  yes|no   Veredelungs-Tab insgesamt an/aus
 *   ml_deco_show_prices_<lieferant>  yes|no   Preise anzeigen (Standard: no; bei Lieferanten mit eigenen Preisen: yes)
 *   ml_deco_factor_<lieferant>       float    Faktor auf den Einkaufspreis der Veredelung (Standard: 1)
 *   ml_deco_calc_enabled             yes|no   Auswahl + Preisrechner im Tab anzeigen (Standard: yes)
 *   ml_deco_tab_title                string
 *   ml_deco_price_note               string   Hinweis unter den Preisen
 *   ml_deco_noprice_note             string   Hinweis, wenn Preise ausgeblendet sind
 *   ml_deco_setup_note               string   Hinweis bei Vorkosten, die nur ein Richtwert sind (Technik-Flag setup_estimate)
 *
 * Der Faktor ist bewusst getrennt vom Aufschlagsfaktor der Produktpreise
 * (ml_markup_factor_<lieferant>).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Settings {

	const GROUP = 'ml_deco';
	const SLUG  = 'ml-decoration';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_action( 'admin_post_ml_deco_reload', [ __CLASS__, 'handle_reload' ] );
		add_action( 'admin_post_ml_deco_upload', [ __CLASS__, 'handle_upload' ] );
		add_action( 'admin_post_ml_deco_excel_upload', [ __CLASS__, 'handle_excel_upload' ] );
		add_action( 'admin_post_ml_deco_excel_template', [ __CLASS__, 'handle_excel_template' ] );
		add_action( 'admin_post_ml_deco_excel_download', [ __CLASS__, 'handle_excel_download' ] );
	}

	// ── Getter ────────────────────────────────────────────────────────────────

	public static function enabled(): bool {
		return get_option( 'ml_deco_enabled', 'yes' ) === 'yes';
	}

	public static function calc_enabled(): bool {
		return get_option( 'ml_deco_calc_enabled', 'yes' ) === 'yes';
	}

	/** Lieferant mit eigenen Verkaufspreisen (Faktor 1, Preise standardmäßig sichtbar). */
	public static function own_prices( string $key ): bool {
		return ! empty( ML_Deco_Store::suppliers()[ $key ]['own_prices'] );
	}

	public static function show_prices( string $key ): bool {
		return get_option( 'ml_deco_show_prices_' . $key, self::own_prices( $key ) ? 'yes' : 'no' ) === 'yes';
	}

	public static function factor( string $key ): float {
		return self::sanitize_factor( get_option( 'ml_deco_factor_' . $key, '1' ) );
	}

	public static function tab_title(): string {
		$v = trim( (string) get_option( 'ml_deco_tab_title', '' ) );
		return $v !== '' ? $v : __( 'Veredelung', 'media-lab-ml-decoration' );
	}

	public static function price_note(): string {
		return trim( (string) get_option( 'ml_deco_price_note', __( 'Alle Preise netto zzgl. MwSt. Die Veredelung wird nach Ihren Angaben individuell kalkuliert.', 'media-lab-ml-decoration' ) ) );
	}

	public static function noprice_note(): string {
		return trim( (string) get_option( 'ml_deco_noprice_note', __( 'Die Preise für die Veredelung erhalten Sie mit Ihrer Anfrage.', 'media-lab-ml-decoration' ) ) );
	}

	public static function setup_note(): string {
		return trim( (string) get_option( 'ml_deco_setup_note', __( 'Die endgültigen Vorkosten nennen wir Ihnen im Angebot – sie hängen von Motiv, Größe und Komplexität ab.', 'media-lab-ml-decoration' ) ) );
	}

	public static function sanitize_factor( $value ): float {
		$f = (float) str_replace( ',', '.', (string) $value );
		return $f > 0 ? round( $f, 4 ) : 1.0;
	}

	// ── Registrierung ─────────────────────────────────────────────────────────

	public static function register(): void {
		$yesno = static fn( $v ) => $v === 'yes' ? 'yes' : 'no';

		register_setting( self::GROUP, 'ml_deco_enabled', [ 'type' => 'string', 'sanitize_callback' => $yesno, 'default' => 'yes' ] );
		register_setting( self::GROUP, 'ml_deco_calc_enabled', [ 'type' => 'string', 'sanitize_callback' => $yesno, 'default' => 'yes' ] );
		register_setting( self::GROUP, 'ml_deco_tab_title', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( self::GROUP, 'ml_deco_price_note', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ] );
		register_setting( self::GROUP, 'ml_deco_noprice_note', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ] );
		register_setting( self::GROUP, 'ml_deco_setup_note', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ] );

		foreach ( array_keys( ML_Deco_Store::suppliers() ) as $key ) {
			register_setting( self::GROUP, 'ml_deco_show_prices_' . $key, [ 'type' => 'string', 'sanitize_callback' => $yesno, 'default' => self::own_prices( $key ) ? 'yes' : 'no' ] );
			register_setting( self::GROUP, 'ml_deco_factor_' . $key, [ 'type' => 'number', 'sanitize_callback' => [ __CLASS__, 'sanitize_factor' ], 'default' => 1 ] );
		}
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Veredelung', 'media-lab-ml-decoration' ),
			__( 'Veredelung', 'media-lab-ml-decoration' ),
			'manage_woocommerce',
			self::SLUG,
			[ __CLASS__, 'page' ]
		);
	}

	// ── Aktionen ──────────────────────────────────────────────────────────────

	public static function handle_reload(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'media-lab-ml-decoration' ) );
		}
		check_admin_referer( 'ml_deco_reload' );

		$ok = [];
		$errors = [];
		foreach ( ML_Deco_Store::suppliers() as $key => $supplier ) {
			$file = ML_Deco_Store::feed_file( $key );
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$res = ML_Deco_Store::import_file( $key, $file );
			if ( $res === true ) {
				$ok[] = $supplier['label'];
			} else {
				$errors[] = $supplier['label'] . ': ' . $res;
			}
		}

		if ( $errors ) {
			self::notice( 'error', implode( ' | ', $errors ) );
		} elseif ( $ok ) {
			self::notice( 'success', sprintf( __( 'Preislisten eingelesen: %s', 'media-lab-ml-decoration' ), implode( ', ', $ok ) ) );
		} else {
			self::notice( 'warning', __( 'Keine Preislisten-Dateien im Ordner supplier-sync/feeds gefunden. Sie können die Datei unten hochladen.', 'media-lab-ml-decoration' ) );
		}
		self::back();
	}

	public static function handle_upload(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'media-lab-ml-decoration' ) );
		}
		check_admin_referer( 'ml_deco_upload' );

		$key       = isset( $_POST['supplier'] ) ? sanitize_key( wp_unslash( $_POST['supplier'] ) ) : '';
		$suppliers = ML_Deco_Store::suppliers();
		if ( ! isset( $suppliers[ $key ] ) ) {
			self::notice( 'error', __( 'Unbekannter Lieferant.', 'media-lab-ml-decoration' ) );
			self::back();
		}

		$tmp = $_FILES['pricelist']['tmp_name'] ?? '';
		if ( ! $tmp || ! is_uploaded_file( $tmp ) || (int) ( $_FILES['pricelist']['size'] ?? 0 ) > 5 * MB_IN_BYTES ) {
			self::notice( 'error', __( 'Keine gültige Datei hochgeladen (JSON, max. 5 MB).', 'media-lab-ml-decoration' ) );
			self::back();
		}

		$json = (string) file_get_contents( $tmp );
		$res  = ML_Deco_Store::import_json( $key, $json, time(), 'upload' );
		if ( $res === true ) {
			self::notice( 'success', sprintf( __( 'Preisliste %s importiert.', 'media-lab-ml-decoration' ), $suppliers[ $key ]['label'] ) );
		} else {
			self::notice( 'error', $res );
		}
		self::back();
	}

	/** @param string[] $details weitere Zeilen (Fehler, Warnungen), werden als Liste unter der Meldung angezeigt */
	public static function handle_excel_upload(): void {
		self::excel_guard( 'ml_deco_excel_upload' );

		$tmp  = $_FILES['excel']['tmp_name'] ?? '';
		$name = sanitize_file_name( wp_unslash( (string) ( $_FILES['excel']['name'] ?? '' ) ) );
		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			self::notice( 'error', __( 'Keine Datei ausgewählt.', 'media-lab-ml-decoration' ) );
			self::back();
		}
		if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'xlsx' ) {
			self::notice( 'error', __( 'Bitte eine Excel-Datei im Format .xlsx hochladen (Speichern unter > Excel-Arbeitsmappe). Es wurde nichts geändert.', 'media-lab-ml-decoration' ) );
			self::back();
		}
		if ( (int) ( $_FILES['excel']['size'] ?? 0 ) > ML_Deco_Excel::MAX_UPLOAD ) {
			self::notice( 'error', __( 'Die Datei ist zu groß (max. 1 MB). Es wurde nichts geändert.', 'media-lab-ml-decoration' ) );
			self::back();
		}

		self::excel_report( ML_Deco_Excel::import_path( $tmp, $name ), __( 'Cotton-Preisliste aus der Excel-Datei übernommen.', 'media-lab-ml-decoration' ) );
	}

	/** Liest die mitgelieferte Vorlage mit den Vorschlagswerten ein. */
	public static function handle_excel_template(): void {
		self::excel_guard( 'ml_deco_excel_template' );
		self::excel_report(
			ML_Deco_Excel::import_path( ML_Deco_Excel::template_path(), 'Cotton-Veredelung-Preise-Vorlage.xlsx' ),
			__( 'Vorschlagswerte eingelesen. Bitte Preise prüfen und bei Bedarf in der Excel anpassen.', 'media-lab-ml-decoration' )
		);
	}

	public static function handle_excel_download(): void {
		self::excel_guard( 'ml_deco_excel_download' );

		$which = isset( $_GET['which'] ) && $_GET['which'] === 'template' ? 'template' : 'current';
		$bin   = $which === 'current' ? ML_Deco_Excel::stored_content() : null;
		$file  = 'Cotton-Veredelung-Preise.xlsx';
		if ( $bin === null ) {
			$bin  = (string) file_get_contents( ML_Deco_Excel::template_path() );
			$file = 'Cotton-Veredelung-Preise-Vorlage.xlsx';
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . strlen( $bin ) );
		echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binärdatei
		exit;
	}

	/** Rechte und Nonce prüfen; Cotton muss als Lieferant vorhanden sein. */
	private static function excel_guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'media-lab-ml-decoration' ) );
		}
		check_admin_referer( $action );
		if ( ! isset( ML_Deco_Store::suppliers()[ ML_Deco_Excel::SUPPLIER_KEY ] ) ) {
			wp_die( esc_html__( 'Der Lieferant für die Excel-Preisliste ist nicht aktiv.', 'media-lab-ml-decoration' ) );
		}
	}

	/** @param array{ok:bool, errors:string[], warnings:string[], summary:string[]} $res */
	private static function excel_report( array $res, string $success ): void {
		if ( ! $res['ok'] ) {
			self::notice(
				'error',
				__( 'Die Excel-Datei wurde nicht übernommen – es wurde nichts geändert. Bitte folgende Punkte korrigieren:', 'media-lab-ml-decoration' ),
				$res['errors']
			);
		} elseif ( $res['warnings'] ) {
			self::notice( 'warning', $success . ' ' . __( 'Bitte diese Auffälligkeiten prüfen (die Preise wurden trotzdem übernommen):', 'media-lab-ml-decoration' ), array_merge( $res['warnings'], $res['summary'] ) );
		} else {
			self::notice( 'success', $success, $res['summary'] );
		}
		self::back();
	}

	private static function notice( string $type, string $message, array $details = [] ): void {
		set_transient( 'ml_deco_notice_' . get_current_user_id(), [ $type, $message, array_values( $details ) ], 120 );
	}

	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	// ── Seite ─────────────────────────────────────────────────────────────────

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = get_transient( 'ml_deco_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'ml_deco_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p>';
			if ( ! empty( $notice[2] ) ) {
				echo '<ul style="list-style:disc;margin:0 0 .8em 1.5em">';
				foreach ( $notice[2] as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Veredelung', 'media-lab-ml-decoration' ) . '</h1>';
		echo '<p>' . esc_html__( 'Zeigt auf der Produktseite die Druckpositionen und Veredelungstechniken, auf Wunsch mit Preisen. MidOcean und Makito: Preis = Einkaufspreis der Veredelung × Faktor. Cotton Classics: Preise aus der Excel-Preisliste (siehe unten), Faktor 1.', 'media-lab-ml-decoration' ) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Veredelungs-Tab', 'media-lab-ml-decoration' ) . '</th><td><label>';
		echo '<input type="checkbox" name="ml_deco_enabled" value="yes"' . checked( self::enabled(), true, false ) . '> ';
		echo esc_html__( 'Auf Produktseiten anzeigen', 'media-lab-ml-decoration' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Auswahl & Preisrechner', 'media-lab-ml-decoration' ) . '</th><td><label>';
		echo '<input type="checkbox" name="ml_deco_calc_enabled" value="yes"' . checked( self::calc_enabled(), true, false ) . '> ';
		echo esc_html__( 'Kunden können Position, Technik und Farben wählen (mit Preisen nur, wenn "Preise anzeigen" aktiv ist)', 'media-lab-ml-decoration' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="ml_deco_tab_title">' . esc_html__( 'Tab-Titel', 'media-lab-ml-decoration' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="ml_deco_tab_title" name="ml_deco_tab_title" value="' . esc_attr( (string) get_option( 'ml_deco_tab_title', '' ) ) . '" placeholder="' . esc_attr__( 'Veredelung', 'media-lab-ml-decoration' ) . '"></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Lieferanten', 'media-lab-ml-decoration' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'Lieferant', 'media-lab-ml-decoration' ) . '</th>';
		echo '<th>' . esc_html__( 'Faktor', 'media-lab-ml-decoration' ) . '</th>';
		echo '<th>' . esc_html__( 'Preise anzeigen', 'media-lab-ml-decoration' ) . '</th>';
		echo '<th>' . esc_html__( 'Preisliste', 'media-lab-ml-decoration' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( ML_Deco_Store::suppliers() as $key => $supplier ) {
			$status = ML_Deco_Store::status( $key );
			$factor = self::factor( $key );
			$show   = self::show_prices( $key );

			echo '<tr><td><strong>' . esc_html( $supplier['label'] ) . '</strong></td>';
			echo '<td><input type="number" step="0.01" min="0.01" style="width:6em" name="ml_deco_factor_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $factor ) . '">';
			if ( self::own_prices( $key ) ) {
				echo '<br><span class="description">' . esc_html__( 'Preise in der Excel sind Verkaufspreise – Faktor 1 lassen.', 'media-lab-ml-decoration' ) . '</span>';
			} elseif ( $show && abs( $factor - 1.0 ) < 0.0001 ) {
				echo '<br><span class="description"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Faktor 1 zeigt den Einkaufspreis an.', 'media-lab-ml-decoration' ) . '</span>';
			}
			echo '</td>';
			echo '<td><label><input type="checkbox" name="ml_deco_show_prices_' . esc_attr( $key ) . '" value="yes"' . checked( $show, true, false ) . '> ' . esc_html__( 'Ja', 'media-lab-ml-decoration' ) . '</label></td>';

			echo '<td>';
			if ( $status ) {
				printf(
					/* translators: 1: Anzahl Techniken, 2: gültig ab, 3: Import-Datum, 4: Anzahl Produkte */
					esc_html__( '%1$d Techniken, gültig ab %2$s, eingelesen %3$s, %4$d Produkte mit Veredelungsdaten', 'media-lab-ml-decoration' ),
					(int) $status['techniques'],
					esc_html( $status['valid_from'] ?: '–' ),
					esc_html( $status['imported'] ? wp_date( 'd.m.Y H:i', $status['imported'] ) : '–' ),
					ML_Deco_Store::product_count( $key )
				);
			} else {
				echo '<em>' . esc_html__( 'Noch keine Preisliste eingelesen.', 'media-lab-ml-decoration' ) . '</em>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Faktor: z. B. 2 verdoppelt alle Veredelungspreise (Staffelpreise, Einrichtung, Mindestwerte). Preise sind standardmäßig ausgeblendet und werden dann gar nicht erst in die Seite geschrieben.', 'media-lab-ml-decoration' ) . '</p>';

		echo '<h2>' . esc_html__( 'Texte', 'media-lab-ml-decoration' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ml_deco_price_note">' . esc_html__( 'Hinweis unter den Preisen', 'media-lab-ml-decoration' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="ml_deco_price_note" name="ml_deco_price_note">' . esc_textarea( self::price_note() ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="ml_deco_noprice_note">' . esc_html__( 'Hinweis ohne Preise', 'media-lab-ml-decoration' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="ml_deco_noprice_note" name="ml_deco_noprice_note">' . esc_textarea( self::noprice_note() ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="ml_deco_setup_note">' . esc_html__( 'Hinweis zu den Vorkosten', 'media-lab-ml-decoration' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="ml_deco_setup_note" name="ml_deco_setup_note">' . esc_textarea( self::setup_note() ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Erscheint bei Techniken, deren Vorkosten nur ein Richtwert sind (Cotton), unter den Preisen und im Rechner.', 'media-lab-ml-decoration' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button();
		echo '</form>';

		self::excel_section();

		echo '<hr><h2>' . esc_html__( 'Preislisten', 'media-lab-ml-decoration' ) . '</h2>';
		echo '<p>' . esc_html__( 'Der Sync schreibt die Preislisten nach supplier-sync/feeds. Das Plugin liest sie automatisch ein, sobald die Datei neuer ist (Prüfung alle 15 Minuten). Hier können Sie das sofort auslösen.', 'media-lab-ml-decoration' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ml_deco_reload">';
		wp_nonce_field( 'ml_deco_reload' );
		submit_button( __( 'Preislisten jetzt einlesen', 'media-lab-ml-decoration' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h3>' . esc_html__( 'Preisliste hochladen (falls der Sync auf einem anderen Server läuft)', 'media-lab-ml-decoration' ) . '</h3>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ml_deco_upload">';
		wp_nonce_field( 'ml_deco_upload' );
		echo '<select name="supplier">';
		foreach ( ML_Deco_Store::suppliers() as $key => $supplier ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $supplier['label'] ) . '</option>';
		}
		echo '</select> <input type="file" name="pricelist" accept=".json,application/json"> ';
		submit_button( __( 'Hochladen', 'media-lab-ml-decoration' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}
	// ── Cotton: Preise per Excel pflegen ──────────────────────────────────────

	private static function excel_section(): void {
		$key = ML_Deco_Excel::SUPPLIER_KEY;
		if ( ! isset( ML_Deco_Store::suppliers()[ $key ] ) ) {
			return;
		}
		$status = ML_Deco_Store::status( $key );
		$stored = ML_Deco_Excel::stored_info();
		$admin  = admin_url( 'admin-post.php' );
		$dl     = static fn( string $which ): string => wp_nonce_url( add_query_arg( [ 'action' => 'ml_deco_excel_download', 'which' => $which ], $admin ), 'ml_deco_excel_download' );

		echo '<hr><h2 id="cotton-preise">' . esc_html__( 'Cotton Classics – Preise per Excel pflegen', 'media-lab-ml-decoration' ) . '</h2>';
		echo '<p>' . esc_html__( 'Die Veredelungspreise für Cotton Classics (Transferdruck, Sublimationsdruck, Stick) pflegen Sie in einer Excel-Datei und laden sie hier hoch. Die Preise in der Excel sind Verkaufspreise (netto, je Stück) und werden ohne Faktor angezeigt.', 'media-lab-ml-decoration' ) . '</p>';

		// Status
		if ( $status && $status['source'] === 'excel' ) {
			echo '<p><strong>' . esc_html__( 'Aktueller Stand:', 'media-lab-ml-decoration' ) . '</strong> ';
			printf(
				/* translators: 1: Dateiname, 2: Datum, 3: Benutzer, 4: gültig ab */
				esc_html__( 'Datei „%1$s“, eingelesen am %2$s%3$s, gültig ab %4$s.', 'media-lab-ml-decoration' ),
				esc_html( $stored['name'] ?? '–' ),
				esc_html( $status['imported'] ? wp_date( 'd.m.Y H:i', $status['imported'] ) : '–' ),
				! empty( $stored['user'] ) ? esc_html( ' ' . sprintf( __( 'von %s', 'media-lab-ml-decoration' ), $stored['user'] ) ) : '',
				esc_html( $status['valid_from'] ?: '–' )
			);
			echo '</p>';
		} elseif ( $status ) {
			echo '<p><strong>' . esc_html__( 'Aktueller Stand:', 'media-lab-ml-decoration' ) . '</strong> ' . esc_html__( 'Preisliste aus einer anderen Quelle (JSON-Datei). Ein Excel-Upload ersetzt sie.', 'media-lab-ml-decoration' ) . '</p>';
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Noch keine Cotton-Preise eingelesen.', 'media-lab-ml-decoration' ) . '</strong> '
				. esc_html__( 'Mit „Vorschlagswerte einlesen“ starten Sie mit geschätzten Preisen. Diese sind nicht bestätigt – bitte prüfen und in der Excel anpassen, bevor die Seite live geht.', 'media-lab-ml-decoration' ) . '</p></div>';
		}

		// Zuordnung der Produkte
		if ( $status && ML_Deco_Cotton::templates() ) {
			$c = ML_Deco_Cotton::counts();
			echo '<p><strong>' . esc_html__( 'Produkte:', 'media-lab-ml-decoration' ) . '</strong> '
				. esc_html( sprintf( __( '%1$d bestätigt, %2$d Vorschlag, %3$d offen, %4$d ohne Veredelung.', 'media-lab-ml-decoration' ), $c['confirmed'], $c['suggested'], $c['open'], $c['none'] ) )
				. ' <a href="' . esc_url( ML_Deco_Assign::url() ) . '">' . esc_html__( 'Zuordnung öffnen', 'media-lab-ml-decoration' ) . '</a></p>';
		}

		// Aktionen
		echo '<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
		echo '<a class="button button-primary" href="' . esc_url( $dl( 'current' ) ) . '">' . esc_html( $stored ? __( 'Aktuelle Excel herunterladen', 'media-lab-ml-decoration' ) : __( 'Excel-Vorlage herunterladen', 'media-lab-ml-decoration' ) ) . '</a>';
		if ( $stored ) {
			echo '<a class="button" href="' . esc_url( $dl( 'template' ) ) . '">' . esc_html__( 'Vorlage mit Vorschlagswerten herunterladen', 'media-lab-ml-decoration' ) . '</a>';
		}
		echo '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $admin ) . '" style="margin:12px 0">';
		echo '<input type="hidden" name="action" value="ml_deco_excel_upload">';
		wp_nonce_field( 'ml_deco_excel_upload' );
		echo '<input type="file" name="excel" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"> ';
		submit_button( __( 'Excel hochladen und prüfen', 'media-lab-ml-decoration' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( $admin ) . '" style="margin:12px 0"';
		if ( $status ) {
			echo ' onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Die aktuellen Cotton-Preise werden durch die Vorschlagswerte ersetzt. Fortfahren?', 'media-lab-ml-decoration' ) ) ) . ');"';
		}
		echo '><input type="hidden" name="action" value="ml_deco_excel_template">';
		wp_nonce_field( 'ml_deco_excel_template' );
		submit_button( __( 'Vorschlagswerte einlesen', 'media-lab-ml-decoration' ), 'secondary', 'submit', false );
		echo '</form>';

		// Anleitung
		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:4px 18px 12px;max-width:900px;margin-top:16px">';
		echo '<h3>' . esc_html__( 'So ändern Sie die Preise', 'media-lab-ml-decoration' ) . '</h3><ol style="margin-left:1.2em">';
		$steps = [
			__( '<strong>Excel herunterladen</strong> (Button oben, „Aktuelle Excel“ enthält immer den zuletzt hochgeladenen Stand).', 'media-lab-ml-decoration' ),
			__( '<strong>Nur die gelben Zellen ändern.</strong> Alles andere rechnet sich selbst oder ist fest vorgegeben.', 'media-lab-ml-decoration' ),
			__( '<strong>Speichern als .xlsx</strong> und hier mit „Excel hochladen und prüfen“ hochladen.', 'media-lab-ml-decoration' ),
			__( '<strong>Meldung lesen.</strong> Bei Fehlern wird nichts geändert; die Meldung nennt Blatt und Zeile. Bei Warnungen (z. B. Preis steigt bei größerer Menge) sind die Preise übernommen, sollten aber geprüft werden.', 'media-lab-ml-decoration' ),
			__( '<strong>Fertig.</strong> Die neuen Preise gelten sofort für alle neuen Berechnungen (Produktseite, Wunschzettel). Es ist keine weitere Aktion nötig.', 'media-lab-ml-decoration' ),
		];
		$allowed = [ 'strong' => [] ];
		foreach ( $steps as $step ) {
			echo '<li>' . wp_kses( $step, $allowed ) . '</li>';
		}
		echo '</ol>';

		echo '<h4>' . esc_html__( 'Was steht wo?', 'media-lab-ml-decoration' ) . '</h4><ul style="list-style:disc;margin-left:1.5em">';
		$where = [
			__( '<strong>Blätter Transferdruck, Sublimationsdruck, Stick:</strong> je Technik eine Preismatrix. Zeilen = Motivfläche („Fläche bis cm²“, Obergrenze inklusive), Spalten = Menge („ab Stück“). In den Zellen steht der Preis je Stück in € netto.', 'media-lab-ml-decoration' ),
			__( '<strong>Flächenstufen:</strong> Der Kunde gibt Breite × Höhe ein, die passende Stufe wird automatisch gewählt. Beispiele: 25 ≈ 5×5 cm, 100 ≈ 10×10 cm, 310 ≈ A5, 625 ≈ A4, 1250 ≈ A3. Über der letzten Stufe gilt die letzte Stufe.', 'media-lab-ml-decoration' ),
			__( '<strong>Mengenstaffeln (Zeile 11):</strong> „ab Stück“, aufsteigend, die erste beginnt bei 1.', 'media-lab-ml-decoration' ),
			__( '<strong>Vorkosten (B6, B7):</strong> ein fester Richtwert je Technik (einmalig bzw. bei Wiederholung). Die endgültigen Vorkosten legen Sie im Angebot fest. Mindestmenge (B8) leer lassen, wenn es keine gibt.', 'media-lab-ml-decoration' ),
			__( '<strong>Mindermengen-Zuschlag (G6, G7):</strong> optional je Technik. Grenze in Stück und Betrag in € netto, z. B. unter 10 Stück: 40,00 €. Der Zuschlag gilt einmal je Position und Technik und wird in der Summe mitgerechnet. Beide Felder leer lassen, wenn es keinen gibt.', 'media-lab-ml-decoration' ),
			__( '<strong>Preis nur auf Anfrage (G8 = ja):</strong> Die Technik bleibt wählbar (Position, Farben, Motivgröße), der Shop zeigt „Preis auf Anfrage“, die Position fließt nicht in die Summe ein und die Anfrage nennt keinen Preis. Preismatrix und Vorkosten werden dann nicht gebraucht. Vorgesehen für Stick, weil der Preis je Projekt unterschiedlich ist.', 'media-lab-ml-decoration' ),
			__( '<strong>Stick:</strong> „Aufpreis je weitere Farbe“ (B9) gilt je Stück ab der 2. Farbe, für alle Stufen und Staffeln gleich (nur relevant, solange Stick mit Preisen gerechnet wird).', 'media-lab-ml-decoration' ),
			__( '<strong>Blatt Positionen:</strong> die Vorlagen je Warenart (T-Shirt, Polo, Cap …). Je Zeile eine Druckposition mit Größe in cm und einem „x“ bei den möglichen Techniken (Stick zusätzlich mit max. Farben). Welches Produkt welche Vorlage hat, ordnen Sie unter „Veredelung: Zuordnung“ zu. Ein Produkt zeigt den Tab erst, wenn die Zuordnung bestätigt ist. Sublimationsdruck erscheint nur bei Produkten, bei denen er dort freigegeben ist.', 'media-lab-ml-decoration' ),
			__( '<strong>Blatt Anleitung:</strong> „Gültig ab“ (B7) ist nur eine Information (Datum der Preisliste).', 'media-lab-ml-decoration' ),
		];
		foreach ( $where as $line ) {
			echo '<li>' . wp_kses( $line, $allowed ) . '</li>';
		}
		echo '</ul>';

		echo '<h4>' . esc_html__( 'Stufen oder Staffeln ändern', 'media-lab-ml-decoration' ) . '</h4><ul style="list-style:disc;margin-left:1.5em">';
		$change = [
			__( '<strong>Zahlen ändern:</strong> „Fläche bis“ bzw. „ab Stück“ überschreiben – die Preise der Zeile bzw. Spalte gehören dann zur neuen Stufe.', 'media-lab-ml-decoration' ),
			__( '<strong>Weitere Flächenstufe:</strong> direkt unter der letzten Zeile „Fläche bis“ und alle Preise eintragen.', 'media-lab-ml-decoration' ),
			__( '<strong>Stufe entfernen:</strong> die letzte Zeile komplett leeren (Inhalt löschen, Zeile nicht löschen). Die Stufen müssen lückenlos untereinander stehen.', 'media-lab-ml-decoration' ),
			__( '<strong>Neue Position:</strong> im Blatt Positionen eine Zeile unter der letzten Position der Vorlage einfügen (Positions-Schlüssel nur Kleinbuchstaben, Ziffern, Bindestrich, eindeutig innerhalb der Vorlage). Schlüssel bestehender Positionen und Vorlagen nicht ändern – die Auswahl im Wunschzettel hängt daran.', 'media-lab-ml-decoration' ),
			__( '<strong>Weitere Mengenstaffel:</strong> Spalte „Prüfung“ markieren, Rechtsklick → Spalte einfügen, dann „ab Stück“ und Preise eintragen.', 'media-lab-ml-decoration' ),
		];
		foreach ( $change as $line ) {
			echo '<li>' . wp_kses( $line, $allowed ) . '</li>';
		}
		echo '</ul>';

		echo '<h4>' . esc_html__( 'Nicht ändern', 'media-lab-ml-decoration' ) . '</h4><p>'
			. esc_html__( 'Blattnamen, Technik-IDs (B4), Vorlagen- und Positions-Schlüssel (Blatt Positionen, Spalten A und C), Lieferantencode LCC und Währung im Blatt „Anleitung“, die Zeilen oberhalb der Preismatrix (keine Zeilen dort einfügen oder löschen). Wenn doch etwas verrutscht: „Vorlage mit Vorschlagswerten herunterladen“ liefert die Ausgangsdatei.', 'media-lab-ml-decoration' )
			. '</p>';
		echo '<p class="description">' . esc_html__( 'Der Name der Techniken (B5) wird im Shop angezeigt. Im Shop erscheint nirgends, wer die Veredelung ausführt – bitte neutrale Namen verwenden.', 'media-lab-ml-decoration' ) . '</p>';
		echo '</div>';
	}
}
