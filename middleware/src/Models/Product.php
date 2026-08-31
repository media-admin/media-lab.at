<?php
namespace SupplierSync\Models;

class Product {
    public string $supplierCode;      // z.B. 'LCC', 'MDO', 'MKT' — wird zu _ml_supplier_code
    public string $supplierSku;       // Original-Artikelnummer des Lieferanten — wird zu _ml_supplier_sku
    public string $name;
    public string $description;
    public float $price;
    public int $stockSupplier;        // supplier_stock, NICHT stock_inhouse (das verwaltet ihr separat)
    public array $categories;
    public array $images;
    public array $attributes;         // z.B. ['color' => 'Rot', 'size' => 'M']
    public ?string $variantKey = null; // Attribut-Kombination, für stabile Variant-Zuordnung

    /**
     * Eindeutige Kennung fürs WP All Import Unique-Identifier-Feld.
     * NICHT die WooCommerce-SKU — die vergibt das ML-SKU-Plugin separat.
     */
    public function importUid(): string {
        return $this->supplierCode . '-' . $this->supplierSku;
    }

    public function toArray(): array {
        return [
            'import_uid'       => $this->importUid(),
            'supplier_code'    => $this->supplierCode,
            'supplier_sku'     => $this->supplierSku,
            'name'             => $this->name,
            'description'      => $this->description,
            'price'            => $this->price,
            'stock_supplier'   => $this->stockSupplier,
            'categories'       => implode('|', $this->categories),
            'images'           => implode('|', $this->images),
            'attributes'       => json_encode($this->attributes, JSON_UNESCAPED_UNICODE),
            'variant_key'      => $this->variantKey,
        ];
    }
}
