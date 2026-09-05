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
        $parentCsv->insertOne([
            'import_uid', 'supplier_code', 'supplier_sku',
            'product_title', 'product_description', 'categories',
            'lead_time_text', 'has_variants', 'image_main', 'image_gallery',
            'price_tiers', 'config_type'   // <- 'config_type' ergänzt, gleiche Position wie in toArray()
        ]);

        $variantCsv = Writer::createFromPath($variantFilepath, 'w+');
        $variantCsv->insertOne([
            'parent_import_uid', 'supplier_variant_sku', 'variant_id',
            'variant_key', 'attributes', 'pa_color', 'pa_size', 'stock',
            'first_arrival_date', 'first_arrival_qty', 'price',
            'image_main', 'image_gallery'
        ]);

        foreach ($products as $product) {
            $parentCsv->insertOne($product->toArray());

            foreach ($product->variants as $variant) {
                $variantCsv->insertOne($variant->toArray($product->importUid));
            }
        }
    }
}