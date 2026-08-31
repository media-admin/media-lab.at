<?php
namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;

/**
 * Makito (TKM) — Datei-basierte XML-Feeds (keine echte REST-API, siehe
 * E-Mail vom Lieferanten), erreichbar über feste URLs mit kundenspezifischem
 * "pszinternal"-Token.
 *
 * Produkte:  http://print.makito.es:8080/user/xml/ItemDataFile.php?pszinternal=<token>
 * Stock:     http://print.makito.es:8080/user/xml/allstockfile.php?pszinternal=<token>
 *
 * Beide gegen echte Beispiel-Responses verifiziert (31.08.2026).
 *
 * WICHTIG — Feldname-Inkonsistenz zwischen den beiden Feeds:
 *   ItemDataFile:  <refct>   (Produkt-Feed, Varianten-Referenz)
 *   allstockfile:  <reftc>   (Stock-Feed, Buchstaben vertauscht!)
 * Die WERTE stimmen überein (z.B. "2050S/T"), nur der Tag-Name unterscheidet
 * sich — das ist der Merge-Schlüssel zwischen Produkt- und Stock-Feed.
 *
 * WICHTIG — Platzhalter-Codes "ohne Ausprägung":
 *   size  = "S/T" ("sin talla"  = ohne Größe / Einheitsgröße) -> KEIN pa_size
 *   colour = "S/C" ("sin color" = ohne Farbe)                  -> KEIN pa_color
 * Verifiziert an echten Beispieldaten (31.08.2026, Produkt 2050/2233).
 *
 * Struktur Produkt-Feed:
 *   <catalog><product>
 *     <ref>          Master-Code (z.B. "2050")
 *     <name>          eher Spec/Untertitel als eigentlicher Titel (z.B. "40 L/ m2")
 *     <type>          wirkt wie der eigentliche Produktname (z.B. "Niederschlagsmesser")
 *     <otherinfo>     kurze Zusatzinfo
 *     <extendedinfo>  volle Marketingbeschreibung
 *     <brand>
 *     <imagemain>, <images><image><imagemax>...
 *     <categories><category_name_1..5>
 *     <variants><variant>
 *       <matnr>       interne Lagernummer
 *       <refct>       Varianten-Referenz -> wird supplier_sku
 *       <colour>, <colftp>, <colourname>
 *       <size>        siehe "Platzhalter-Codes" oben
 *       <image500px>  variantenspezifisches Bild
 *
 * Struktur Stock-Feed:
 *   <catalog><product>
 *     <matnr>, <reftc>, <ref>, <colour>, <colftp>, <size>, <from>, <stock>, <available>
 *
 * OFFEN / TODO:
 *  - matnr vs. refct als Unique Identifier: refct gewählt (wirkt wie
 *    Bestell-/Referenzcode), matnr wie reine Lagernummer — mit Markus
 *    gegenchecken, falls Bestellungen später matnr erwarten.
 *  - Preise (PriceListFile.php) und Druckflächen (ItemPrintingFile.php)
 *    noch nicht angebunden — laut Canonical-Dokument aktuell optional.
 *  - "ldl"-Sprachparameter noch nicht genutzt (Standard-Sprache bisher ok?).
 */
class MakitoAdapter extends AbstractAdapter {

    /** Werte, die "keine Ausprägung" bedeuten und NICHT als Attribut gesetzt werden. */
    private const NO_SIZE_VALUES  = ['S/T', ''];
    private const NO_COLOR_VALUES = ['S/C', ''];

    /** @return Product[] */
    public function fetchProducts(): array {
        $xml = $this->fetchXml($this->config['api_url']);
        $stockByRef = $this->fetchStock();

        $products = [];
        foreach ($xml->product as $masterNode) {
            foreach ($this->mapMasterToProducts($masterNode, $stockByRef) as $product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return Product[] eine Product-Instanz pro Variante des Masters
     */
    private function mapMasterToProducts(\SimpleXMLElement $masterNode, array $stockByRef): array {
        $products = [];

        $masterCode = (string) $masterNode->ref;

        $type = trim((string) $masterNode->type);
        $name = trim((string) $masterNode->name);
        $title = trim($type . ' ' . $name);
        if ($title === '') {
            $title = $masterCode;
        }

        $description       = (string) $masterNode->extendedinfo;
        $shortDescription  = (string) $masterNode->otherinfo;
        $brand             = trim((string) $masterNode->brand) ?: null;

        $categories = [];
        if (isset($masterNode->categories)) {
            for ($i = 1; $i <= 5; $i++) {
                $catField = "category_name_{$i}";
                $val = trim((string) $masterNode->categories->{$catField});
                if ($val !== '') {
                    $categories[] = $val;
                }
            }
        }

        $productImageMain = trim((string) $masterNode->imagemain) ?: null;
        $productGallery = [];
        if (isset($masterNode->images->image)) {
            foreach ($masterNode->images->image as $img) {
                $url = trim((string) $img->imagemax);
                if ($url !== '') {
                    $productGallery[] = $url;
                }
            }
        }

        if (!isset($masterNode->variants->variant)) {
            return [];
        }

        foreach ($masterNode->variants->variant as $variant) {
            $product = new Product();

            $product->supplierCode = $this->config['supplier_code']; // 'TKM'
            $product->supplierSku  = trim((string) $variant->refct);
            $product->masterCode   = $masterCode;

            $product->productTitle              = $title;
            $product->productDescription         = $description;
            $product->productShortDescription    = $shortDescription;
            $product->brand                       = $brand;
            $product->categories                  = $categories;

            $colourName = trim((string) $variant->colourname);
            $colour     = trim((string) $variant->colour);
            $resolvedColour = $colourName !== '' ? $colourName : $colour;

            $attrs = [];
            if (!in_array(strtoupper($resolvedColour), self::NO_COLOR_VALUES, true) && $resolvedColour !== '') {
                $attrs['pa_color'] = $resolvedColour;
            }

            $size = trim((string) $variant->size);
            if (!in_array(strtoupper($size), self::NO_SIZE_VALUES, true)) {
                $attrs['pa_size'] = $size;
            }
            $product->variationAttributes = $attrs;

            $variantImage = trim((string) $variant->image500px);
            $product->imageMain    = $variantImage ?: $productImageMain;
            $product->imageGallery = $productGallery;
            $product->imageSource  = 'url';

            if (isset($stockByRef[$product->supplierSku])) {
                $stock = $stockByRef[$product->supplierSku];
                $product->supplierStock      = $stock['qty'];
                $product->supplierStockTotal = $stock['qty'];
                if ($stock['available'] && $stock['available'] !== 'immediately') {
                    $product->leadTimeText = $stock['available'];
                }
            }

            $product->lastImportSource = 'makito';

            $products[] = $product;
        }

        return $products;
    }

    /**
     * @return array<string,array{qty:int,available:?string}> reftc/reftc-Wert => Stock-Infos
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

            $map[$ref] = [
                'qty'       => (int) $row->stock,
                'available' => trim((string) $row->available) ?: null,
            ];
        }

        return $map;
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
