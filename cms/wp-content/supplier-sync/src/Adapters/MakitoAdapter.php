<?php
/**
 * FILE: src/Adapters/MakitoAdapter.php
 *
 * Makito (TKM) – dateibasierte XML-Feeds (keine echte REST-API), erreichbar
 * über feste URLs mit kundenspezifischem "pszinternal"-Token in der URL
 * (kein API-Key-Header wie bei MidOcean).
 *
 * Produkte:  http://print.makito.es:8080/user/xml/ItemDataFile.php?pszinternal=<token>
 * Stock:     http://print.makito.es:8080/user/xml/allstockfile.php?pszinternal=<token>
 *
 * Beide gegen echte Beispiel-Responses verifiziert (31.08.2026, Vorsession).
 *
 * WICHTIG - Feldname-Inkonsistenz zwischen den beiden Feeds:
 *   ItemDataFile:  <refct>   (Produkt-Feed, Varianten-Referenz)
 *   allstockfile:  <reftc>   (Stock-Feed, Buchstaben vertauscht!)
 * Die WERTE stimmen überein (z.B. "5200AZULS/T"), nur der Tag-Name
 * unterscheidet sich - das ist der Merge-Schlüssel zwischen den Feeds.
 *
 * Struktur Produkt-Feed:
 *   <catalog><product>
 *     <ref>            Master-Code (z.B. "5200")
 *     <name>            eher Spec/Untertitel als eigentlicher Titel
 *     <type>            wirkt wie der eigentliche Produktname
 *     <otherinfo>       kurze Zusatzinfo
 *     <extendedinfo>    volle Marketingbeschreibung
 *     <brand>
 *     <imagemain>, <images><image><imagemax>...
 *     <categories><category_name_1..5>
 *     <variants><variant>
 *       <matnr>         interne Lagernummer (NICHT als SKU verwendet)
 *       <refct>         Varianten-Referenz -> wird supplier_variant_sku
 *       <colour>, <colftp>, <colourname>
 *       <size>          "S/T" = "sin talla" (spanisch: ohne Größe) -> KEIN pa_size
 *       <image500px>    variantenspezifisches Bild
 *
 * Struktur Stock-Feed:
 *   <catalog><product>
 *     <matnr>, <reftc>, <ref>, <colour>, <colftp>, <size>, <from>, <stock>, <available>
 *
 * OFFEN / TODO (aus Vorsession übernommen, noch nicht final geklärt):
 *  - matnr vs. refct als Unique Identifier: refct gewählt (wirkt wie
 *    Bestell-/Referenzcode), matnr wie reine Lagernummer - gegenchecken,
 *    falls Bestellungen später matnr erwarten.
 *  - Preise (PriceListFile.php) und Druckflächen (ItemPrintingFile.php)
 *    noch nicht angebunden.
 *  - "ldl"-Sprachparameter noch nicht genutzt.
 */

namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;
use SupplierSync\Models\ProductVariant;

class MakitoAdapter extends AbstractAdapter {

    /** Größen-Werte, die "keine Größe" bedeuten und NICHT als pa_size gesetzt werden. */
    private const NO_SIZE_VALUES = ['S/T', ''];

    /**
     * Farb-Werte, die "keine Farbe" bedeuten (analog zu S/T bei Größen -
     * vermutlich "sin color", bestätigt per echten Daten 05.09.2026) und
     * NICHT als pa_color gesetzt werden.
     */
    private const NO_COLOR_VALUES = ['S/C', ''];

    public function fetchProducts(): array {
        $productXml = $this->fetchXml($this->config['api_url']);
        $stockByRef = $this->fetchStock();
        $priceByMaster = $this->fetchPriceList();

        $products = [];
        foreach ($productXml->product as $masterNode) {
            // AbstractAdapter verlangt "array $rawData" (siehe MidOceanAdapter-
            // Signatur, an die wir uns hier halten müssen - PHP erlaubt keine
            // Verengung der Elternklassen-Signatur). Wir wickeln den
            // SimpleXMLElement-Knoten daher einfach in ein 1-Element-Array,
            // statt ihn verlustbehaftet in ein Array zu konvertieren.
            $product = $this->transformToProduct(['_xml' => $masterNode]);
            if ($product !== null) {
                $this->applyStock($product, $stockByRef);
                $this->applyPricing($product, $priceByMaster);
                $products[] = $product;
            }
        }

        return $products;
    }

    protected function transformToProduct(array $rawData) {
        /** @var \SimpleXMLElement $masterNode */
        $masterNode = $rawData['_xml'];

        $masterCode = trim((string) $masterNode->ref);

        // <type> wirkt wie der eigentliche Produktname, <name> eher ein
        // Spec-Untertitel (z.B. "40 L/m2") - beide kombiniert als Titel.
        $type = trim((string) $masterNode->type);
        $name = trim((string) $masterNode->name);
        $title = trim($type . ' ' . $name);
        if ($title === '') {
            $title = $masterCode; // Fallback, sollte praktisch nie eintreten
        }

        if (!isset($masterNode->variants->variant)) {
            return null; // Master ohne Varianten - nichts zu importieren
        }

        $product = new Product();
        $product->supplierCode = $this->config['supplier_code']; // 'TKM'
        $product->supplierSku  = $masterCode;
        $product->importUid    = $product->supplierCode . '|' . $masterCode;

        $product->productTitle       = $title;
        $product->productDescription = $this->sanitizeDescription((string) $masterNode->extendedinfo);

        if (isset($masterNode->categories)) {
            for ($i = 1; $i <= 5; $i++) {
                $catField = "category_name_{$i}";
                $val = trim((string) $masterNode->categories->{$catField});
                if ($val !== '') {
                    $product->categories[] = $this->mapCategory($val);
                }
            }
        }

        $productImageMain = trim((string) $masterNode->imagemain);
        $productGallery = [];
        if (isset($masterNode->images->image)) {
            foreach ($masterNode->images->image as $img) {
                $url = trim((string) $img->imagemax);
                if ($url !== '') {
                    $productGallery[] = $url;
                }
            }
        }

        foreach ($masterNode->variants->variant as $variantNode) {
            $product->variants[] = $this->transformVariant($variantNode, $productImageMain, $productGallery);
        }

        // Parent-Vorschaubild: erste Variante mit eigenem Bild, sonst
        // Master-Bild als Fallback (gleiches Prinzip wie beim MidOcean-Adapter).
        foreach ($product->variants as $variant) {
            if ($variant->imageMain !== '') {
                $product->imageMain = $variant->imageMain;
                $product->imageGallery = $variant->imageGallery;
                break;
            }
        }
        if ($product->imageMain === '' && $productImageMain !== '') {
            $product->imageMain = $productImageMain;
            $product->imageGallery = $productGallery;
        }

        return $product;
    }

    private function transformVariant(\SimpleXMLElement $variantNode, string $productImageMain, array $productGallery): ProductVariant {
        $variant = new ProductVariant();

        $variant->supplierVariantSku = trim((string) $variantNode->refct);
        $variant->variantId = trim((string) $variantNode->matnr); // interne Lagernummer, nur zur Referenz

        $colourName = trim((string) $variantNode->colourname);
        $colour     = trim((string) $variantNode->colour);
        $colourValue = $colourName !== '' ? $colourName : $colour;
        if ($colourValue !== '' && !in_array(strtoupper($colourValue), self::NO_COLOR_VALUES, true)) {
            // mb_strtolower() statt strtolower(): Letzteres ist NICHT UTF-8-
            // sicher und lässt Mehrbyte-Umlaute wie "Ü" unverändert, während
            // nur reine ASCII-Buchstaben klein geschrieben werden - Ergebnis
            // wäre "GRÜN" -> "grÜn" statt korrekt "grün" (bestätigt per
            // echten Daten 05.09.2026).
            $variant->attributes['pa_color'] = mb_strtolower($colourValue, 'UTF-8');
        }

        $size = trim((string) $variantNode->size);
        if (!in_array(strtoupper($size), self::NO_SIZE_VALUES, true)) {
            $variant->attributes['pa_size'] = mb_strtolower($size, 'UTF-8');
        }

        $variantImage = trim((string) $variantNode->image500px);
        $variant->imageMain = $variantImage !== '' ? $variantImage : $productImageMain;
        $variant->imageGallery = $productGallery;

        return $variant;
    }

    /**
     * @return array<string, array{qty:int, available:?string, from:?string}> reftc-Wert => Stock-Infos
     */
    private function fetchStock(): array {
        if (empty($this->config['stock_api_url'])) {
            return [];
        }

        $xml = $this->fetchXml($this->config['stock_api_url']);

        $map = [];
        foreach ($xml->product as $row) {
            // ACHTUNG: hier heißt das Feld <reftc>, nicht <refct> (siehe Klassenkommentar)
            $ref = trim((string) $row->reftc);
            if ($ref === '') continue;

            // BESTÄTIGT per echten Daten (05.09.2026): Makito nutzt "." als
            // TAUSENDERTRENNZEICHEN, nicht als Dezimalpunkt (Beweis: Werte wie
            // "1.000.000" mit zwei Punkten). Ein naives (int)-Cast würde bei
            // solchen Werten nur bis zum zweiten Punkt lesen und den Bestand
            // um den Faktor 1000+ unterschätzen - Punkte müssen vor dem Cast
            // entfernt werden.
            $rawStock = (string) $row->stock;
            $qty = (int) str_replace('.', '', $rawStock);

            // <from> enthält oft nur Platzhalter wie "+" statt eines echten
            // Datums (bestätigt per echten Daten), selbst wenn stock > 0 und
            // available = "immediately" ist. Nur Werte behandeln, die mit
            // einer Ziffer beginnen (heuristische Erkennung eines Datums) -
            // alles andere (z.B. "+") wird ignoriert.
            $fromRaw = trim((string) $row->from);
            $from = ($fromRaw !== '' && ctype_digit($fromRaw[0])) ? $fromRaw : null;

            $map[$ref] = [
                'qty'       => $qty,
                'available' => trim((string) $row->available) ?: null,
                'from'      => $from,
            ];
        }

        return $map;
    }

    private function applyStock(Product $product, array $stockByRef): void {
        foreach ($product->variants as $variant) {
            if (!isset($stockByRef[$variant->supplierVariantSku])) continue;

            $stock = $stockByRef[$variant->supplierVariantSku];
            $variant->stock = $stock['qty'];

            // Lead-Time-Regel laut Mapping-Doc: wenn kommende Ware mit Datum
            // vorhanden ist, hat das Vorrang vor dem allgemeinen "available"-Text.
            if ($stock['from'] !== null) {
                $product->leadTimeText = 'ab ' . $stock['from'];
            } elseif ($stock['available'] !== null && $stock['available'] !== 'immediately') {
                $product->leadTimeText = $stock['available'];
            }
        }
    }

    /**
     * @return array<string, array{price1: float, tiers: array}> Master-ref => Preisinfos
     *
     * BESTÄTIGT per echten Daten (05.09.2026): Die Preisliste ist auf
     * MASTER-Ebene (<ref>), NICHT pro Variante wie bei MidOcean - alle
     * Farben/Größen eines Produkts teilen sich denselben Preis. Zusätzlich
     * liefert Makito gestaffelte Mengenpreise (section1..4/price1..4, z.B.
     * "-500" / "500" / "2000" / "5000" Stück) als ABSOLUTE Preise pro Stück.
     */
    private function fetchPriceList(): array {
        if (empty($this->config['price_api_url'])) {
            return [];
        }

        $xml = $this->fetchXml($this->config['price_api_url']);

        $map = [];
        foreach ($xml->product as $row) {
            $ref = trim((string) $row->ref);
            if ($ref === '') continue;

            $tiers = [];
            for ($i = 1; $i <= 4; $i++) {
                $sectionField = "section{$i}";
                $priceField = "price{$i}";
                $section = trim((string) $row->{$sectionField});
                $price = trim((string) $row->{$priceField});
                if ($section === '' || $price === '') continue;
                $tiers[] = ['section' => $section, 'price' => (float) $price];
            }

            if (empty($tiers)) continue;

            $map[$ref] = [
                'price1' => $tiers[0]['price'],
                'tiers'  => $tiers,
            ];
        }

        return $map;
    }

    private function applyPricing(Product $product, array $priceByMaster): void {
        if (!isset($priceByMaster[$product->supplierSku])) return;

        $priceInfo = $priceByMaster[$product->supplierSku];
        $price1 = $priceInfo['price1'];

        // Einzelpreis: price1 (Basis-Staffel, kleinste Menge) für alle
        // Varianten dieses Masters - Makito differenziert Preise nicht
        // nach Farbe/Größe.
        foreach ($product->variants as $variant) {
            $variant->price = $price1;
        }

        // Mengenstaffel als Rabatt-Prozentsatz relativ zu price1 umrechnen -
        // gleiches Datenformat wie das bestehende ACF-Feld "tier_pricing"
        // im Konfigurator-System (media-lab-woocommerce), damit Kunden ein
        // einheitliches Staffelpreis-Erlebnis sehen, unabhängig vom
        // Lieferanten dahinter. section "-500" (= "unter 500 Stück") wird
        // als min_quantity=1 interpretiert (Basis-Staffel).
        $tiersFormatted = [];
        foreach ($priceInfo['tiers'] as $tier) {
            $section = $tier['section'];
            $minQuantity = (int) ltrim($section, '-'); // "-500" -> 500, aber als Basis-Staffel siehe unten
            if (str_starts_with($section, '-')) {
                $minQuantity = 1; // "-500" = "bis 500 Stück" = die Basis-Staffel ab Menge 1
            }

            $discountPercent = $price1 > 0
                ? round((1 - ($tier['price'] / $price1)) * 100, 2)
                : 0.0;

            $tiersFormatted[] = [
                'min_quantity'     => $minQuantity,
                'discount_percent' => $discountPercent,
            ];
        }

        $product->priceTiers = $tiersFormatted;
    }

    private function sanitizeDescription(string $html): string {
        return trim(strip_tags($html, '<p><br><ul><li><strong><em>'));
    }

    private function fetchXml(string $url): \SimpleXMLElement {
        $response = $this->apiClient->get($url);
        $body = (string) $response->getBody();

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $msg = implode('; ', array_map(fn($e) => trim($e->message), $errors));
            throw new \RuntimeException("Makito XML konnte nicht geparst werden ({$url}): {$msg}");
        }
        libxml_use_internal_errors($previous);

        return $xml;
    }
}