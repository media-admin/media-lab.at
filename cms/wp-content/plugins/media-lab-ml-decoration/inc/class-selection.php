<?php
/**
 * Auswahl des Kunden (Position, Technik, Farben ...) pro Produkt in der WooCommerce-Session und
 * Übergabe an die Anfrage: Filter "mlw_before_save_inquiry" der Inquiry-Engine (media-lab-woocommerce)
 * hängt die Veredelung an die Produktzeile (config_display), damit sie in Anfrage-Mail und Anfrage-Eintrag steht.
 *
 * Vertrauen: Der Browser schickt nur die AUSWAHL (Position, Technik, Farben, Stufe, Fläche). Alle Werte werden hier
 * gegen die Produktdaten geprüft und die Preise serverseitig neu berechnet - Preise aus dem Browser werden nie genutzt.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Selection {

	const SESSION_KEY   = 'mld_selection';
	const USER_META_KEY = 'ml_deco_selection';
	const NONCE_ACTION  = 'mld_selection';
	const MAX_PRODUCTS  = 100;
	const TTL_DAYS      = 90;

	public static function init(): void {
		foreach ( [ 'mld_selection_save' => 'ajax_save', 'mld_selection_get' => 'ajax_get', 'mld_wishlist_state' => 'ajax_wishlist_state' ] as $action => $method ) {
			add_action( "wp_ajax_{$action}", [ __CLASS__, $method ] );
			add_action( "wp_ajax_nopriv_{$action}", [ __CLASS__, $method ] );
		}
		add_filter( 'mlw_before_save_inquiry', [ __CLASS__, 'inject' ], 20 );
		// Wunschliste: Die Veredelung wird beim Hinzufügen als Teil des Eintrags gespeichert (Filter in
		// MediaLab_Wishlist_Storage::add(), media-lab-woocommerce ab 2.32.0). Dasselbe Produkt mit anderer Veredelung
		// ist damit ein eigener Eintrag.
		add_filter( 'mlw_wishlist_item_extras', [ __CLASS__, 'item_extras' ], 20, 2 );
		// Anzeige auf der Wunschlisten-Seite (Filter in media-lab-woocommerce, MediaLab_Wishlist_Storage::get_items_for_display)
		add_filter( 'mlw_wishlist_display_items', [ __CLASS__, 'display_items' ], 20, 2 );
		// Wie die Wunschliste: Gast-Auswahl beim Login in die dauerhafte Auswahl des Nutzers übernehmen
		add_action( 'wp_login', [ __CLASS__, 'merge_session_on_login' ], 10, 2 );
	}

	// ── Ajax ──────────────────────────────────────────────────────────────────

	public static function ajax_save(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$product_id = (int) ( $_POST['product_id'] ?? 0 );
		$raw        = json_decode( wp_unslash( (string) ( $_POST['selection'] ?? '' ) ), true );
		$saved      = self::save( $product_id, $raw );
		wp_send_json_success( [ 'saved' => $saved !== null ] );
	}

	public static function ajax_get(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$product_id = (int) ( $_POST['product_id'] ?? 0 );
		$all        = self::all();
		$sel        = $all[ $product_id ] ?? null;
		if ( is_array( $sel ) ) {
			unset( $sel['ts'] );
		}
		wp_send_json_success( [ 'selection' => $sel ] );
	}

	/**
	 * Zustand des Wunschlisten-Buttons auf der Produktseite: Steht genau dieses Produkt (und diese Variante) mit genau
	 * dieser Veredelung auf der Wunschliste? Antwort: item_id und Menge des Eintrags, sonst null.
	 */
	public static function ajax_wishlist_state(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$product_id   = (int) ( $_POST['product_id'] ?? 0 );
		$variation_id = (int) ( $_POST['variation_id'] ?? 0 );
		$raw          = json_decode( wp_unslash( (string) ( $_POST['selection'] ?? '' ) ), true );
		wp_send_json_success( self::wishlist_state( $product_id, $variation_id, $raw ) );
	}

	/** @return array{item_id:?string, quantity:?int} */
	public static function wishlist_state( int $product_id, int $variation_id, $raw_selection ): array {
		$clean = $product_id ? self::sanitize( $product_id, $raw_selection ) : null;
		$item  = $product_id ? self::find_wishlist_item( $product_id, $variation_id, $clean ) : null;
		return [
			'item_id'  => $item ? (string) $item['item_id'] : null,
			'quantity' => $item ? max( 1, (int) ( $item['quantity'] ?? 1 ) ) : null,
		];
	}

	/**
	 * Sucht den Wunschlisten-Eintrag zu Produkt + Variante + Veredelung. Ohne Veredelung ($clean = null) ist es der Eintrag
	 * ohne Veredelung. Konfigurierte Einträge (Konfigurator) zählen nicht.
	 */
	public static function find_wishlist_item( int $product_id, int $variation_id, ?array $clean ): ?array {
		if ( ! class_exists( 'MediaLab_Wishlist_Storage' ) ) {
			return null;
		}
		$want = $clean ? self::decoration_key( $clean ) : '';
		foreach ( (array) MediaLab_Wishlist_Storage::get_items() as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['product_id'] ?? 0 ) !== $product_id || ! empty( $item['config'] ) || (int) ( $item['variation_id'] ?? 0 ) !== $variation_id ) {
				continue;
			}
			$dec  = $item['extras']['decoration'] ?? null;
			$have = ( is_array( $dec ) && ! empty( $dec['lines'] ) ) ? self::decoration_key( $dec ) : '';
			if ( $have === $want ) {
				return $item;
			}
		}
		return null;
	}

	private static function decoration_key( array $sel ): string {
		return (string) wp_json_encode( [ 'lines' => $sel['lines'] ?? [], 'repeat' => ! empty( $sel['repeat'] ) ] );
	}

	// ── Speichern ─────────────────────────────────────────────────────────────

	/** @return array|null die gespeicherte (bereinigte) Auswahl, null wenn leer/ungültig (Eintrag wird dann gelöscht) */
	public static function save( int $product_id, $raw ): ?array {
		$clean = $product_id ? self::sanitize( $product_id, $raw ) : null;
		$all   = self::all();

		if ( $clean === null ) {
			unset( $all[ $product_id ] );
		} else {
			$all[ $product_id ] = $clean + [ 'ts' => time() ];
		}
		self::store( $all );
		return $clean;
	}

	/**
	 * Prüft die Auswahl gegen die Produktdaten.
	 *
	 * @return array|null ['lines' => [['pos','tech','colours','range','area'], ...], 'repeat' => bool]
	 */
	public static function sanitize( int $product_id, $raw ): ?array {
		$ctx = self::context( $product_id );
		if ( ! $ctx || ! is_array( $raw ) ) {
			return null;
		}
		[ $data, $pricelist ] = $ctx;

		$byPos = [];
		foreach ( (array) $data['positions'] as $pos ) {
			$byPos[ (string) ( $pos['id'] ?? '' ) ] = $pos;
		}

		$lines = [];
		$used  = [];
		foreach ( (array) ( $raw['lines'] ?? [] ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$posId  = (string) ( $l['pos'] ?? '' );
			$techId = (string) ( $l['tech'] ?? '' );
			if ( ! isset( $byPos[ $posId ] ) || isset( $used[ $posId ] ) ) {
				continue;
			}
			$pos   = $byPos[ $posId ];
			$entry = null;
			foreach ( (array) $pos['techniques'] as $pt ) {
				if ( (string) ( $pt['id'] ?? '' ) === $techId ) {
					$entry = $pt;
				}
			}
			$tech = $pricelist['techniques'][ $techId ] ?? null;
			if ( ! $entry || ! is_array( $tech ) ) {
				continue;
			}

			$maxC    = (int) ( $entry['max_colours'] ?? 0 );
			$full    = ML_Deco_Render::is_full_colour( ML_Deco_Render::technique_name( $tech ) );
			$colours = ( $full || $maxC <= 1 ) ? 1 : max( 1, min( $maxC, (int) ( $l['colours'] ?? 1 ) ) );

			$maxArea = (float) ( $pos['max_area'] ?? 0 );
			$ranges  = ML_Deco_Calc::relevant_ranges( $tech, $maxArea > 0 ? $maxArea : null );
			$range   = max( 0, min( count( $ranges ) - 1, (int) ( $l['range'] ?? 0 ) ) );

			$area = (float) ( $l['area'] ?? $maxArea );
			if ( $maxArea > 0 ) {
				$area = $area > 0 ? min( $area, $maxArea ) : $maxArea;
			}

			$lines[]       = [ 'pos' => $posId, 'tech' => $techId, 'colours' => $colours, 'range' => $range, 'area' => round( $area, 1 ) ];
			$used[ $posId ] = true;
		}

		return $lines ? [ 'lines' => $lines, 'repeat' => ! empty( $raw['repeat'] ) ] : null;
	}

	// ── Übergabe an die Anfrage ───────────────────────────────────────────────

	/**
	 * Filter mlw_before_save_inquiry: hängt je Produktzeile die Veredelung an config_display.
	 *
	 * @param mixed $data Normalisierte Anfrage-Daten der Inquiry-Engine.
	 */
	public static function inject( $data ) {
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $data;
		}
		// Wunschlisten-Positionen tragen ihre Veredelung selbst (extras am Eintrag). Die zuletzt auf der Produktseite
		// gewählte Auswahl darf dort nicht dazukommen, sonst bekäme auch ein Eintrag ohne Veredelung eine.
		if ( ( $data['source'] ?? '' ) === 'wishlist' ) {
			$data['items'] = self::append_wishlist_rows( $data['items'] );
			return $data;
		}
		$data['items'] = self::append_rows( $data['items'] );
		return $data;
	}

	/**
	 * Anfrage aus der Wunschliste: Die Items der Anfrage stammen aus den gespeicherten Einträgen, tragen aber deren
	 * Zusatzdaten (extras) nicht mit. Deshalb werden sie hier den Einträgen der Wunschliste des Absenders zugeordnet
	 * (Produkt, Menge und Konfiguration müssen übereinstimmen, gleiche Einträge der Reihe nach) und bekommen die
	 * Veredelungszeilen ihres eigenen Eintrags. Die Wunschliste ist zu diesem Zeitpunkt noch nicht geleert.
	 */
	private static function append_wishlist_rows( array $items ): array {
		if ( ! class_exists( 'MediaLab_Wishlist_Storage' ) ) {
			return $items;
		}
		$queues = [];
		foreach ( (array) MediaLab_Wishlist_Storage::get_items() as $stored ) {
			if ( is_array( $stored ) ) {
				$queues[ self::match_key( $stored ) ][] = $stored;
			}
		}
		foreach ( $items as &$item ) {
			$key = self::match_key( $item );
			if ( empty( $queues[ $key ] ) ) {
				continue;
			}
			$stored = array_shift( $queues[ $key ] );
			$dec    = $stored['extras']['decoration'] ?? null;
			$pid    = (int) ( $item['product_id'] ?? 0 );
			if ( ! $pid || ! is_array( $dec ) || empty( $dec['lines'] ) ) {
				continue;
			}
			$clean = self::sanitize( $pid, $dec );
			if ( $clean === null ) {
				continue;
			}
			$rows = self::rows( $pid, $clean, max( 1, (int) ( $item['quantity'] ?? 1 ) ) );
			if ( ! $rows ) {
				continue;
			}
			$display = is_array( $item['config_display'] ?? null ) ? $item['config_display'] : [];
			foreach ( $rows as $label => $value ) {
				$display[ $label ] = $value;
			}
			$item['config_display'] = $display;
		}
		unset( $item );
		return $items;
	}

	private static function match_key( array $item ): string {
		return (int) ( $item['product_id'] ?? 0 ) . '|' . max( 1, (int) ( $item['quantity'] ?? 1 ) ) . '|' . md5( (string) wp_json_encode( $item['config'] ?? null ) );
	}

	/**
	 * Filter mlw_wishlist_item_extras: legt die aktuelle Auswahl des Besuchers beim Hinzufügen am Wunschlisten-Eintrag ab.
	 * Die Auswahl kommt aus dem Speicher des Plugins (serverseitig geprüft), nie aus dem Request.
	 *
	 * @param mixed $extras Bisherige Zusatzdaten.
	 * @param array $data   Daten des Hinzufügens (product_id, ...).
	 */
	public static function item_extras( $extras, $data = [] ) {
		$extras = is_array( $extras ) ? $extras : [];
		$pid    = (int) ( is_array( $data ) ? ( $data['product_id'] ?? 0 ) : 0 );
		if ( ! $pid ) {
			return $extras;
		}
		$all = self::all();
		if ( ! empty( $all[ $pid ]['lines'] ) ) {
			// Nur die Auswahl, ohne Zeitstempel: gleiche Auswahl ergibt gleiche Eintrags-ID.
			$extras['decoration'] = [ 'lines' => $all[ $pid ]['lines'], 'repeat' => ! empty( $all[ $pid ]['repeat'] ) ];
		}
		return $extras;
	}

	/**
	 * Filter mlw_wishlist_display_items: zeigt die Veredelung je Wunschlisten-Eintrag (Seite, Ajax-Nachrendern, Anfrage).
	 * Die Auswahl steht am Eintrag selbst (extras), daher gilt das auch in der geteilten Ansicht: Dort sieht der
	 * Empfänger die Auswahl des Listeninhabers. Einträge ohne Veredelung bleiben unverändert.
	 *
	 * @param mixed $items     Items wie von MediaLab_Wishlist_Storage::get_items_for_display().
	 * @param bool  $own_list  true = eigene Wunschliste, false = explizit übergebene Liste (geteilter Link).
	 */
	public static function display_items( $items, $own_list = true ) {
		if ( ! is_array( $items ) || ! $items ) {
			return $items;
		}
		foreach ( $items as &$item ) {
			$stored = $item['extras']['decoration'] ?? null;
			$pid    = (int) ( $item['product_id'] ?? 0 );
			if ( ! $pid || ! is_array( $stored ) || empty( $stored['lines'] ) ) {
				continue;
			}
			// Gegen die aktuellen Produktdaten prüfen (Position/Technik könnten sich seit dem Hinzufügen geändert haben).
			$clean = self::sanitize( $pid, $stored );
			if ( $clean === null ) {
				continue;
			}
			$rows = self::rows( $pid, $clean, max( 1, (int) ( $item['quantity'] ?? 1 ) ) );
			if ( ! $rows ) {
				continue;
			}
			$display = is_array( $item['config_display'] ?? null ) ? $item['config_display'] : [];
			foreach ( $rows as $label => $value ) {
				$display[ $label ] = $value;
			}
			$item['config_display'] = $display;
		}
		unset( $item );
		return $items;
	}

	/** Hängt je Produktzeile mit gemerkter Auswahl die Veredelungszeilen an config_display. */
	private static function append_rows( array $items ): array {
		$all = self::all();
		if ( ! $all ) {
			return $items;
		}
		foreach ( $items as &$item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( ! $pid || empty( $all[ $pid ] ) ) {
				continue;
			}
			$rows = self::rows( $pid, $all[ $pid ], max( 1, (int) ( $item['quantity'] ?? 1 ) ) );
			if ( ! $rows ) {
				continue;
			}
			$display = is_array( $item['config_display'] ?? null ) ? $item['config_display'] : [];
			foreach ( $rows as $label => $value ) {
				$display[ $label ] = $value;
			}
			$item['config_display'] = $display;
		}
		unset( $item );
		return $items;
	}

	/**
	 * Zeilen "Label => Text" für die Anfrage. Beträge nur, wenn "Preise anzeigen" für den Lieferanten aktiv ist
	 * (die Kundenmail enthält dieselbe Produktliste).
	 *
	 * @return array<string,string>
	 */
	public static function rows( int $product_id, array $selection, int $qty ): array {
		$ctx = self::context( $product_id );
		if ( ! $ctx ) {
			return [];
		}
		[ $data, $pricelist, $key ] = $ctx;

		$show     = ML_Deco_Settings::show_prices( $key );
		$factor   = ML_Deco_Settings::factor( $key );
		$handling = $show ? ML_Deco_Calc::handling( $pricelist, $data['manipulation'] ?? null ) : 0.0;
		// Für die Berechnung immer mit Preisen aufbereiten; ausgegeben werden sie nur bei $show.
		static $memo = [];
		$memoKey = $product_id . '|' . $factor . '|' . (int) $show;
		if ( ! isset( $memo[ $memoKey ] ) ) {
			$memo[ $memoKey ] = ML_Deco_Calculator::payload( $data, $pricelist, true, $factor, $handling );
		}
		$payload = $memo[ $memoKey ];

		$posByid = [];
		foreach ( $payload['positions'] as $pos ) {
			$posByid[ $pos['id'] ] = $pos;
		}
		$t = ML_Deco_Calculator::payload_strings();

		$rows  = [];
		$sum   = 0.0;
		$setup = 0.0;
		$any   = false;

		foreach ( (array) ( $selection['lines'] ?? [] ) as $l ) {
			$pos  = $posByid[ $l['pos'] ?? '' ] ?? null;
			$tech = $payload['techniques']->{ $l['tech'] ?? '' } ?? null;
			if ( ! $pos || ! $tech ) {
				continue;
			}
			$tech    = (array) $tech;
			$colours = (int) $l['colours'];
			$range   = ML_Deco_Calc::relevant_ranges( $tech, $pos['maxArea'] > 0 ? (float) $pos['maxArea'] : null );
			$range   = $range[ min( (int) $l['range'], count( $range ) - 1 ) ] ?? null;
			if ( ! $range ) {
				continue;
			}

			$label = sprintf( /* translators: %s: Positionsname */ __( 'Veredelung – %s', 'media-lab-ml-decoration' ), $pos['label'] );
			if ( isset( $rows[ $label ] ) ) {
				$label .= ' (' . $pos['id'] . ')';
			}

			$text = (string) $tech['name'];
			if ( ! $tech['fullColour'] && $colours > 1 ) {
				$text .= ', ' . sprintf( __( '%d Farben', 'media-lab-ml-decoration' ), $colours );
			} elseif ( ! $tech['fullColour'] ) {
				$text .= ', ' . __( '1 Farbe', 'media-lab-ml-decoration' );
			}
			$multi = count( ML_Deco_Calc::relevant_ranges( $tech, $pos['maxArea'] > 0 ? (float) $pos['maxArea'] : null ) ) > 1;
			if ( $multi ) {
				$text .= ', ' . ML_Deco_Calc::range_label( $range );
			}

			if ( $show ) {
				$calc = ML_Deco_Calculator::compute_line( $tech, $range, $qty, $colours, (float) $l['area'], ! empty( $selection['repeat'] ), (float) $payload['handling'] );
				if ( $calc['onRequest'] ) {
					$text .= ' – ' . __( 'Preis auf Anfrage', 'media-lab-ml-decoration' );
				} else {
					$any    = true;
					$sum   += $calc['total'];
					$setup += round( $calc['setup'], 2 );
					$text  .= sprintf(
						/* translators: 1: Stückpreis, 2: Einrichtung, 3: Summe */
						' – ' . __( '%1$s je Stück + %2$s Einrichtung = %3$s', 'media-lab-ml-decoration' ),
						self::plain( $calc['unit'] ),
						self::plain( $calc['setup'] ),
						self::plain( $calc['total'] )
					);
				}
			}
			$rows[ $label ] = $text;
		}

		if ( $rows && $show && $any ) {
			$rows[ __( 'Veredelung gesamt (ca., netto, zusätzlich zum Produktpreis)', 'media-lab-ml-decoration' ) ] = sprintf(
				__( '%1$s bei %2$d Stück (davon Einrichtung %3$s) – unverbindliche Schätzung', 'media-lab-ml-decoration' ),
				self::plain( $sum ),
				$qty,
				self::plain( $setup )
			);
		}
		if ( $rows && ! empty( $selection['repeat'] ) ) {
			$rows[ __( 'Veredelung – Wiederholungsauftrag', 'media-lab-ml-decoration' ) ] = __( 'Ja', 'media-lab-ml-decoration' );
		}
		return $rows;
	}

	/** Betrag als reiner Text (config_display wird in der Mail escaped, kein HTML erlaubt). */
	private static function plain( float $value ): string {
		return html_entity_decode( wp_strip_all_tags( ML_Deco_Render::money( $value ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** @return array{0:array,1:array,2:string}|null [Produktdaten, Preisliste, Lieferantenschlüssel] */
	private static function context( int $product_id ): ?array {
		$key  = ML_Deco_Store::supplier_key_for_product( $product_id );
		$data = $key ? ML_Deco_Store::product_data( $product_id ) : null;
		$pl   = $key ? ML_Deco_Store::pricelist( $key ) : null;
		return ( $key && $data && $pl ) ? [ $data, $pl, $key ] : null;
	}

	// ── Speicher: gleicher Ort wie die Wunschliste ────────────────────────────

	/**
	 * Alle gemerkten Auswahlen des aktuellen Besuchers. Gast = WooCommerce-Session (lebt genau so lange wie seine
	 * Wunschliste), eingeloggt = User-Meta (dauerhaft, wie die Wunschliste). Einträge älter als TTL_DAYS entfallen.
	 *
	 * @return array<int, array>
	 */
	public static function all(): array {
		$all = is_user_logged_in() ? self::user_get() : self::session_get();
		$ttl = (int) apply_filters( 'ml_deco_selection_ttl_days', self::TTL_DAYS ) * DAY_IN_SECONDS;
		$min = time() - $ttl;
		return array_filter( $all, static fn( $e ) => is_array( $e ) && (int) ( $e['ts'] ?? time() ) >= $min );
	}

	private static function store( array $all ): void {
		// älteste zuerst entfernen, wenn zu viele Produkte
		if ( count( $all ) > self::MAX_PRODUCTS ) {
			uasort( $all, static fn( $a, $b ) => (int) ( $a['ts'] ?? 0 ) <=> (int) ( $b['ts'] ?? 0 ) );
			$all = array_slice( $all, -self::MAX_PRODUCTS, null, true );
		}
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_META_KEY, $all );
		} else {
			self::session_set( $all );
		}
	}

	/** Beim Login: Gast-Auswahl (Session) in die Auswahl des Nutzers übernehmen, die frischere gewinnt. */
	public static function merge_session_on_login( $user_login, $user ): void {
		$session = self::session_get();
		if ( ! $session || ! ( $user instanceof WP_User ) ) {
			return;
		}
		$existing = get_user_meta( $user->ID, self::USER_META_KEY, true );
		$existing = is_array( $existing ) ? $existing : [];
		foreach ( $session as $pid => $entry ) {
			if ( ! isset( $existing[ $pid ] ) || (int) ( $entry['ts'] ?? 0 ) >= (int) ( $existing[ $pid ]['ts'] ?? 0 ) ) {
				$existing[ $pid ] = $entry;
			}
		}
		update_user_meta( $user->ID, self::USER_META_KEY, $existing );
		self::session_set( [] );
	}

	/** @return array<int, array> */
	private static function user_get(): array {
		$v = get_user_meta( get_current_user_id(), self::USER_META_KEY, true );
		return is_array( $v ) ? $v : [];
	}

	/** @return array<int, array> */
	public static function session_get(): array {
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
