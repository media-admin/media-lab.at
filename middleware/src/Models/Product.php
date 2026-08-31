<?php
namespace SupplierSync\Models;

/**
 * Kanonisches Produkt-Modell (siehe "Canonical Product Model (ML)").
 *
 * WICHTIG: stock_inhouse ist bewusst NICHT Teil dieses Modells / des Feeds.
 * Es wird intern in WooCommerce gepflegt und darf durch einen Re-Import
 * nicht überschrieben werden (siehe WP All Import Feld-Mapping-Konfiguration:
 * stock_inhouse dort NICHT mappen).
 *
 * Ebenso werden hier keine Preise geführt (laut Dokument "aktuell optional,
 * da Preisquellen teils separat" — z. B. Cotton Classics teils per Mail).
 *
 * Jede Product-Instanz repräsentiert EINE VARIANTE (eine Feed-Zeile).
 * masterCode gruppiert mehrere Varianten zu einem WooCommerce-Parent-Produkt
 * (WP All Import: "Unique Identifier" für den Parent = supplierCode+masterCode,
 * getrennt vom Varianten-Identifier supplierCode+supplierSku).
 */
class Product {

    /* ---- 1) Identität / Referenzen (intern) -------------------------- */
    public string $supplierCode;             // LCC | DIM | TKM
    public string $supplierSku;               // Varianten-Artikelnummer des Lieferanten
    public ?string $supplierSkuNormalized = null;
    public ?string $gtinEan = null;           // intern, nie öffentlich ausgeben

    /** Gruppierungs-Schlüssel für Parent-Produkt (z. B. MidOcean master_code). */
    public ?string $masterCode = null;

    /* ---- 3) Produktstruktur ------------------------------------------ */
    public string $productTitle;
    public string $productDescription = '';
    public string $productShortDescription = '';
    public ?string $brand = null;
    /** @var string[] Kategorie-Pfade oder IDs, je nach Woo-Setup */
    public array $categories = [];
    /** @var string[] */
    public array $tags = [];

    /** @var array<string,string> z. B. ['pa_color' => 'red', 'pa_size' => 'l'] */
    public array $variationAttributes = [];
    public ?string $variantKey = null;

    /* ---- 4) Medien ----------------------------------------------------- */
    public ?string $imageMain = null;
    /** @var string[] */
    public array $imageGallery = [];
    public ?string $imageSource = null;       // 'url' | 'zip' | 'ftp'

    /* ---- 5) Lager / Verfügbarkeit / Lieferzeit ------------------------ */
    // stock_inhouse bewusst NICHT hier — siehe Klassenkommentar oben.
    public ?int $supplierStock = null;
    public ?int $supplierStockTotal = null;
    public ?string $leadTimeText = null;

    /* ---- 7) Import-Freshness & Safety ---------------------------------- */
    public ?string $lastSeenImportAt = null;
    public string $lastImportSource;

    /** Variante: "<supplier_code>|<supplier_sku>" — Unique Identifier je Variation. */
    public function importUid(): string {
        return $this->supplierCode . '|' . $this->supplierSku;
    }

    /** Parent: "<supplier_code>|<master_code>" — Unique Identifier fürs Eltern-Produkt. */
    public function parentImportUid(): ?string {
        if (!$this->masterCode) return null;
        return $this->supplierCode . '|' . $this->masterCode;
    }

    public function computedVariantKey(): ?string {
        if ($this->variantKey !== null) {
            return $this->variantKey;
        }
        if (empty($this->variationAttributes)) {
            return null;
        }
        $attrs = $this->variationAttributes;
        ksort($attrs);
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return implode('|', $parts);
    }

    public function toArray(): array {
        return [
            'import_uid'                 => $this->importUid(),
            'parent_import_uid'          => $this->parentImportUid(),
            'supplier_code'               => $this->supplierCode,
            'supplier_sku'                 => $this->supplierSku,
            'supplier_sku_normalized'      => $this->supplierSkuNormalized,
            'gtin_ean'                     => $this->gtinEan,
            'master_code'                  => $this->masterCode,

            'product_title'                => $this->productTitle,
            'product_description'          => $this->productDescription,
            'product_short_description'    => $this->productShortDescription,
            'brand'                         => $this->brand,
            'categories'                    => implode('|', $this->categories),
            'tags'                          => implode('|', $this->tags),
            'variation_attributes'          => json_encode($this->variationAttributes, JSON_UNESCAPED_UNICODE),
            'variant_key'                   => $this->computedVariantKey(),

            'image_main'                    => $this->imageMain,
            'image_gallery'                 => implode('|', $this->imageGallery),
            'image_source'                  => $this->imageSource,

            'supplier_stock'                => $this->supplierStock,
            'supplier_stock_total'          => $this->supplierStockTotal,
            'lead_time_text'                => $this->leadTimeText,

            'last_seen_import_at'           => $this->lastSeenImportAt ?? gmdate('Y-m-d H:i:s'),
            'last_import_source'            => $this->lastImportSource,
        ];
    }
}
