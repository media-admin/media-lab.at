<?php
/**
 * Cotton Classics: Positionsvorlagen je Warenart und Zuordnung je Produkt.
 *
 * Cotton hat keinen Feed mit Positionen. Statt des Custom Fields _ml_decoration gilt:
 *   - Die Vorlagen (Warenarten mit Druckpositionen) stehen in der Excel-Preisliste (Blatt "Positionen") und
 *     liegen mit der Preisliste im Schlüssel "templates".
 *   - Jedes Produkt hat eine Zuordnung (Post-Meta):
 *       _ml_deco_template     Schlüssel der Vorlage, "none" = keine Veredelung anzeigen, leer = offen
 *       _ml_deco_tpl_state    "confirmed" = geprüft und freigegeben, sonst Vorschlag
 *       _ml_deco_sublimation  "yes" = Sublimationsdruck für dieses Produkt möglich (Standard: nein)
 *   - Nur bestätigte Zuordnungen zeigen den Veredelungs-Tab. Neue Produkte sind "offen" und zeigen keinen Tab.
 *
 * ML_Deco_Store::product_data() liefert für Cotton-Produkte das Ergebnis von product_data() dieser Klasse.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Cotton {

	const META_TPL   = '_ml_deco_template';
	const META_STATE = '_ml_deco_tpl_state';
	const META_SUB   = '_ml_deco_sublimation';
	const NONE       = 'none';

	/** @return array<string, array{name:string, positions:array}> */
	public static function templates(): array {
		$pl = ML_Deco_Store::pricelist( ML_Deco_Excel::SUPPLIER_KEY );
		return is_array( $pl['templates'] ?? null ) ? $pl['templates'] : [];
	}

	/** Produktdaten im selben Format wie das Custom Field _ml_decoration oder null (kein Tab). */
	public static function product_data( int $product_id ): ?array {
		if ( get_post_meta( $product_id, self::META_STATE, true ) !== 'confirmed' ) {
			return null;
		}
		$tpl = (string) get_post_meta( $product_id, self::META_TPL, true );
		if ( $tpl === '' || $tpl === self::NONE ) {
			return null;
		}
		$template = self::templates()[ $tpl ] ?? null;
		if ( ! $template ) {
			return null;
		}

		$sublimation = get_post_meta( $product_id, self::META_SUB, true ) === 'yes';
		$positions   = [];
		foreach ( (array) $template['positions'] as $position ) {
			$techs = [];
			foreach ( (array) ( $position['techniques'] ?? [] ) as $t ) {
				if ( ! empty( $t['sublimation'] ) && ! $sublimation ) {
					continue;
				}
				$techs[] = [ 'id' => $t['id'], 'max_colours' => (int) ( $t['max_colours'] ?? 1 ) ];
			}
			if ( $techs ) {
				$position['techniques'] = $techs;
				$positions[]            = $position;
			}
		}
		return $positions ? [ 'manipulation' => null, 'template' => $tpl, 'positions' => $positions ] : null;
	}

	// ── Vorschlag nach Regeln ─────────────────────────────────────────────────

	/**
	 * Reihenfolge = Priorität. Geprüft werden die Namen der Produktkategorien (bei Cotton oft mehrere je Produkt),
	 * ersatzweise der Titel. Per Filter anpassbar.
	 *
	 * @return array<string, string> Vorlagen-Schlüssel => Regex
	 */
	public static function rules(): array {
		return apply_filters( 'ml_deco_cotton_rules', [
			'baby'     => '/baby/iu',
			'haube'    => '/beanie|headwear|haube|m[üu]tze|stirnband/iu',
			'cap'      => '/\bcaps?\b|kappe|k[äa]ppi/iu',
			'polo'     => '/polo/iu',
			'tshirt'   => '/t-?\s?shirt/iu',
			'hemd'     => '/\bshirts?\b|hemd|bluse|blouse/iu',
			'jacke'    => '/jacket|jacke|weste|\bvest\b|parka|mantel|\bcoat|softshell/iu',
			'sweat'    => '/sweat|pullover|fleece|hoodie|kapuz|strick/iu',
			'tasche'   => '/\bbags?\b|tasche|rucksack|rucks[äa]ck|backpack|shopper|beutel/iu',
			'hose'     => '/trousers|skirts?|dresses|hose|\brock\b|\bkleid|shorts/iu',
			'frottier' => '/frottier|towel|handtuch|bademantel|bathrobe/iu',
			// zuletzt: Produkte ohne Veredelung (nur, wenn keine andere Regel passt)
			self::NONE => '/sales\s*support|packing|verpackung|\bshoes?\b|schuh|underwear|unterw|umbrella|schirm/iu',
		] );
	}

	/** Vorschlag (Vorlagen-Schlüssel, "none" oder "") für ein Produkt nach Kategorien, ersatzweise Titel. */
	public static function suggest( int $product_id ): string {
		$terms = get_the_terms( $product_id, 'product_cat' );
		$cats  = [];
		if ( is_array( $terms ) ) {
			// Nur die untersten Kategorien des Produkts: Die Hauptkategorie (z. B. "Taschen & Reisen") hängt
			// an allen Unterkategorien und würde sonst auch Regenschirme als Tasche vorschlagen.
			$parents = [];
			foreach ( $terms as $term ) {
				if ( ! empty( $term->parent ) ) {
					$parents[ (int) $term->parent ] = true;
				}
			}
			foreach ( $terms as $term ) {
				if ( ! isset( $parents[ (int) $term->term_id ] ) ) {
					$cats[] = $term->name . ' ' . $term->slug;
				}
			}
		}
		$known = self::templates();
		foreach ( [ implode( ' | ', $cats ), (string) get_the_title( $product_id ) ] as $haystack ) {
			if ( trim( $haystack ) === '' ) {
				continue;
			}
			foreach ( self::rules() as $key => $regex ) {
				if ( ( $key === self::NONE || isset( $known[ $key ] ) ) && preg_match( $regex, $haystack ) ) {
					return $key;
				}
			}
		}
		return '';
	}

	// ── Zuordnung lesen/schreiben ─────────────────────────────────────────────

	/** Zuordnung eines Produkts: Vorlage, Status (open|suggested|confirmed), Sublimation. */
	public static function assignment( int $product_id ): array {
		$tpl = (string) get_post_meta( $product_id, self::META_TPL, true );
		if ( $tpl === '' ) {
			return [ 'tpl' => '', 'state' => 'open', 'sub' => get_post_meta( $product_id, self::META_SUB, true ) === 'yes' ];
		}
		return [
			'tpl'   => $tpl,
			'state' => get_post_meta( $product_id, self::META_STATE, true ) === 'confirmed' ? 'confirmed' : 'suggested',
			'sub'   => get_post_meta( $product_id, self::META_SUB, true ) === 'yes',
		];
	}

	public static function save_assignment( int $product_id, string $tpl, string $state, ?bool $sublimation = null ): void {
		if ( $tpl === '' ) {
			delete_post_meta( $product_id, self::META_TPL );
			delete_post_meta( $product_id, self::META_STATE );
		} else {
			update_post_meta( $product_id, self::META_TPL, $tpl );
			update_post_meta( $product_id, self::META_STATE, $state === 'confirmed' ? 'confirmed' : 'suggested' );
		}
		if ( $sublimation !== null ) {
			if ( $sublimation ) {
				update_post_meta( $product_id, self::META_SUB, 'yes' );
			} else {
				delete_post_meta( $product_id, self::META_SUB );
			}
		}
	}

	// ── Abfragen für die Admin-Tabelle ────────────────────────────────────────

	/** Meta-Werte von _ml_supplier_code, die zu Cotton gehören. */
	private static function supplier_codes(): array {
		$s = ML_Deco_Store::suppliers()[ ML_Deco_Excel::SUPPLIER_KEY ] ?? null;
		return $s ? array_values( array_unique( [ $s['code'], ML_Deco_Excel::SUPPLIER_KEY ] ) ) : [];
	}

	public static function is_cotton_product( int $product_id ): bool {
		return in_array( (string) get_post_meta( $product_id, '_ml_supplier_code', true ), self::supplier_codes(), true );
	}

	/**
	 * @param array{status?:string, tpl?:string, cat?:int, s?:string} $f
	 * @return array{0:string, 1:array}  FROM/WHERE-Teil und Parameter
	 */
	private static function sql_base( array $f ): array {
		global $wpdb;
		$codes = self::supplier_codes();
		$ph    = implode( ',', array_fill( 0, max( 1, count( $codes ) ), '%s' ) );
		$args  = $codes ?: [ '' ];

		$sql = "FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} sc ON sc.post_id = p.ID AND sc.meta_key = '_ml_supplier_code' AND sc.meta_value IN ({$ph})
			LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '" . self::META_TPL . "'
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '" . self::META_STATE . "'
			LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = '_sku'
			LEFT JOIN {$wpdb->postmeta} ss ON ss.post_id = p.ID AND ss.meta_key = '_ml_supplier_sku'
			WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')";

		switch ( $f['status'] ?? '' ) {
			case 'open':
				$sql .= " AND (t.meta_value IS NULL OR t.meta_value = '')";
				break;
			case 'suggested':
				$sql .= " AND t.meta_value <> '' AND (st.meta_value IS NULL OR st.meta_value <> 'confirmed')";
				break;
			case 'confirmed':
				$sql .= " AND st.meta_value = 'confirmed' AND t.meta_value <> '" . self::NONE . "'";
				break;
			case 'none':
				$sql .= " AND st.meta_value = 'confirmed' AND t.meta_value = '" . self::NONE . "'";
				break;
		}
		if ( ! empty( $f['tpl'] ) ) {
			$sql   .= ' AND t.meta_value = %s';
			$args[] = $f['tpl'];
		}
		if ( ! empty( $f['cat'] ) ) {
			$ids = array_map( 'intval', array_merge( [ (int) $f['cat'] ], (array) get_term_children( (int) $f['cat'], 'product_cat' ) ) );
			$sql .= " AND p.ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . implode( ',', $ids ) . '))';
		}
		if ( ! empty( $f['s'] ) ) {
			$like   = '%' . $wpdb->esc_like( $f['s'] ) . '%';
			$sql   .= ' AND (p.post_title LIKE %s OR sk.meta_value LIKE %s OR ss.meta_value LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}
		return [ $sql, $args ];
	}

	/** @return array{ids:int[], total:int} */
	public static function query( array $f, int $page, int $per_page ): array {
		global $wpdb;
		[ $base, $args ] = self::sql_base( $f );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) {$base}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$args2 = array_merge( $args, [ $per_page, max( 0, ( $page - 1 ) * $per_page ) ] );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID {$base} ORDER BY p.post_title ASC, p.ID ASC LIMIT %d OFFSET %d", $args2 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'ids' => array_map( 'intval', $ids ), 'total' => $total ];
	}

	/** Alle Produkt-IDs eines Status (für Sammelaktionen). @return int[] */
	public static function ids_by_status( string $status ): array {
		global $wpdb;
		[ $base, $args ] = self::sql_base( [ 'status' => $status ] );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID {$base}", $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array{open:int, suggested:int, confirmed:int, none:int, total:int} */
	public static function counts(): array {
		global $wpdb;
		$out = [ 'open' => 0, 'suggested' => 0, 'confirmed' => 0, 'none' => 0, 'total' => 0 ];
		foreach ( [ 'open', 'suggested', 'confirmed', 'none' ] as $status ) {
			[ $base, $args ] = self::sql_base( [ 'status' => $status ] );
			$out[ $status ]  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) {$base}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out['total']   += $out[ $status ];
		}
		return $out;
	}

	/**
	 * Produkte, die in einer Kollektion/Kategorie/einem Schlagwort mit diesem Namen oder Slug stehen (beliebige Produkt-Taxonomie).
	 *
	 * @return int[] Nur Cotton-Produkte
	 */
	public static function ids_in_collection( string $name ): array {
		global $wpdb;
		$name = trim( $name );
		if ( $name === '' ) {
			return [];
		}
		$tt = [];
		foreach ( get_object_taxonomies( 'product' ) as $tax ) {
			$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'all' ] );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( strcasecmp( $term->name, $name ) === 0 || strcasecmp( $term->slug, sanitize_title( $name ) ) === 0 ) {
					$tt[] = (int) $term->term_taxonomy_id;
				}
			}
		}
		if ( ! $tt ) {
			return [];
		}
		[ $base, $args ] = self::sql_base( [] );
		$base .= ' AND p.ID IN (SELECT object_id FROM ' . $wpdb->term_relationships . ' WHERE term_taxonomy_id IN (' . implode( ',', $tt ) . '))';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID {$base}", $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
