<?php
/* ============================================================
 * FILE: src/Adapters/MidOceanAdapter.php
 *
 * Basiert auf dem echten MidOcean "API implementation guide" (v1.12)
 * UND auf echten, verifizierten API-Calls (31.08.2026):
 *   - products/2.0   -> Antwort ist reine Presigned-URL als Text,
 *                        dahinter ein direktes JSON-Array von Produkten
 *                        mit eingebetteten "variants"
 *   - stock/2.0      -> Antwort ist JSON {"preSignedUrl": "..."},
 *                        dahinter {"stock": [{sku, qty, ...}]}
 *   - pricelist/2.0  -> Bei Erstnutzung: Warning-Wrapper
 *                        {"PRICELIST_RESPONSE": {"RETURN_STATUS": "Warning", ...}}
 *                        Danach (verifiziert): direktes JSON
 *                        {"price": [{sku, variant_id, price, valid_until}]}
 *
 * WICHTIG: API-Key wird per Header "x-Gateway-APIKey" übergeben.
 * ============================================================ */

namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;
use SupplierSync\Models\ProductVariant;

class MidOceanAdapter extends AbstractAdapter {

    private const SUPPLIER_CODE = 'DIM';
    private const BASE_URL_PROD = 'https://api.midocean.com/gateway';
    private const BASE_URL_TEST = 'https://apitest.midocean.com/gateway';

    private array $stockBySku = [];
    private array $priceBySku = [];

    /** @var \Psr\Log\LoggerInterface|null Optional - wird über sync.php injiziert */
    private ?\Psr\Log\LoggerInterface $logger = null;

    public function setLogger(\Psr\Log\LoggerInterface $logger): void {
        $this->logger = $logger;
    }

    public function fetchProducts(): array {
        $baseUrl = ($this->config['use_test_environment'] ?? false)
            ? self::BASE_URL_TEST
            : self::BASE_URL_PROD;

        // Reihenfolge wichtig: Stock + Preise zuerst laden, damit sie beim
        // Aufbau der Varianten direkt zugeordnet werden können.
        $this->stockBySku = $this->fetchStock($baseUrl);
        $this->priceBySku = $this->fetchPricelist($baseUrl);

        $language = $this->config['language'] ?? 'de';
        $rawProducts = $this->fetchViaPresignedUrl(
            "{$baseUrl}/products/2.0?language={$language}"
        );

        // BESTÄTIGT (echter API-Call, 31.08.2026): Antwort ist ein direktes
        // JSON-Array von Produkt-Objekten, kein Wrapper-Key.
        $products = [];
        foreach ($rawProducts as $rawProduct) {
            $products[] = $this->transformToProduct($rawProduct);
        }

        return $products;
    }

    private function fetchStock(string $baseUrl): array {
        $body = $this->fetchViaPresignedUrl("{$baseUrl}/stock/2.0");
        $stockEntries = is_array($body) && isset($body['stock']) ? $body['stock'] : $body;

        $bySku = [];
        foreach ((array) $stockEntries as $entry) {
            $sku = $entry['sku'] ?? null;
            if (!$sku) continue;
            $bySku[$sku] = [
                'qty'                => (int) ($entry['qty'] ?? 0),
                'first_arrival_date' => $entry['first_arrival_date'] ?? null,
                'first_arrival_qty'  => isset($entry['first_arrival_qty']) ? (int) $entry['first_arrival_qty'] : null,
            ];
        }
        return $bySku;
    }

    private function fetchPricelist(string $baseUrl): array {
        $body = $this->fetchViaPresignedUrl("{$baseUrl}/pricelist/2.0");

        // Sonderfall (BESTÄTIGT 31.08.2026): Bei Erstnutzung / Neugenerierung
        // liefert MidOcean statt Daten einen Warning-Wrapper:
        // {"PRICELIST_RESPONSE": {"RETURN_STATUS": "Warning", "STATUS_TEXT": "..."}}
        // In diesem Fall ist die Pricelist noch nicht bereit.
        if (isset($body['PRICELIST_RESPONSE']['RETURN_STATUS'])
            && $body['PRICELIST_RESPONSE']['RETURN_STATUS'] === 'Warning') {
            $this->logger?->warning(
                'MidOcean Pricelist noch nicht verfügbar: ' .
                ($body['PRICELIST_RESPONSE']['STATUS_TEXT'] ?? 'Kein Statustext')
            );
            // Leeres Array zurückgeben statt Exception - der Rest des Syncs
            // (Produkte, Stock) soll trotzdem laufen können.
            return [];
        }

        $priceEntries = is_array($body) && isset($body['price']) ? $body['price'] : $body;

        $bySku = [];
        foreach ((array) $priceEntries as $entry) {
            $sku = $entry['sku'] ?? null;
            if (!$sku) continue;
            // MidOcean liefert Preise als String mit Komma als Dezimaltrenner ("4,22")
            $bySku[$sku] = $this->parseMidOceanDecimal((string) ($entry['price'] ?? '0'));
        }
        return $bySku;
    }

    /**
     * MidOcean's Endpoints liefern je nach Endpoint eine von zwei
     * "Presigned URL"-Varianten:
     *
     *   - products/2.0: Antwort-Body ist die reine URL als Plaintext
     *   - stock/2.0:    Antwort ist JSON mit Feld {"preSignedUrl": "..."}
     *
     * Diese Methode erkennt beide Fälle automatisch und lädt im Bedarfsfall
     * die eigentlichen Daten von der Presigned-URL nach. Liefert die erste
     * Antwort weder eine URL noch ein preSignedUrl-Feld, wird angenommen,
     * dass es sich bereits um die fertigen Nutzdaten handelt (z.B. der
     * Pricelist-Warning-Wrapper bei Erstnutzung).
     */
    private function fetchViaPresignedUrl(string $url): array {
        $firstResponse = $this->apiClient->get($url, [
            'headers' => [
                'x-Gateway-APIKey' => $this->config['api_key'],
                'Accept'           => 'text/json',
            ],
        ]);

        $rawBody = trim($firstResponse->getBody()->getContents());
        $presignedUrl = null;

        // Fall 1: reiner Text, der mit "http" beginnt (products/2.0-Stil)
        if (str_starts_with($rawBody, 'http')) {
            $presignedUrl = $rawBody;
        } else {
            // Fall 2: JSON mit "preSignedUrl"-Feld (stock/2.0-Stil) ODER
            // die Antwort ist bereits die eigentliche Nutzdaten-Struktur.
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (isset($decoded['preSignedUrl'])) {
                    $presignedUrl = $decoded['preSignedUrl'];
                } else {
                    // Kein Presigned-URL-Pattern, sondern schon die fertigen Daten
                    return $decoded;
                }
            } else {
                throw new \RuntimeException(
                    'MidOcean: Unbekanntes Antwortformat bei ' . $url .
                    ' - weder URL-Text noch valides JSON. Body-Anfang: ' .
                    substr($rawBody, 0, 200)
                );
            }
        }

        $dataResponse = $this->apiClient->get($presignedUrl, [
            'timeout' => 120, // Dateien können mehrere MB groß sein
        ]);
        $rawData = $dataResponse->getBody()->getContents();

        $decoded = json_decode($rawData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'MidOcean: Konnte Nutzdaten von Presigned-URL nicht als JSON parsen. ' .
                'Ursprungs-URL: ' . $url . ' JSON-Fehler: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    protected function transformToProduct(array $raw): Product {
        $product = new Product();

        $product->supplierCode = self::SUPPLIER_CODE;
        $product->supplierSku  = trim((string) ($raw['master_code'] ?? ''));
        $product->importUid    = self::SUPPLIER_CODE . '|' . $product->supplierSku;

        $product->productTitle = trim((string) ($raw['product_name'] ?? ''));

        $product->productDescription = $this->sanitizeDescription(
            (string) ($raw['long_description'] ?? $raw['short_description'] ?? '')
        );

        // BESTÄTIGT: category_level1/2/3 liegen auf Varianten-Ebene und sind
        // deutlich spezifischer als das grobe "product_class" auf Parent-Ebene
        // (z.B. "Outdoor & Freizeit" > "Sport & Gesundheit" > "Lauf- & Wanderzubehör").
        // Wir nehmen die Kategorie der ersten Variante als Produkt-Kategorie an,
        // da alle Varianten eines Produkts i.d.R. dieselbe Kategorie teilen.
        $firstVariant = $raw['variants'][0] ?? null;
        if ($firstVariant && !empty($firstVariant['category_level1'])) {
            $levels = array_filter([
                $firstVariant['category_level1'] ?? null,
                $firstVariant['category_level2'] ?? null,
                $firstVariant['category_level3'] ?? null,
            ]);
            $product->categories[] = $this->mapCategory(implode(' > ', $levels));
        } elseif (!empty($raw['product_class'])) {
            // Fallback, falls category_level1 mal fehlen sollte
            $product->categories[] = $this->mapCategory((string) $raw['product_class']);
        }

        // BESTÄTIGT (echter Call 31.08.2026): "variants" ist der korrekte Key.
        $rawVariants = $raw['variants'] ?? [];
        foreach ($rawVariants as $rawVariant) {
            $product->variants[] = $this->transformVariant($rawVariant);
        }

        // Falls das Produkt gar keine Varianten hat (Randfall), Bild vom
        // Parent übernehmen, falls vorhanden.
        if (empty($product->variants) && !empty($raw['digital_assets'])) {
            $this->applyImagesFromDigitalAssets($raw['digital_assets'], $product->imageMain, $product->imageGallery);
        }

        return $product;
    }

    private function transformVariant(array $raw): ProductVariant {
        $variant = new ProductVariant();

        $sku = trim((string) ($raw['sku'] ?? ''));
        $variant->supplierVariantSku = $sku;
        $variant->variantId = (string) ($raw['variant_id'] ?? '');

        if (isset($raw['color_description'])) {
            $variant->attributes['pa_color'] = strtolower(trim((string) $raw['color_description']));
        }
        // Textilien haben zusätzlich eine Größe - Feldname noch nicht mit
        // einem echten Textil-Produkt-Response verifiziert. SKU-Suffixe wie
        // "-L", "-XXL" (siehe Pricelist-Sample) bestätigen aber, dass Größen
        // existieren - Fallback auf "size_description" bis final geprüft.
        if (isset($raw['size_description'])) {
            $variant->attributes['pa_size'] = strtolower(trim((string) $raw['size_description']));
        }

        // Stock- und Preisdaten aus den vorab geladenen Maps zuordnen
        if (isset($this->stockBySku[$sku])) {
            $stockInfo = $this->stockBySku[$sku];
            $variant->stock = $stockInfo['qty'];
            $variant->firstArrivalDate = $stockInfo['first_arrival_date'];
            $variant->firstArrivalQty = $stockInfo['first_arrival_qty'];
        }

        if (isset($this->priceBySku[$sku])) {
            $variant->price = $this->priceBySku[$sku];
        }

        if (!empty($raw['digital_assets'])) {
            $this->applyImagesFromDigitalAssets($raw['digital_assets'], $variant->imageMain, $variant->imageGallery);
        }

        return $variant;
    }

    /**
     * digital_assets ist ein Array von {url, url_highress, type, subtype}.
     * Wir nehmen "item_picture_front" als Hauptbild, alles andere als Galerie.
     */
    private function applyImagesFromDigitalAssets(array $assets, string &$imageMain, array &$imageGallery): void {
        foreach ($assets as $asset) {
            if (($asset['type'] ?? '') !== 'image') continue;

            $url = (string) ($asset['url'] ?? '');
            if ($url === '') continue;

            if (($asset['subtype'] ?? '') === 'item_picture_front' && $imageMain === '') {
                $imageMain = $url;
            } else {
                $imageGallery[] = $url;
            }
        }
    }

    private function sanitizeDescription(string $html): string {
        return trim(strip_tags($html, '<p><br><ul><li><strong><em>'));
    }

    /**
     * MidOcean liefert Preise als String mit Komma als Dezimaltrenner,
     * z.B. "4,22" statt "4.22".
     */
    private function parseMidOceanDecimal(string $value): float {
        $normalized = str_replace(',', '.', trim($value));
        return (float) $normalized;
    }
}