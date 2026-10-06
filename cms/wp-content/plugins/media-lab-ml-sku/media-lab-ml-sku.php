<?php
/**
 * Plugin Name: Media Lab – ML SKU Generator
 * Description: Generates Media Lab SKUs (ML-CODE-NNNNNN(-VVV)) for WooCommerce products/variations.
 *              Fires after WP All Import finishes writing custom fields (pmxi_saved_post),
 *              with a manual-save fallback and an atomic counter to avoid race conditions.
 * Version: 0.3.0
 */

defined('ABSPATH') || exit;

class MediaLab_ML_SKU_Generator {

        const SKU_PREFIX = 'ML';
        const PARENT_PAD = 6;
        const VAR_PAD    = 3;

        // supplier_code => token used in SKU (fallback mapping only;
        // the importer SHOULD already write the token directly into _ml_supplier_code)
        private $supplierTokens = [
                'cotton'   => 'LCC',
                'midocean' => 'DIM',
                'makito'   => 'TKM',
        ];

        private $isReprocessing = false;

        public function init() {
                // PRIMARY: fires after WP All Import has written all custom fields
                // (both for the imported product/parent AND for each imported variation)
                add_action('pmxi_saved_post', [$this, 'handle_import_saved_post'], 10, 1);

                // FALLBACK: manual edits/creation in wp-admin (not via importer)
                add_action('save_post_product', [$this, 'maybe_assign_skus_on_product_save'], 50, 3);
                add_action('save_post_product_variation', [$this, 'maybe_assign_sku_on_variation_save'], 50, 3);

                // SAFETY NET (BUG FIX, verifiziert 01.09.2026): WordPress feuert den
                // SPEZIFISCHEN Hook 'save_post_product' VOR dem GENERISCHEN Hook
                // 'save_post'. WooCommerce's eigene Metabox-Speicherroutine für
                // Produktdaten hängt an 'save_post' (Priorität 1) und überschreibt
                // unsere gerade gesetzte SKU im selben Request mit dem Wert aus dem
                // Formularfeld (der alten SKU, die beim Laden der Bearbeitungsseite
                // noch angezeigt wurde). Dieser zusätzliche Hook auf das generische
                // 'save_post' mit sehr hoher Priorität (999) garantiert, dass unsere
                // Zuweisung tatsächlich als Letztes läuft und nicht überschrieben wird.
                add_action('save_post', [$this, 'safety_net_after_woocommerce_save'], 999, 3);

                // SWEEP (BUG FIX, verifiziert 03.09.2026): WP All Import legt neue
                // Varianten offenbar zweistufig an - der Post wird zuerst mit
                // post_type='product' erstellt, und ERST DANACH (vermutlich per
                // direktem $wpdb-Zugriff, ohne erneuten save_post-Hook) final auf
                // 'product_variation' umgestellt. Feuert 'pmxi_saved_post' während
                // dieses Zwischenzustands, klassifiziert unser Code die Variante
                // fälschlich als 'product' und überspringt die SKU-Zuweisung dauerhaft
                // - kein weiterer Hook feuert danach mehr. Betrifft nicht-deterministisch
                // einen Teil der Varianten pro Importlauf (Timing-Rennen).
                // Lösung: Einmaliger Sweep nach Abschluss des GESAMTEN Imports, der
                // alle Varianten mit noch nicht-finaler SKU nachträglich korrigiert.
                add_action('pmxi_import_complete', [$this, 'sweep_fix_stale_variation_skus'], 10, 1);
                add_action('pmxi_import_complete', [$this, 'sweep_draft_products_without_image'], 10, 1);

                // INTERNER LAGERSTAND (Inventur-Feature, 2026-09): eigenes, im
                // WP-Admin editierbares Feld 'stock_inhouse' - unabhängig vom per
                // Feed importierten Lieferantenbestand (_stock). Zwei Render-/Save-
                // Stellen nötig, da Produktvarianten keine eigene Bearbeitungsseite
                // haben (siehe Methoden weiter unten).
                add_action('woocommerce_product_after_variable_attributes', [$this, 'render_stock_inhouse_variation_field'], 10, 3);
                add_action('woocommerce_save_product_variation', [$this, 'save_stock_inhouse_variation_field'], 10, 2);
                add_action('woocommerce_product_options_inventory_product_data', [$this, 'render_stock_inhouse_simple_field']);
                add_action('woocommerce_process_product_meta', [$this, 'save_stock_inhouse_simple_field']);

                // PREISKALKULATION (2026-09): Cotton-Classics-Preise aus dem Feed
                // sind EINKAUFSPREISE (Cotton Classics' eigener Verkaufspreis AN
                // Media Lab, Feldname "VKEinzel" aus DEREN Perspektive) - der
                // tatsächliche Shop-Verkaufspreis wird erst hier über einen im
                // WP-Admin editierbaren Faktor berechnet. Der Wert wird von
                // supplier-sync (eigenständiges CLI-Skript, lädt kein WordPress)
                // direkt per PDO aus wp_options gelesen, nicht über get_field().
                add_action('acf/init', [$this, 'register_pricing_options_page']);
        }

        /* ------------------------------------------------------------------ *
         *  Preiskalkulation: Aufschlagsfaktor Einkaufspreis -> Verkaufspreis
         * ------------------------------------------------------------------ */

        public function register_pricing_options_page() {
                if (!function_exists('acf_add_options_page')) return;

                acf_add_options_sub_page([
                        'page_title'  => 'Preiskalkulation',
                        'menu_title'  => 'Preiskalkulation',
                        'parent_slug' => 'woocommerce',
                        'capability'  => 'manage_woocommerce',
                        'slug'        => 'ml-pricing-factors',
                ]);

                acf_add_local_field_group([
                        'key'    => 'group_ml_pricing_factors',
                        'title'  => 'Preiskalkulation',
                        'fields' => [
                                [
                                        'key'           => 'field_ml_markup_factor_cotton_classics',
                                        'label'         => 'Aufschlagsfaktor Cotton Classics',
                                        'name'          => 'ml_markup_factor_cotton_classics',
                                        'type'          => 'number',
                                        'instructions'  => 'Verkaufspreis = Einkaufspreis (aus dem Cotton-Classics-Feed) x dieser Faktor. Beispiel: 1.8 = 80% Aufschlag. Wirkt erst ab dem naechsten Sync-Lauf (php sync.php cotton_classics), nicht rueckwirkend auf bereits importierte Preise.',
                                        'required'      => 1,
                                        'default_value' => 1,
                                        'min'           => 0.01,
                                        'step'          => 0.01,
                                ],
                        ],
                        'location' => [
                                [
                                        [
                                                'param'    => 'options_page',
                                                'operator' => '==',
                                                'value'    => 'ml-pricing-factors',
                                        ],
                                ],
                        ],
                ]);

                // MidOcean: Feed-Preise sind Einkaufspreise (bestaetigt 28.09.2026)
                acf_add_local_field([
                        'key'           => 'field_ml_markup_factor_midocean',
                        'label'         => 'Aufschlagsfaktor MidOcean',
                        'name'          => 'ml_markup_factor_midocean',
                        'type'          => 'number',
                        'instructions'  => 'Verkaufspreis = Einkaufspreis (aus dem MidOcean-Feed) x dieser Faktor. Wirkt erst ab dem naechsten Sync-Lauf (php sync.php midocean).',
                        'required'      => 1,
                        'default_value' => 1,
                        'min'           => 0.01,
                        'step'          => 0.01,
                        'parent'        => 'group_ml_pricing_factors',
                ]);

                // Makito: Feed-Preise sind Einkaufspreise (bestaetigt 29.09.2026)
                acf_add_local_field([
                        'key'           => 'field_ml_markup_factor_makito',
                        'label'         => 'Aufschlagsfaktor Makito',
                        'name'          => 'ml_markup_factor_makito',
                        'type'          => 'number',
                        'instructions'  => 'Verkaufspreis = Einkaufspreis (aus dem Makito-Feed) x dieser Faktor. Wirkt erst ab dem naechsten Sync-Lauf (php sync.php makito).',
                        'required'      => 1,
                        'default_value' => 1,
                        'min'           => 0.01,
                        'step'          => 0.01,
                        'parent'        => 'group_ml_pricing_factors',
                ]);
        }

        /* ------------------------------------------------------------------ *
         *  Interner Lagerstand (stock_inhouse) - editierbar im WP-Admin
         * ------------------------------------------------------------------ */

        /**
         * Feld in der Varianten-Zeile (Tab "Variationen"), dort wo auch
         * Preis/Lagerbestand des Lieferanten-Imports stehen.
         */
        public function render_stock_inhouse_variation_field($loop, $variation_data, $variation) {
                $value = get_post_meta($variation->ID, 'stock_inhouse', true);

                echo '<div class="form-row form-row-full">';
                woocommerce_wp_text_input([
                        'id'                => "stock_inhouse_{$loop}",
                        'name'              => "stock_inhouse[{$loop}]",
                        'value'             => $value,
                        'label'             => __('Interner Lagerstand', 'media-lab-ml-sku'),
                        'desc_tip'          => true,
                        'description'       => __('Physisch vorhandener Bestand im eigenen Lager (Inventur) - unabhängig vom Lieferantenbestand.', 'media-lab-ml-sku'),
                        'type'              => 'number',
                        'custom_attributes' => ['step' => '1', 'min' => '0'],
                ]);
                echo '</div>';
        }

        public function save_stock_inhouse_variation_field($variation_id, $loop) {
                if (!isset($_POST['stock_inhouse'][$loop])) return;

                $raw = wc_clean(wp_unslash($_POST['stock_inhouse'][$loop]));
                update_post_meta($variation_id, 'stock_inhouse', $raw === '' ? '' : absint($raw));
        }

        /**
         * Feld im "Lagerbestand"-Tab des Parent-Produkts - nur für einfache
         * Produkte relevant, Varianten haben ihr eigenes Feld (siehe oben).
         * Ohne diesen Guard würde bei variablen Produkten zusätzlich ein
         * verwirrendes, ungenutztes Parent-Feld erscheinen.
         */
        public function render_stock_inhouse_simple_field() {
                global $post;

                $product = wc_get_product($post->ID);
                if ($product && $product->is_type('variable')) return;

                woocommerce_wp_text_input([
                        'id'                => 'stock_inhouse',
                        'value'             => get_post_meta($post->ID, 'stock_inhouse', true),
                        'label'             => __('Interner Lagerstand', 'media-lab-ml-sku'),
                        'desc_tip'          => true,
                        'description'       => __('Physisch vorhandener Bestand im eigenen Lager (Inventur) - unabhängig vom Lieferantenbestand.', 'media-lab-ml-sku'),
                        'type'              => 'number',
                        'custom_attributes' => ['step' => '1', 'min' => '0'],
                ]);
        }

        public function save_stock_inhouse_simple_field($post_id) {
                if (!isset($_POST['stock_inhouse']) || is_array($_POST['stock_inhouse'])) return;

                $raw = wc_clean(wp_unslash($_POST['stock_inhouse']));
                update_post_meta($post_id, 'stock_inhouse', $raw === '' ? '' : absint($raw));
        }

        /**
         * Läuft einmal, wenn ein kompletter WP-All-Import-Lauf fertig ist.
         * Findet alle product_variation-Posts, deren SKU noch nicht im
         * ML-Format vorliegt (Symptom der oben beschriebenen Race Condition),
         * und korrigiert sie nachträglich über den bewährten Zuweisungspfad.
         */
        public function sweep_fix_stale_variation_skus($import_id) {
                global $wpdb;

                $staleVariationIds = $wpdb->get_col($wpdb->prepare(
                        "SELECT p.ID
                         FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
                         WHERE p.post_type = 'product_variation'
                         AND p.post_status = 'publish'
                         AND (pm.meta_value = '' OR pm.meta_value NOT LIKE %s)",
                        self::SKU_PREFIX . '-%'
                ));

                if (empty($staleVariationIds)) {
                        error_log("[ML SKU SWEEP] Import {$import_id} abgeschlossen - keine veralteten Varianten-SKUs gefunden.");
                        return;
                }

                error_log("[ML SKU SWEEP] Import {$import_id} abgeschlossen - " . count($staleVariationIds) . " Varianten mit veralteter SKU gefunden, korrigiere...");

                $fixed = 0;
                foreach ($staleVariationIds as $variation_id) {
                        $this->process_variation_via_import((int) $variation_id);
                        $fixed++;
                }

                error_log("[ML SKU SWEEP] Sweep fertig - {$fixed} Varianten verarbeitet.");
        }

        /**
         * Setzt veroeffentlichte Produkte automatisch auf Entwurf, wenn kein
         * gueltiges Titelbild vorhanden ist (z.B. fehlgeschlagener Bild-
         * Download, siehe Cotton Classics: 9 GUIDs, die auf dem FTP nicht
         * existieren). Nur Lieferanten-Sync-Produkte (_ml_supplier_code
         * gesetzt) - manuell angelegte Admin-Produkte bleiben unberuehrt.
         * Re-Publish erfolgt ausschliesslich manuell, kein automatisches
         * Zurueck-Veroeffentlichen (01.10.2026).
         */
        public function sweep_draft_products_without_image($import_id) {
                global $wpdb;

                $productIds = $wpdb->get_col(
                        "SELECT p.ID
                         FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} sc ON sc.post_id = p.ID AND sc.meta_key = '_ml_supplier_code'
                         LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_thumbnail_id'
                         WHERE p.post_type = 'product'
                         AND p.post_status = 'publish'
                         AND (t.meta_value IS NULL OR t.meta_value = '')"
                );

                if (empty($productIds)) {
                        error_log("[ML IMAGE SWEEP] Import {$import_id} abgeschlossen - keine Produkte ohne Bild gefunden.");
                        return;
                }

                error_log("[ML IMAGE SWEEP] Import {$import_id} abgeschlossen - " . count($productIds) . " veroeffentlichte Produkte ohne Bild gefunden, setze auf Entwurf...");

                $drafted = 0;
                foreach ($productIds as $product_id) {
                        wp_update_post([
                                'ID'          => (int) $product_id,
                                'post_status' => 'draft',
                        ]);
                        $drafted++;
                }

                error_log("[ML IMAGE SWEEP] Sweep fertig - {$drafted} Produkte auf Entwurf gesetzt.");
        }

        /**
         * Läuft garantiert NACH WooCommerce's eigener Produktdaten-Speicherung
         * (siehe Erklärung bei add_action('save_post', ...) oben).
         * Recursion-Guard nötig, da $product->save() intern wp_update_post()
         * aufruft, was wiederum 'save_post' erneut auslösen würde.
         */
        public function safety_net_after_woocommerce_save($post_id, $post, $update) {
                if ($this->isReprocessing) return;
                if (wp_is_post_revision($post_id)) return;
                if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

                $post_type = get_post_type($post_id);
                if (!in_array($post_type, ['product', 'product_variation'], true)) return;

                $this->isReprocessing = true;

                if ($post_type === 'product') {
                        $this->process_parent($post_id);
                } else {
                        $this->process_variation_via_import($post_id);
                }

                $this->isReprocessing = false;
        }

        /* ------------------------------------------------------------------ *
         *  WP All Import hook
         * ------------------------------------------------------------------ */

        /**
         * Fires once per imported post (product OR variation), after XML/CSV
         * fields and custom fields have been fully written by WP All Import.
         */
        public function handle_import_saved_post($post_id) {
                $post_type = get_post_type($post_id);

                if ($post_type === 'product') {
                        $this->process_parent($post_id);
                } elseif ($post_type === 'product_variation') {
                        $this->process_variation_via_import($post_id);
                }
        }

        private function process_parent($post_id) {
                error_log("[ML SKU DEBUG] process_parent() gestartet für Post {$post_id}");

                $product = wc_get_product($post_id);
                if (!$product) {
                        error_log("[ML SKU DEBUG] wc_get_product() lieferte NICHTS für Post {$post_id} - Abbruch");
                        return;
                }

                $supplierCode = get_post_meta($post_id, '_ml_supplier_code', true);
                $supplierSku  = get_post_meta($post_id, '_ml_supplier_sku', true);

                error_log("[ML SKU DEBUG] _ml_supplier_code = '{$supplierCode}', _ml_supplier_sku = '{$supplierSku}'");

                // Importer hasn't written supplier data (yet) -> nothing to do.
                if (!$supplierCode || !$supplierSku) {
                        error_log("[ML SKU DEBUG] supplierCode oder supplierSku leer - Abbruch für Post {$post_id}");
                        return;
                }

                $token = $this->token_for_supplier($supplierCode);
                error_log("[ML SKU DEBUG] token_for_supplier('{$supplierCode}') = " . var_export($token, true));

                if (!$token) {
                        // Unknown supplier code — don't silently assign a broken SKU.
                        error_log("[ML SKU] Unknown supplier code '{$supplierCode}' on product {$post_id}");
                        return;
                }

                $parentNumber = $this->ensure_parent_number($post_id, $token);
                error_log("[ML SKU DEBUG] parentNumber = {$parentNumber}");

                // Assign SKU to parent if missing ODER falls die aktuelle SKU noch
                // nicht unserem ML-Format entspricht (z.B. eine temporäre
                // Import-SKU wie "DIM|AR1249", die WP All Import zur
                // Varianten-Zuordnung braucht, aber final ersetzt werden soll).
                $currentSku = $product->get_sku();
                error_log("[ML SKU DEBUG] currentSku = '{$currentSku}'");

                if (!$currentSku || !str_starts_with($currentSku, self::SKU_PREFIX . '-')) {
                        $sku = $this->format_parent_sku($token, $parentNumber);
                        error_log("[ML SKU DEBUG] Setze neue SKU: '{$sku}'");
                        $product->set_sku($sku);
                        $saveResult = $product->save();
                        error_log("[ML SKU DEBUG] save() Ergebnis: " . var_export($saveResult, true));
                } else {
                        error_log("[ML SKU DEBUG] currentSku beginnt schon mit ML- Prefix, keine Änderung");
                }

                // If it's a variable product, make sure any children already present
                // (e.g. re-import updating an existing variable product) also get SKUs.
                if ($product->is_type('variable')) {
                        foreach ($product->get_children() as $variation_id) {
                                $this->assign_variation_sku($variation_id, $post_id, $token, $parentNumber);
                        }
                }

                error_log("[ML SKU DEBUG] process_parent() fertig für Post {$post_id}");
        }

        private function process_variation_via_import($variation_id) {
                error_log("[ML SKU DEBUG] process_variation_via_import() gestartet für Variation {$variation_id}");

                $variation = wc_get_product($variation_id);
                if (!$variation || !$variation->is_type('variation')) {
                        error_log("[ML SKU DEBUG] Post {$variation_id} ist keine gültige Variation - Abbruch");
                        return;
                }

                $parent_id = $variation->get_parent_id();
                error_log("[ML SKU DEBUG] parent_id für Variation {$variation_id} = {$parent_id}");
                if (!$parent_id) return;

                $supplierCode = get_post_meta($parent_id, '_ml_supplier_code', true);
                error_log("[ML SKU DEBUG] _ml_supplier_code des Parents {$parent_id} = '{$supplierCode}'");
                if (!$supplierCode) {
                        error_log("[ML SKU DEBUG] Parent {$parent_id} hat keinen supplierCode - Abbruch (Parent noch nicht verarbeitet?)");
                        return;
                }

                $token = $this->token_for_supplier($supplierCode);
                if (!$token) return;

                $parentNumber = $this->ensure_parent_number($parent_id, $token);
                error_log("[ML SKU DEBUG] parentNumber = {$parentNumber}, rufe assign_variation_sku auf");

                $this->assign_variation_sku($variation_id, $parent_id, $token, $parentNumber);

                error_log("[ML SKU DEBUG] process_variation_via_import() fertig für Variation {$variation_id}, aktuelle SKU: " . $variation->get_sku());
        }

        /* ------------------------------------------------------------------ *
         *  Manual save fallback (wp-admin, no importer involved)
         * ------------------------------------------------------------------ */

        public function maybe_assign_skus_on_product_save($post_id, $post, $update) {
                if (wp_is_post_revision($post_id)) return;
                if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

                $this->process_parent($post_id);
        }

        public function maybe_assign_sku_on_variation_save($post_id, $post, $update) {
                if (wp_is_post_revision($post_id)) return;
                if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

                $this->process_variation_via_import($post_id);
        }

        /* ------------------------------------------------------------------ *
         *  SKU assignment helpers
         * ------------------------------------------------------------------ */

        private function assign_variation_sku($variation_id, $parent_id, $token, $parentNumber) {
                $variation = wc_get_product($variation_id);
                if (!$variation) return;

                $currentSku = $variation->get_sku();
                // Gleiche Logik wie beim Parent: überschreiben, außer die SKU hat
                // schon unser ML-Format. Nötig, weil WP All Import beim Varianten-
                // Import eine temporäre SKU (supplier_variant_sku) setzt, die wir
                // noch durch die finale ML-SKU ersetzen müssen.
                if ($currentSku && str_starts_with($currentSku, self::SKU_PREFIX . '-')) {
                        return; // schon final zugewiesen, nichts zu tun
                }

                $variantKey = $this->build_variant_key($variation);
                update_post_meta($variation_id, '_ml_variant_key', $variantKey);

                $varNumber = get_post_meta($variation_id, '_ml_variant_number', true);
                if (!$varNumber) {
                        $varNumber = $this->atomic_increment("ml_var_counter_parent_{$parent_id}");
                        update_post_meta($variation_id, '_ml_variant_number', $varNumber);
                }

                $sku = $this->format_variant_sku($token, $parentNumber, $varNumber);

                $variation->set_sku($sku);
                $variation->save();
        }

        private function ensure_parent_number($post_id, $token) {
                $parentNumber = get_post_meta($post_id, '_ml_parent_number', true);
                if (!$parentNumber) {
                        $parentNumber = $this->atomic_increment("ml_counter_{$token}");
                        update_post_meta($post_id, '_ml_parent_number', $parentNumber);
                }
                return (int) $parentNumber;
        }

        private function build_variant_key($variation) {
                $attrs = $variation->get_attributes(); // ['pa_color' => 'red', 'pa_size' => 'l', ...]
                ksort($attrs);

                $parts = [];
                foreach ($attrs as $k => $v) {
                        $parts[] = "{$k}={$v}";
                }
                return implode('|', $parts);
        }

        private function token_for_supplier($supplierCode) {
                // supplierCode may already be the token (DIM/LCC/TKM) or a logical code (midocean, etc.)
                if (in_array($supplierCode, ['DIM', 'LCC', 'TKM'], true)) return $supplierCode;
                return $this->supplierTokens[$supplierCode] ?? null;
        }

        private function format_parent_sku($token, $parentNumber) {
                $num = str_pad((string) $parentNumber, self::PARENT_PAD, '0', STR_PAD_LEFT);
                return self::SKU_PREFIX . '-' . $token . '-' . $num;
        }

        private function format_variant_sku($token, $parentNumber, $varNumber) {
                $pnum = str_pad((string) $parentNumber, self::PARENT_PAD, '0', STR_PAD_LEFT);
                $vnum = str_pad((string) $varNumber, self::VAR_PAD, '0', STR_PAD_LEFT);
                return self::SKU_PREFIX . '-' . $token . '-' . $pnum . '-' . $vnum;
        }

        /**
         * Atomic counter increment using a single UPSERT statement,
         * safe against concurrent requests (unlike get_option/update_option).
         * Requires option_name to have a UNIQUE index — true for wp_options by default.
         */
        private function atomic_increment($optionKey) {
                global $wpdb;

                $wpdb->query($wpdb->prepare(
                        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                         VALUES (%s, '1', 'no')
                         ON DUPLICATE KEY UPDATE option_value = option_value + 1",
                        $optionKey
                ));

                return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                        $optionKey
                ));
        }
}

add_action('plugins_loaded', function () {
        if (!class_exists('WooCommerce')) return;
        $gen = new MediaLab_ML_SKU_Generator();
        $gen->init();
});

/* ============================================================
 * Frontend-Verfügbarkeitslogik (ab 30.09.2026)
 *
 * Dreistufig:
 *   1. stock_inhouse > 0            -> "Kurzfristig lieferbar" (eigener Lagerbestand)
 *   2. sonst Lieferantenbestand > 0 -> "Lieferbar" (+ Datum aus
 *                                      _ml_lead_time_text, falls vorhanden -
 *                                      aktuell nur bei Makito teilweise befüllt)
 *   3. sonst                        -> WooCommerce-Standardverhalten unverändert
 *                                      ("Nicht vorrätig" o.ä.)
 * ============================================================ */

/**
 * @param WC_Product|WC_Product_Variation $product
 * @return array{status:string,label:string,badge_class:string}|null
 *         null = Fall 3, WooCommerce-Standard beibehalten
 */
function ml_get_product_availability( $product ): ?array {
    if ( ! $product instanceof WC_Product ) {
        return null;
    }

    $product_id = $product->get_id();

    $stock_inhouse = (int) get_post_meta( $product_id, 'stock_inhouse', true );
    if ( $stock_inhouse > 0 ) {
        return [
            'status'      => 'in_house',
            'label'       => __( 'Kurzfristig lieferbar', 'media-lab-ml-sku' ),
            'badge_class' => 'ml-availability--in-house',
        ];
    }

    if ( $product->is_in_stock() ) {
        $label = __( 'Lieferbar', 'media-lab-ml-sku' );

        $lead_time_raw = get_post_meta( $product_id, '_ml_lead_time_text', true );
        if ( $lead_time_raw ) {
            // Format TT-MM-JJJJ (Makito). Bei unbekanntem Format wird das
            // Datum stillschweigend ignoriert, Label bleibt "Lieferbar".
            $date = DateTime::createFromFormat( 'd-m-Y', trim( (string) $lead_time_raw ) );
            if ( $date instanceof DateTime ) {
                $label = sprintf(
                    /* translators: %s: Datum im Format TT.MM. */
                    __( 'Lieferbar ab %s', 'media-lab-ml-sku' ),
                    $date->format( 'd.m.' )
                );
            }
        }

        return [
            'status'      => 'supplier',
            'label'       => $label,
            'badge_class' => 'ml-availability--supplier',
        ];
    }

    return null;
}

/**
 * Einzelproduktseite: überschreibt WooCommerce's Standard-Lagerstatustext
 * ("Vorrätig" / "X auf Lager"). Feuert sowohl für einfache Produkte als
 * auch für jede Variante einzeln (beim Variantenwechsel via AJAX,
 * WC_AJAX::get_variation()) - Varianten-Ebene ist damit automatisch
 * mit abgedeckt, ohne zusätzlichen Hook.
 */
add_filter( 'woocommerce_get_availability', function( $availability, $product ) {
    $override = ml_get_product_availability( $product );
    if ( $override === null ) {
        return $availability;
    }

    $availability['availability'] = $override['label'];
    $availability['class']        = $override['badge_class'];

    return $availability;
}, 10, 2 );

/**
 * Wie ml_get_product_availability(), aber für variable Parent-Produkte:
 * prüft stock_inhouse über ALLE Varianten (nicht nur den Parent selbst,
 * der ja gar keinen eigenen stock_inhouse-Wert trägt). Wird nur im
 * Shop-Grid gebraucht, wo WooCommerce mit der Parent-ID arbeitet, nicht
 * auf der Einzelproduktseite (dort übernimmt der jeweils aktive
 * Varianten-Kontext das automatisch, siehe woocommerce_get_availability).
 */
function ml_get_grid_availability( WC_Product $product ): ?array {
    if ( $product->is_type( 'variable' ) ) {
        global $wpdb;
        $has_stock_variation = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_parent = %d AND p.post_type = 'product_variation'
             AND m.meta_key = 'stock_inhouse' AND CAST(m.meta_value AS UNSIGNED) > 0
             LIMIT 1",
            $product->get_id()
        ) );

        if ( $has_stock_variation ) {
            return [
                'status'      => 'in_house',
                'label'       => __( 'Kurzfristig lieferbar', 'media-lab-ml-sku' ),
                'badge_class' => 'ml-availability--in-house',
            ];
        }

        if ( $product->is_in_stock() ) {
            return [
                'status'      => 'supplier',
                'label'       => __( 'Lieferbar', 'media-lab-ml-sku' ),
                'badge_class' => 'ml-availability--supplier',
            ];
        }

        return null;
    }

    return ml_get_product_availability( $product );
}

/**
 * Shop-Grid: WooCommerce zeigt dort standardmäßig KEINEN Lagerstatus-Text
 * (anders als auf der Einzelproduktseite) - wird hier komplett neu
 * ausgegeben, direkt über dem Produkttitel.
 */
add_action( 'woocommerce_before_shop_loop_item_title', function() {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $availability = ml_get_grid_availability( $product );
    if ( $availability === null ) {
        return;
    }

    printf(
        '<span class="ml-availability-badge %s">%s</span>',
        esc_attr( $availability['badge_class'] ),
        esc_html( $availability['label'] )
    );
}, 9 ); // Priorität 9: vor dem Standard-Sale-Badge (Prio 10), damit beide nebeneinander Platz finden

/* ============================================================
 * Frontend-Anzeige des Lieferanten-Status-Badges (ab 01.10.2026)
 *
 * product_badge (ACF, group_product_additional) lebt nur auf
 * Parent-Ebene. Unabhaengig von der Verfuegbarkeits-Logik oben -
 * ein Produkt kann beides, eins von beiden oder keins haben.
 * ============================================================ */

function ml_get_product_badge( int $product_id ): ?array {
    $value = get_field( 'product_badge', $product_id );
    if ( empty( $value ) ) {
        return null;
    }

    $labels = [
        'new'        => __( 'Neu', 'media-lab-ml-sku' ),
        'restposten' => __( 'Restposten', 'media-lab-ml-sku' ),
        'bestseller' => __( 'Bestseller', 'media-lab-ml-sku' ),
        'limited'    => __( 'Limitiert', 'media-lab-ml-sku' ),
        'eco'        => __( 'Umweltfreundlich', 'media-lab-ml-sku' ),
    ];

    if ( ! isset( $labels[ $value ] ) ) {
        return null;
    }

    return [
        'value' => $value,
        'label' => $labels[ $value ],
        'class' => 'ml-product-badge--' . $value,
    ];
}

/**
 * Shop-Grid. $product->get_id() reicht ohne Sonderbehandlung - anders
 * als stock_inhouse lebt product_badge direkt am Parent.
 */
add_action( 'woocommerce_before_shop_loop_item_title', function() {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $badge = ml_get_product_badge( $product->get_id() );
    if ( $badge === null ) {
        return;
    }

    printf(
        '<span class="ml-product-badge %s">%s</span>',
        esc_attr( $badge['class'] ),
        esc_html( $badge['label'] )
    );
}, 9 );

/**
 * Einzelproduktseite, vor dem Titel (WooCommerce-Standard fuer den
 * Titel selbst ist Prioritaet 5).
 */
add_action( 'woocommerce_single_product_summary', function() {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $badge = ml_get_product_badge( $product->get_id() );
    if ( $badge === null ) {
        return;
    }

    printf(
        '<span class="ml-product-badge ml-product-badge--inline %s">%s</span>',
        esc_attr( $badge['class'] ),
        esc_html( $badge['label'] )
    );
}, 4 );

/* ============================================================
 * Projektspezifische Erweiterungen fuer das zentral verwaltete
 * Starter-Kit-Plugin media-lab-woocommerce (ab 01.10.2026)
 *
 * Bewusst HIER statt dort gepflegt: media-lab-woocommerce wird zentral
 * im Starter-Kit-Repo verwaltet und auf alle Projekte ausgerollt -
 * projektspezifische Logik (stock_inhouse, product_badge) gehoert ins
 * Projekt-Plugin, nicht in die geteilte Codebasis.
 * ============================================================ */

add_filter( 'media_lab_ajax_search_result', function( $result, $post_id, $post_type ) {
    if ( $post_type !== 'product' || ! function_exists( 'wc_get_product' ) ) {
        return $result;
    }

    $product = wc_get_product( $post_id );
    if ( ! $product ) {
        return $result;
    }

    $availability = ml_get_grid_availability( $product );
    $result['availability_badge'] = ( $availability && $availability['status'] === 'in_house' )
        ? $availability['label']
        : null;

    $product_badge = ml_get_product_badge( $post_id );
    $result['product_badge_label'] = $product_badge['label'] ?? null;
    $result['product_badge_class'] = $product_badge['class'] ?? null;

    return $result;
}, 20, 3 );


/* ============================================================
 * Mengenstaffel-Anzeige für Cotton Classics (ab 02.10.2026)
 *
 * tier_pricing (ACF, Konfigurator-Feldgruppe) ist zwar an die
 * is_configurable-Bedingung gebunden - aber nur in der ADMIN-UI
 * (conditional_logic), nicht programmatisch. get_field()/update_field()
 * funktionieren unabhaengig davon, siehe class-price-calculator.php::
 * get_all_tiers() (liest einfach get_field('tier_pricing', ...) ohne
 * weitere Pruefung). Wir nutzen hier bewusst NICHT dieses Feld (lebt auf
 * Parent-Ebene, Cotton Classics hat aber Preise PRO VARIANTE - 29% der
 * Styles haben abweichende Preise zwischen Farben/Groessen, siehe
 * Notion), sondern bauen eine eigene, Varianten-genaue Anzeige:
 *
 * - woocommerce_available_variation haengt price_tiers (aus dem Custom
 *   Field _ml_price_tiers_raw, importiert von WP All Import Import 13)
 *   in das ohnehin pro Variante an den Browser ausgelieferte JSON-Paket.
 * - Kein neuer AJAX-Call noetig: found_variation (von WooCommerce's
 *   eigenem Variantenformular gefeuert) liest direkt aus diesem bereits
 *   geladenen Paket, exakt wie der Preis selbst beim Variantenwechsel.
 * - Simple Produkte (272 Cotton-Classics-Artikel ohne Varianten) haben
 *   keine Variantenauswahl - dort direkt aus dem Parent-Feld.
 */

add_filter( 'woocommerce_available_variation', function( $data, $product, $variation ) {
    $raw = get_post_meta( $variation->get_id(), '_ml_price_tiers_raw', true );
    if ( $raw ) {
        $decoded = json_decode( $raw, true );
        $data['ml_price_tiers'] = is_array( $decoded ) ? $decoded : null;
    }
    return $data;
}, 10, 3 );

/**
 * Liefert die Mengenstaffel für ein Simple-Produkt (kein Varianten-
 * Kontext, daher kein Variantenwechsel-Event moeglich) oder null, wenn
 * keine Daten vorliegen.
 */
function ml_get_simple_product_price_tiers( int $product_id ): ?array {
    $raw = get_post_meta( $product_id, '_ml_price_tiers_raw', true );
    if ( ! $raw ) {
        return null;
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : null;
}

/**
 * Eine Staffel ist nur sinnvoll, wenn mindestens eine Stufe einen Rabatt hat.
 * Sonst stuende dieselbe Zahl mehrfach untereinander (z. B. Produkte ohne Mengenrabatt).
 */
function ml_price_tiers_have_discount( array $tiers ): bool {
    foreach ( $tiers as $tier ) {
        if ( (float) ( $tier['discount_percent'] ?? 0 ) > 0 ) {
            return true;
        }
    }
    return false;
}

/**
 * Container fuer die Mengenstaffel-Tabelle auf der Einzelproduktseite.
 * Prioritaet 15: nach dem Preis (10), vor der Kurzbeschreibung (20).
 * Simple Produkte: Tabelle sofort serverseitig gerendert.
 * Variable Produkte: leerer Platzhalter, JS befuellt bei found_variation
 * (siehe woocommerce_available_variation-Filter oben, liefert die Daten
 * bereits im initialen Varianten-JSON mit, kein AJAX-Nachladen noetig).
 */
add_action( 'woocommerce_single_product_summary', function() {
    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    if ( $product->is_type( 'simple' ) ) {
        $tiers = ml_get_simple_product_price_tiers( $product->get_id() );
        if ( ! $tiers || ! ml_price_tiers_have_discount( $tiers ) ) {
            return;
        }
        echo '<div class="ml-price-tiers">';
        echo '<h3 class="ml-price-tiers__title">' . esc_html__( 'Mengenrabatt', 'media-lab-ml-sku' ) . '</h3>';
        echo '<table class="ml-price-tiers__table"><tbody>';
        $basePrice = (float) $product->get_price();
        foreach ( $tiers as $tier ) {
            $tierPrice = round( $basePrice * ( 1 - ( $tier['discount_percent'] ?? 0 ) / 100 ), 2 );
            printf(
                '<tr><td>%s %d</td><td>%s</td></tr>',
                esc_html__( 'ab', 'media-lab-ml-sku' ),
                (int) $tier['min_quantity'],
                wc_price( $tierPrice )
            );
        }
        echo '</tbody></table></div>';
        return;
    }

    if ( $product->is_type( 'variable' ) ) {
        echo '<div class="ml-price-tiers ml-price-tiers--variable" style="display:none;">';
        echo '<h3 class="ml-price-tiers__title">' . esc_html__( 'Mengenrabatt', 'media-lab-ml-sku' ) . '</h3>';
        echo '<table class="ml-price-tiers__table"><tbody></tbody></table>';
        echo '</div>';
    }
}, 33 ); // nach dem Preis (32), vorher 15

// ── Anbindung an media-lab-woocommerce ab 2.10.0 (Einzelprodukt-Layout, Produktkarten) ──────────
// Layout, Beschreibung, Mengenfeld, Marke und Preisreihenfolge kommen aus dem Starter-Kit (Filter im
// Theme, siehe functions.php). Projektspezifisch bleibt die dreistufige Verfuegbarkeit:
add_filter( 'mlw_loop_availability', function ( $data, $product ) {
    if ( ! $product instanceof WC_Product || ! function_exists( 'ml_get_grid_availability' ) ) { return $data; }
    $av = ml_get_grid_availability( $product );
    return is_array( $av ) ? [ 'label' => $av['label'], 'status' => $av['status'] ] : $data;
}, 10, 2 );
