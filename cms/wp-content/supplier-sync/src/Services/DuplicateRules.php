<?php
/* ============================================================
 * FILE: src/Services/DuplicateRules.php
 *
 * Regeln für Dubletten zwischen Lieferanten (config/duplicates.php).
 * Bewusst ohne Abhängigkeiten, damit sync.php (Composer) und
 * tools/dedupe_suppliers.php (WP-CLI) dieselbe Datei nutzen.
 * ============================================================ */

namespace SupplierSync\Services;

class DuplicateRules {

    /** @var array<int, array{brand:string, prefer:string, drop:string, key:array<string,string>}> */
    private array $rules;

    public function __construct(array $rules) {
        $this->rules = array_values(array_filter($rules, static function ($r) {
            return is_array($r) && !empty($r['prefer']) && !empty($r['drop']) && !empty($r['key'][$r['prefer']]) && !empty($r['key'][$r['drop']]);
        }));
    }

    /** @return array<int, array> Regeln, bei denen $supplierCode der verdrängte Lieferant ist */
    public function rulesDropping(string $supplierCode): array {
        return array_values(array_filter($this->rules, static fn($r) => $r['drop'] === $supplierCode));
    }

    /** @return array<int, array> */
    public function all(): array {
        return $this->rules;
    }

    /** Schlüssel einer Lieferanten-Artikelnummer nach der Regel (ohne führende Nullen) oder null. */
    public static function key(array $rule, string $supplierCode, string $sku): ?string {
        $regex = $rule['key'][$supplierCode] ?? null;
        if ($regex === null || !preg_match($regex, trim($sku), $m) || !isset($m[1])) {
            return null;
        }
        $key = ltrim($m[1], '0');
        return $key === '' ? null : $key;
    }

    /** Markenvergleich ohne Groß-/Kleinschreibung, HTML-Entities und typografische Apostrophe. */
    public static function sameBrand(string $a, string $b): bool {
        return self::normalizeBrand($a) === self::normalizeBrand($b);
    }

    private static function normalizeBrand(string $brand): string {
        $brand = html_entity_decode($brand, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $brand = str_replace(["\u{2019}", "\u{2018}", '`', '´'], "'", $brand);
        $brand = trim($brand);
        return function_exists('mb_strtolower') ? mb_strtolower($brand) : strtolower($brand);
    }

    /** Schlüsselmenge des bevorzugten Lieferanten aus dessen Lieferanten-Artikelnummern: [ key => true ] */
    public static function preferredKeys(array $rule, array $preferredSkus): array {
        $keys = [];
        foreach ($preferredSkus as $sku) {
            $k = self::key($rule, $rule['prefer'], (string) $sku);
            if ($k !== null) {
                $keys[$k] = true;
            }
        }
        return $keys;
    }

    /**
     * Teilt Produkte des Lieferanten $supplierCode in behalten und verdrängt.
     * Fehlt die Liste des bevorzugten Lieferanten oder ergibt sie keinen Schlüssel, bleibt alles erhalten (sicherer Ausgang).
     *
     * @param object[] $products Objekte mit supplierSku und brand (SupplierSync\Models\Product)
     * @param array<string, string[]> $preferredSkusByCode [ 'LCC' => [ '25.0553', ... ] ]
     * @return array{kept: object[], dropped: array<int, array{product: object, rule: array, key: string}>}
     */
    public function filterProducts(array $products, string $supplierCode, array $preferredSkusByCode): array {
        $rules = $this->rulesDropping($supplierCode);
        if (!$rules) {
            return ['kept' => $products, 'dropped' => []];
        }
        $keySets = [];
        foreach ($rules as $i => $rule) {
            $keySets[$i] = self::preferredKeys($rule, $preferredSkusByCode[$rule['prefer']] ?? []);
        }
        $kept = [];
        $dropped = [];
        foreach ($products as $product) {
            $hit = null;
            foreach ($rules as $i => $rule) {
                if (!$keySets[$i]) continue;
                if (!empty($rule['brand']) && !self::sameBrand((string) $product->brand, $rule['brand'])) continue;
                $k = self::key($rule, $supplierCode, (string) $product->supplierSku);
                if ($k !== null && isset($keySets[$i][$k])) {
                    $hit = ['product' => $product, 'rule' => $rule, 'key' => $k];
                    break;
                }
            }
            if ($hit) {
                $dropped[] = $hit;
            } else {
                $kept[] = $product;
            }
        }
        return ['kept' => $kept, 'dropped' => $dropped];
    }
}
