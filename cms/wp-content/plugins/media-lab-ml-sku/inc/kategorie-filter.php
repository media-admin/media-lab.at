<?php
/**
 * Kategorie-Filter in der Filterleiste (media-lab-ml-sku, Projekt media-lab.at)
 *
 * 1. Shop: Die Filterleiste (media-lab-woocommerce) zeigt den Kategorie-Filter nur auf Kategorie- und Marken-Archiven. Im Shop
 *    setzt dieses Projekt-Plugin die Gruppe "Kategorie" in die Leiste ein (ohne das Plugin zu aendern: die Leiste wird
 *    zwischengespeichert, die Gruppe eingefuegt, dann ausgegeben). Die Kaestchen heissen wie dort subcat[], das Filter-JS und der
 *    AJAX-Handler des Plugins arbeiten damit unveraendert.
 * 2. Gruppen: Haupt- und Unterkategorien kommen aus der Tabelle data/kategorien-baum.csv. Das JSON fuer das Theme
 *    (mlwf-term-groups.js) erhaelt den Schluessel "product_cat": {"<slug>": ["Hauptkategorie", Gruppennummer, Platz, "Beschriftung"]}.
 *    Die Hauptkategorie steht als erster Eintrag ihrer Gruppe, beschriftet "Alle <Hauptkategorie>". Das JSON wird in
 *    inc/groessen.php ausgegeben (Filter ml_term_groups).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Baum aus der Tabelle in Tabellenreihenfolge: slug => [haupt, main(bool), gruppe, rang]. */
function ml_kf_baum() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = array();
    $file  = __DIR__ . '/../data/kategorien-baum.csv';
    if (!is_readable($file)) {
        return $cache;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return $cache;
    }
    $mains = array();
    $g     = 0;
    $rang  = 0;
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            continue;   // Kopfzeile
        }
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
        }
        $r = str_getcsv($line, ';');
        $haupt = isset($r[0]) ? trim($r[0]) : '';
        $unter = isset($r[1]) ? trim($r[1]) : '';
        $hs    = isset($r[2]) ? trim($r[2]) : '';
        $us    = isset($r[3]) ? trim($r[3]) : '';
        if ($haupt === '' || $hs === '') {
            continue;
        }
        if ($unter === '') {
            $g++;
            $rang         = 0;
            $mains[$hs]   = $g;
            $cache[$hs]   = array('haupt' => $haupt, 'main' => true, 'gruppe' => $g, 'rang' => 0);
        } elseif ($us !== '') {
            $rang++;
            $cache[$us] = array('haupt' => $haupt, 'main' => false, 'gruppe' => isset($mains[$hs]) ? $mains[$hs] : 999, 'rang' => $rang);
        }
    }
    return $cache;
}

/** JSON fuer das Theme: Gruppe, Gruppennummer, Platz, Beschriftung (nur Hauptkategorien). */
function ml_kf_term_groups($data) {
    $data = is_array($data) ? $data : array();
    $map  = array();
    foreach (ml_kf_baum() as $slug => $e) {
        $map[$slug] = $e['main']
            ? array($e['haupt'], $e['gruppe'], 0, 'Alle ' . $e['haupt'])
            : array($e['haupt'], $e['gruppe'], $e['rang']);
    }
    if ($map) {
        $data['product_cat'] = $map;
    }
    return $data;
}
add_filter('ml_term_groups', 'ml_kf_term_groups');

/** Gruppe "Kategorie" im Shop (gleiches Markup wie der Unterkategorie-Filter der Filterleiste). */
function ml_kf_shop_gruppe() {
    $config = function_exists('mlwf_get_current_filter_config') ? mlwf_get_current_filter_config() : array();
    if (isset($config['show_subcategories']) && !$config['show_subcategories']) {
        return '';
    }
    $baum = ml_kf_baum();
    if (!$baum) {
        return '';
    }
    $terms = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'exclude'    => array((int) get_option('default_product_cat')),
    ));
    if (is_wp_error($terms) || !$terms) {
        return '';
    }
    $by_slug = array();
    foreach ($terms as $t) {
        $by_slug[$t->slug] = $t;
    }

    $raw    = isset($_GET['subcat']) ? $_GET['subcat'] : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Anzeige
    $raw    = is_array($raw) ? implode(',', $raw) : (string) $raw;
    $active = array_values(array_filter(array_map('sanitize_text_field', explode(',', $raw))));

    $items = '';
    foreach ($baum as $slug => $e) {
        if (!isset($by_slug[$slug]) || (int) $by_slug[$slug]->count < 1) {
            continue;
        }
        $t     = $by_slug[$slug];
        $label = $e['main'] ? 'Alle ' . $e['haupt'] : $t->name;
        $items .= '<li class="wc-filter-checklist__item"><label class="wc-filter-option" for="filter-subcat-' . esc_attr($slug) . '">'
            . '<input class="wc-filter-option__checkbox" id="filter-subcat-' . esc_attr($slug) . '" type="checkbox" name="subcat[]" value="' . esc_attr($slug) . '"'
            . (in_array($slug, $active, true) ? ' checked' : '') . '>'
            . '<span class="wc-filter-option__label">' . esc_html($label) . '</span>'
            . '<span class="wc-filter-option__count">(' . absint($t->count) . ')</span>'
            . '</label></li>';
    }
    if ($items === '') {
        return '';
    }

    $title = (class_exists('MediaLab_Filter_Settings') && MediaLab_Filter_Settings::label('category') !== '') ? MediaLab_Filter_Settings::label('category') : 'Kategorie';
    $id    = 'filter-drop-subcategories';
    return '<div class="wc-filter-group js-filter-group" data-filter-type="subcategory">'
        . '<button class="wc-filter-group__toggle' . ($active ? ' has-active' : '') . '" type="button" aria-expanded="false" aria-controls="' . esc_attr($id) . '">'
        . esc_html($title)
        . ($active ? '<span class="wc-filter-group__count">' . count($active) . '</span>' : '')
        . '<span class="wc-filter-group__icon" aria-hidden="true"></span></button>'
        . '<div class="wc-filter-group__dropdown" id="' . esc_attr($id) . '" hidden><ul class="wc-filter-checklist" role="group">' . $items . '</ul></div>'
        . '</div>';
}

/** Filterleiste im Shop: Plugin-Ausgabe zwischenspeichern, Kategorie-Gruppe vor die Attribut-Gruppen setzen. */
function ml_kf_render_shop_filter_bar() {
    ob_start();
    mlwf_render_filter_bar();
    $html = ob_get_clean();

    $gruppe = ml_kf_shop_gruppe();
    if ($gruppe !== '' && strpos($html, 'name="subcat[]"') === false) {
        $marker = '<div class="wc-filter-group js-filter-group" data-filter-type="attribute"';
        $pos    = strpos($html, $marker);
        if ($pos === false) {
            $marker = '</div><!-- .wc-filter-bar__groups -->';
            $pos    = strpos($html, $marker);
        }
        if ($pos !== false) {
            $html = substr_replace($html, $gruppe, $pos, 0);
        }
    }
    echo $html;   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ausgabe des Plugins, Gruppe oben escaped
}

add_action('wp', function () {
    if (!function_exists('mlwf_render_filter_bar') || !function_exists('is_shop') || !is_shop()) {
        return;
    }
    if (has_action('woocommerce_before_shop_loop', 'mlwf_render_filter_bar') === false) {
        return;
    }
    remove_action('woocommerce_before_shop_loop', 'mlwf_render_filter_bar', 15);
    add_action('woocommerce_before_shop_loop', 'ml_kf_render_shop_filter_bar', 15);
});
