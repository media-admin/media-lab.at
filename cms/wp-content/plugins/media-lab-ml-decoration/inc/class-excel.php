<?php
/**
 * Excel-Preisliste (.xlsx) -> Preisliste im Plugin-Format (wie MidOcean/Makito).
 *
 * Liest die Vorlage "Cotton-Veredelung-Preise-Vorlage.xlsx" (Blätter Anleitung, Transferdruck, Sublimationsdruck, Stick).
 * Ohne Composer-Bibliothek: eine .xlsx ist ein ZIP mit XML, das hier direkt gelesen wird (benötigt die PHP-Erweiterung zip).
 * Es werden nur die Eingabezellen gelesen. Bei Formelzellen zählt der zuletzt in Excel berechnete Wert.
 *
 * Dieselbe Logik steckt im CLI-Werkzeug supplier-sync/tools/cotton_decoration_from_xlsx.php. Bei Änderungen am Aufbau beide anpassen.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ML_Deco_Excel {

	/** Lieferanten-Schlüssel (siehe ML_Deco_Store::suppliers()), für den die Excel-Pflege gilt. */
	const SUPPLIER_KEY = 'cotton';
	const OPT_FILE     = 'ml_deco_excel_';
	const MAX_UPLOAD   = 1048576; // 1 MB reicht für die Vorlage (ca. 15 KB) bei weitem

	const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/** Blattname => Preisart des Plugins */
	const SHEETS = [
		'Transferdruck'     => 'area_range',
		'Sublimationsdruck' => 'area_range',
		'Stick'             => 'colour_area_range',
	];

	public static function template_path(): string {
		return ML_DECO_PATH . 'data/Cotton-Veredelung-Preise-Vorlage.xlsx';
	}

	// ── xlsx lesen ────────────────────────────────────────────────────────────

	/**
	 * @return array<string, array<int, array<int, mixed>>>|string  Blatt => Zeile (1-basiert) => Spalte (0-basiert) => Wert, oder Fehlertext
	 */
	public static function read_book( string $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return __( 'Auf dem Server fehlt die PHP-Erweiterung "zip". Bitte beim Hoster aktivieren lassen.', 'media-lab-ml-decoration' );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return __( 'Die Datei ist keine gültige Excel-Datei (.xlsx).', 'media-lab-ml-decoration' );
		}

		try {
			$workbook = self::zip_xml( $zip, 'xl/workbook.xml' );
			$rels     = self::zip_xml( $zip, 'xl/_rels/workbook.xml.rels' );
			if ( ! $workbook || ! $rels ) {
				return __( 'Die Datei ist keine gültige Excel-Datei (.xlsx).', 'media-lab-ml-decoration' );
			}

			// Beziehungen: rId => Pfad im ZIP
			$targets = [];
			foreach ( $rels->Relationship ?? [] as $rel ) {
				$target = (string) $rel['Target'];
				$target = ( $target !== '' && $target[0] === '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
				$targets[ (string) $rel['Id'] ] = $target;
			}

			// Gemeinsame Texte (fehlen bei manchen Programmen komplett)
			$shared = [];
			$sst    = self::zip_xml( $zip, 'xl/sharedStrings.xml' );
			if ( $sst ) {
				foreach ( $sst->children( self::NS_MAIN )->si as $si ) {
					$shared[] = self::rich_text( $si );
				}
			}

			$book = [];
			foreach ( $workbook->children( self::NS_MAIN )->sheets->sheet ?? [] as $sheet ) {
				$name = trim( (string) $sheet->attributes()['name'] );
				$rid  = (string) $sheet->attributes( self::NS_REL )['id'];
				if ( $name === '' || ! isset( $targets[ $rid ] ) ) {
					continue;
				}
				$xml = self::zip_xml( $zip, $targets[ $rid ] );
				if ( $xml ) {
					$book[ $name ] = self::read_sheet( $xml, $shared );
				}
			}
		} finally {
			$zip->close();
		}

		return $book ?: __( 'In der Datei wurden keine Tabellenblätter gefunden.', 'media-lab-ml-decoration' );
	}

	private static function zip_xml( ZipArchive $zip, string $name ): ?SimpleXMLElement {
		$stat = $zip->statName( $name );
		if ( ! $stat || $stat['size'] > 8 * MB_IN_BYTES ) {
			return null;
		}
		$xml = $zip->getFromName( $name );
		if ( ! is_string( $xml ) || $xml === '' ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$obj  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $obj ?: null;
	}

	/** Text eines <si> oder <is>: entweder <t> oder mehrere <r><t> (formatierter Text). Phonetik wird ignoriert. */
	private static function rich_text( SimpleXMLElement $node ): string {
		$c = $node->children( self::NS_MAIN );
		if ( isset( $c->t ) ) {
			return (string) $c->t;
		}
		$out = '';
		foreach ( $c->r ?? [] as $run ) {
			$out .= (string) $run->children( self::NS_MAIN )->t;
		}
		return $out;
	}

	/** "AB12" -> 27 (0-basierter Spaltenindex) */
	private static function col_index( string $ref ): int {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) );
		$n       = 0;
		for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $n - 1 );
	}

	private static function read_sheet( SimpleXMLElement $xml, array $shared ): array {
		$rows  = [];
		$auto  = 0;
		$count = 0;
		foreach ( $xml->children( self::NS_MAIN )->sheetData->row ?? [] as $row ) {
			$auto++;
			$rn = (int) $row->attributes()['r'] ?: $auto;
			if ( $rn > 1000 || ++$count > 1000 ) {
				break;
			}
			foreach ( $row->children( self::NS_MAIN )->c as $c ) {
				$attr = $c->attributes();
				$ref  = (string) $attr['r'];
				$type = (string) $attr['t'];
				$cc   = $c->children( self::NS_MAIN );
				$val  = null;

				if ( $type === 'inlineStr' ) {
					$val = isset( $cc->is ) ? self::rich_text( $cc->is ) : null;
				} elseif ( isset( $cc->v ) && (string) $cc->v !== '' ) {
					$raw = (string) $cc->v;
					if ( $type === 's' ) {
						$val = $shared[ (int) $raw ] ?? null;
					} elseif ( $type === 'str' ) {
						$val = $raw;
					} elseif ( $type === 'b' ) {
						$val = (int) $raw;
					} elseif ( $type === 'e' ) {
						$val = null;
					} else {
						$val = is_numeric( $raw ) ? (float) $raw : $raw;
					}
				}
				if ( $val !== null ) {
					$rows[ $rn ][ self::col_index( $ref ) ] = $val;
				}
			}
		}
		return $rows;
	}

	// ── Umrechnung ────────────────────────────────────────────────────────────

	/** Zahl aus einer Zelle (auch "1,5" als Text), sonst null. */
	private static function num( $v ): ?float {
		if ( $v === null || $v === '' ) {
			return null;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return round( (float) $v, 6 );
		}
		$s = str_replace( [ ' ', "\u{00a0}", '€' ], '', (string) $v );
		$s = str_replace( ',', '.', $s );
		return is_numeric( $s ) ? round( (float) $s, 6 ) : null;
	}

	/**
	 * @param array      $book     Ergebnis von read_book()
	 * @param array|null $fallback Vorlagen, die gelten, wenn die Datei kein Blatt "Positionen" hat (ältere Datei)
	 * @return array{pricelist:?array, errors:string[], warnings:string[], summary:string[]}
	 */
	public static function convert( array $book, ?array $fallback = null ): array {
		$errors   = [];
		$warnings = [];
		$summary  = [];

		// ── Allgemein ──
		$gen       = $book['Anleitung'] ?? [];
		$supplier  = strtoupper( trim( (string) ( $gen[5][1] ?? '' ) ) );
		$currency  = strtoupper( trim( (string) ( $gen[6][1] ?? '' ) ) ) ?: 'EUR';
		$validRaw  = $gen[7][1] ?? '';
		$validFrom = is_float( $validRaw ) && $validRaw > 20000 && $validRaw < 80000
			? gmdate( 'Y-m-d', (int) round( ( $validRaw - 25569 ) * 86400 ) ) // echtes Excel-Datum
			: trim( (string) $validRaw );
		if ( ! isset( $book['Anleitung'] ) ) {
			$errors[] = __( 'Blatt "Anleitung" fehlt.', 'media-lab-ml-decoration' );
		} elseif ( $supplier === '' ) {
			$errors[] = __( 'Anleitung: Lieferantencode (B5) fehlt.', 'media-lab-ml-decoration' );
		}

		$techniques  = [];
		$techBySheet = [];
		foreach ( self::SHEETS as $sheetName => $pricing ) {
			$rows = $book[ $sheetName ] ?? null;
			if ( $rows === null ) {
				$errors[] = sprintf( __( 'Blatt "%s" fehlt.', 'media-lab-ml-decoration' ), $sheetName );
				continue;
			}
			$E = static fn( string $m ): string => $sheetName . ': ' . $m;

			$id   = trim( (string) ( $rows[4][1] ?? '' ) );
			$name = trim( (string) ( $rows[5][1] ?? '' ) );
			if ( $id === '' ) {
				$errors[] = $E( __( 'Technik-ID (B4) fehlt.', 'media-lab-ml-decoration' ) );
				continue;
			}
			if ( $name === '' ) {
				$errors[] = $E( __( 'Name im Shop (B5) fehlt.', 'media-lab-ml-decoration' ) );
			}
			$setup    = self::num( $rows[6][1] ?? null );
			$setupRep = self::num( $rows[7][1] ?? null );
			$minQty   = self::num( $rows[8][1] ?? null );
			foreach ( [ 6 => $setup, 7 => $setupRep ] as $r => $v ) {
				if ( $v === null || $v < 0 ) {
					$errors[] = $E( sprintf( __( 'Vorkosten in B%d fehlen oder sind negativ (0 eintragen, wenn es keine gibt).', 'media-lab-ml-decoration' ), $r ) );
				}
			}
			$extra = null;
			if ( $pricing === 'colour_area_range' ) {
				$extra = self::num( $rows[9][1] ?? null );
				if ( $extra === null || $extra < 0 ) {
					$errors[] = $E( __( 'Aufpreis je weitere Farbe (B9) fehlt oder ist negativ (0 eintragen, wenn es keinen gibt).', 'media-lab-ml-decoration' ) );
					$extra = 0.0;
				}
			}

			// Mengenstaffeln: Zeile 11, ab Spalte C
			$qtys = [];
			for ( $c = 2; $c < 40; $c++ ) {
				$q = self::num( $rows[11][ $c ] ?? null );
				if ( $q === null ) {
					break;
				}
				$qtys[ $c ] = (int) $q;
			}
			if ( ! $qtys ) {
				$errors[] = $E( __( 'Keine Mengenstaffeln in Zeile 11 (ab Spalte C).', 'media-lab-ml-decoration' ) );
				continue;
			}
			$prev = 0;
			foreach ( $qtys as $q ) {
				if ( $q <= $prev ) {
					$errors[] = $E( sprintf( __( 'Mengenstaffeln (Zeile 11) müssen aufsteigend sein und bei 1 oder mehr beginnen (Wert %d).', 'media-lab-ml-decoration' ), $q ) );
					break;
				}
				$prev = $q;
			}
			if ( reset( $qtys ) !== 1 ) {
				$warnings[] = $E( __( 'Die erste Mengenstaffel beginnt nicht bei 1 – kleinere Mengen werden wie die erste Staffel gerechnet.', 'media-lab-ml-decoration' ) );
			}

			// Flächenstufen: ab Zeile 14, Spalte B = "bis cm²"
			$ranges  = [];
			$from    = 0.0;
			$prevRow = null;
			for ( $r = 14; $r < 200; $r++ ) {
				$to = self::num( $rows[ $r ][1] ?? null );
				if ( $to === null ) {
					break;
				}
				if ( $to <= $from ) {
					$errors[] = $E( sprintf( __( 'Zeile %1$d: "Fläche bis" (%2$s) muss größer sein als die Stufe davor (%3$s).', 'media-lab-ml-decoration' ), $r, self::fmt( $to ), self::fmt( $from ) ) );
					break;
				}
				$scales = [];
				$prices = [];
				foreach ( $qtys as $c => $q ) {
					$p = self::num( $rows[ $r ][ $c ] ?? null );
					if ( $p === null || $p < 0 ) {
						$errors[] = $E( sprintf( __( 'Zeile %1$d, Spalte %2$s: Preis fehlt oder ist ungültig.', 'media-lab-ml-decoration' ), $r, chr( 65 + $c ) ) );
						$p = 0.0;
					}
					$prices[] = $p;
					$scale    = [ 'qty' => $q, 'price' => $p ];
					if ( $extra !== null ) {
						$scale['next'] = $extra;
					}
					$scales[] = $scale;
				}
				for ( $i = 1; $i < count( $prices ); $i++ ) {
					if ( $prices[ $i ] > $prices[ $i - 1 ] + 1e-9 ) {
						$warnings[] = $E( sprintf( __( 'Zeile %d: Preis steigt bei größerer Menge.', 'media-lab-ml-decoration' ), $r ) );
						break;
					}
				}
				if ( $prevRow !== null ) {
					foreach ( $prices as $i => $p ) {
						if ( $p < $prevRow[ $i ] - 1e-9 ) {
							$warnings[] = $E( sprintf( __( 'Zeile %d: Preis sinkt bei größerer Fläche.', 'media-lab-ml-decoration' ), $r ) );
							break;
						}
					}
				}
				$ranges[] = [ 'id' => 'S' . ( count( $ranges ) + 1 ), 'from' => $from, 'to' => $to, 'scales' => $scales ];
				$from     = $to;
				$prevRow  = $prices;
			}
			if ( ! $ranges ) {
				$errors[] = $E( __( 'Keine Flächenstufen ab Zeile 14 (Spalte B).', 'media-lab-ml-decoration' ) );
				continue;
			}

			// Produktionszeit (optional, B10): Text, den der Kunde sieht
			$lead = trim( preg_replace( '/\s+/u', ' ', (string) ( $rows[10][1] ?? '' ) ) );
			if ( mb_strlen( $lead ) > 80 ) {
				$errors[] = $E( __( 'Produktionszeit (B10) ist zu lang (max. 80 Zeichen).', 'media-lab-ml-decoration' ) );
			}

			$techniques[ $id ] = [
				'name'         => [ 'de' => $name ],
				'pricing'      => $pricing,
				'next_colour'  => $pricing === 'colour_area_range' && ( $extra ?? 0 ) > 0,
				'setup'        => $setup ?? 0.0,
				'setup_repeat' => $setupRep ?? 0.0,
				'min_qty'      => ( $minQty !== null && $minQty > 0 ) ? (int) $minQty : null,
				'setup_estimate' => true, // Vorkosten sind ein Richtwert, endgültig im Angebot
				'lead_time'    => $lead !== '' ? [ 'de' => $lead ] : (object) [],
				'ranges'       => $ranges,
			];

			$techBySheet[ $sheetName ] = $id;

			$summary[] = sprintf(
				/* translators: 1: Technikname, 2: Anzahl Flächenstufen, 3: Flächengrenzen, 4: Anzahl Mengenstaffeln, 5: Vorkosten */
				__( '%1$s: %2$d Flächenstufen (bis %3$s cm²), %4$d Mengenstaffeln, Vorkosten-Richtwert %5$s €', 'media-lab-ml-decoration' ),
				$name,
				count( $ranges ),
				implode( ' / ', array_map( static fn( $x ) => self::fmt( $x['to'] ), $ranges ) ),
				count( $qtys ),
				number_format_i18n( (float) $setup, 2 )
			);
		}

		// ── Positionen je Warenart ──
		if ( isset( $book['Positionen'] ) ) {
			$templates = self::parse_templates( $book['Positionen'], $techBySheet, $errors, $warnings );
			$npos      = array_sum( array_map( static fn( $t ) => count( $t['positions'] ), $templates ) );
			$summary[] = sprintf( __( 'Positionen: %1$d Vorlagen mit %2$d Positionen', 'media-lab-ml-decoration' ), count( $templates ), $npos );
		} else {
			$templates  = $fallback ?? [];
			$warnings[] = __( 'Die Datei hat kein Blatt "Positionen" (ältere Vorlage). Die bisherigen Positionsvorlagen bleiben unverändert. Laden Sie bei Gelegenheit die aktuelle Excel herunter.', 'media-lab-ml-decoration' );
		}

		if ( $errors ) {
			return [ 'pricelist' => null, 'errors' => $errors, 'warnings' => $warnings, 'summary' => [] ];
		}

		return [
			'pricelist' => [
				'supplier'      => $supplier,
				'currency'      => $currency,
				'valid_from'    => $validFrom,
				'valid_until'   => '',
				'area_unit'     => 'cm2',
				'generated_at'  => gmdate( 'c' ),
				'manipulations' => new stdClass(),
				'notes'         => new stdClass(),
				'techniques'    => $techniques,
				'templates'     => $templates,
			],
			'errors'    => [],
			'warnings'  => $warnings,
			'summary'   => $summary,
		];
	}

	/**
	 * Blatt "Positionen": je Zeile eine Position einer Vorlage (Warenart).
	 * Spalten: A Vorlage-Schlüssel, B Warenart, C Positions-Schlüssel, D Name im Shop, E Breite cm, F Höhe cm,
	 * G Transferdruck, H Sublimationsdruck, I Stick (x = möglich), J max. Stickfarben. Ab Zeile 5, bis zur ersten leeren Spalte A.
	 *
	 * @param array<int, array<int, mixed>> $rows
	 * @param array<string, string>         $techBySheet Blattname => Technik-ID
	 * @return array<string, array{name:string, positions:array}>
	 */
	private static function parse_templates( array $rows, array $techBySheet, array &$errors, array &$warnings ): array {
		$E         = static fn( string $m ): string => 'Positionen: ' . $m;
		$templates = [];
		$flagCols  = [ 6 => 'Transferdruck', 7 => 'Sublimationsdruck', 8 => 'Stick' ];
		$yes       = static fn( $v ): bool => in_array( strtolower( trim( (string) $v ) ), [ 'x', 'ja', 'yes', '1', 'true' ], true );

		for ( $r = 5; $r < 400; $r++ ) {
			$key = strtolower( trim( (string) ( $rows[ $r ][0] ?? '' ) ) );
			if ( $key === '' ) {
				break;
			}
			$line = sprintf( __( 'Zeile %d', 'media-lab-ml-decoration' ), $r );
			if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/', $key ) || $key === 'none' ) {
				$errors[] = $E( "{$line}: Vorlagen-Schlüssel \"{$key}\" ist ungültig (nur Kleinbuchstaben, Ziffern, Bindestrich; \"none\" ist reserviert)." );
				continue;
			}
			$art = trim( (string) ( $rows[ $r ][1] ?? '' ) );
			if ( ! isset( $templates[ $key ] ) ) {
				$templates[ $key ] = [ 'name' => $art !== '' ? $art : $key, 'positions' => [] ];
			}

			$pid   = strtolower( trim( (string) ( $rows[ $r ][2] ?? '' ) ) );
			$pname = trim( (string) ( $rows[ $r ][3] ?? '' ) );
			$w     = self::num( $rows[ $r ][4] ?? null );
			$h     = self::num( $rows[ $r ][5] ?? null );
			if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/', $pid ) ) {
				$errors[] = $E( "{$line}: Positions-Schlüssel (Spalte C) fehlt oder ist ungültig (nur Kleinbuchstaben, Ziffern, Bindestrich)." );
				continue;
			}
			foreach ( $templates[ $key ]['positions'] as $existing ) {
				if ( $existing['id'] === $pid ) {
					$errors[] = $E( "{$line}: Positions-Schlüssel \"{$pid}\" kommt in der Vorlage \"{$key}\" doppelt vor." );
					continue 2;
				}
			}
			if ( $pname === '' ) {
				$errors[] = $E( "{$line}: Name im Shop (Spalte D) fehlt." );
			}
			if ( $w === null || $h === null || $w <= 0 || $h <= 0 ) {
				$errors[] = $E( "{$line}: Breite und Höhe (Spalten E, F) müssen größer als 0 sein." );
				continue;
			}

			$techs = [];
			foreach ( $flagCols as $col => $sheetName ) {
				if ( ! $yes( $rows[ $r ][ $col ] ?? '' ) ) {
					continue;
				}
				if ( ! isset( $techBySheet[ $sheetName ] ) ) {
					continue; // Blatt der Technik fehlt, dafür gibt es schon einen Fehler
				}
				$entry = [ 'id' => $techBySheet[ $sheetName ], 'max_colours' => 1 ];
				if ( $sheetName === 'Stick' ) {
					$colours = self::num( $rows[ $r ][9] ?? null );
					if ( $colours === null || $colours < 1 ) {
						$errors[] = $E( "{$line}: Stick ist angekreuzt, aber \"Stick: max. Farben\" (Spalte J) fehlt." );
						continue;
					}
					$entry['max_colours'] = (int) $colours;
				}
				if ( $sheetName === 'Sublimationsdruck' ) {
					$entry['sublimation'] = true;
				}
				$techs[] = $entry;
			}
			if ( ! $techs ) {
				$warnings[] = $E( "{$line} ({$pname}): keine Technik angekreuzt – die Position wird im Shop nicht angezeigt." );
			}

			$templates[ $key ]['positions'][] = [
				'id'         => $pid,
				'name'       => $pname,
				'w'          => $w,
				'h'          => $h,
				'unit'       => 'cm',
				'shape'      => 'Rectangle',
				'max_area'   => round( $w * $h, 2 ),
				'image'      => '',
				'colors'     => [],
				'techniques' => $techs,
			];
		}

		foreach ( $templates as $key => $t ) {
			if ( ! $t['positions'] ) {
				unset( $templates[ $key ] );
			}
		}
		if ( ! $templates && ! $errors ) {
			$errors[] = $E( 'Keine Vorlagen ab Zeile 5 gefunden.' );
		}
		return $templates;
	}

	/** Vorlagen für ältere Dateien ohne Blatt "Positionen": die gespeicherten, sonst die der mitgelieferten Vorlage. */
	private static function fallback_templates(): array {
		$pl = ML_Deco_Store::pricelist( self::SUPPLIER_KEY );
		if ( ! empty( $pl['templates'] ) && is_array( $pl['templates'] ) ) {
			return $pl['templates'];
		}
		$book = self::read_book( self::template_path() );
		if ( is_array( $book ) ) {
			$res = self::convert( $book );
			return (array) ( $res['pricelist']['templates'] ?? [] );
		}
		return [];
	}

	private static function fmt( float $n ): string {
		return rtrim( rtrim( number_format( $n, 2, ',', '' ), '0' ), ',' );
	}

	// ── Import + gespeicherte Datei ───────────────────────────────────────────

	/**
	 * Liest eine Excel-Datei ein, prüft sie und speichert bei Erfolg Preisliste + Datei.
	 *
	 * @param string $label Bezeichnung der Quelle für die Anzeige (z. B. Dateiname)
	 * @return array{ok:bool, errors:string[], warnings:string[], summary:string[]}
	 */
	public static function import_path( string $path, string $label ): array {
		$book = self::read_book( $path );
		if ( is_string( $book ) ) {
			return [ 'ok' => false, 'errors' => [ $book ], 'warnings' => [], 'summary' => [] ];
		}
		$res = self::convert( $book, isset( $book['Positionen'] ) ? null : self::fallback_templates() );
		if ( $res['errors'] ) {
			return [ 'ok' => false ] + $res;
		}

		$json = wp_json_encode( $res['pricelist'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$done = ML_Deco_Store::import_json( self::SUPPLIER_KEY, (string) $json, time(), 'excel' );
		if ( $done !== true ) {
			return [ 'ok' => false, 'errors' => [ $done ], 'warnings' => $res['warnings'], 'summary' => [] ];
		}

		$user = wp_get_current_user();
		$data = file_get_contents( $path );
		update_option( self::OPT_FILE . self::SUPPLIER_KEY, [
			'name' => $label,
			'time' => time(),
			'user' => $user && $user->exists() ? $user->display_name : '',
			'data' => base64_encode( (string) $data ),
		], false );

		return [ 'ok' => true, 'errors' => [], 'warnings' => $res['warnings'], 'summary' => $res['summary'] ];
	}

	/** Metadaten der zuletzt eingelesenen Excel-Datei (ohne Inhalt) oder leer. */
	public static function stored_info(): array {
		$s = get_option( self::OPT_FILE . self::SUPPLIER_KEY, null );
		if ( ! is_array( $s ) || empty( $s['data'] ) ) {
			return [];
		}
		return [
			'name' => (string) ( $s['name'] ?? '' ),
			'time' => (int) ( $s['time'] ?? 0 ),
			'user' => (string) ( $s['user'] ?? '' ),
		];
	}

	/** Inhalt der zuletzt eingelesenen Excel-Datei oder null. */
	public static function stored_content(): ?string {
		$s = get_option( self::OPT_FILE . self::SUPPLIER_KEY, null );
		if ( ! is_array( $s ) || empty( $s['data'] ) ) {
			return null;
		}
		$bin = base64_decode( (string) $s['data'], true );
		return $bin === false ? null : $bin;
	}
}
