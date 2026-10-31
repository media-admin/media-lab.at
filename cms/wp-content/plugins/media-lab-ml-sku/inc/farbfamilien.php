<?php
/**
 * Farbfamilien (10/2026)
 *
 * Ordnet die Lieferantenfarben (Attribut pa_color, rund 2.500 Begriffe, Deutsch und Englisch durcheinander) einer
 * kleinen Liste von 17 Farbfamilien zu (Attribut pa_farbfamilie, "Farbfamilie"). Die Originalfarbe bleibt an Produkt
 * und Variante, die Farbfamilie dient nur dem Filter und ist unsichtbar (kein Eintrag im Tab "Zusaetzliche
 * Informationen").
 *
 * Zuordnungstabelle: data/farbfamilien.csv (Trennzeichen Semikolon, Spalten slug, name, produkte, familien,
 * hinweis). "slug" ist der Slug des pa_color-Begriffs, "familien" eine oder mehrere Farbfamilien, getrennt durch "|".
 * Eine Zeile ohne Familie gilt als bekannt, aber noch offen. Farben, die in der Tabelle fehlen oder offen sind,
 * werden nicht zugeordnet und im Protokoll gemeldet.
 *
 * Ablauf (die Schleife):
 *   1. Nach jedem Import laeuft der Sweep (Hooks pmxi_import_complete / pmxi_after_xml_import, Prioritaet 20, also
 *      nach dem Sweep sweep_variation_attributes) und setzt die Farbfamilien an den Elternprodukten.
 *   2. wp ml-farbfamilien offen --out="$HOME/Desktop/farben-offen.csv" listet alle Farben ohne Zuordnung, mit einem
 *      automatischen Vorschlag (Schluesselwoerter, Spalte hinweis). Nach Durchsicht die Zeilen in
 *      data/farbfamilien.csv uebernehmen.
 *   3. wp ml-farbfamilien anwenden [--dry-run] [--limit=N] setzt die Familien an allen Produkten neu (Aufholen nach
 *      Aenderungen an der Tabelle, Trockenlauf mit --dry-run).
 *
 * Der Produktfilter (media-lab-woocommerce, Hooks mlwf_auto_filter_attributes und mlwf_search_facet_taxonomies) zeigt die
 * Farbfamilie statt der Farbe.
 *
 * Idempotent. Aendert nur pa_farbfamilie, nie pa_color. Bei fehlender oder leerer Tabelle passiert nichts.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Die 17 Farbfamilien in der Reihenfolge im Filter: Slug => Name. */
function ml_ff_families() {
    return array(
        'schwarz' => 'Schwarz',
        'weiss' => 'Weiß',
        'grau' => 'Grau',
        'blau' => 'Blau',
        'navy-marine' => 'Navy / Marine',
        'rot' => 'Rot',
        'orange' => 'Orange',
        'gelb' => 'Gelb',
        'gruen' => 'Grün',
        'tuerkis' => 'Türkis',
        'lila-violett' => 'Lila / Violett',
        'pink-rosa' => 'Pink / Rosa',
        'braun' => 'Braun',
        'beige-natur' => 'Beige / Natur',
        'silber-metall' => 'Silber / Metall',
        'transparent' => 'Transparent',
        'mehrfarbig-muster' => 'Mehrfarbig / Muster',
    );
}

/** Pfad der Zuordnungstabelle. */
function ml_ff_csv_path() {
    return dirname(__DIR__) . '/data/farbfamilien.csv';
}

/** Akzente entfernen (entspricht Unicode NFKD ohne kombinierende Zeichen, fuer Kleinbuchstaben). */
function ml_ff_accents() {
    return array(
        'À' => 'a',
        'à' => 'a',
        'Á' => 'a',
        'á' => 'a',
        'Â' => 'a',
        'â' => 'a',
        'Ã' => 'a',
        'ã' => 'a',
        'Ä' => 'a',
        'ä' => 'a',
        'Å' => 'a',
        'å' => 'a',
        'Ç' => 'c',
        'ç' => 'c',
        'È' => 'e',
        'è' => 'e',
        'É' => 'e',
        'é' => 'e',
        'Ê' => 'e',
        'ê' => 'e',
        'Ë' => 'e',
        'ë' => 'e',
        'Ì' => 'i',
        'ì' => 'i',
        'Í' => 'i',
        'í' => 'i',
        'Î' => 'i',
        'î' => 'i',
        'Ï' => 'i',
        'ï' => 'i',
        'Ñ' => 'n',
        'ñ' => 'n',
        'Ò' => 'o',
        'ò' => 'o',
        'Ó' => 'o',
        'ó' => 'o',
        'Ô' => 'o',
        'ô' => 'o',
        'Õ' => 'o',
        'õ' => 'o',
        'Ö' => 'o',
        'ö' => 'o',
        'Ù' => 'u',
        'ù' => 'u',
        'Ú' => 'u',
        'ú' => 'u',
        'Û' => 'u',
        'û' => 'u',
        'Ü' => 'u',
        'ü' => 'u',
        'Ý' => 'y',
        'ý' => 'y',
        'ÿ' => 'y',
        'Ā' => 'a',
        'ā' => 'a',
        'Ă' => 'a',
        'ă' => 'a',
        'Ą' => 'a',
        'ą' => 'a',
        'Ć' => 'c',
        'ć' => 'c',
        'Ĉ' => 'c',
        'ĉ' => 'c',
        'Ċ' => 'c',
        'ċ' => 'c',
        'Č' => 'c',
        'č' => 'c',
        'Ď' => 'd',
        'ď' => 'd',
        'Ē' => 'e',
        'ē' => 'e',
        'Ĕ' => 'e',
        'ĕ' => 'e',
        'Ė' => 'e',
        'ė' => 'e',
        'Ę' => 'e',
        'ę' => 'e',
        'Ě' => 'e',
        'ě' => 'e',
        'Ĝ' => 'g',
        'ĝ' => 'g',
        'Ğ' => 'g',
        'ğ' => 'g',
        'Ġ' => 'g',
        'ġ' => 'g',
        'Ģ' => 'g',
        'ģ' => 'g',
        'Ĥ' => 'h',
        'ĥ' => 'h',
        'Ĩ' => 'i',
        'ĩ' => 'i',
        'Ī' => 'i',
        'ī' => 'i',
        'Ĭ' => 'i',
        'ĭ' => 'i',
        'Į' => 'i',
        'į' => 'i',
        'İ' => 'i',
        'i̇' => 'i',
        'Ĳ' => 'ij',
        'ĳ' => 'ij',
        'Ĵ' => 'j',
        'ĵ' => 'j',
        'Ķ' => 'k',
        'ķ' => 'k',
        'Ĺ' => 'l',
        'ĺ' => 'l',
        'Ļ' => 'l',
        'ļ' => 'l',
        'Ľ' => 'l',
        'ľ' => 'l',
        'Ń' => 'n',
        'ń' => 'n',
        'Ņ' => 'n',
        'ņ' => 'n',
        'Ň' => 'n',
        'ň' => 'n',
        'Ō' => 'o',
        'ō' => 'o',
        'Ŏ' => 'o',
        'ŏ' => 'o',
        'Ő' => 'o',
        'ő' => 'o',
        'Ŕ' => 'r',
        'ŕ' => 'r',
        'Ŗ' => 'r',
        'ŗ' => 'r',
        'Ř' => 'r',
        'ř' => 'r',
        'Ś' => 's',
        'ś' => 's',
        'Ŝ' => 's',
        'ŝ' => 's',
        'Ş' => 's',
        'ş' => 's',
        'Š' => 's',
        'š' => 's',
        'Ţ' => 't',
        'ţ' => 't',
        'Ť' => 't',
        'ť' => 't',
        'Ũ' => 'u',
        'ũ' => 'u',
        'Ū' => 'u',
        'ū' => 'u',
        'Ŭ' => 'u',
        'ŭ' => 'u',
        'Ů' => 'u',
        'ů' => 'u',
        'Ű' => 'u',
        'ű' => 'u',
        'Ų' => 'u',
        'ų' => 'u',
        'Ŵ' => 'w',
        'ŵ' => 'w',
        'Ŷ' => 'y',
        'ŷ' => 'y',
        'Ÿ' => 'y',
        'Ź' => 'z',
        'ź' => 'z',
        'Ż' => 'z',
        'ż' => 'z',
        'Ž' => 'z',
        'ž' => 'z',
        'ſ' => 's',
        'Ơ' => 'o',
        'ơ' => 'o',
        'Ư' => 'u',
        'ư' => 'u',
        'Ǆ' => 'dz',
        'ǆ' => 'dz',
        'ǅ' => 'dz',
        'Ǉ' => 'lj',
        'ǉ' => 'lj',
        'ǈ' => 'lj',
        'Ǌ' => 'nj',
        'ǌ' => 'nj',
        'ǋ' => 'nj',
        'Ǎ' => 'a',
        'ǎ' => 'a',
        'Ǐ' => 'i',
        'ǐ' => 'i',
        'Ǒ' => 'o',
        'ǒ' => 'o',
        'Ǔ' => 'u',
        'ǔ' => 'u',
        'Ǖ' => 'u',
        'ǖ' => 'u',
        'Ǘ' => 'u',
        'ǘ' => 'u',
        'Ǚ' => 'u',
        'ǚ' => 'u',
        'Ǜ' => 'u',
        'ǜ' => 'u',
        'Ǟ' => 'a',
        'ǟ' => 'a',
        'Ǡ' => 'a',
        'ǡ' => 'a',
        'Ǧ' => 'g',
        'ǧ' => 'g',
        'Ǩ' => 'k',
        'ǩ' => 'k',
        'Ǫ' => 'o',
        'ǫ' => 'o',
        'Ǭ' => 'o',
        'ǭ' => 'o',
        'ǰ' => 'j',
        'Ǳ' => 'dz',
        'ǳ' => 'dz',
        'ǲ' => 'dz',
        'Ǵ' => 'g',
        'ǵ' => 'g',
        'Ǹ' => 'n',
        'ǹ' => 'n',
        'Ǻ' => 'a',
        'ǻ' => 'a',
        'Ȁ' => 'a',
        'ȁ' => 'a',
        'Ȃ' => 'a',
        'ȃ' => 'a',
        'Ȅ' => 'e',
        'ȅ' => 'e',
        'Ȇ' => 'e',
        'ȇ' => 'e',
        'Ȉ' => 'i',
        'ȉ' => 'i',
        'Ȋ' => 'i',
        'ȋ' => 'i',
        'Ȍ' => 'o',
        'ȍ' => 'o',
        'Ȏ' => 'o',
        'ȏ' => 'o',
        'Ȑ' => 'r',
        'ȑ' => 'r',
        'Ȓ' => 'r',
        'ȓ' => 'r',
        'Ȕ' => 'u',
        'ȕ' => 'u',
        'Ȗ' => 'u',
        'ȗ' => 'u',
        'Ș' => 's',
        'ș' => 's',
        'Ț' => 't',
        'ț' => 't',
        'Ȟ' => 'h',
        'ȟ' => 'h',
        'Ȧ' => 'a',
        'ȧ' => 'a',
        'Ȩ' => 'e',
        'ȩ' => 'e',
        'Ȫ' => 'o',
        'ȫ' => 'o',
        'Ȭ' => 'o',
        'ȭ' => 'o',
        'Ȯ' => 'o',
        'ȯ' => 'o',
        'Ȱ' => 'o',
        'ȱ' => 'o',
        'Ȳ' => 'y',
        'ȳ' => 'y',
    );
}

/** Namen vereinheitlichen: klein, ss statt ss-Zeichen, ohne Akzente, Trenner (- _ / , . + &) zu Leerzeichen. */
function ml_ff_norm($s) {
    $s = trim((string) $s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = str_replace("\xC3\x9F", 'ss', $s);
    $s = strtr($s, ml_ff_accents());
    $s = preg_replace('~[\-_/,.+&]+~u', ' ', $s);
    $s = preg_replace('~\s+~u', ' ', $s);
    return trim((string) $s);
}

/** Familienname oder -slug aus der Tabelle zum Slug der Familie, sonst null. */
function ml_ff_family_slug($label) {
    static $lookup = null;
    if ($lookup === null) {
        $lookup = array();
        foreach (ml_ff_families() as $slug => $name) {
            $lookup[ml_ff_norm($name)] = $slug;
            $lookup[ml_ff_norm($slug)] = $slug;
        }
    }
    $k = ml_ff_norm($label);
    return isset($lookup[$k]) ? $lookup[$k] : null;
}

/** Schluesselwort-Regeln fuer den automatischen Vorschlag: Art, Familienname, Muster. */
function ml_ff_rules() {
    return array(
        array('main', 'Navy / Marine', '~\b(navy|marine|marineblau|nachtblau|midnight|french navy|oxford navy|dunkelblau|dark blue|deep navy|nautical)\b~u'),
        array('main', 'Schwarz', '~\b(black|schwarz|noir|ebony|onyx|jet|nero)\b~u'),
        array('main', 'Weiß', '~\b(white|weiss|snow|ivory|off white|offwhite|creme|cream|alabaster|natur weiss|ecru)\b~u'),
        array('main', 'Grau', '~\b(grey|gray|grau|grauer|anthracite|anthrazit|charcoal|graphite|graphit|carbon|slate|ash|stone|steel|marl|titanium|titan|zinn|pewter|asphalt|cement|concrete|smoke)\b~u'),
        array('main', 'Blau', '~\b(blue|blau|royal|koenigsblau|cobalt|kobalt|azure|azur|sky|hellblau|denim|indigo|petrol|ocean|sapphire|saphir|aqua blue|cyan|baby blue|ice blue|light blue|ultramarine|dodger)\b~u'),
        array('main', 'Rot', '~\b(red|rot|burgundy|bordeaux|wine|maroon|cherry|kirsch|crimson|scarlet|ruby|rubin|brick|ziegel|cardinal|poppy|tomato|chili|blood|cranberry|weinrot)\b~u'),
        array('main', 'Grün', '~\b(green|gruen|grun|olive|oliv|khaki|forest|bottle|kelly|lime|mint|moss|emerald|smaragd|sage|salbei|tanne|fir|jade|army|military|mantis|apple|apfel|pistachio|leaf|kiwi|hunter|spruce|pine)\b~u'),
        array('main', 'Gelb', '~\b(yellow|gelb|mustard|senf|lemon|zitrone|sun|sonnen|gold|golden|canary|banana|honey|honig|maize|vanilla|saffron|curry)\b~u'),
        array('main', 'Orange', '~\b(orange|apricot|aprikose|tangerine|mandarin|rust|rost|pumpkin|kuerbis|terracotta|terra cotta|amber|bernstein|peach|pfirsich|coral|koralle|salmon|lachs)\b~u'),
        array('main', 'Pink / Rosa', '~\b(pink|rosa|rose|fuchsia|fuchsie|magenta|flamingo|candy|blush|bubblegum|hot pink|raspberry|himbeere|berry|cerise)\b~u'),
        array('main', 'Lila / Violett', '~\b(purple|lila|violet|violett|lavender|lavendel|lilac|plum|pflaume|aubergine|amethyst|orchid|mauve|grape|traube|brombeer|brombeere)\b~u'),
        array('main', 'Türkis', '~\b(turquoise|tuerkis|turkis|teal|petrol|aqua|lagoon|lagune|ocean green|caribbean|mint blue|cyan)\b~u'),
        array('main', 'Braun', '~\b(brown|braun|chocolate|schokolade|coffee|kaffee|mocha|mokka|cognac|chestnut|kastanie|hazelnut|haselnuss|walnut|walnuss|espresso|tobacco|toffee|caramel|karamell|cinnamon|zimt|bronze|copper|kupfer|mahogany|cocoa|kakao|cacao|nougat|umber|sepia|camel)\b~u'),
        array('main', 'Beige / Natur', '~\b(beige|sand|natural|nature|natur|naturfarbe|naturell|nude|taupe|ecru|stone sand|biscuit|keks|linen|leinen|oatmeal|hafer|champagne|wheat|weizen|bamboo|bambus|kraft|holz|wood|cork|kork|jute|hemp|hanf|rattan|buff|latte)\b~u'),
        array('main', 'Silber / Metall', '~\b(silver|silber|mattsilber|metal|metallic|chrome|chrom|platinum|platin|aluminium|aluminum|nickel|inox|stainless|edelstahl|zinc)\b~u'),
        array('main', 'Transparent', '~\b(transparent|clear|durchsichtig|crystal|kristall|frosted|milchig|translucent)\b~u'),
        array('main', 'Mehrfarbig / Muster', '~\b(multi|multicolor|multicolour|mehrfarbig|bunt|rainbow|regenbogen|camo|camouflage|stripe|striped|streifen|check|kariert|dots|punkte|print|muster|pattern|mixed|assorted|sortiert|tie dye|batik|leopard)\b~u'),
        array('end', 'Blau', '~blau\b~u'),
        array('end', 'Grün', '~(gruen|grun)\b~u'),
        array('end', 'Grau', '~grau\b~u'),
        array('end', 'Rot', '~rot\b~u'),
        array('end', 'Gelb', '~gelb\b~u'),
        array('end', 'Braun', '~braun\b~u'),
        array('end', 'Pink / Rosa', '~rosa\b~u'),
        array('end', 'Lila / Violett', '~(lila|violett)\b~u'),
        array('end', 'Weiß', '~weiss\b~u'),
        array('end', 'Schwarz', '~schwarz\b~u'),
        array('end', 'Silber / Metall', '~silber\b~u'),
        array('end', 'Orange', '~orange\b~u'),
        array('end', 'Türkis', '~(tuerkis|turkis)\b~u'),
        array('end', 'Beige / Natur', '~(beige|kaki|khaki)\b~u'),
        array('extra', 'Gelb', '~\b(sunflower|ochre|ocker|gold|vergoldet|golden)\b~u'),
        array('extra', 'Grün', '~\b(limette|kaki|militaergruen|armeegruen)\b~u'),
        array('extra', 'Beige / Natur', '~\b(tan|clay|oyster|elfenbein|mastic|sandstone|earth|rope)\b~u'),
        array('extra', 'Navy / Marine', '~\b(night|ink|pacific|atoll)\b~u'),
        array('extra', 'Grau', '~\b(shadow|magnet|mausgrau|steingrau)\b~u'),
    );
}

/**
 * Automatischer Vorschlag fuer einen Farbnamen: Liste von Familiennamen (leer = nichts erkannt).
 * Nur ein Vorschlag fuer die Durchsicht, wird nie ungeprueft angewendet.
 */
function ml_ff_vorschlag($name) {
    $n    = ml_ff_norm($name);
    $hits = array();
    foreach (ml_ff_rules() as $rule) {
        if (preg_match($rule[2], $n) && !in_array($rule[1], $hits, true)) {
            $hits[] = $rule[1];
        }
    }
    $words = preg_split('~\s+~', $n, -1, PREG_SPLIT_NO_EMPTY);
    if (in_array('Navy / Marine', $hits, true) && in_array('Blau', $hits, true) && count($words) <= 3 && strpos((string) $name, '/') === false) {
        $hits = array_values(array_diff($hits, array('Blau')));
    }
    return $hits;
}

/**
 * Zuordnungstabelle laden: ['map' => [farbslug => [familienslug, ...]], 'errors' => [...], 'file' => pfad].
 * Eine Farbe mit leerer Liste ist bekannt, aber noch offen.
 */
function ml_ff_map($reload = false) {
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = array('map' => array(), 'errors' => array(), 'file' => ml_ff_csv_path());
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
    $iFam  = array_search('familien', $header, true);
    if ($iSlug === false || $iFam === false) {
        fclose($h);
        $cache['errors'][] = 'Zuordnungstabelle: Spalten slug und familien fehlen';
        return $cache;
    }
    $line = 1;
    while (($row = fgetcsv($h, 0, ';', '"', '\\')) !== false) {
        $line++;
        $slug = isset($row[$iSlug]) ? trim((string) $row[$iSlug]) : '';
        if ($slug === '') {
            continue;
        }
        $raw  = isset($row[$iFam]) ? trim((string) $row[$iFam]) : '';
        $fams = array();
        if ($raw !== '') {
            foreach (explode('|', $raw) as $label) {
                $label = trim($label);
                if ($label === '') {
                    continue;
                }
                $fs = ml_ff_family_slug($label);
                if ($fs === null) {
                    $cache['errors'][] = "Zuordnungstabelle Zeile {$line}: unbekannte Farbfamilie '{$label}' bei '{$slug}'";
                    continue;
                }
                $fams[$fs] = true;
            }
        }
        $cache['map'][$slug] = array_keys($fams);
    }
    fclose($h);
    return $cache;
}

/**
 * Familien zu einer Liste von pa_color-Slugs, in der Reihenfolge von ml_ff_families().
 * Farben ohne Zuordnung werden in $unmapped gezaehlt (slug => Anzahl).
 */
function ml_ff_families_for(array $colorSlugs, array $map, array &$unmapped) {
    $found = array();
    foreach ($colorSlugs as $cs) {
        if (!empty($map[$cs])) {
            foreach ($map[$cs] as $f) {
                $found[$f] = true;
            }
        } else {
            $unmapped[$cs] = (isset($unmapped[$cs]) ? $unmapped[$cs] : 0) + 1;
        }
    }
    $ordered = array();
    foreach (array_keys(ml_ff_families()) as $f) {
        if (isset($found[$f])) {
            $ordered[] = $f;
        }
    }
    return $ordered;
}

/**
 * Attribut "Farbfamilie" und die 17 Begriffe sicherstellen. Rueckgabe: [familienslug => term_id] oder false.
 */
function ml_ff_ensure($mode = '') {
    static $ids = null;
    if ($mode === 'reset') {   // nur fuer Tests: Zwischenspeicher leeren
        $ids = null;
        return null;
    }
    if ($ids !== null) {
        return $ids;
    }
    if (!taxonomy_exists('pa_farbfamilie')) {
        if (!function_exists('wc_create_attribute') || !function_exists('wc_attribute_taxonomy_id_by_name')) {
            error_log('[ML FARBFAMILIE] WooCommerce nicht geladen, Attribut kann nicht angelegt werden.');
            return false;
        }
        if (!wc_attribute_taxonomy_id_by_name('farbfamilie')) {
            $created = wc_create_attribute(array(
                'name'         => 'Farbfamilie',
                'slug'         => 'farbfamilie',
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ));
            if (is_wp_error($created)) {
                error_log('[ML FARBFAMILIE] Attribut konnte nicht angelegt werden: ' . $created->get_error_message());
                return false;
            }
            delete_transient('wc_attribute_taxonomies');
        }
        if (!taxonomy_exists('pa_farbfamilie')) {
            register_taxonomy('pa_farbfamilie', apply_filters('woocommerce_taxonomy_objects_pa_farbfamilie', array('product')), array(
                'labels'       => array('name' => 'Farbfamilie'),
                'hierarchical' => false,
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
                'public'       => false,
            ));
        }
    }
    $ids = array();
    $i   = 0;
    foreach (ml_ff_families() as $slug => $name) {
        $i++;
        $term = get_term_by('slug', $slug, 'pa_farbfamilie');
        if ($term) {
            $ids[$slug] = (int) $term->term_id;
            continue;
        }
        $r = wp_insert_term($name, 'pa_farbfamilie', array('slug' => $slug));
        if (is_wp_error($r)) {
            error_log('[ML FARBFAMILIE] Begriff ' . $name . ' konnte nicht angelegt werden: ' . $r->get_error_message());
            $ids = null;
            return false;
        }
        $ids[$slug] = (int) $r['term_id'];
        update_term_meta($ids[$slug], 'order_pa_farbfamilie', $i);
    }
    return $ids;
}

/**
 * Elternprodukte: die des abgeschlossenen Imports (WP All Import fuehrt sie in {prefix}pmxi_posts, bei
 * Variantenimporten ueber post_parent), sonst alle Lieferanten-Sync-Produkte (_ml_supplier_code).
 */
function ml_ff_parent_ids($import_id = 0) {
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
 * Farbfamilien an Elternprodukten setzen.
 * Optionen: dry (nur zaehlen), limit (nur die ersten N), defer_count (Begriffszaehler am Ende), progress (Callback).
 * Rueckgabe: ['stats' => [...], 'unmapped' => [slug => anzahl]] oder null, wenn die Familien nicht angelegt werden konnten.
 */
function ml_ff_process(array $parents, array $opt = array()) {
    global $wpdb;
    $dry      = !empty($opt['dry']);
    $limit    = isset($opt['limit']) ? (int) $opt['limit'] : 0;
    $progress = isset($opt['progress']) ? $opt['progress'] : null;
    $m        = ml_ff_map();
    $stats    = array('geprueft' => 0, 'geaendert' => 0, 'ohne_farbe' => 0, 'nur_offen' => 0);
    $unmapped = array();
    $termIds  = array();

    if (!$dry) {
        $termIds = ml_ff_ensure();
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
            $colors = get_the_terms($pid, 'pa_color');
            if (!is_array($colors) || !$colors) {
                $stats['ohne_farbe']++;
                continue;
            }
            $slugs = array();
            foreach ($colors as $t) {
                $slugs[] = $t->slug;
            }
            $want = ml_ff_families_for($slugs, $m['map'], $unmapped);
            if (!$want) {
                $stats['nur_offen']++;   // nur unbekannte Farben: bestehende Familien nicht anfassen
                continue;
            }
            $have = array();
            $cur  = get_the_terms($pid, 'pa_farbfamilie');
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
            $needAttr = !isset($attrs['pa_farbfamilie']);
            if ($have === $w && !$needAttr) {
                continue;
            }
            $stats['geaendert']++;
            if ($dry) {
                continue;
            }
            $ids = array();
            foreach ($want as $fs) {
                $ids[] = $termIds[$fs];
            }
            wp_set_object_terms($pid, $ids, 'pa_farbfamilie');
            if ($needAttr) {
                $attrs['pa_farbfamilie'] = array(
                    'name'         => 'pa_farbfamilie',
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
function ml_ff_summary($label, $import_id, array $res) {
    $s   = $res['stats'];
    $off = $res['unmapped'];
    arsort($off);
    $top = array_slice(array_keys($off), 0, 8);
    return sprintf(
        '[ML FARBFAMILIE] %s - %d Eltern geprueft, %d geaendert, %d ohne Farbe, %d nur mit unbekannten Farben, %d Farben ohne Zuordnung%s.',
        $label,
        $s['geprueft'],
        $s['geaendert'],
        $s['ohne_farbe'],
        $s['nur_offen'],
        count($off),
        $top ? ' (haeufigste: ' . implode(', ', $top) . '; Liste: wp ml-farbfamilien offen)' : ''
    );
}

/** Sweep nach einem Import. */
function ml_ff_sweep_hook($import_id) {
    static $done = array();
    if (isset($done[$import_id])) {
        return;   // beide Hooks koennen feuern, nur einmal je Import
    }
    $done[$import_id] = true;
    try {
        $m = ml_ff_map();
        foreach ($m['errors'] as $e) {
            error_log('[ML FARBFAMILIE] ' . $e);
        }
        if (!$m['map']) {
            error_log("[ML FARBFAMILIE] Import {$import_id} uebersprungen - keine Zuordnung geladen.");
            return;
        }
        $res = ml_ff_process(ml_ff_parent_ids((int) $import_id));
        if ($res === null) {
            error_log("[ML FARBFAMILIE] Import {$import_id} abgebrochen - Farbfamilien konnten nicht angelegt werden.");
            return;
        }
        $msg = ml_ff_summary("Import {$import_id} abgeschlossen", $import_id, $res);
        error_log($msg);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::log($msg);
        }
    } catch (\Throwable $e) {
        error_log('[ML FARBFAMILIE] Fehler im Sweep: ' . $e->getMessage());
    }
}
add_action('pmxi_import_complete', 'ml_ff_sweep_hook', 20, 1);
add_action('pmxi_after_xml_import', 'ml_ff_sweep_hook', 20, 1);

/**
 * Produktfilter (media-lab-woocommerce): Die automatische Filterliste (Shop, Kategorien, Marken) und die Facetten der
 * Suchergebnisseite enthalten alle WooCommerce-Attribute. Hier tritt die Farbfamilie (17 Werte) an die Stelle der Farbe
 * (rund 2.500 Werte, Deutsch und Englisch gemischt). Nur wenn beide Attribute in der Liste stehen, alle anderen Eintraege
 * (Kategorie, Marke, weitere Attribute) bleiben, wie sie sind. Gilt nur fuer die automatische Liste, ausdruecklich
 * eingetragene Attribute bleiben.
 */
function ml_ff_filter_attributes($slugs) {
    $slugs = (array) $slugs;
    if (!in_array('pa_color', $slugs, true) || !in_array('pa_farbfamilie', $slugs, true)) {
        return $slugs;
    }
    $out = array();
    foreach ($slugs as $s) {
        if ($s === 'pa_farbfamilie') {
            continue;   // kommt an die Stelle von pa_color
        }
        $out[] = ($s === 'pa_color') ? 'pa_farbfamilie' : $s;
    }
    return $out;
}
add_filter('mlwf_auto_filter_attributes', 'ml_ff_filter_attributes');    // Shop, Kategorien, Marken
add_filter('mlwf_search_facet_taxonomies', 'ml_ff_filter_attributes');   // Suchergebnisseite

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    /** Farbfamilien: Zuordnung, Aufholen und Liste der offenen Farben. */
    class ML_Farbfamilien_CLI {

        /**
         * Farbfamilien an allen Lieferanten-Produkten setzen (Aufholen).
         *
         * ## OPTIONS
         *
         * [--dry-run]
         * : Nur zaehlen, nichts aendern (legt auch das Attribut nicht an).
         *
         * [--limit=<n>]
         * : Nur die ersten n Produkte.
         */
        public function anwenden($args, $assoc) {
            $dry   = !empty($assoc['dry-run']);
            $limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 0;
            $m     = ml_ff_map(true);
            foreach ($m['errors'] as $e) {
                WP_CLI::warning($e);
            }
            if (!$m['map']) {
                WP_CLI::error('Keine Zuordnung geladen (' . $m['file'] . '), nichts getan.');
            }
            $parents = ml_ff_parent_ids(0);
            WP_CLI::log(count($parents) . ' Elternprodukte gefunden' . ($limit ? ", verarbeite die ersten {$limit}" : '') . ($dry ? ' (Trockenlauf)' : '') . '.');
            $res = ml_ff_process($parents, array(
                'dry'         => $dry,
                'limit'       => $limit,
                'defer_count' => true,
                'progress'    => function ($done, $total) {
                    WP_CLI::log("  {$done} / {$total}");
                },
            ));
            if ($res === null) {
                WP_CLI::error('Farbfamilien konnten nicht angelegt werden (siehe Protokoll).');
            }
            WP_CLI::log(ml_ff_summary($dry ? 'Trockenlauf' : 'Lauf', 0, $res));
            WP_CLI::success($dry ? 'Trockenlauf fertig, nichts geaendert.' : 'Fertig.');
        }

        /**
         * Alle Farben (pa_color) ohne Zuordnung, mit automatischem Vorschlag, im Format der Tabelle.
         *
         * ## OPTIONS
         *
         * [--out=<datei>]
         * : CSV-Datei (Semikolon, UTF-8 mit BOM). Ohne Angabe Ausgabe im Terminal. Besser "$HOME/..." als "~/...".
         */
        public function offen($args, $assoc) {
            $m = ml_ff_map(true);
            foreach ($m['errors'] as $e) {
                WP_CLI::warning($e);
            }
            $terms = get_terms(array('taxonomy' => 'pa_color', 'hide_empty' => false, 'number' => 0));
            if (is_wp_error($terms)) {
                WP_CLI::error($terms->get_error_message());
            }
            $rows = array();
            foreach ($terms as $t) {
                if (!empty($m['map'][$t->slug]) || (int) $t->count < 1) {
                    continue;
                }
                $v      = ml_ff_vorschlag($t->name);
                $rows[] = array($t->slug, $t->name, (int) $t->count, implode(' | ', $v), $v ? 'Vorschlag automatisch, pruefen' : 'offen');
            }
            usort($rows, function ($a, $b) {
                return $b[2] <=> $a[2];
            });
            $out = isset($assoc['out']) ? (string) $assoc['out'] : '';
            if ($out !== '' && strpos($out, '~/') === 0) {
                $out = getenv('HOME') . substr($out, 1);
            }
            $h = $out !== '' ? fopen($out, 'w') : fopen('php://output', 'w');
            if (!$h) {
                WP_CLI::error('Datei nicht zu schreiben: ' . $out);
            }
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, array('slug', 'name', 'produkte', 'familien', 'hinweis'), ';', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($h, $r, ';', '"', '\\');
            }
            fclose($h);
            WP_CLI::success(count($rows) . ' Farben ohne Zuordnung' . ($out !== '' ? ', geschrieben nach ' . $out : '') . '.');
        }
    }
    WP_CLI::add_command('ml-farbfamilien', 'ML_Farbfamilien_CLI');
}
