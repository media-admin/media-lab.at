<?php
/**
 * Kategorien (11/2026)
 *
 * Die drei Lieferanten bringen drei verschiedene Kategoriebaeume mit (MidOcean tief, Makito flach und deutsch, Cotton Classics flach und
 * englisch, zusammen 392 Begriffe). Dieses Modul ordnet jedes Produkt in einen eigenen Zielbaum ein (12 Hauptkategorien, 76
 * Unterkategorien, zwei Ebenen) und setzt eine Kollektion als Filter-Merkmal (pa_kollektion).
 *
 * Daten (data/, Semikolon, UTF-8, mit Kopfzeile):
 *   kategorien.csv             Lieferantenkategorie (Pfad "A > B > C" aus den Namen) -> Aktion, Zielkategorie, Kollektion
 *                              Aktion: zuordnen (Unterkategorie), haupt (nur Hauptkategorie), kollektion, streichen, offen
 *   kategorien-baum.csv        Zielbaum mit Slugs, Spalte standard: Unterkategorie, die eine Hauptkategorie ohne Unterkategorie erhaelt
 *   kategorien-titelregeln.csv Muster (Regex, Kleinschreibung) im Produkttitel -> Haupt- und Unterkategorie
 *   kategorien-ausnahmen.csv   Einzelne Produkte von Hand: sku;zielkategorien;kollektionen (Zielkategorien "Haupt > Unter | ...")
 *
 * Regel je Produkt:
 *   - Ausnahme (Tabelle) gewinnt immer (Kollektionen aus der Tabelle, sonst die berechneten).
 *   - Makito (SKU ML-TKM-...): Titelregel zuerst, dann Unterkategorie der Quelle. MidOcean und Cotton Classics: zuerst die Quelle,
 *     dann die Titelregel (nur wenn die Quelle keine Unterkategorie liefert).
 *   - Fallback-Regeln (Spalte fallback = 1, allgemeine Woerter wie Halter oder Tasche) greifen erst, wenn Titel und Quelle keine Unterkategorie liefern.
 *   - Allgemeine Quellkategorien (Geschenke und Werbegaben, OUTDOOR-SAISON, Freizeit und Unterhaltung) zaehlen nur, wenn nichts anderes da ist.
 *   - Bleibt nur eine Hauptkategorie, gilt deren Standard (Taschen & Reisen: Einkaufstaschen & Beutel).
 *   - Ergibt sich nichts, bleiben die Kategorien des Produkts unveraendert und das Produkt steht im Bericht.
 *
 * Ablauf: Jeder Import (full_update) setzt product_cat auf die Lieferantenkategorien zurueck. Der Sweep danach (pmxi_import_complete)
 * liest sie, merkt sie am Produkt (_ml_kat_quelle) und setzt die Zielkategorien (_ml_kat_ziel). Aenderungen an den Tabellen wirken
 * mit wp ml-kategorien anwenden, ohne neuen Import. Aendert nur product_cat und pa_kollektion.
 */

if (!defined('ABSPATH')) {
    exit;
}

function ml_kat_dir() {
    return dirname(__DIR__) . '/data/';
}

/** Allgemeine Lieferantenkategorien (Pfad): zaehlen nur, wenn nichts anderes da ist. */
function ml_kat_schwach() {
    return array('Geschenke und Werbegaben', 'OUTDOOR-SAISON', 'Freizeit und Unterhaltung');
}

function ml_kat_dec($s) {
    return html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ml_kat_key($h, $u = null) {
    return $h . "\x1f" . ($u === null || $u === '' ? '' : $u);
}

/** "Haupt > Unter | Haupt2" in eine Liste von [haupt, unter oder null]. */
function ml_kat_ziele_parse($s) {
    $out = array();
    foreach (explode('|', (string) $s) as $z) {
        $z = trim($z);
        if ($z === '') {
            continue;
        }
        $p = explode(' > ', $z, 2);
        $out[] = array(trim($p[0]), isset($p[1]) && trim($p[1]) !== '' ? trim($p[1]) : null);
    }
    return $out;
}

/** CSV mit Kopfzeile lesen: Liste assoziativer Zeilen (Kopfnamen klein). */
function ml_kat_csv($datei, array &$fehler) {
    $pfad = ml_kat_dir() . $datei;
    if (!is_readable($pfad)) {
        $fehler[] = "Datei nicht lesbar: {$pfad}";
        return array();
    }
    $h = fopen($pfad, 'r');
    if (!$h) {
        $fehler[] = "Datei nicht zu oeffnen: {$pfad}";
        return array();
    }
    $kopf = fgetcsv($h, 0, ';', '"', '\\');
    if (!is_array($kopf)) {
        fclose($h);
        $fehler[] = "Datei ist leer: {$datei}";
        return array();
    }
    $kopf[0] = preg_replace('~^\xEF\xBB\xBF~', '', (string) $kopf[0]);
    $kopf    = array_map(function ($c) {
        return strtolower(trim((string) $c));
    }, $kopf);
    $rows = array();
    while (($r = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
        if (count($r) === 1 && trim((string) $r[0]) === '') {
            continue;
        }
        $row = array();
        foreach ($kopf as $i => $k) {
            $row[$k] = isset($r[$i]) ? trim((string) $r[$i]) : '';
        }
        $rows[] = $row;
    }
    fclose($h);
    return $rows;
}

/**
 * Alle Tabellen laden und pruefen. Rueckgabe: map (pfad => aktion, ziele, koll, stufe), baum, zielpfade (Pfad der Zielkategorie =>
 * [haupt, unter]), regeln, ausnahmen, standard (haupt => unter), kollektionen, fehler.
 */
function ml_kat_laden($reload = false) {
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $f = array();
    $d = array('map' => array(), 'baum' => array(), 'zielpfade' => array(), 'regeln' => array(), 'ausnahmen' => array(), 'standard' => array(), 'kollektionen' => array(), 'fehler' => &$f);

    foreach (ml_kat_csv('kategorien-baum.csv', $f) as $r) {
        if ($r['haupt'] === '') {
            continue;
        }
        if ($r['unter'] === '') {
            $d['baum'][ml_kat_key($r['haupt'])] = array('haupt' => $r['haupt'], 'unter' => null, 'slug' => $r['haupt_slug'], 'parent_slug' => '');
            $d['zielpfade'][$r['haupt']]        = array($r['haupt'], null);
            if (!empty($r['standard'])) {
                $d['standard'][$r['haupt']] = $r['standard'];
            }
        } else {
            $d['baum'][ml_kat_key($r['haupt'], $r['unter'])] = array('haupt' => $r['haupt'], 'unter' => $r['unter'], 'slug' => $r['unter_slug'], 'parent_slug' => $r['haupt_slug']);
            $d['zielpfade'][$r['haupt'] . ' > ' . $r['unter']] = array($r['haupt'], $r['unter']);
        }
    }
    foreach ($d['standard'] as $h => $u) {
        if (!isset($d['baum'][ml_kat_key($h, $u)])) {
            $f[] = "Standard {$h} > {$u} steht nicht im Baum";
        }
    }

    foreach (ml_kat_csv('kategorien.csv', $f) as $r) {
        if ($r['pfad'] === '') {
            continue;
        }
        $ziele = ml_kat_ziele_parse($r['zielkategorie']);
        foreach ($ziele as $z) {
            if (!isset($d['baum'][ml_kat_key($z[0], $z[1])])) {
                $f[] = "kategorien.csv: Zielkategorie {$z[0]} > {$z[1]} steht nicht im Baum (Pfad {$r['pfad']})";
            }
        }
        $koll = array();
        foreach (explode('|', $r['kollektion']) as $k) {
            $k = trim($k);
            if ($k !== '') {
                $koll[$k]                = $k;
                $d['kollektionen'][$k]   = $k;
            }
        }
        $d['map'][$r['pfad']] = array('aktion' => $r['aktion'], 'ziele' => $ziele, 'koll' => array_values($koll), 'stufe' => $r['stufe']);
    }

    foreach (ml_kat_csv('kategorien-titelregeln.csv', $f) as $r) {
        if ($r['muster'] === '') {
            continue;
        }
        $rx = '~' . $r['muster'] . '~u';
        if (@preg_match($rx, '') === false) {
            $f[] = "Titelregel {$r['nr']}: ungueltiges Muster";
            continue;
        }
        if (!isset($d['baum'][ml_kat_key($r['haupt'], $r['unter'])])) {
            $f[] = "Titelregel {$r['nr']}: Zielkategorie {$r['haupt']} > {$r['unter']} steht nicht im Baum";
            continue;
        }
        $d['regeln'][] = array('nr' => $r['nr'], 'rx' => $rx, 'haupt' => $r['haupt'], 'unter' => $r['unter'], 'fallback' => isset($r['fallback']) && $r['fallback'] === '1');
    }

    foreach (ml_kat_csv('kategorien-ausnahmen.csv', $f) as $r) {
        if ($r['sku'] === '') {
            continue;
        }
        $z = ml_kat_ziele_parse($r['zielkategorien']);
        foreach ($z as $zz) {
            if (!isset($d['baum'][ml_kat_key($zz[0], $zz[1])])) {
                $f[] = "kategorien-ausnahmen.csv: Zielkategorie {$zz[0]} > {$zz[1]} steht nicht im Baum (SKU {$r['sku']})";
            }
        }
        $k = array();
        foreach (explode('|', $r['kollektionen']) as $kk) {
            if (trim($kk) !== '') {
                $k[] = trim($kk);
            }
        }
        $d['ausnahmen'][$r['sku']] = array('ziele' => $z, 'koll' => $k);
    }
    $d['kollektionen'] = array_values($d['kollektionen']);
    sort($d['kollektionen']);
    $cache = $d;
    return $cache;
}

/** Lieferantenkuerzel aus der SKU (ML-TKM-001234 -> TKM). */
function ml_kat_lieferant($sku) {
    return preg_match('~^ML-([A-Z]+)-~', (string) $sku, $m) ? $m[1] : '';
}

/**
 * Ziel aus dem Titel: der Treffer, der im Titel am weitesten links steht (bei gleicher Stelle die Regel weiter oben).
 * $fallback false: nur starke Regeln; true: nur Fallback-Regeln (allgemeine Woerter wie "Halter" oder "Tasche", die eine
 * Unterkategorie der Quelle nicht ueberstimmen sollen).
 */
function ml_kat_titel_ziel($titel, array $regeln, $fallback = false) {
    $t = mb_strtolower(ml_kat_dec($titel), 'UTF-8');
    $t = preg_replace('~[„“"”]~u', ' ', $t);
    $best = null;
    foreach ($regeln as $i => $r) {
        if ($r['fallback'] !== (bool) $fallback) {
            continue;
        }
        if (preg_match($r['rx'], $t, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            if ($best === null || $pos < $best['pos'] || ($pos === $best['pos'] && $i < $best['i'])) {
                $best = array('pos' => $pos, 'i' => $i, 'z' => array($r['haupt'], $r['unter']));
            }
        }
    }
    return $best ? $best['z'] : null;
}

/** Entfernt Hauptkategorien, fuer die es eine Unterkategorie gibt. */
function ml_kat_reduziere(array $T) {
    $hat = array();
    foreach ($T as $z) {
        if ($z[1] !== null) {
            $hat[$z[0]] = true;
        }
    }
    $out = array();
    foreach ($T as $k => $z) {
        if ($z[1] !== null || !isset($hat[$z[0]])) {
            $out[$k] = $z;
        }
    }
    return $out;
}

/** Ziele und Kollektionen aus Quellpfaden. Unbekannte Pfade landen in $unbekannt. */
function ml_kat_quelle(array $pfade, array $d, array &$K, array &$unbekannt) {
    $T = array();
    foreach ($pfade as $p) {
        if (isset($d['map'][$p])) {
            foreach ($d['map'][$p]['ziele'] as $z) {
                $T[ml_kat_key($z[0], $z[1])] = $z;
            }
            foreach ($d['map'][$p]['koll'] as $k) {
                $K[$k] = $k;
            }
        } elseif (isset($d['zielpfade'][$p])) {
            $z = $d['zielpfade'][$p];   // schon eine Zielkategorie
            $T[ml_kat_key($z[0], $z[1])] = $z;
        } else {
            $unbekannt[$p] = $p;
        }
    }
    return $T;
}

function ml_kat_standard(array $T, array $d) {
    $out = array();
    foreach ($T as $z) {
        if ($z[1] === null && isset($d['standard'][$z[0]])) {
            $z = array($z[0], $d['standard'][$z[0]]);
        }
        $out[ml_kat_key($z[0], $z[1])] = $z;
    }
    return $out;
}

/**
 * Zielkategorien und Kollektionen eines Produkts. $pfade: Pfade der Lieferantenkategorien.
 * Rueckgabe: ziele (Liste [haupt, unter]), koll (Liste), weg (ausnahme, titel, quelle-unter, standard, nur-haupt, nur-haupt-schwach, keine),
 * unbekannt (Pfade ohne Eintrag in kategorien.csv).
 */
function ml_kat_final($sku, $titel, array $pfade, array $d) {
    $unbekannt = array();
    $schwach   = ml_kat_schwach();
    $stark     = array();
    $schw      = array();
    foreach ($pfade as $p) {
        if (in_array($p, $schwach, true)) {
            $schw[] = $p;
        } else {
            $stark[] = $p;
        }
    }
    $K  = array();
    $T  = ml_kat_reduziere(ml_kat_quelle($stark, $d, $K, $unbekannt));
    $Kw = array();
    $Tw = ml_kat_quelle($schw, $d, $Kw, $unbekannt);
    $koll = array_values($K);
    sort($koll);
    if (isset($d['ausnahmen'][$sku])) {
        // Ausnahme: Ziele aus der Tabelle; Kollektionen aus der Tabelle, wenn dort welche stehen, sonst die berechneten
        $a = $d['ausnahmen'][$sku];
        return array('ziele' => $a['ziele'], 'koll' => $a['koll'] ? $a['koll'] : $koll, 'weg' => 'ausnahme', 'unbekannt' => array_values($unbekannt));
    }
    $tz = ml_kat_titel_ziel($titel, $d['regeln'], false);
    $tf = ml_kat_titel_ziel($titel, $d['regeln'], true);
    $U  = array();
    $Hs = array();
    foreach ($T as $k => $z) {
        if ($z[1] !== null) {
            $U[$k] = $z;
        } else {
            $Hs[$k] = $z;
        }
    }
    $mk = function ($arr, $weg) use ($koll, $unbekannt) {
        return array('ziele' => array_values($arr), 'koll' => $koll, 'weg' => $weg, 'unbekannt' => array_values($unbekannt));
    };
    $zt = $tz ? array(ml_kat_key($tz[0], $tz[1]) => $tz) : array();
    $zf = $tf ? array(ml_kat_key($tf[0], $tf[1]) => $tf) : array();
    if (ml_kat_lieferant($sku) === 'TKM') {
        if ($zt) {
            return $mk($zt, 'titel');
        }
        if ($U) {
            return $mk($U, 'quelle-unter');
        }
        if ($zf) {
            return $mk($zf, 'titel');
        }
    } else {
        if ($U) {
            return $mk($U, 'quelle-unter');
        }
        if ($zt) {
            return $mk($zt, 'titel');
        }
        if ($zf) {
            return $mk($zf, 'titel');
        }
    }
    if ($Hs) {
        $weg = 'nur-haupt';
        foreach ($Hs as $z) {
            if (isset($d['standard'][$z[0]])) {
                $weg = 'standard';
            }
        }
        return $mk(ml_kat_standard($Hs, $d), $weg);
    }
    if ($Tw) {
        return $mk(ml_kat_standard(ml_kat_reduziere($Tw), $d), 'nur-haupt-schwach');
    }
    return $mk(array(), 'keine');
}

/** Pfad (Namen mit " > ") eines Ziels. */
function ml_kat_ziel_pfad(array $z) {
    return $z[1] === null ? $z[0] : $z[0] . ' > ' . $z[1];
}

/* ------------------------------------------------------------------------------------------------------------------------------- */
/* WordPress-Teil                                                                                                                   */
/* ------------------------------------------------------------------------------------------------------------------------------- */

/** Alle product_cat-Begriffe: [id => ['pfad' => ..., 'parent' => ..., 'slug' => ..., 'count' => ...]]. */
function ml_kat_begriffe() {
    $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'id', 'number' => 0));
    if (is_wp_error($terms)) {
        return array();
    }
    $by = array();
    foreach ($terms as $t) {
        $by[(int) $t->term_id] = $t;
    }
    $pfad = function ($id) use (&$pfad, $by) {
        static $memo = array();
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        $t = $by[$id];
        $p = ml_kat_dec($t->name);
        if ((int) $t->parent && isset($by[(int) $t->parent])) {
            $p = $pfad((int) $t->parent) . ' > ' . $p;
        }
        $memo[$id] = $p;
        return $p;
    };
    $out = array();
    foreach ($by as $id => $t) {
        $out[$id] = array('pfad' => $pfad($id), 'parent' => (int) $t->parent, 'slug' => $t->slug, 'count' => (int) $t->count);
    }
    return $out;
}

/**
 * Zielbaum anlegen (oder vorhandene Begriffe mit gleichem Pfad weiterverwenden). Wenn der gewuenschte Slug von einem Lieferantenbegriff
 * belegt ist, bekommt dieser den Slug "quelle-<slug>". Rueckgabe: [Schluessel => term_id] oder null bei einem Fehler.
 */
function ml_kat_ziele_anlegen(array $d, &$neu = 0) {
    $idx = array();
    foreach (ml_kat_begriffe() as $id => $b) {
        if (!isset($idx[$b['pfad']])) {
            $idx[$b['pfad']] = $id;
        }
    }
    $ids = array();
    $neu = 0;
    foreach ($d['baum'] as $k => $b) {
        if ($b['unter'] !== null) {
            continue;
        }
        $pfad = $b['haupt'];
        if (isset($idx[$pfad])) {
            $ids[$k] = $idx[$pfad];
            continue;
        }
        $id = ml_kat_term_neu($b['haupt'], $b['slug'], 0);
        if (!$id) {
            return null;
        }
        $idx[$pfad] = $id;
        $ids[$k]    = $id;
        $neu++;
    }
    foreach ($d['baum'] as $k => $b) {
        if ($b['unter'] === null) {
            continue;
        }
        $pfad = $b['haupt'] . ' > ' . $b['unter'];
        if (isset($idx[$pfad])) {
            $ids[$k] = $idx[$pfad];
            continue;
        }
        $parent = $ids[ml_kat_key($b['haupt'])];
        $id     = ml_kat_term_neu($b['unter'], $b['slug'], $parent);
        if (!$id) {
            return null;
        }
        $idx[$pfad] = $id;
        $ids[$k]    = $id;
        $neu++;
    }
    return $ids;
}

function ml_kat_term_neu($name, $slug, $parent) {
    $vorhanden = get_term_by('slug', $slug, 'product_cat');
    if ($vorhanden) {
        wp_update_term((int) $vorhanden->term_id, 'product_cat', array('slug' => 'quelle-' . $slug));   // Slug fuer das Ziel freimachen
    }
    $r = wp_insert_term($name, 'product_cat', array('slug' => $slug, 'parent' => (int) $parent));
    if (is_wp_error($r)) {
        error_log('[ML KATEGORIEN] Begriff ' . $name . ' konnte nicht angelegt werden: ' . $r->get_error_message());
        return 0;
    }
    return (int) $r['term_id'];
}

/** Attribut "Kollektion" (pa_kollektion) und seine Werte anlegen. Rueckgabe: [name => term_id] oder false. */
function ml_kat_kollektion_anlegen(array $d) {
    if (!taxonomy_exists('pa_kollektion')) {
        if (!function_exists('wc_create_attribute') || !function_exists('wc_attribute_taxonomy_id_by_name')) {
            error_log('[ML KATEGORIEN] WooCommerce nicht geladen, Attribut kann nicht angelegt werden.');
            return false;
        }
        if (!wc_attribute_taxonomy_id_by_name('kollektion')) {
            $c = wc_create_attribute(array('name' => 'Kollektion', 'slug' => 'kollektion', 'type' => 'select', 'order_by' => 'name', 'has_archives' => false));
            if (is_wp_error($c)) {
                error_log('[ML KATEGORIEN] Attribut Kollektion konnte nicht angelegt werden: ' . $c->get_error_message());
                return false;
            }
            delete_transient('wc_attribute_taxonomies');
        }
        if (!taxonomy_exists('pa_kollektion')) {
            register_taxonomy('pa_kollektion', apply_filters('woocommerce_taxonomy_objects_pa_kollektion', array('product')), array(
                'labels' => array('name' => 'Kollektion'), 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false, 'public' => false,
            ));
        }
    }
    $ids = array();
    foreach ($d['kollektionen'] as $name) {
        $slug = sanitize_title($name);
        $t    = get_term_by('slug', $slug, 'pa_kollektion');
        if ($t) {
            $ids[$name] = (int) $t->term_id;
            continue;
        }
        $r = wp_insert_term($name, 'pa_kollektion', array('slug' => $slug));
        if (is_wp_error($r)) {
            error_log('[ML KATEGORIEN] Kollektion ' . $name . ' konnte nicht angelegt werden: ' . $r->get_error_message());
            return false;
        }
        $ids[$name] = (int) $r['term_id'];
    }
    return $ids;
}

function ml_kat_parent_ids($import_id = 0) {
    global $wpdb;
    $parents = array();
    if ($import_id) {
        $suppressed = $wpdb->suppress_errors(true);
        $parents    = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID)
             FROM {$wpdb->prefix}pmxi_posts ip
             INNER JOIN {$wpdb->posts} p ON p.ID = ip.post_id
             WHERE ip.import_id = %d",
            (int) $import_id
        ));
        $wpdb->suppress_errors($suppressed);
    }
    if (empty($parents)) {
        $parents = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ml_supplier_code'
             WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')"
        );
    }
    return array_values(array_filter(array_map('intval', (array) $parents)));
}

/**
 * Kategorien an Produkten setzen. Optionen: dry (nichts schreiben), limit, progress (Callback), defer_count.
 * Rueckgabe: ['stats' => [...], 'unbekannt' => [pfad => anzahl], 'bericht' => [[id, sku, titel, weg], ...]] oder null.
 */
function ml_kat_process(array $parents, array $opt = array()) {
    global $wpdb;
    $d = ml_kat_laden();
    $dry      = !empty($opt['dry']);
    $limit    = isset($opt['limit']) ? (int) $opt['limit'] : 0;
    $progress = isset($opt['progress']) ? $opt['progress'] : null;
    $stats    = array('geprueft' => 0, 'geaendert' => 0, 'unter' => 0, 'nur_haupt' => 0, 'ohne' => 0, 'ausnahme' => 0, 'titel' => 0, 'quelle' => 0, 'standard' => 0);
    $unbekannt = array();
    $bericht   = array();
    $ids       = array();
    $kids      = array();

    if (!$dry) {
        $ids = ml_kat_ziele_anlegen($d);
        if ($ids === null) {
            return null;
        }
        $kids = ml_kat_kollektion_anlegen($d);
        if ($kids === false) {
            return null;
        }
        wp_defer_term_counting(true);
    }
    $begriffe = ml_kat_begriffe();
    $zielpfade = $d['zielpfade'];
    if ($limit > 0) {
        $parents = array_slice($parents, 0, $limit);
    }
    $total = count($parents);
    $done  = 0;

    foreach (array_chunk($parents, 400) as $chunk) {
        $done += count($chunk);
        $in    = implode(',', array_map('intval', $chunk));
        $chunk = array_map('intval', $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ml_supplier_code' AND post_id IN ($in)"
        ));
        if ($chunk) {
            update_meta_cache('post', $chunk);
            update_object_term_cache($chunk, 'product');
            _prime_post_caches($chunk, false, false);
        }
        foreach ($chunk as $pid) {
            $stats['geprueft']++;
            $cur = get_the_terms($pid, 'product_cat');
            $cur = is_array($cur) ? $cur : array();
            $curIds   = array();
            $curPfade = array();
            foreach ($cur as $t) {
                $curIds[]   = (int) $t->term_id;
                $curPfade[] = isset($begriffe[(int) $t->term_id]) ? $begriffe[(int) $t->term_id]['pfad'] : ml_kat_dec($t->name);
            }
            sort($curIds);
            // Quellen: nach einem Sweep ohne neuen Import die gemerkten, sonst die aktuellen Kategorien
            $mz = json_decode((string) get_post_meta($pid, '_ml_kat_ziel', true), true);
            $mq = json_decode((string) get_post_meta($pid, '_ml_kat_quelle', true), true);
            $ausMeta = is_array($mz) && is_array($mq) && $mq && $mz === $curIds;
            $pfade   = $ausMeta ? $mq : array_values(array_unique($curPfade));
            $sku     = (string) get_post_meta($pid, '_sku', true);
            $titel   = (string) get_post_field('post_title', $pid);
            $res     = ml_kat_final($sku, $titel, $pfade, $d);
            foreach ($res['unbekannt'] as $p) {
                $unbekannt[$p] = (isset($unbekannt[$p]) ? $unbekannt[$p] : 0) + 1;
            }
            $weg = $res['weg'];
            if (!$res['ziele']) {
                $stats['ohne']++;
                $bericht[] = array($pid, $sku, $titel, $weg, implode(' | ', $pfade));
                continue;   // nichts zu tun: Kategorien bleiben
            }
            $mitUnter = false;
            foreach ($res['ziele'] as $z) {
                if ($z[1] !== null) {
                    $mitUnter = true;
                }
            }
            if ($mitUnter) {
                $stats['unter']++;
            } else {
                $stats['nur_haupt']++;
                $bericht[] = array($pid, $sku, $titel, $weg, implode(' | ', $pfade));
            }
            if ($weg === 'ausnahme') {
                $stats['ausnahme']++;
            } elseif ($weg === 'titel') {
                $stats['titel']++;
            } elseif ($weg === 'quelle-unter') {
                $stats['quelle']++;
            } elseif ($weg === 'standard') {
                $stats['standard']++;
            }
            $wantPfade = array();
            $wantIds   = array();
            foreach ($res['ziele'] as $z) {
                $wantPfade[] = ml_kat_ziel_pfad($z);
                if (isset($ids[ml_kat_key($z[0], $z[1])])) {
                    $wantIds[] = $ids[ml_kat_key($z[0], $z[1])];
                }
            }
            $havePfade = array_values(array_unique($curPfade));
            sort($havePfade);
            sort($wantPfade);
            $haveKoll = get_the_terms($pid, 'pa_kollektion');
            $hk       = array();
            if (is_array($haveKoll)) {
                foreach ($haveKoll as $t) {
                    $hk[] = ml_kat_dec($t->name);
                }
            }
            sort($hk);
            $wk = $res['koll'];
            sort($wk);
            $aendert = ($havePfade !== $wantPfade) || ($hk !== $wk);
            if ($aendert) {
                $stats['geaendert']++;
            }
            if ($dry) {
                continue;
            }
            sort($wantIds);
            if ($havePfade !== $wantPfade || $curIds !== $wantIds) {
                wp_set_object_terms($pid, $wantIds, 'product_cat');
            }
            if (!$ausMeta) {
                update_post_meta($pid, '_ml_kat_quelle', wp_json_encode($pfade, JSON_UNESCAPED_UNICODE));
            }
            update_post_meta($pid, '_ml_kat_ziel', wp_json_encode($wantIds));
            if ($hk !== $wk) {
                $kidList = array();
                foreach ($wk as $name) {
                    if (isset($kids[$name])) {
                        $kidList[] = $kids[$name];
                    }
                }
                wp_set_object_terms($pid, $kidList, 'pa_kollektion');
                $attrs = get_post_meta($pid, '_product_attributes', true);
                $attrs = is_array($attrs) ? $attrs : array();
                if ($kidList && !isset($attrs['pa_kollektion'])) {
                    $attrs['pa_kollektion'] = array('name' => 'pa_kollektion', 'value' => '', 'position' => count($attrs), 'is_visible' => 0, 'is_variation' => 0, 'is_taxonomy' => 1);
                    update_post_meta($pid, '_product_attributes', $attrs);
                }
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
        }
        if (is_callable($progress)) {
            call_user_func($progress, $done, $total);
        }
    }
    if (!$dry) {
        wp_defer_term_counting(false);
    }
    return array('stats' => $stats, 'unbekannt' => $unbekannt, 'bericht' => $bericht);
}

/**
 * Leere Lieferantenbegriffe loeschen (Pfad steht in kategorien.csv, kein Zielbegriff, keine Produkte). Sie tauchen sonst als
 * "Unterkategorie (0)" unter Zielkategorien auf, die so heissen wie eine Lieferantenkategorie (zum Beispiel Technologie).
 */
function ml_kat_aufraeumen(array $d) {
    $begriffe = ml_kat_begriffe();
    $tiefe    = function ($id) use (&$tiefe, $begriffe) {
        return $begriffe[$id]['parent'] && isset($begriffe[$begriffe[$id]['parent']]) ? 1 + $tiefe($begriffe[$id]['parent']) : 0;
    };
    $kandidaten = array();
    foreach ($begriffe as $id => $b) {
        if ($b['count'] === 0 && isset($d['map'][$b['pfad']]) && !isset($d['zielpfade'][$b['pfad']])) {
            $kandidaten[$id] = $tiefe($id);
        }
    }
    arsort($kandidaten);   // tiefste zuerst
    $n = 0;
    foreach (array_keys($kandidaten) as $id) {
        $r = wp_delete_term($id, 'product_cat');
        if ($r === true) {
            $n++;
        }
    }
    return $n;
}

function ml_kat_summary($label, array $res) {
    $s = $res['stats'];
    $unb = $res['unbekannt'];
    arsort($unb);
    $top = array_slice(array_keys($unb), 0, 5);
    return sprintf(
        '[ML KATEGORIEN] %s - %d Produkte geprueft, %d geaendert, %d mit Unterkategorie, %d nur Hauptkategorie, %d ohne Zielkategorie (Wege: Titel %d, Quelle %d, Standard %d, Ausnahme %d), %d Lieferantenkategorien ohne Eintrag%s.',
        $label, $s['geprueft'], $s['geaendert'], $s['unter'], $s['nur_haupt'], $s['ohne'], $s['titel'], $s['quelle'], $s['standard'], $s['ausnahme'], count($unb),
        $top ? ' (zum Beispiel: ' . implode('; ', $top) . '; Liste: wp ml-kategorien offen)' : ''
    );
}

/** Sweep nach einem Import. */
function ml_kat_sweep_hook($import_id) {
    static $done = array();
    if (isset($done[$import_id])) {
        return;
    }
    $done[$import_id] = true;
    try {
        $d = ml_kat_laden();
        foreach ($d['fehler'] as $e) {
            error_log('[ML KATEGORIEN] ' . $e);
        }
        if (!$d['map'] || !$d['baum']) {
            error_log("[ML KATEGORIEN] Import {$import_id} uebersprungen - keine Zuordnung geladen.");
            return;
        }
        $res = ml_kat_process(ml_kat_parent_ids((int) $import_id), array('defer_count' => true));
        if ($res === null) {
            error_log("[ML KATEGORIEN] Import {$import_id} abgebrochen - Zielkategorien konnten nicht angelegt werden.");
            return;
        }
        $n   = ml_kat_aufraeumen($d);
        $msg = ml_kat_summary("Import {$import_id} abgeschlossen", $res) . " {$n} leere Lieferantenbegriffe geloescht.";
        error_log($msg);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::log($msg);
        }
    } catch (\Throwable $e) {
        error_log('[ML KATEGORIEN] Fehler im Sweep: ' . $e->getMessage());
    }
}
add_action('pmxi_import_complete', 'ml_kat_sweep_hook', 30, 1);
add_action('pmxi_after_xml_import', 'ml_kat_sweep_hook', 30, 1);

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {

    /** WP-CLI: Kategorien. */
    class ML_Kategorien_CLI {

        /**
         * Setzt Zielkategorien und Kollektionen an allen Lieferanten-Produkten.
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Nur zaehlen, nichts aendern und nichts anlegen.
         *
         * [--limit=<n>]
         * : Nur die ersten N Produkte.
         *
         * [--bericht=<datei>]
         * : CSV mit Produkten, die nur eine Hauptkategorie oder keine Zielkategorie bekommen.
         */
        public function anwenden($args, $assoc) {
            $dry   = !empty($assoc['dry-run']);
            $limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 0;
            $d     = ml_kat_laden(true);
            foreach ($d['fehler'] as $e) {
                WP_CLI::warning($e);
            }
            if ($d['fehler']) {
                WP_CLI::error('Die Tabellen haben Fehler (siehe oben), es wurde nichts geaendert.');
            }
            if (!$d['map'] || !$d['baum']) {
                WP_CLI::error('Keine Zuordnung geladen.');
            }
            $parents = ml_kat_parent_ids(0);
            WP_CLI::log(count($parents) . ' Elternprodukte gefunden' . ($dry ? ' (Trockenlauf).' : '.'));
            $res = ml_kat_process($parents, array(
                'dry' => $dry, 'limit' => $limit,
                'progress' => function ($done, $total) {
                    WP_CLI::log("  {$done} / {$total}");
                },
            ));
            if ($res === null) {
                WP_CLI::error('Die Zielkategorien konnten nicht angelegt werden (siehe PHP-Log).');
            }
            $msg = ml_kat_summary($dry ? 'Trockenlauf' : 'Lauf', $res);
            if (!$dry) {
                $n    = ml_kat_aufraeumen($d);
                $msg .= " {$n} leere Lieferantenbegriffe geloescht.";
            }
            error_log($msg);
            WP_CLI::log($msg);
            if (!empty($assoc['bericht'])) {
                $fh = fopen($assoc['bericht'], 'w');
                if ($fh) {
                    fwrite($fh, "\xEF\xBB\xBF");
                    fputcsv($fh, array('id', 'sku', 'titel', 'weg', 'quellkategorien'), ';', '"', '\\');
                    foreach ($res['bericht'] as $row) {
                        fputcsv($fh, $row, ';', '"', '\\');
                    }
                    fclose($fh);
                    WP_CLI::log(count($res['bericht']) . ' Produkte im Bericht: ' . $assoc['bericht']);
                }
            }
            WP_CLI::success($dry ? 'Trockenlauf fertig, nichts geaendert.' : 'Fertig.');
        }

        /**
         * Listet Lieferantenkategorien mit Produkten, die in kategorien.csv fehlen (im Format der Tabelle).
         *
         * ## OPTIONS
         *
         * [--out=<datei>]
         * : Ausgabedatei (sonst Bildschirm).
         */
        public function offen($args, $assoc) {
            $d   = ml_kat_laden(true);
            $rows = array();
            foreach (ml_kat_begriffe() as $id => $b) {
                if ($b['count'] > 0 && !isset($d['map'][$b['pfad']]) && !isset($d['zielpfade'][$b['pfad']])) {
                    $rows[] = array($b['pfad'], 'offen', '', '', 'offen', $b['count'] . ' Produkte');
                }
            }
            usort($rows, function ($a, $b) {
                return strcmp($a[0], $b[0]);
            });
            $fh = isset($assoc['out']) ? fopen($assoc['out'], 'w') : fopen('php://output', 'w');
            if (isset($assoc['out'])) {
                fwrite($fh, "\xEF\xBB\xBF");
            }
            fputcsv($fh, array('pfad', 'aktion', 'zielkategorie', 'kollektion', 'stufe', 'hinweis'), ';', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($fh, $r, ';', '"', '\\');
            }
            fclose($fh);
            if (isset($assoc['out'])) {
                WP_CLI::success(count($rows) . ' Lieferantenkategorien ohne Eintrag, geschrieben nach ' . $assoc['out'] . '.');
            }
        }
    }
    WP_CLI::add_command('ml-kategorien', 'ML_Kategorien_CLI');
}
