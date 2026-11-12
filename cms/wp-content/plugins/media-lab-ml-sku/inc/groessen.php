<?php
/**
 * Groessenfilter (11/2026)
 *
 * Ordnet die Lieferantengroessen (Attribut pa_size, rund 290 Begriffe, Schreibweisen gemischt: xxl, XXL, 3XL, xxxl, 10_S, 7-8, 104,
 * 35-38 ...) einer Liste normalisierter Werte in Gruppen zu (Attribut pa_groesse, "Groesse"). Die Originalgroesse bleibt an Produkt
 * und Variante, pa_groesse dient nur dem Filter und ist unsichtbar (kein Eintrag im Tab "Zusaetzliche Informationen").
 *
 * Gruppen (Reihenfolge im Filter): Kleidergroessen, Zahlengroessen (Konfektion, Schuhe), Kinder Koerpergroesse in cm, Kinder Alter in
 * Jahren, Baby Alter in Monaten, Schuh- und Sockengroessen, Weitere Groessen. Jeder Wert ist ein Begriff von pa_groesse mit den
 * Term-Metafeldern mlwf_group (Gruppenname), mlwf_group_order (Nummer der Gruppe) und mlwf_order (Reihenfolge in der Gruppe, natuerlich:
 * XS, S, M ... 8XL, Zahlen aufsteigend). Die Gruppen mit Ueberschrift bildet das Theme (assets/src/js/components/mlwf-term-groups.js) aus
 * einem JSON in der Seite (ml_gr_print_term_groups); das Plugin media-lab-woocommerce bleibt unveraendert. Die Reihenfolge der Werte
 * setzen zwei Filter (get_terms, mlwf_search_facets) auch ohne JavaScript.
 *
 * Zuordnungstabelle: data/groessen.csv (Semikolon, UTF-8, Spalten slug, name, produkte, gruppe, werte, hinweis). "slug" ist der Slug
 * des pa_size-Begriffs, "gruppe" der Name der Gruppe, "werte" ein oder mehrere Werte, getrennt durch "|" (eine Doppelgroesse wie
 * S/M zaehlt fuer S und M). Eine Zeile ohne Gruppe gilt als bekannt, aber offen. Groessen, die fehlen oder offen sind, werden nicht
 * zugeordnet und im Protokoll gemeldet.
 *
 * Ablauf (die Schleife):
 *   1. Nach jedem Import laeuft der Sweep (Hooks pmxi_import_complete / pmxi_after_xml_import, Prioritaet 20) und setzt pa_groesse
 *      an den Elternprodukten.
 *   2. wp ml-groessen offen --out="$HOME/Desktop/groessen-offen.csv" listet Groessen ohne Zuordnung, mit einem automatischen
 *      Vorschlag (Regeln nach der Form des Namens, Spalte hinweis). Nach Durchsicht die Zeilen in data/groessen.csv uebernehmen.
 *   3. wp ml-groessen anwenden [--dry-run] [--limit=N] setzt pa_groesse an allen Produkten neu.
 *
 * Idempotent. Aendert nur pa_groesse, nie pa_size. Bei fehlender oder leerer Tabelle passiert nichts.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Gruppen in der Reihenfolge im Filter: Slug => Name. */
function ml_gr_groups() {
    return array(
        'kleidergroessen' => 'Kleidergrößen',
        'zahlengroessen'  => 'Zahlengrößen (Konfektion, Schuhe)',
        'kinder-cm'       => 'Kinder, Körpergröße in cm',
        'kinder-jahre'    => 'Kinder, Alter in Jahren',
        'baby-monate'     => 'Baby, Alter in Monaten',
        'schuhe-socken'   => 'Schuh- und Sockengrößen',
        'weitere'         => 'Weitere Größen',
    );
}

/** Pfad der Zuordnungstabelle. */
function ml_gr_csv_path() {
    return dirname(__DIR__) . '/data/groessen.csv';
}

/** Buchstabengroessen in natuerlicher Reihenfolge. */
function ml_gr_letters() {
    return array('3XS', 'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL', '6XL', '7XL', '8XL');
}

/** Gruppenname oder -slug zum Slug der Gruppe, sonst null. */
function ml_gr_group_slug($label) {
    static $lookup = null;
    if ($lookup === null) {
        $lookup = array();
        foreach (ml_gr_groups() as $slug => $name) {
            $lookup[ml_gr_norm($name)] = $slug;
            $lookup[ml_gr_norm($slug)] = $slug;
        }
    }
    $k = ml_gr_norm($label);
    return isset($lookup[$k]) ? $lookup[$k] : null;
}

function ml_gr_norm($s) {
    $s = trim((string) $s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Buchstabengroesse zu einem Kuerzel (xxl, 2xl, xxxl, 3xl ...), sonst null. */
function ml_gr_letter($tok) {
    $t    = strtolower(trim((string) $tok));
    $base = array('3xs' => '3XS', '2xs' => 'XXS', 'xxs' => 'XXS', 'xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL', 'xxl' => 'XXL', '2xl' => 'XXL', 'xxxl' => '3XL');
    if (isset($base[$t])) {
        return $base[$t];
    }
    if (preg_match('~^(\d)xl$~', $t, $m) && (int) $m[1] >= 3 && (int) $m[1] <= 8) {
        return $m[1] . 'XL';
    }
    return null;
}

/** Schuh-Zusatz bereinigen: Klammern weg, Punkt zu Komma, " 3/4" zu ",75". */
function ml_gr_eu_clean($s) {
    $s = trim((string) $s);
    $s = trim($s, '()');
    $s = str_replace('.', ',', $s);
    return preg_replace('~\s*3/4$~', ',75', $s);
}

/**
 * Automatischer Vorschlag fuer einen Groessennamen nach der Form: Liste von [gruppenslug, wert] (leer = nicht erkannt).
 * Nur ein Vorschlag fuer die Durchsicht, wird nie ungeprueft angewendet.
 */
function ml_gr_vorschlag($name) {
    $n   = trim((string) $name);
    $out = array();
    $l   = ml_gr_letter($n);
    if ($l !== null) {
        return array(array('kleidergroessen', $l));
    }
    if (preg_match('~^([a-z0-9]+)/([a-z0-9]+)$~i', $n, $m) && ml_gr_letter($m[1]) !== null && ml_gr_letter($m[2]) !== null) {
        $a = ml_gr_letter($m[1]);
        $b = ml_gr_letter($m[2]);
        $out[] = array('kleidergroessen', $a);
        if ($b !== $a) {
            $out[] = array('kleidergroessen', $b);
        }
        return $out;
    }
    if (preg_match('~^\d+[_/]([a-z0-9]+)$~i', $n, $m) && ml_gr_letter($m[1]) !== null) {
        return array(array('kleidergroessen', ml_gr_letter($m[1])));
    }
    if (preg_match('~^\d{2,3}$~', $n)) {
        $v = (int) $n;
        if ($v >= 30 && $v <= 64) {
            return array(array('zahlengroessen', (string) $v));
        }
        if ($v >= 65 && $v <= 176) {
            return array(array('kinder-cm', (string) $v));
        }
    }
    if (preg_match('~^(\d{2,3})\s*[-/]\s*(\d{2,3})$~', $n, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        if ($a >= 68 && $a <= 176 && $b > $a && $b <= 176 && $b - $a <= 20) {
            return array(array('kinder-cm', $a . '-' . $b));
        }
        if ($a >= 27 && $a <= 50 && $b > $a && $b <= 52 && $b - $a <= 6) {
            return array(array('schuhe-socken', $a . '-' . $b));
        }
    }
    if (preg_match('~^(\d{2})[,.]5$~', $n, $m) && (int) $m[1] >= 30 && (int) $m[1] <= 55) {
        return array(array('schuhe-socken', $m[1] . ',5'));
    }
    if (preg_match('~^(\d{1,2})-(\d{1,2})$~', $n, $m)) {
        if (in_array($n, array('0-3', '3-6', '6-12', '12-18', '18-24', '18-23'), true)) {
            return array(array('baby-monate', $n . ' Monate'));
        }
        $a = (int) $m[1];
        $b = (int) $m[2];
        if ($a >= 1 && $a < $b && $b <= 16) {
            return array(array('kinder-jahre', $a . '-' . $b));
        }
    }
    if (preg_match('~^(\d{1,2})\s?Y$~i', $n, $m)) {
        return array(array('kinder-jahre', (string) (int) $m[1]));
    }
    if (preg_match('~^(\d{1,2})\s?M$~', $n, $m)) {
        return array(array('baby-monate', (int) $m[1] . ' Monate'));
    }
    if (preg_match('~^[\d,\.]+_\(?([\d,\.]+(?: 3/4)?)\)?$~', $n, $m)) {
        $eu = ml_gr_eu_clean($m[1]);
        $v  = (float) str_replace(',', '.', $eu);
        if ($v >= 30 && $v <= 55) {
            if (floor($v) == $v) {
                return array(array('zahlengroessen', (string) (int) $v));
            }
            return array(array('schuhe-socken', $eu));
        }
    }
    return array();
}

/**
 * Zuordnungstabelle laden: ['map' => [groessenslug => [[gruppenslug, wert], ...]], 'errors' => [...], 'file' => pfad].
 * Eine Groesse mit leerer Liste ist bekannt, aber noch offen.
 */
function ml_gr_map($reload = false) {
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = array('map' => array(), 'errors' => array(), 'file' => ml_gr_csv_path());
    if (!is_readable($cache['file'])) {
        $cache['errors'][] = 'Zuordnungstabelle nicht lesbar: ' . $cache['file'];
        return $cache;
    }
    $h = fopen($cache['file'], 'r');
    if (!$h) {
        $cache['errors'][] = 'Zuordnungstabelle nicht zu oeffnen: ' . $cache['file'];
        return $cache;
    }
    $header = fgetcsv($h, 0, ';', '"', '\\');
    if (!is_array($header)) {
        fclose($h);
        $cache['errors'][] = 'Zuordnungstabelle ist leer';
        return $cache;
    }
    $header[0] = preg_replace('~^\xEF\xBB\xBF~', '', (string) $header[0]);
    $header    = array_map(function ($c) {
        return strtolower(trim((string) $c));
    }, $header);
    $iSlug = array_search('slug', $header, true);
    $iGr   = array_search('gruppe', $header, true);
    $iVal  = array_search('werte', $header, true);
    if ($iSlug === false || $iGr === false || $iVal === false) {
        fclose($h);
        $cache['errors'][] = 'Zuordnungstabelle: Spalten slug, gruppe und werte fehlen';
        return $cache;
    }
    $line = 1;
    while (($row = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
        $line++;
        $slug = isset($row[$iSlug]) ? trim((string) $row[$iSlug]) : '';
        if ($slug === '') {
            continue;
        }
        $gr   = isset($row[$iGr]) ? trim((string) $row[$iGr]) : '';
        $raw  = isset($row[$iVal]) ? trim((string) $row[$iVal]) : '';
        $list = array();
        if ($gr !== '' && $raw !== '') {
            $gs = ml_gr_group_slug($gr);
            if ($gs === null) {
                $cache['errors'][] = "Zuordnungstabelle Zeile {$line}: unbekannte Gruppe '{$gr}' bei '{$slug}'";
            } else {
                foreach (explode('|', $raw) as $v) {
                    $v = trim($v);
                    if ($v !== '') {
                        $list[$gs . "\0" . $v] = array($gs, $v);
                    }
                }
            }
        }
        $cache['map'][$slug] = array_values($list);
    }
    fclose($h);
    return $cache;
}

/** Sortierschluessel eines Werts in seiner Gruppe: Buchstaben nach Liste, sonst erste Zahl (Bereiche nach der Einzelzahl). */
function ml_gr_sortkey($group, $value) {
    if ($group === 'kleidergroessen') {
        $i = array_search($value, ml_gr_letters(), true);
        return $i === false ? 1000000.0 : (float) $i;
    }
    if (preg_match('~(\d+(?:,\d+)?)(?:-(\d+))?~', (string) $value, $m)) {
        $a = (float) str_replace(',', '.', $m[1]);
        $b = isset($m[2]) ? (float) $m[2] : 0.0;
        return $a * 1000 + $b;
    }
    return 1000000000.0;
}

/**
 * Alle Werte der Tabelle als Liste in Filter-Reihenfolge: [['group' => slug, 'value' => wert, 'group_order' => n, 'order' => rang], ...].
 */
function ml_gr_values(array $map) {
    $perGroup = array();
    foreach ($map as $list) {
        foreach ($list as $pair) {
            $perGroup[$pair[0]][$pair[1]] = true;
        }
    }
    $out = array();
    $gi  = 0;
    foreach (array_keys(ml_gr_groups()) as $gs) {
        $gi++;
        if (empty($perGroup[$gs])) {
            continue;
        }
        $values = array_keys($perGroup[$gs]);
        usort($values, function ($a, $b) use ($gs) {
            $ka = ml_gr_sortkey($gs, (string) $a);
            $kb = ml_gr_sortkey($gs, (string) $b);
            if ($ka !== $kb) {
                return $ka < $kb ? -1 : 1;
            }
            return strnatcasecmp((string) $a, (string) $b);
        });
        $rank = 0;
        foreach ($values as $v) {
            $rank++;
            $out[] = array('group' => $gs, 'value' => (string) $v, 'group_order' => $gi, 'order' => $rank);
        }
    }
    return $out;
}

/** Slug eines Werts: Gruppenslug + Wert (der gleiche Wert in zwei Gruppen kollidiert so nicht). */
function ml_gr_term_slug($group, $value) {
    return $group . '-' . sanitize_title((string) $value);
}

/** Groessen der Eltern in Werte (Slugs von pa_groesse) umsetzen. Unbekannte Groessen in $unmapped zaehlen (slug => Anzahl). */
function ml_gr_values_for(array $sizeSlugs, array $map, array &$unmapped) {
    $found = array();
    foreach ($sizeSlugs as $ss) {
        if (!empty($map[$ss])) {
            foreach ($map[$ss] as $pair) {
                $found[ml_gr_term_slug($pair[0], $pair[1])] = true;
            }
        } else {
            $unmapped[$ss] = (isset($unmapped[$ss]) ? $unmapped[$ss] : 0) + 1;
        }
    }
    return array_keys($found);
}

/**
 * Attribut "Groesse" und alle Werte sicherstellen, mit Term-Metafeldern. Rueckgabe: [termslug => term_id] oder false.
 */
function ml_gr_ensure($mode = '') {
    static $ids = null;
    if ($mode === 'reset') {   // nur fuer Tests: Zwischenspeicher leeren
        $ids = null;
        return null;
    }
    if ($ids !== null) {
        return $ids;
    }
    if (!taxonomy_exists('pa_groesse')) {
        if (!function_exists('wc_create_attribute') || !function_exists('wc_attribute_taxonomy_id_by_name')) {
            error_log('[ML GROESSEN] WooCommerce nicht geladen, Attribut kann nicht angelegt werden.');
            return false;
        }
        if (!wc_attribute_taxonomy_id_by_name('groesse')) {
            $created = wc_create_attribute(array(
                'name'         => 'Größe',
                'slug'         => 'groesse',
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ));
            if (is_wp_error($created)) {
                error_log('[ML GROESSEN] Attribut konnte nicht angelegt werden: ' . $created->get_error_message());
                return false;
            }
            delete_transient('wc_attribute_taxonomies');
        }
        if (!taxonomy_exists('pa_groesse')) {
            register_taxonomy('pa_groesse', apply_filters('woocommerce_taxonomy_objects_pa_groesse', array('product')), array(
                'labels'       => array('name' => 'Größe'),
                'hierarchical' => false,
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
                'public'       => false,
            ));
        }
    }
    $m      = ml_gr_map();
    $groups = ml_gr_groups();
    $ids    = array();
    foreach (ml_gr_values($m['map']) as $v) {
        $slug = ml_gr_term_slug($v['group'], $v['value']);
        $term = get_term_by('slug', $slug, 'pa_groesse');
        if ($term) {
            $id = (int) $term->term_id;
        } else {
            $r = wp_insert_term($v['value'], 'pa_groesse', array('slug' => $slug));
            if (is_wp_error($r)) {
                error_log('[ML GROESSEN] Begriff ' . $v['value'] . ' konnte nicht angelegt werden: ' . $r->get_error_message());
                $ids = null;
                return false;
            }
            $id = (int) $r['term_id'];
        }
        $ids[$slug] = $id;
        update_term_meta($id, 'mlwf_group', $groups[$v['group']]);
        update_term_meta($id, 'mlwf_group_order', $v['group_order']);
        update_term_meta($id, 'mlwf_order', $v['order']);
        update_term_meta($id, 'order_pa_groesse', $v['group_order'] * 1000 + $v['order']);
    }
    return $ids;
}

/**
 * Elternprodukte: die des abgeschlossenen Imports (WP All Import fuehrt sie in {prefix}pmxi_posts, bei Variantenimporten ueber
 * post_parent), sonst alle Lieferanten-Sync-Produkte (_ml_supplier_code).
 */
function ml_gr_parent_ids($import_id = 0) {
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
 * pa_groesse an Elternprodukten setzen.
 * Optionen: dry (nur zaehlen), limit (nur die ersten N), defer_count (Begriffszaehler am Ende), progress (Callback).
 * Rueckgabe: ['stats' => [...], 'unmapped' => [slug => anzahl]] oder null, wenn die Werte nicht angelegt werden konnten.
 */
function ml_gr_process(array $parents, array $opt = array()) {
    global $wpdb;
    $dry      = !empty($opt['dry']);
    $limit    = isset($opt['limit']) ? (int) $opt['limit'] : 0;
    $progress = isset($opt['progress']) ? $opt['progress'] : null;
    $m        = ml_gr_map();
    $stats    = array('geprueft' => 0, 'geaendert' => 0, 'ohne_groesse' => 0, 'nur_offen' => 0);
    $unmapped = array();
    $termIds  = array();

    if (!$dry) {
        $termIds = ml_gr_ensure();
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
        }
        foreach ($chunk as $pid) {
            $stats['geprueft']++;
            $sizes = get_the_terms($pid, 'pa_size');
            if (!is_array($sizes) || !$sizes) {
                $stats['ohne_groesse']++;
                continue;
            }
            $slugs = array();
            foreach ($sizes as $t) {
                $slugs[] = $t->slug;
            }
            $want = ml_gr_values_for($slugs, $m['map'], $unmapped);
            if (!$want) {
                $stats['nur_offen']++;   // nur unbekannte Groessen: bestehende Werte nicht anfassen
                continue;
            }
            $have = array();
            $cur  = get_the_terms($pid, 'pa_groesse');
            if (is_array($cur)) {
                foreach ($cur as $t) {
                    $have[] = $t->slug;
                }
            }
            sort($have);
            $w = $want;
            sort($w);
            $attrs    = get_post_meta($pid, '_product_attributes', true);
            $attrs    = is_array($attrs) ? $attrs : array();
            $needAttr = !isset($attrs['pa_groesse']);
            if ($have === $w && !$needAttr) {
                continue;
            }
            $stats['geaendert']++;
            if ($dry) {
                continue;
            }
            $ids = array();
            foreach ($want as $ts) {
                if (isset($termIds[$ts])) {
                    $ids[] = $termIds[$ts];
                }
            }
            wp_set_object_terms($pid, $ids, 'pa_groesse');
            if ($needAttr) {
                $attrs['pa_groesse'] = array(
                    'name'         => 'pa_groesse',
                    'value'        => '',
                    'position'     => count($attrs),
                    'is_visible'   => 0,
                    'is_variation' => 0,
                    'is_taxonomy'  => 1,
                );
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
    return array('stats' => $stats, 'unmapped' => $unmapped);
}

/** Protokollzeile fuer Sweep und Befehl. */
function ml_gr_summary($label, $import_id, array $res) {
    $s   = $res['stats'];
    $off = $res['unmapped'];
    arsort($off);
    $top = array_slice(array_keys($off), 0, 8);
    return sprintf(
        '[ML GROESSEN] %s - %d Eltern geprueft, %d geaendert, %d ohne Groesse, %d nur mit unbekannten Groessen, %d Groessen ohne Zuordnung%s.',
        $label,
        $s['geprueft'],
        $s['geaendert'],
        $s['ohne_groesse'],
        $s['nur_offen'],
        count($off),
        $top ? ' (haeufigste: ' . implode(', ', $top) . '; Liste: wp ml-groessen offen)' : ''
    );
}

/** Sweep nach einem Import. */
function ml_gr_sweep_hook($import_id) {
    static $done = array();
    if (isset($done[$import_id])) {
        return;   // beide Hooks koennen feuern, nur einmal je Import
    }
    $done[$import_id] = true;
    try {
        $m = ml_gr_map();
        foreach ($m['errors'] as $e) {
            error_log('[ML GROESSEN] ' . $e);
        }
        if (!$m['map']) {
            error_log("[ML GROESSEN] Import {$import_id} uebersprungen - keine Zuordnung geladen.");
            return;
        }
        $res = ml_gr_process(ml_gr_parent_ids((int) $import_id));
        if ($res === null) {
            error_log("[ML GROESSEN] Import {$import_id} abgebrochen - Werte konnten nicht angelegt werden.");
            return;
        }
        $msg = ml_gr_summary("Import {$import_id} abgeschlossen", $import_id, $res);
        error_log($msg);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::log($msg);
        }
    } catch (\Throwable $e) {
        error_log('[ML GROESSEN] Fehler im Sweep: ' . $e->getMessage());
    }
}
add_action('pmxi_import_complete', 'ml_gr_sweep_hook', 20, 1);
add_action('pmxi_after_xml_import', 'ml_gr_sweep_hook', 20, 1);

/**
 * Produktfilter (media-lab-woocommerce): Die automatische Filterliste (Shop, Kategorien, Marken) und die Facetten der Suchergebnisseite
 * enthalten alle WooCommerce-Attribute. Hier tritt pa_groesse (normalisierte Werte in Gruppen) an die Stelle von pa_size. Nur wenn
 * beide Attribute in der Liste stehen, alle anderen Eintraege bleiben, wie sie sind.
 */
function ml_gr_filter_attributes($slugs) {
    $slugs = (array) $slugs;
    if (!in_array('pa_size', $slugs, true) || !in_array('pa_groesse', $slugs, true)) {
        return $slugs;
    }
    $out = array();
    foreach ($slugs as $s) {
        if ($s === 'pa_groesse') {
            continue;   // kommt an die Stelle von pa_size
        }
        $out[] = ($s === 'pa_size') ? 'pa_groesse' : $s;
    }
    return $out;
}
add_filter('mlwf_auto_filter_attributes', 'ml_gr_filter_attributes');    // Shop, Kategorien, Marken
add_filter('mlwf_search_facet_taxonomies', 'ml_gr_filter_attributes');   // Suchergebnisseite

/**
 * Reihenfolge und Gruppe je Wert, ohne Datenbank (aus der Tabelle): [termslug => ['group' => name, 'group_order' => n, 'order' => rang]].
 */
function ml_gr_term_order() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache  = array();
    $groups = ml_gr_groups();
    $m      = ml_gr_map();
    foreach (ml_gr_values($m['map']) as $v) {
        $cache[ml_gr_term_slug($v['group'], $v['value'])] = array(
            'group'       => $groups[$v['group']],
            'group_order' => $v['group_order'],
            'order'       => $v['order'],
        );
    }
    return $cache;
}

/** Gesamtreihenfolge eines Werts (Gruppe vor Platz in der Gruppe); unbekannte ans Ende. */
function ml_gr_total_order($slug) {
    $o = ml_gr_term_order();
    return isset($o[$slug]) ? $o[$slug]['group_order'] * 1000 + $o[$slug]['order'] : 1000000000;
}

/**
 * Die Filterleiste (media-lab-woocommerce, filter-bar.php) laedt die Werte nach Name. Hier werden die Werte von pa_groesse in die
 * natuerliche Reihenfolge gebracht (Gruppe, dann Platz in der Gruppe), ohne das Plugin zu aendern. Nur im Frontend.
 */
function ml_gr_sort_terms($terms, $taxonomies = array()) {
    if (is_admin() || !is_array($terms) || !$terms || (array) $taxonomies !== array('pa_groesse')) {
        return $terms;
    }
    if (!is_object(reset($terms))) {
        return $terms;   // fields != all
    }
    usort($terms, function ($a, $b) {
        $oa = ml_gr_total_order($a->slug);
        $ob = ml_gr_total_order($b->slug);
        return $oa !== $ob ? ($oa < $ob ? -1 : 1) : strnatcasecmp((string) $a->name, (string) $b->name);
    });
    return $terms;
}
add_filter('get_terms', 'ml_gr_sort_terms', 10, 2);

/** Suchergebnisseite: dieselbe Reihenfolge fuer die Facette pa_groesse (Hook mlwf_search_facets). */
function ml_gr_sort_facets($facets) {
    if (!is_array($facets) || empty($facets['pa_groesse']['terms']) || !is_array($facets['pa_groesse']['terms'])) {
        return $facets;
    }
    usort($facets['pa_groesse']['terms'], function ($a, $b) {
        $oa = ml_gr_total_order(isset($a['slug']) ? $a['slug'] : '');
        $ob = ml_gr_total_order(isset($b['slug']) ? $b['slug'] : '');
        return $oa !== $ob ? ($oa < $ob ? -1 : 1) : strnatcasecmp((string) (isset($a['name']) ? $a['name'] : ''), (string) (isset($b['name']) ? $b['name'] : ''));
    });
    return $facets;
}
add_filter('mlwf_search_facets', 'ml_gr_sort_facets', 10, 1);

/**
 * Gruppen fuer die Filterleiste: Das Theme (assets/src/js/components/mlwf-term-groups.js) liest dieses JSON und haengt die Werte der
 * Liste in Gruppen mit Ueberschrift um (Kleidergroessen immer offen, die uebrigen eingeklappt). Format:
 * {"pa_groesse": {"<termslug>": ["<Gruppenname>", <Gruppennummer>, <Platz in der Gruppe>], ...}}
 * Nur auf Seiten mit Filterleiste (Shop, Kategorie, Schlagwort, Marke, Suche).
 */
function ml_gr_print_term_groups() {
    if (!function_exists('is_shop') || !taxonomy_exists('pa_groesse')) {
        return;
    }
    $page = is_shop() || is_product_category() || is_product_tag() || is_tax('product_brand') || is_search();
    if (!$page) {
        return;
    }
    $map = array();
    foreach (ml_gr_term_order() as $slug => $o) {
        $map[$slug] = array($o['group'], $o['group_order'], $o['order']);
    }
    if (!$map) {
        return;
    }
    echo '<script type="application/json" id="mlwf-term-groups">'
        . wp_json_encode(array('pa_groesse' => $map), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . "</script>\n";
}
add_action('wp_footer', 'ml_gr_print_term_groups', 5);

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {

    /** WP-CLI: Groessenfilter. */
    class ML_Groessen_CLI {

        /**
         * Setzt pa_groesse an allen Lieferanten-Elternprodukten (nach Aenderungen an der Tabelle).
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
            $m     = ml_gr_map(true);
            foreach ($m['errors'] as $e) {
                WP_CLI::warning($e);
            }
            if (!$m['map']) {
                WP_CLI::error('Keine Zuordnung geladen: ' . $m['file']);
            }
            $parents = ml_gr_parent_ids(0);
            WP_CLI::log(count($parents) . ' Elternprodukte gefunden' . ($dry ? ' (Trockenlauf).' : '.'));
            $res = ml_gr_process($parents, array(
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
            $msg = ml_gr_summary($dry ? 'Trockenlauf' : 'Lauf', 0, $res);
            error_log($msg);
            WP_CLI::log($msg);
            WP_CLI::success($dry ? 'Trockenlauf fertig, nichts geaendert.' : 'Fertig.');
        }

        /**
         * Listet Groessen ohne Zuordnung im Format der Tabelle, mit automatischem Vorschlag.
         *
         * ## OPTIONS
         *
         * [--out=<datei>]
         * : Ausgabedatei (sonst Bildschirm).
         */
        public function offen($args, $assoc) {
            $m      = ml_gr_map(true);
            $groups = ml_gr_groups();
            $terms  = get_terms(array('taxonomy' => 'pa_size', 'hide_empty' => false));
            if (is_wp_error($terms)) {
                WP_CLI::error($terms->get_error_message());
            }
            $rows = array();
            foreach ($terms as $t) {
                if (!empty($m['map'][$t->slug])) {
                    continue;
                }
                $v    = ml_gr_vorschlag($t->name);
                $gr   = '';
                $vals = array();
                foreach ($v as $pair) {
                    $gr     = $groups[$pair[0]];
                    $vals[] = $pair[1];
                }
                $rows[] = array($t->slug, $t->name, (int) $t->count, $gr, implode(' | ', array_unique($vals)), $v ? 'automatisch' : 'offen');
            }
            usort($rows, function ($a, $b) {
                return $b[2] <=> $a[2];
            });
            $fh = isset($assoc['out']) ? fopen($assoc['out'], 'w') : fopen('php://output', 'w');
            if (!$fh) {
                WP_CLI::error('Ausgabedatei nicht zu oeffnen.');
            }
            if (isset($assoc['out'])) {
                fwrite($fh, "\xEF\xBB\xBF");
            }
            fputcsv($fh, array('slug', 'name', 'produkte', 'gruppe', 'werte', 'hinweis'), ';', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($fh, $r, ';', '"', '\\');
            }
            fclose($fh);
            if (isset($assoc['out'])) {
                WP_CLI::success(count($rows) . ' Groessen ohne Zuordnung, geschrieben nach ' . $assoc['out'] . '.');
            }
        }
    }
    WP_CLI::add_command('ml-groessen', 'ML_Groessen_CLI');
}
