<?php
/**
 * Geschlechtsfilter (11/2026)
 *
 * Setzt das Attribut pa_geschlecht ("Geschlecht") an den Elternprodukten: Herren, Damen, Unisex, Kinder. Das Attribut ist unsichtbar
 * (kein Eintrag im Tab "Zusaetzliche Informationen") und dient nur dem Filter. Die Filterleiste (media-lab-woocommerce) nimmt alle
 * pa_*-Attribute automatisch auf, das Plugin bleibt unveraendert.
 *
 * Entscheidung 16.11.2026 (Markus): Gibt es im Feed keinen Hinweis, bekommt das Produkt gar keinen Wert (kein "Unisex" als Auffuellwert).
 * "Kinder" ist ein eigener Wert.
 *
 * Regeln: data/geschlecht.csv (Semikolon, UTF-8, Spalten quelle, muster, wert, max_zeichen, hinweis). "quelle" ist eine oder mehrere
 * Quellen (mit Komma), "muster" ein regulaerer Ausdruck (ohne Trennzeichen, gross/klein egal, Unicode), "wert" Herren, Damen, Unisex
 * oder Kinder, "max_zeichen" die Laenge des durchsuchten Textanfangs (0 = alles).
 *
 * Quellen in dieser Reihenfolge, die erste mit eindeutigem Ergebnis gewinnt:
 *   hinweis       Zusatzfeld _ml_gender_hint am Produkt (Cotton Classics: Name2 deutsch aus der Style List, wenn der Adapter es liefert)
 *   titel         Produkttitel
 *   groesse       pa_size-Slugs (Makito: hom = Herren, muj = Damen; beide an einem Produkt = beide Werte)
 *   kategorie     Lieferantenkategorien (gemerkt in _ml_kat_quelle, sonst die aktuellen product_cat)
 *   beschreibung  Anfang der Beschreibung (nur Unisex im Ganzen, Herren und Damen nur ganz vorn)
 *
 * Treffer einer Quelle: genau ein Wert = dieser Wert. Herren und Damen zusammen = Unisex. Unisex mit einem weiteren Wert: Kinder bleibt
 * Kinder, sonst Unisex. Kinder zusammen mit Herren oder Damen ist ein Widerspruch, die Quelle zaehlt nicht und die naechste entscheidet.
 * Die Quelle groesse liefert bei hom und muj zusammen beide Werte (das Produkt gibt es fuer beide, z. B. Flip Flops).
 *
 * Ablauf:
 *   1. Nach jedem Import laeuft der Sweep (Hooks pmxi_import_complete / pmxi_after_xml_import, Prioritaet 25: nach Farben und Groessen,
 *      vor den Kategorien) und setzt pa_geschlecht an den Elternprodukten.
 *   2. wp ml-geschlecht anwenden [--dry-run] [--limit=N] setzt den Wert an allen Produkten neu (nach Aenderungen an der Tabelle).
 *   3. wp ml-geschlecht bericht --out=<datei> schreibt je Produkt SKU, Titel, Wert und Quelle (zum Pruefen der Regeln).
 *
 * Idempotent. Aendert nur pa_geschlecht. Bei fehlender oder leerer Tabelle passiert nichts.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Werte in der Reihenfolge im Filter: Name => Slug. */
function ml_ge_values() {
    return array(
        'Herren' => 'herren',
        'Damen'  => 'damen',
        'Unisex' => 'unisex',
        'Kinder' => 'kinder',
    );
}

/** Quellen in der Reihenfolge der Auswertung. */
function ml_ge_sources() {
    return array('hinweis', 'titel', 'groesse', 'kategorie', 'beschreibung');
}

/** Pfad der Regeltabelle. */
function ml_ge_csv_path() {
    return dirname(__DIR__) . '/data/geschlecht.csv';
}

/**
 * Regeln laden: ['rules' => [quelle => [[muster, wert, max], ...]], 'errors' => [...], 'file' => pfad].
 */
function ml_ge_rules($reload = false) {
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = array('rules' => array(), 'errors' => array(), 'file' => ml_ge_csv_path());
    if (!is_readable($cache['file'])) {
        $cache['errors'][] = 'Regeltabelle nicht lesbar: ' . $cache['file'];
        return $cache;
    }
    $h = fopen($cache['file'], 'r');
    if (!$h) {
        $cache['errors'][] = 'Regeltabelle nicht zu oeffnen: ' . $cache['file'];
        return $cache;
    }
    $header = fgetcsv($h, 0, ';', '"', '\\');
    if (!is_array($header)) {
        fclose($h);
        $cache['errors'][] = 'Regeltabelle ist leer';
        return $cache;
    }
    $header[0] = preg_replace('~^\xEF\xBB\xBF~', '', (string) $header[0]);
    $header    = array_map(function ($c) {
        return strtolower(trim((string) $c));
    }, $header);
    $iQ = array_search('quelle', $header, true);
    $iM = array_search('muster', $header, true);
    $iW = array_search('wert', $header, true);
    $iL = array_search('max_zeichen', $header, true);
    if ($iQ === false || $iM === false || $iW === false) {
        fclose($h);
        $cache['errors'][] = 'Regeltabelle: Spalten quelle, muster und wert fehlen';
        return $cache;
    }
    $values  = ml_ge_values();
    $sources = ml_ge_sources();
    $line    = 1;
    while (($row = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
        $line++;
        $pattern = isset($row[$iM]) ? trim((string) $row[$iM]) : '';
        if ($pattern === '' || $pattern[0] === '#') {
            continue;
        }
        $wert = isset($row[$iW]) ? trim((string) $row[$iW]) : '';
        if (!isset($values[$wert])) {
            $cache['errors'][] = "Regeltabelle Zeile {$line}: unbekannter Wert '{$wert}'";
            continue;
        }
        $regex = '~' . str_replace('~', '\\~', $pattern) . '~iu';
        if (@preg_match($regex, '') === false) {
            $cache['errors'][] = "Regeltabelle Zeile {$line}: ungueltiges Muster '{$pattern}'";
            continue;
        }
        $max = ($iL !== false && isset($row[$iL])) ? max(0, (int) $row[$iL]) : 0;
        foreach (explode(',', (string) $row[$iQ]) as $q) {
            $q = strtolower(trim($q));
            if (!in_array($q, $sources, true)) {
                $cache['errors'][] = "Regeltabelle Zeile {$line}: unbekannte Quelle '{$q}'";
                continue;
            }
            $cache['rules'][$q][] = array($regex, $wert, $max);
        }
    }
    fclose($h);
    return $cache;
}

/**
 * Treffer einer Quelle in Werte umsetzen. Rueckgabe: Liste der Werte, oder null bei Widerspruch (Kinder mit Herren/Damen).
 * Leere Liste = kein Treffer.
 */
function ml_ge_resolve(array $hits, $source) {
    $hits = array_values(array_unique($hits));
    if (count($hits) <= 1) {
        return $hits;
    }
    $has = array_flip($hits);
    if ($source === 'groesse') {
        return array_values(array_intersect(array('Herren', 'Damen', 'Unisex', 'Kinder'), $hits));   // das Produkt gibt es fuer beide
    }
    if (isset($has['Kinder'])) {
        return (isset($has['Herren']) || isset($has['Damen'])) ? null : array('Kinder');   // Kinder + Unisex = Kinder
    }
    return array('Unisex');   // Herren + Damen (+ Unisex), Unisex + Herren oder Damen
}

/**
 * Wert eines Produkts bestimmen (ohne WordPress, damit testbar).
 *
 * @param array $in   ['hinweis' => text, 'titel' => text, 'groesse' => [slugs], 'kategorie' => [pfade], 'beschreibung' => text]
 * @param array $rules Ergebnis von ml_ge_rules()['rules']
 * @return array ['werte' => [...], 'quelle' => name oder '', 'konflikte' => [quellen]]
 */
function ml_ge_detect(array $in, array $rules) {
    $conflicts = array();
    foreach (ml_ge_sources() as $src) {
        if (empty($rules[$src])) {
            continue;
        }
        $texts = isset($in[$src]) ? (array) $in[$src] : array();
        $hits  = array();
        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            foreach ($rules[$src] as $r) {
                $t = ($r[2] > 0 && mb_strlen($text) > $r[2]) ? mb_substr($text, 0, $r[2]) : $text;
                if (preg_match($r[0], $t)) {
                    $hits[] = $r[1];
                }
            }
        }
        if (!$hits) {
            continue;
        }
        $res = ml_ge_resolve($hits, $src);
        if ($res === null) {
            $conflicts[] = $src;
            continue;
        }
        return array('werte' => $res, 'quelle' => $src, 'konflikte' => $conflicts);
    }
    return array('werte' => array(), 'quelle' => '', 'konflikte' => $conflicts);
}

/** Slug eines Werts. */
function ml_ge_term_slug($value) {
    $v = ml_ge_values();
    return isset($v[$value]) ? $v[$value] : sanitize_title((string) $value);
}

/**
 * Attribut "Geschlecht" und die vier Werte sicherstellen. Rueckgabe: [slug => term_id] oder false.
 */
function ml_ge_ensure($mode = '') {
    static $ids = null;
    if ($mode === 'reset') {   // nur fuer Tests
        $ids = null;
        return null;
    }
    if ($ids !== null) {
        return $ids;
    }
    if (!taxonomy_exists('pa_geschlecht')) {
        if (!function_exists('wc_create_attribute') || !function_exists('wc_attribute_taxonomy_id_by_name')) {
            error_log('[ML GESCHLECHT] WooCommerce nicht geladen, Attribut kann nicht angelegt werden.');
            return false;
        }
        if (!wc_attribute_taxonomy_id_by_name('geschlecht')) {
            $created = wc_create_attribute(array(
                'name'         => 'Geschlecht',
                'slug'         => 'geschlecht',
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ));
            if (is_wp_error($created)) {
                error_log('[ML GESCHLECHT] Attribut konnte nicht angelegt werden: ' . $created->get_error_message());
                return false;
            }
            delete_transient('wc_attribute_taxonomies');
        }
        if (!taxonomy_exists('pa_geschlecht')) {
            register_taxonomy('pa_geschlecht', apply_filters('woocommerce_taxonomy_objects_pa_geschlecht', array('product')), array(
                'labels'       => array('name' => 'Geschlecht'),
                'hierarchical' => false,
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
                'public'       => false,
            ));
        }
    }
    $ids   = array();
    $order = 0;
    foreach (ml_ge_values() as $name => $slug) {
        $order++;
        $term = get_term_by('slug', $slug, 'pa_geschlecht');
        if ($term) {
            $id = (int) $term->term_id;
        } else {
            $r = wp_insert_term($name, 'pa_geschlecht', array('slug' => $slug));
            if (is_wp_error($r)) {
                error_log('[ML GESCHLECHT] Begriff ' . $name . ' konnte nicht angelegt werden: ' . $r->get_error_message());
                $ids = null;
                return false;
            }
            $id = (int) $r['term_id'];
        }
        $ids[$slug] = $id;
        update_term_meta($id, 'mlwf_order', $order);
        update_term_meta($id, 'order_pa_geschlecht', $order);
    }
    return $ids;
}

/**
 * Eingaben eines Produkts aus WordPress sammeln (fuer ml_ge_detect).
 */
function ml_ge_inputs($pid) {
    $titel = (string) get_post_field('post_title', $pid);

    $pfade = array();
    $mq    = json_decode((string) get_post_meta($pid, '_ml_kat_quelle', true), true);
    if (is_array($mq) && $mq) {
        $pfade = $mq;   // Lieferantenkategorien aus dem Kategorien-Sweep (die aktuellen sind schon Zielkategorien)
    } else {
        $cur = get_the_terms($pid, 'product_cat');
        if (is_array($cur)) {
            foreach ($cur as $t) {
                $pfade[] = html_entity_decode((string) $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }

    $sizes = array();
    $st    = get_the_terms($pid, 'pa_size');
    if (is_array($st)) {
        foreach ($st as $t) {
            $sizes[] = $t->slug;
        }
    }

    $desc = (string) get_post_field('post_content', $pid);
    if (trim($desc) === '') {
        $desc = (string) get_post_field('post_excerpt', $pid);
    }
    $desc = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($desc)));
    if (mb_strlen($desc) > 600) {
        $desc = mb_substr($desc, 0, 600);
    }

    return array(
        'hinweis'      => (string) get_post_meta($pid, '_ml_gender_hint', true),
        'titel'        => $titel,
        'groesse'      => $sizes,
        'kategorie'    => array_values(array_map('strval', $pfade)),
        'beschreibung' => $desc,
    );
}

/** Elternprodukte: die des abgeschlossenen Imports, sonst alle Lieferanten-Sync-Produkte. */
function ml_ge_parent_ids($import_id = 0) {
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
 * pa_geschlecht an Elternprodukten setzen.
 * Optionen: dry (nur zaehlen), limit, defer_count, progress (Callback), report (Callback je Produkt: pid, werte, quelle).
 * Rueckgabe: ['stats' => [...]] oder null, wenn die Werte nicht angelegt werden konnten.
 */
function ml_ge_process(array $parents, array $opt = array()) {
    global $wpdb;
    $dry      = !empty($opt['dry']);
    $limit    = isset($opt['limit']) ? (int) $opt['limit'] : 0;
    $progress = isset($opt['progress']) ? $opt['progress'] : null;
    $report   = isset($opt['report']) ? $opt['report'] : null;
    $rules    = ml_ge_rules();
    $stats    = array(
        'geprueft' => 0, 'geaendert' => 0, 'ohne_wert' => 0, 'konflikt' => 0,
        'werte' => array('Herren' => 0, 'Damen' => 0, 'Unisex' => 0, 'Kinder' => 0),
        'quellen' => array(),
        'lieferanten' => array(),
    );
    $termIds = array();

    if (!$dry) {
        $termIds = ml_ge_ensure();
        if (!$termIds) {
            return null;
        }
    }
    if ($limit > 0) {
        $parents = array_slice($parents, 0, $limit);
    }
    $total = count($parents);
    $done  = 0;
    if (!$dry && !empty($opt['defer_count'])) {
        wp_defer_term_counting(true);
    }

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
            $det    = ml_ge_detect(ml_ge_inputs($pid), $rules['rules']);
            $werte  = $det['werte'];
            $lief   = strtoupper((string) get_post_meta($pid, '_ml_supplier_code', true));
            if ($det['konflikte']) {
                $stats['konflikt']++;
            }
            if (!isset($stats['lieferanten'][$lief])) {
                $stats['lieferanten'][$lief] = array('gesamt' => 0, 'mit_wert' => 0);
            }
            $stats['lieferanten'][$lief]['gesamt']++;
            if ($werte) {
                $stats['lieferanten'][$lief]['mit_wert']++;
                $stats['quellen'][$det['quelle']] = (isset($stats['quellen'][$det['quelle']]) ? $stats['quellen'][$det['quelle']] : 0) + 1;
                foreach ($werte as $w) {
                    $stats['werte'][$w]++;
                }
            } else {
                $stats['ohne_wert']++;
            }
            if (is_callable($report)) {
                call_user_func($report, $pid, $werte, $det['quelle'], $det['konflikte']);
            }

            $want = array();
            foreach ($werte as $w) {
                $want[] = ml_ge_term_slug($w);
            }
            sort($want);
            $have = array();
            $cur  = get_the_terms($pid, 'pa_geschlecht');
            if (is_array($cur)) {
                foreach ($cur as $t) {
                    $have[] = $t->slug;
                }
            }
            sort($have);
            $attrs    = get_post_meta($pid, '_product_attributes', true);
            $attrs    = is_array($attrs) ? $attrs : array();
            $hasAttr  = isset($attrs['pa_geschlecht']);
            $needAttr = $want && !$hasAttr;
            $dropAttr = !$want && $hasAttr;
            if ($have === $want && !$needAttr && !$dropAttr) {
                continue;
            }
            $stats['geaendert']++;
            if ($dry) {
                continue;
            }
            $ids = array();
            foreach ($want as $slug) {
                if (isset($termIds[$slug])) {
                    $ids[] = $termIds[$slug];
                }
            }
            wp_set_object_terms($pid, $ids, 'pa_geschlecht');   // leere Liste entfernt alte Werte
            if ($needAttr) {
                $attrs['pa_geschlecht'] = array(
                    'name'         => 'pa_geschlecht',
                    'value'        => '',
                    'position'     => count($attrs),
                    'is_visible'   => 0,
                    'is_variation' => 0,
                    'is_taxonomy'  => 1,
                );
                update_post_meta($pid, '_product_attributes', $attrs);
            } elseif ($dropAttr) {
                unset($attrs['pa_geschlecht']);
                update_post_meta($pid, '_product_attributes', $attrs);
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
        }
        if (is_callable($progress)) {
            call_user_func($progress, $done, $total);
        }
    }
    if (!$dry && !empty($opt['defer_count'])) {
        wp_defer_term_counting(false);
    }
    return array('stats' => $stats);
}

/** Protokollzeile fuer Sweep und Befehl. */
function ml_ge_summary($label, array $res) {
    $s  = $res['stats'];
    $lf = array();
    foreach ($s['lieferanten'] as $code => $c) {
        $lf[] = $code . ' ' . $c['mit_wert'] . '/' . $c['gesamt'];
    }
    $qu = array();
    foreach ($s['quellen'] as $q => $n) {
        $qu[] = $q . ' ' . $n;
    }
    return sprintf(
        '[ML GESCHLECHT] %s - %d Eltern geprueft, %d geaendert, Herren %d, Damen %d, Unisex %d, Kinder %d, ohne Wert %d, mit Widerspruch in einer Quelle %d (je Lieferant mit Wert: %s; Quellen: %s).',
        $label,
        $s['geprueft'],
        $s['geaendert'],
        $s['werte']['Herren'],
        $s['werte']['Damen'],
        $s['werte']['Unisex'],
        $s['werte']['Kinder'],
        $s['ohne_wert'],
        $s['konflikt'],
        $lf ? implode(', ', $lf) : '-',
        $qu ? implode(', ', $qu) : '-'
    );
}

/** Sweep nach einem Import. */
function ml_ge_sweep_hook($import_id) {
    static $done = array();
    if (isset($done[$import_id])) {
        return;   // beide Hooks koennen feuern, nur einmal je Import
    }
    $done[$import_id] = true;
    try {
        $m = ml_ge_rules();
        foreach ($m['errors'] as $e) {
            error_log('[ML GESCHLECHT] ' . $e);
        }
        if (!$m['rules']) {
            error_log("[ML GESCHLECHT] Import {$import_id} uebersprungen - keine Regeln geladen.");
            return;
        }
        $res = ml_ge_process(ml_ge_parent_ids((int) $import_id));
        if ($res === null) {
            error_log("[ML GESCHLECHT] Import {$import_id} abgebrochen - Werte konnten nicht angelegt werden.");
            return;
        }
        $msg = ml_ge_summary("Import {$import_id} abgeschlossen", $res);
        error_log($msg);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::log($msg);
        }
    } catch (\Throwable $e) {
        error_log('[ML GESCHLECHT] Fehler im Sweep: ' . $e->getMessage());
    }
}
add_action('pmxi_import_complete', 'ml_ge_sweep_hook', 25, 1);
add_action('pmxi_after_xml_import', 'ml_ge_sweep_hook', 25, 1);

/** Gesamtreihenfolge eines Werts (Herren, Damen, Unisex, Kinder); unbekannte ans Ende. */
function ml_ge_total_order($slug) {
    $i = array_search($slug, array_values(ml_ge_values()), true);
    return $i === false ? 1000000 : $i + 1;
}

/** Filterleiste laedt die Werte nach Name: hier in die feste Reihenfolge bringen. Nur im Frontend. */
function ml_ge_sort_terms($terms, $taxonomies = array()) {
    if (is_admin() || !is_array($terms) || !$terms || (array) $taxonomies !== array('pa_geschlecht')) {
        return $terms;
    }
    if (!is_object(reset($terms))) {
        return $terms;   // fields != all
    }
    usort($terms, function ($a, $b) {
        return ml_ge_total_order($a->slug) <=> ml_ge_total_order($b->slug);
    });
    return $terms;
}
add_filter('get_terms', 'ml_ge_sort_terms', 10, 2);

/** Suchergebnisseite: dieselbe Reihenfolge fuer die Facette pa_geschlecht. */
function ml_ge_sort_facets($facets) {
    if (!is_array($facets) || empty($facets['pa_geschlecht']['terms']) || !is_array($facets['pa_geschlecht']['terms'])) {
        return $facets;
    }
    usort($facets['pa_geschlecht']['terms'], function ($a, $b) {
        return ml_ge_total_order(isset($a['slug']) ? $a['slug'] : '') <=> ml_ge_total_order(isset($b['slug']) ? $b['slug'] : '');
    });
    return $facets;
}
add_filter('mlwf_search_facets', 'ml_ge_sort_facets', 11, 1);

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {

    /** WP-CLI: Geschlechtsfilter. */
    class ML_Geschlecht_CLI {

        /**
         * Setzt pa_geschlecht an allen Lieferanten-Elternprodukten (nach Aenderungen an der Tabelle).
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Nur zaehlen, nichts aendern.
         *
         * [--limit=<n>]
         * : Nur die ersten N Produkte.
         */
        public function anwenden($args, $assoc) {
            $dry   = !empty($assoc['dry-run']);
            $limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 0;
            $m     = ml_ge_rules(true);
            foreach ($m['errors'] as $e) {
                WP_CLI::warning($e);
            }
            if (!$m['rules']) {
                WP_CLI::error('Keine Regeln geladen: ' . $m['file']);
            }
            $parents = ml_ge_parent_ids(0);
            WP_CLI::log(count($parents) . ' Elternprodukte gefunden' . ($dry ? ' (Trockenlauf).' : '.'));
            $res = ml_ge_process($parents, array(
                'dry'         => $dry,
                'limit'       => $limit,
                'defer_count' => true,
                'progress'    => function ($done, $total) {
                    WP_CLI::log("  {$done} / {$total}");
                },
            ));
            if ($res === null) {
                WP_CLI::error('Die Werte konnten nicht angelegt werden (siehe PHP-Log).');
            }
            $msg = ml_ge_summary($dry ? 'Trockenlauf' : 'Lauf', $res);
            error_log($msg);
            WP_CLI::log($msg);
            WP_CLI::success($dry ? 'Trockenlauf fertig, nichts geaendert.' : 'Fertig.');
        }

        /**
         * Schreibt je Produkt SKU, Lieferant, Titel, Wert und Quelle (nichts wird geaendert), zum Pruefen der Regeln.
         *
         * ## OPTIONS
         *
         * --out=<datei>
         * : Ausgabedatei (CSV, Semikolon).
         */
        public function bericht($args, $assoc) {
            if (empty($assoc['out'])) {
                WP_CLI::error('--out=<datei> fehlt.');
            }
            $m = ml_ge_rules(true);
            foreach ($m['errors'] as $e) {
                WP_CLI::warning($e);
            }
            if (!$m['rules']) {
                WP_CLI::error('Keine Regeln geladen: ' . $m['file']);
            }
            $fh = fopen($assoc['out'], 'w');
            if (!$fh) {
                WP_CLI::error('Ausgabedatei nicht zu oeffnen.');
            }
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, array('sku', 'lieferant', 'titel', 'wert', 'quelle', 'widerspruch'), ';', '"', '\\');
            $res = ml_ge_process(ml_ge_parent_ids(0), array(
                'dry'    => true,
                'report' => function ($pid, $werte, $quelle, $konflikte) use ($fh) {
                    fputcsv($fh, array(
                        (string) get_post_meta($pid, '_sku', true),
                        (string) get_post_meta($pid, '_ml_supplier_code', true),
                        (string) get_post_field('post_title', $pid),
                        implode(' | ', $werte),
                        $quelle,
                        implode(',', $konflikte),
                    ), ';', '"', '\\');
                },
            ));
            fclose($fh);
            WP_CLI::log(ml_ge_summary('Bericht', $res));
            WP_CLI::success('Geschrieben nach ' . $assoc['out'] . '.');
        }
    }
    WP_CLI::add_command('ml-geschlecht', 'ML_Geschlecht_CLI');
}
