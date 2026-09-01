<?php
/* ============================================================
 * FILE: src/Models/ProductVariant.php
 * ============================================================ */

namespace SupplierSync\Models;

class ProductVariant {
    public string $supplierVariantSku;  // z.B. "AR1804-03" (master_code + color_code)
    public string $variantId;           // MidOcean "variant_id", z.B. "10168709"
    public array $attributes = [];      // z.B. ['pa_color' => 'black']
    public int $stock = 0;
    public ?string $firstArrivalDate = null;
    public ?int $firstArrivalQty = null;
    public ?float $price = null;
    public string $imageMain = '';
    public array $imageGallery = [];

    public function buildVariantKey(): string {
        $attrs = $this->attributes;
        ksort($attrs);
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return implode('|', $parts);
    }

    public function toArray(string $parentImportUid): array {
        return [
            'parent_import_uid'    => $parentImportUid,
            'supplier_variant_sku' => $this->supplierVariantSku,
            'variant_id'           => $this->variantId,
            'variant_key'          => $this->buildVariantKey(),
            'attributes'           => json_encode($this->attributes),
            'stock'                => $this->stock,
            'first_arrival_date'   => $this->firstArrivalDate,
            'first_arrival_qty'    => $this->firstArrivalQty,
            'price'                => $this->price,
            'image_main'           => $this->imageMain,
            'image_gallery'        => implode('|', $this->imageGallery),
        ];
    }
}
