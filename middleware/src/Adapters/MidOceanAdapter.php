<?php
namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;

/**
 * MidOcean (DIM) — Eingang: API (Prod/Test), siehe "Mapping – MidOcean (DIM)".
 *
 * WICHTIG: Die genauen JSON-Feldnamen unten sind Annahmen basierend auf dem
 * Mapping-Dokument (name/title, description/html, color/size, stock,
 * deliveryTime/availability, image URLs) — noch NICHT gegen die echte
 * MidOcean-API-Antwort verifiziert. Vor dem ersten echten Testlauf mit
 * `var_dump()`/Logging der Rohantwort abgleichen und anpassen.
 *
 * Offene Punkte aus dem Mapping-Dokument:
 *  - Welche Endpoints/Entities liefern die Variantenstruktur am zuverlässigsten
 *  - Standardisierung der Lieferzeittexte
 *  - Umgang mit kommenden Stock-Lieferungen (falls API das liefert)
 */
class MidOceanAdapter extends AbstractAdapter {

    /** @return Product[] */
    public function fetchProducts(): array {
        $response = $this->apiClient->get($this->config['api_url'], [
            'headers' => [
                // TODO: exaktes Auth-Schema prüfen (Bearer? API-Key-Header? Basic Auth?)
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Accept'        => 'application/json',
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        // TODO: prüfen, ob die Antwort direkt ein Array ist oder unter einem
        // Wrapper-Key liegt (z. B. $data['products'] oder $data['data']).
        $rawProducts = $data['products'] ?? $data ?? [];

        $products = [];
        foreach ($rawProducts as $raw) {
            $products[] = $this->mapToProduct($raw);
        }

        return $products;
    }

    private function mapToProduct(array $raw): Product {
        $product = new Product();

        $product->supplierCode      = $this->config['supplier_code']; // 'DIM'
        $product->supplierSku       = trim($raw['sku'] ?? $raw['id'] ?? '');

        $product->productTitle       = $raw['name'] ?? $raw['title'] ?? '';
        $product->productDescription = $raw['description'] ?? $raw['html'] ?? '';

        // Kategorien laut Mapping-Doc noch offen — bis dahin roh übernehmen
        // und via category_mapping (config/suppliers.php) übersetzen.
        if (!empty($raw['category'])) {
            $product->categories = [$this->mapCategory($raw['category'])];
        }

        // Variantenattribute: color/size laut Mapping-Doc
        $attrs = [];
        if (!empty($raw['color'])) $attrs['pa_color'] = $raw['color'];
        if (!empty($raw['size']))  $attrs['pa_size']  = $raw['size'];
        $product->variationAttributes = $attrs;

        $product->supplierStock      = isset($raw['stock']) ? (int) $raw['stock'] : null;
        $product->supplierStockTotal = $product->supplierStock; // Single-Supplier-Fall — aggregiert = gleich

        // TODO: deliveryTime/availability-Feld prüfen und normalisieren
        // (Mapping-Doc: "z. B. '2–5 Werktage'")
        $product->leadTimeText = $raw['deliveryTime'] ?? $raw['availability'] ?? null;

        // Bild-URLs — TODO: Struktur prüfen (einzelnes Feld vs. Array,
        // Haupt- vs. Galeriebild separat oder gemeinsam geliefert)
        if (!empty($raw['images']) && is_array($raw['images'])) {
            $product->imageMain    = $raw['images'][0] ?? null;
            $product->imageGallery = $raw['images'];
        } elseif (!empty($raw['image'])) {
            $product->imageMain = $raw['image'];
        }
        $product->imageSource = 'url';

        $product->lastImportSource = 'midocean';

        return $product;
    }
}
