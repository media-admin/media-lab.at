<?php
/* ============================================================
 * FILE: src/Models/Product.php
 * ============================================================ */

namespace SupplierSync\Models;

class Product {
    // Lieferanten-Identifikation (intern, NICHT im Frontend/Feed sichtbar)
    public string $supplierCode;    // Token: 'DIM' | 'LCC' | 'TKM'
    public string $supplierSku;     // = master_code bei MidOcean
    public string $importUid;       // z.B. "DIM|AR1804"

    // Produktdaten
    public string $productTitle;
    public string $productDescription;
    public array $categories = [];

    // Lager / Verfügbarkeit (Option A aus Notion-Dok)
    // supplierStockTotal wird NICHT hier aggregiert - Stock ist pro Variante
    // (MidOcean Stock API liefert Stock pro variant-SKU, nicht pro Parent).
    public string $leadTimeText = ''; // MidOcean liefert keine direkte Lieferzeit im Stock-File,
                                       // wird ggf. aus first_arrival_date berechnet (siehe Adapter).

    // Bilder (Parent-Level gibt es bei MidOcean nicht - Bilder sind pro Variante!)
    public string $imageMain = '';
    public array $imageGallery = [];

    // Varianten (bei MidOcean hat praktisch jedes Produkt mind. 1 Variante)
    /** @var ProductVariant[] */
    public array $variants = [];

    public function isVariable(): bool {
        return count($this->variants) > 1;
    }

    public function toArray(): array {
        return [
            'import_uid'            => $this->importUid,
            'supplier_code'         => $this->supplierCode,
            'supplier_sku'          => $this->supplierSku,
            'product_title'         => $this->productTitle,
            'product_description'   => $this->productDescription,
            'categories'            => implode('|', $this->categories),
            'lead_time_text'        => $this->leadTimeText,
            'has_variants'          => $this->isVariable() ? '1' : '0',
            'image_main'            => $this->imageMain,
            'image_gallery'         => implode('|', $this->imageGallery),
        ];
    }
}