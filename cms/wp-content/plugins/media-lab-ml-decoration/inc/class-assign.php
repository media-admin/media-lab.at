<?php
/**
 * Admin: WooCommerce -> Cotton-Zuordnung
 *
 * Tabelle aller Cotton-Produkte mit Vorlage (Warenart) und Sublimation-Eignung. Nur bestätigte Zuordnungen zeigen
 * den Veredelungs-Tab. Siehe ML_Deco_Cotton.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Assign {

	const SLUG     = 'ml-decoration-cotton';
	const PER_PAGE = 50;

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_post_ml_deco_assign_save', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_ml_deco_assign_suggest', [ __CLASS__, 'handle_suggest' ] );
		add_action( 'admin_post_ml_deco_assign_confirm_all', [ __CLASS__, 'handle_confirm_all' ] );
		add_action( 'admin_post_ml_deco_assign_sublimation', [ __CLASS__, 'handle_sublimation' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	public static function menu(): void {
		if ( ! isset( ML_Deco_Store::suppliers()[ ML_Deco_Excel::SUPPLIER_KEY ] ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'Veredelung: Cotton-Zuordnung', 'media-lab-ml-decoration' ),
			__( 'Veredelung: Zuordnung', 'media-lab-ml-decoration' ),
			'manage_woocommerce',
			self::SLUG,
			[ __CLASS__, 'page' ]
		);
	}

	// ── Aktionen ──────────────────────────────────────────────────────────────

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'media-lab-ml-decoration' ) );
		}
		check_admin_referer( $action );
	}

	private static function back( string $msg, string $type = 'success' ): void {
		set_transient( 'ml_deco_assign_notice_' . get_current_user_id(), [ $type, $msg ], 120 );
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : '';
		// nur zurück auf die eigene Seite
		if ( $return === '' || strpos( $return, 'page=' . self::SLUG ) === false || strpos( $return, admin_url() ) !== 0 ) {
			$return = self::url();
		}
		wp_safe_redirect( $return );
		exit;
	}

	/** Zeilen der aktuellen Seite speichern: Vorlage gewählt = bestätigt, leer = wieder offen. */
	public static function handle_save(): void {
		self::guard( 'ml_deco_assign_save' );

		$ids       = array_map( 'intval', (array) ( $_POST['ml_ids'] ?? [] ) );
		$rows      = (array) ( $_POST['ml_tpl'] ?? [] );
		$subs      = (array) ( $_POST['ml_sub'] ?? [] );
		$templates = ML_Deco_Cotton::templates();
		$saved     = 0;

		foreach ( $ids as $id ) {
			if ( ! $id || ! ML_Deco_Cotton::is_cotton_product( $id ) ) {
				continue;
			}
			$tpl = sanitize_key( wp_unslash( $rows[ $id ] ?? '' ) );
			if ( $tpl !== '' && $tpl !== ML_Deco_Cotton::NONE && ! isset( $templates[ $tpl ] ) ) {
				continue; // unbekannte Vorlage ignorieren
			}
			ML_Deco_Cotton::save_assignment( $id, $tpl, 'confirmed', isset( $subs[ $id ] ) );
			$saved++;
		}
		self::back( sprintf( __( '%d Produkte gespeichert (Zeilen mit Vorlage sind bestätigt).', 'media-lab-ml-decoration' ), $saved ) );
	}

	/** Offene Produkte nach den Regeln vorbelegen (Status "vorgeschlagen", zeigt noch keinen Tab). */
	public static function handle_suggest(): void {
		self::guard( 'ml_deco_assign_suggest' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$ids = ML_Deco_Cotton::ids_by_status( 'open' );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			update_object_term_cache( $chunk, 'product' );
		}
		$set = 0;
		foreach ( $ids as $id ) {
			$tpl = ML_Deco_Cotton::suggest( $id );
			if ( $tpl !== '' ) {
				ML_Deco_Cotton::save_assignment( $id, $tpl, 'suggested' );
				$set++;
			}
		}
		self::back( sprintf(
			__( 'Vorschläge erstellt: %1$d von %2$d offenen Produkten. Die übrigen %3$d blieben offen (keine Regel passt). Sichtbar wird der Tab erst nach dem Bestätigen.', 'media-lab-ml-decoration' ),
			$set,
			count( $ids ),
			count( $ids ) - $set
		) );
	}

	public static function handle_confirm_all(): void {
		self::guard( 'ml_deco_assign_confirm_all' );
		$n = 0;
		foreach ( ML_Deco_Cotton::ids_by_status( 'suggested' ) as $id ) {
			$a = ML_Deco_Cotton::assignment( $id );
			ML_Deco_Cotton::save_assignment( $id, $a['tpl'], 'confirmed' );
			$n++;
		}
		self::back( sprintf( __( '%d Vorschläge bestätigt.', 'media-lab-ml-decoration' ), $n ) );
	}

	/** Sublimation für alle Produkte einer Kollektion/Kategorie/eines Schlagworts freigeben. */
	public static function handle_sublimation(): void {
		self::guard( 'ml_deco_assign_sublimation' );
		$name = sanitize_text_field( wp_unslash( $_POST['collection'] ?? '' ) );
		$ids  = ML_Deco_Cotton::ids_in_collection( $name );
		if ( ! $ids ) {
			self::back( sprintf( __( 'Keine Cotton-Produkte in „%s“ gefunden (Name oder Slug der Kollektion prüfen).', 'media-lab-ml-decoration' ), $name ), 'error' );
		}
		foreach ( $ids as $id ) {
			update_post_meta( $id, ML_Deco_Cotton::META_SUB, 'yes' );
		}
		self::back( sprintf( __( 'Sublimation für %1$d Produkte aus „%2$s“ freigegeben.', 'media-lab-ml-decoration' ), count( $ids ), $name ) );
	}

	// ── Seite ─────────────────────────────────────────────────────────────────

	private static function status_label( string $state, string $tpl ): string {
		if ( $state === 'confirmed' ) {
			return $tpl === ML_Deco_Cotton::NONE ? __( 'keine Veredelung', 'media-lab-ml-decoration' ) : __( 'bestätigt', 'media-lab-ml-decoration' );
		}
		return $state === 'suggested' ? __( 'Vorschlag', 'media-lab-ml-decoration' ) : __( 'offen', 'media-lab-ml-decoration' );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = get_transient( 'ml_deco_assign_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'ml_deco_assign_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}

		$templates = ML_Deco_Cotton::templates();
		$admin     = admin_url( 'admin-post.php' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Veredelung: Cotton-Zuordnung', 'media-lab-ml-decoration' ) . '</h1>';

		if ( ! $templates ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Es sind noch keine Positionsvorlagen eingelesen. Bitte zuerst unter WooCommerce > Veredelung die Cotton-Excel einlesen („Vorschlagswerte einlesen“).', 'media-lab-ml-decoration' ) . '</p></div></div>';
			return;
		}

		$counts = ML_Deco_Cotton::counts();
		echo '<p>' . esc_html__( 'Jedes Cotton-Produkt bekommt hier eine Vorlage (Warenart) mit den möglichen Druckpositionen. Nur bestätigte Produkte zeigen im Shop den Veredelungs-Tab. Neue Produkte sind „offen“ und zeigen keinen Tab, bis sie zugeordnet sind.', 'media-lab-ml-decoration' ) . '</p>';

		// Übersicht / Filter-Links
		$f = [
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'tpl'    => isset( $_GET['tpl'] ) ? sanitize_key( wp_unslash( $_GET['tpl'] ) ) : '',
			'cat'    => isset( $_GET['cat'] ) ? (int) $_GET['cat'] : 0,
			's'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		];
		$page = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );

		$tabs = [
			''          => [ __( 'Alle', 'media-lab-ml-decoration' ), $counts['total'] ],
			'open'      => [ __( 'Offen', 'media-lab-ml-decoration' ), $counts['open'] ],
			'suggested' => [ __( 'Vorschlag', 'media-lab-ml-decoration' ), $counts['suggested'] ],
			'confirmed' => [ __( 'Bestätigt', 'media-lab-ml-decoration' ), $counts['confirmed'] ],
			'none'      => [ __( 'Keine Veredelung', 'media-lab-ml-decoration' ), $counts['none'] ],
		];
		echo '<ul class="subsubsub">';
		$i = 0;
		foreach ( $tabs as $k => [ $label, $n ] ) {
			echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( self::url( $k !== '' ? [ 'status' => $k ] : [] ) ) . '"' . ( $f['status'] === $k ? ' class="current"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . (int) $n . ')</span></a></li>';
		}
		echo '</ul><br class="clear">';

		// Sammelaktionen
		echo '<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;margin:12px 0">';

		echo '<form method="post" action="' . esc_url( $admin ) . '">';
		wp_nonce_field( 'ml_deco_assign_suggest' );
		echo '<input type="hidden" name="action" value="ml_deco_assign_suggest">';
		submit_button( sprintf( __( 'Vorschläge für %d offene Produkte erstellen', 'media-lab-ml-decoration' ), $counts['open'] ), 'secondary', 'submit', false );
		echo '<p class="description" style="max-width:26em">' . esc_html__( 'Belegt offene Produkte nach ihren Kategorien vor. Der Tab erscheint erst nach dem Bestätigen. Auch für neu importierte Produkte nutzbar.', 'media-lab-ml-decoration' ) . '</p></form>';

		echo '<form method="post" action="' . esc_url( $admin ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( sprintf( __( '%d Vorschläge ungeprüft bestätigen? Danach zeigen alle diese Produkte den Veredelungs-Tab.', 'media-lab-ml-decoration' ), $counts['suggested'] ) ) ) . ');">';
		wp_nonce_field( 'ml_deco_assign_confirm_all' );
		echo '<input type="hidden" name="action" value="ml_deco_assign_confirm_all">';
		submit_button( sprintf( __( 'Alle %d Vorschläge bestätigen', 'media-lab-ml-decoration' ), $counts['suggested'] ), 'secondary', 'submit', false, $counts['suggested'] ? [] : [ 'disabled' => 'disabled' ] );
		echo '<p class="description" style="max-width:26em">' . esc_html__( 'Nur, wenn die Vorschläge stichprobenartig geprüft sind. Zeilenweise Prüfung: Tabelle unten.', 'media-lab-ml-decoration' ) . '</p></form>';

		echo '<form method="post" action="' . esc_url( $admin ) . '">';
		wp_nonce_field( 'ml_deco_assign_sublimation' );
		echo '<input type="hidden" name="action" value="ml_deco_assign_sublimation">';
		echo '<input type="text" name="collection" value="Sublimation" class="regular-text" style="width:12em"> ';
		submit_button( __( 'Sublimation für diese Kollektion freigeben', 'media-lab-ml-decoration' ), 'secondary', 'submit', false );
		echo '<p class="description" style="max-width:26em">' . esc_html__( 'Name oder Slug einer Kollektion, Kategorie oder eines Schlagworts. Sublimationsdruck wird nur Produkten angeboten, bei denen er hier freigegeben ist.', 'media-lab-ml-decoration' ) . '</p></form>';
		echo '</div>';

		// Filter
		echo '<form method="get" style="margin:8px 0"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		if ( $f['status'] !== '' ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $f['status'] ) . '">';
		}
		echo '<select name="tpl"><option value="">' . esc_html__( 'Alle Vorlagen', 'media-lab-ml-decoration' ) . '</option>';
		foreach ( $templates as $key => $t ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $f['tpl'], $key, false ) . '>' . esc_html( $t['name'] ) . '</option>';
		}
		echo '<option value="' . esc_attr( ML_Deco_Cotton::NONE ) . '"' . selected( $f['tpl'], ML_Deco_Cotton::NONE, false ) . '>' . esc_html__( 'Keine Veredelung', 'media-lab-ml-decoration' ) . '</option></select> ';
		wp_dropdown_categories( [
			'taxonomy'        => 'product_cat',
			'name'            => 'cat',
			'selected'        => $f['cat'],
			'show_option_all' => __( 'Alle Kategorien', 'media-lab-ml-decoration' ),
			'hide_empty'      => true,
			'hierarchical'    => true,
		] );
		echo ' <input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="' . esc_attr__( 'Titel oder Artikelnummer', 'media-lab-ml-decoration' ) . '"> ';
		submit_button( __( 'Filtern', 'media-lab-ml-decoration' ), 'secondary', '', false );
		echo '</form>';

		// Liste
		$res = ML_Deco_Cotton::query( $f, $page, self::PER_PAGE );
		$ids = $res['ids'];
		if ( ! $ids ) {
			echo '<p><em>' . esc_html__( 'Keine Produkte für diesen Filter.', 'media-lab-ml-decoration' ) . '</em></p></div>';
			return;
		}
		update_meta_cache( 'post', $ids );
		update_object_term_cache( $ids, 'product' );

		$return = add_query_arg( array_filter( [ 'page' => self::SLUG, 'status' => $f['status'], 'tpl' => $f['tpl'], 'cat' => $f['cat'], 's' => $f['s'], 'paged' => $page ] ), admin_url( 'admin.php' ) );

		echo '<form method="post" action="' . esc_url( $admin ) . '">';
		wp_nonce_field( 'ml_deco_assign_save' );
		echo '<input type="hidden" name="action" value="ml_deco_assign_save"><input type="hidden" name="return" value="' . esc_attr( $return ) . '">';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Produkt', 'media-lab-ml-decoration' ) . '</th>'
			. '<th>' . esc_html__( 'Kategorien', 'media-lab-ml-decoration' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'media-lab-ml-decoration' ) . '</th>'
			. '<th>' . esc_html__( 'Vorlage', 'media-lab-ml-decoration' ) . '</th>'
			. '<th>' . esc_html__( 'Sublimation', 'media-lab-ml-decoration' ) . '</th></tr></thead><tbody>';

		foreach ( $ids as $id ) {
			$a     = ML_Deco_Cotton::assignment( $id );
			$sku   = (string) get_post_meta( $id, '_sku', true );
			$terms = get_the_terms( $id, 'product_cat' );
			$cats  = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : [];
			$value = $a['tpl'];
			$hint  = '';
			if ( $value === '' ) {
				$value = ML_Deco_Cotton::suggest( $id ); // nur Anzeige, gespeichert wird erst beim Speichern
				$hint  = $value !== '' ? __( '(Vorschlag, noch nicht gespeichert)', 'media-lab-ml-decoration' ) : '';
			}

			echo '<tr><td><input type="hidden" name="ml_ids[]" value="' . (int) $id . '">'
				. '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>'
				. ( $sku !== '' ? '<br><code>' . esc_html( $sku ) . '</code>' : '' ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $cats ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $a['state'], $a['tpl'] ) ) . ( $hint ? '<br><span class="description">' . esc_html( $hint ) . '</span>' : '' ) . '</td>';
			echo '<td><select name="ml_tpl[' . (int) $id . ']"><option value="">' . esc_html__( '– offen –', 'media-lab-ml-decoration' ) . '</option>';
			foreach ( $templates as $key => $t ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $t['name'] ) . '</option>';
			}
			echo '<option value="' . esc_attr( ML_Deco_Cotton::NONE ) . '"' . selected( $value, ML_Deco_Cotton::NONE, false ) . '>' . esc_html__( 'Keine Veredelung', 'media-lab-ml-decoration' ) . '</option></select></td>';
			echo '<td><label><input type="checkbox" name="ml_sub[' . (int) $id . ']" value="1"' . checked( $a['sub'], true, false ) . '> ' . esc_html__( 'möglich', 'media-lab-ml-decoration' ) . '</label></td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Mit „Seite speichern“ werden alle Zeilen dieser Seite mit gewählter Vorlage bestätigt. Zeilen auf „– offen –“ bleiben offen.', 'media-lab-ml-decoration' ) . '</p>';
		submit_button( __( 'Seite speichern', 'media-lab-ml-decoration' ), 'primary', 'submit', false );
		echo '</form>';

		$pages = (int) ceil( $res['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%', $return ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			] ) ) . '</div></div>';
		}
		echo '<p class="description">' . esc_html( sprintf( __( '%d Produkte in dieser Ansicht.', 'media-lab-ml-decoration' ), $res['total'] ) ) . '</p></div>';
	}
}
