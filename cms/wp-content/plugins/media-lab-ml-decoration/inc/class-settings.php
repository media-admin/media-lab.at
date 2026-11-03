<?php
/**
 * Einstellungen: WooCommerce -> Veredelung
 *
 * Optionen:
 *   ml_deco_enabled                  yes|no   Veredelungs-Tab insgesamt an/aus
 *   ml_deco_show_prices_<lieferant>  yes|no   Preise anzeigen (Standard: no)
 *   ml_deco_factor_<lieferant>       float    Faktor auf den Einkaufspreis der Veredelung (Standard: 1)
 *   ml_deco_tab_title                string
 *   ml_deco_price_note               string   Hinweis unter den Preisen
 *   ml_deco_noprice_note             string   Hinweis, wenn Preise ausgeblendet sind
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
	}

	// ── Getter ────────────────────────────────────────────────────────────────

	public static function enabled(): bool {
		return get_option( 'ml_deco_enabled', 'yes' ) === 'yes';
	}

	public static function show_prices( string $key ): bool {
		return get_option( 'ml_deco_show_prices_' . $key, 'no' ) === 'yes';
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

	public static function sanitize_factor( $value ): float {
		$f = (float) str_replace( ',', '.', (string) $value );
		return $f > 0 ? round( $f, 4 ) : 1.0;
	}

	// ── Registrierung ─────────────────────────────────────────────────────────

	public static function register(): void {
		$yesno = static fn( $v ) => $v === 'yes' ? 'yes' : 'no';

		register_setting( self::GROUP, 'ml_deco_enabled', [ 'type' => 'string', 'sanitize_callback' => $yesno, 'default' => 'yes' ] );
		register_setting( self::GROUP, 'ml_deco_tab_title', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( self::GROUP, 'ml_deco_price_note', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ] );
		register_setting( self::GROUP, 'ml_deco_noprice_note', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ] );

		foreach ( array_keys( ML_Deco_Store::suppliers() ) as $key ) {
			register_setting( self::GROUP, 'ml_deco_show_prices_' . $key, [ 'type' => 'string', 'sanitize_callback' => $yesno, 'default' => 'no' ] );
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

	private static function notice( string $type, string $message ): void {
		set_transient( 'ml_deco_notice_' . get_current_user_id(), [ $type, $message ], 60 );
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
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Veredelung', 'media-lab-ml-decoration' ) . '</h1>';
		echo '<p>' . esc_html__( 'Zeigt auf der Produktseite die Druckpositionen und Veredelungstechniken von MidOcean und Makito, auf Wunsch mit Preisen. Preis = Einkaufspreis der Veredelung × Faktor.', 'media-lab-ml-decoration' ) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Veredelungs-Tab', 'media-lab-ml-decoration' ) . '</th><td><label>';
		echo '<input type="checkbox" name="ml_deco_enabled" value="yes"' . checked( self::enabled(), true, false ) . '> ';
		echo esc_html__( 'Auf Produktseiten anzeigen', 'media-lab-ml-decoration' ) . '</label></td></tr>';
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
			if ( $show && abs( $factor - 1.0 ) < 0.0001 ) {
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
		echo '</tbody></table>';

		submit_button();
		echo '</form>';

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
}
