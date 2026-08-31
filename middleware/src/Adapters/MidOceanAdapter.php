<?php
namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;

/**
 * MidOcean (DIM) — echte API, verifiziert gegen die Postman Collection
 * "midocean REST API (Media Lab)" und echte Beispiel-Responses.
 *
 * Produkte:  GET https://api.midocean.com/gateway/products/2.0?language=de
 *            (antwortet mit 303-Redirect auf eine vorsignierte S3-URL —
 *            Guzzle folgt dem automatisch, KEIN manuelles Handling nötig)
 * Stock:     GET https://api.midocean.com/gateway/stock/2.0
 *            Response: {"modified_at": "...", "stock": [{"sku": "...", "qty": N}, ...]}
 * Preise:    GET https://api.midocean.com/gateway/pricelist/2.0/   (separater Endpoint, optional)
 * Auth:      Header "x-Gateway-APIKey: <api_key>"
 *
 * Jeder "master"-Eintrag im Products-Response enthält ein variants[]-Array.
 * Wir erzeugen EINE Product-Zeile PRO VARIANTE. master_code gruppiert die
 * Varianten zu einem WooCommerce-Parent-Produkt (Product::parentImportUid()).
 *
 * WICHTIG — Größe/Kapazität: Das Produkt-API liefert AUSSCHLIESSLICH Farbe
 * als strukturiertes Variant-Attribut (color_description/color_group/
 * color_code/pms_color). Es gibt KEIN separates Größen-Feld. Das zweite
 * SKU-Segment (z. B. "-M" bei Textilien, aber auch "-4GB" bei USB-Sticks —
 * beides KEINE Größe im engeren Sinn) ist uneinheitlich. Wir parsen daher
 * nur dann pa_size aus dem letzten SKU-Segment, wenn es zu einer bekannten
 * Kleidergrößen-Liste passt — sonst bleibt pa_size leer, statt Kapazitäten
 * o. ä. fälschlich als Größe zu interpretieren.
 */
class MidOceanAdapter extends AbstractAdapter {

    /** Bekannte Kleidergrößen-Tokens, case-insensitive geprüft. */
    private const KNOWN_SIZES = [
        'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL',
        '2XL', '3XL', '4XL', '5XL',
    ];

    /** @return Product[] */
    public function fetchProducts(): array {
        $response = $this->apiClient->get($this->config['api_url'], [
            'headers' => [
                'x-Gateway-APIKey' => $this->config['api_key'],
                'Accept'           => 'application/json',
            ],
            'query' => [
                'language' => $this->config['language'] ?? 'de',
            ],
        ]);

        $masters = json_decode((string) $response->getBody(), true) ?? [];
        $stockByVariantSku = $this->fetchStock();

        $products = [];
        foreach ($masters as $master) {
            foreach ($this->mapMasterToProducts($master, $stockByVariantSku) as $product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return Product[] eine Product-Instanz pro Variante des Masters
     */
    private function mapMasterToProducts(array $master, array $stockByVariantSku): array {
        $products = [];

        $masterCode  = $master['master_code'] ?? '';
        // Annahme: short_description ist der eigentliche Produkttitel,
        // product_name eher ein interner Kollektions-/Familienname.
        $title       = $master['short_description'] ?? $master['product_name'] ?? '';
        $description = $master['long_description'] ?? '';
        $brand       = $master['brand'] ?? null;
        $categoryPath = $this->buildMasterCategoryPath($master);

        foreach ($master['variants'] ?? [] as $variant) {
            $product = new Product();

            $product->supplierCode = $this->config['supplier_code']; // 'DIM'
            $product->supplierSku  = trim($variant['sku'] ?? '');
            $product->masterCode   = $masterCode;
            $product->gtinEan      = $variant['gtin'] ?? null;

            $product->productTitle             = $title;
            $product->productDescription        = $description;
            $product->productShortDescription   = $master['short_description'] ?? '';
            $product->brand                      = $brand;

            $variantCategoryPath = array_filter([
                $variant['category_level1'] ?? null,
                $variant['category_level2'] ?? null,
                $variant['category_level3'] ?? null,
            ]);
            $product->categories = $variantCategoryPath ?: $categoryPath;

            $attrs = [];
            if (!empty($variant['color_description'])) {
                $attrs['pa_color'] = $variant['color_description'];
            }
            $size = $this->extractKnownSizeFromSku($product->supplierSku);
            if ($size !== null) {
                $attrs['pa_size'] = $size;
            }
            $product->variationAttributes = $attrs;

            $images = array_values(array_filter(
                $variant['digital_assets'] ?? [],
                fn($a) => ($a['type'] ?? '') === 'image'
            ));
            $frontImage = null;
            foreach ($images as $img) {
                if (($img['subtype'] ?? '') === 'item_picture_front') {
                    $frontImage = $img['url'];
                    break;
                }
            }
            $product->imageMain    = $frontImage ?? ($images[0]['url'] ?? null);
            $product->imageGallery = array_column($images, 'url');
            $product->imageSource  = 'url';

            if (isset($stockByVariantSku[$product->supplierSku])) {
                $product->supplierStock      = $stockByVariantSku[$product->supplierSku];
                $product->supplierStockTotal = $product->supplierStock;
            }

            $product->lastImportSource = 'midocean';

            $products[] = $product;
        }

        return $products;
    }

    /**
     * Prüft das letzte SKU-Segment gegen eine bekannte Kleidergrößen-Liste.
     * "MO1001a-03-4GB" -> "4GB" ist keine bekannte Größe -> null
     * "S02071-RB-M"    -> "M" ist eine bekannte Größe -> "M"
     */
    private function extractKnownSizeFromSku(string $sku): ?string {
        $parts = explode('-', $sku);
        $last = strtoupper(end($parts));
        return in_array($last, self::KNOWN_SIZES, true) ? $last : null;
    }

    private function buildMasterCategoryPath(array $master): array {
        return array_filter([
            $master['category_code'] ?? null,
            $master['product_class'] ?? null,
        ]);
    }

    /**
     * Ruft den Stock-Endpoint ab und baut eine Lookup-Map variant_sku => qty.
     * Response-Struktur verifiziert: {"modified_at": "...", "stock": [{"sku": "...", "qty": N}, ...]}
     *
     * @return array<string,int>
     */
    private function fetchStock(): array {
        if (empty($this->config['stock_api_url'])) {
            return [];
        }

        $response = $this->apiClient->get($this->config['stock_api_url'], [
            'headers' => [
                'x-Gateway-APIKey' => $this->config['api_key'],
                'Accept'           => 'application/json',
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true) ?? [];
        $rows = $data['stock'] ?? [];

        $map = [];
        foreach ($rows as $row) {
            $sku = $row['sku'] ?? null;
            $qty = $row['qty'] ?? null;
            if ($sku !== null && $qty !== null) {
                $map[$sku] = (int) $qty;
            }
        }

        return $map;
    }
}
