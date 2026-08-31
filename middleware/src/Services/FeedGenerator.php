<?php
namespace SupplierSync\Services;

use League\Csv\Writer;

class FeedGenerator {
    public function generateCSV(array $products, string $filepath): void {
        $csv = Writer::createFromPath($filepath, 'w+');
        $csv->insertOne([
            'import_uid', 'parent_import_uid', 'supplier_code', 'supplier_sku',
            'supplier_sku_normalized', 'gtin_ean', 'master_code',
            'product_title', 'product_description', 'product_short_description', 'brand',
            'categories', 'tags', 'variation_attributes', 'variant_key',
            'image_main', 'image_gallery', 'image_source',
            'supplier_stock', 'supplier_stock_total', 'lead_time_text',
            'last_seen_import_at', 'last_import_source',
        ]);
        foreach ($products as $product) {
            $csv->insertOne($product->toArray());
        }
    }
}
