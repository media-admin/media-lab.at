<?php
namespace SupplierSync\Services;

use League\Csv\Writer;
use SupplierSync\Models\Product;

class FeedGenerator {

    /**
     * Generiert ZWEI CSV-Dateien: eine für Parent-Produkte, eine für Varianten.
     * WP All Import importiert diese als zwei getrennte, aber über
     * "import_uid" / "parent_import_uid" verknüpfte Importe.
     *
     * @param Product[] $products
     */
    public function generateCsv(array $products, string $parentFilepath, string $variantFilepath): void {
        $parentCsv = Writer::createFromPath($parentFilepath, 'w+');
        $parentHeader = [
            'import_uid', 'supplier_code', 'supplier_sku',
            'product_title', 'product_description', 'brand', 'categories',
            'lead_time_text', 'has_variants', 'image_main', 'image_gallery',
            'price_tiers', 'config_type', 'single_variant_price', 'single_variant_cost_price', 'single_variant_stock', 'product_badge', 'single_variant_price_tiers'
        ];
        $parentCsv->insertOne($parentHeader);

        $variantCsv = Writer::createFromPath($variantFilepath, 'w+');
        $variantHeader = [
            'parent_import_uid', 'parent_has_variants', 'supplier_variant_sku', 'variant_id',
            'variant_key', 'attributes', 'pa_color', 'pa_size', 'stock',
            'first_arrival_date', 'first_arrival_qty', 'price', 'cost_price',
            'price_tiers', 'image_main', 'image_gallery'
        ];
        $variantCsv->insertOne($variantHeader);

        $parentChecked  = false;
        $variantChecked = false;

        foreach ($products as $product) {
            $parentRow = $product->toArray();
            if (!$parentChecked) {
                self::assertColumns($parentRow, $parentHeader, 'Parent-CSV (Product::toArray)');
                $parentChecked = true;
            }
            $parentCsv->insertOne($parentRow);

            foreach ($product->variants as $variant) {
                $variantRow = $variant->toArray($product->importUid, $product->isVariable());
                if (!$variantChecked) {
                    self::assertColumns($variantRow, $variantHeader, 'Varianten-CSV (ProductVariant::toArray)');
                    $variantChecked = true;
                }
                $variantCsv->insertOne($variantRow);
            }
        }
    }

    /**
     * Schützt vor stillen Spaltenverschiebungen: Der CSV-Kopf und die Schlüssel von toArray() müssen exakt gleich sein
     * (Namen und Reihenfolge). Sonst stünden die Werte unter falschen Spaltennamen im Feed und der Import schriebe sie
     * ins falsche Feld. Geprüft wird die erste Zeile je Datei.
     *
     * @throws \RuntimeException wenn Kopf und Schlüssel abweichen
     */
    private static function assertColumns(array $row, array $header, string $what): void {
        $keys = array_keys($row);
        if ($keys === $header) {
            return;
        }
        throw new \RuntimeException(sprintf(
            '%s: CSV-Kopf und toArray() stimmen nicht überein. Im Kopf, aber nicht in toArray(): [%s]. In toArray(), aber nicht im Kopf: [%s]. Reihenfolge gleich: %s.',
            $what,
            implode(', ', array_diff($header, $keys)),
            implode(', ', array_diff($keys, $header)),
            array_values(array_intersect($header, $keys)) === array_values(array_intersect($keys, $header)) ? 'ja' : 'nein'
        ));
    }
}