<?php
namespace SupplierSync\Services;

use League\Csv\Writer;

class FeedGenerator {
    public function generateCSV(array $products, string $filepath): void {
        $csv = Writer::createFromPath($filepath, 'w+');
        $csv->insertOne([
            'import_uid', 'supplier_code', 'supplier_sku', 'name', 'description',
            'price', 'stock_supplier', 'categories', 'images', 'attributes', 'variant_key'
        ]);
        foreach ($products as $product) {
            $csv->insertOne($product->toArray());
        }
    }
}
