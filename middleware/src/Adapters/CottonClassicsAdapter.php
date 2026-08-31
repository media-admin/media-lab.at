<?php
namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Cotton Classics (LCC) — Datei-basiert (jährlicher Stammdaten-Export als
 * .xlsx, monatliche Preis-Updates separat, siehe Mapping-Dokument).
 *
 * Kein API-Endpoint — die Datei muss manuell unter config['file_path']
 * abgelegt werden, bevor sync.php läuft.
 *
 * Struktur verifiziert gegen echten Export "Article_Export_2025-01__EUR_.xlsx"
 * (31.08.2026):
 *
 * Sheet "SKU List" (eine Zeile = eine Variante, 113.776 Zeilen im Beispiel):
 *   SKU, Style, Manufacturer, Name, Colour, Size, Farbtyp, Groessentyp,
 *   VKEinzel, VK10, VK100, VK500, VK1000, PC_Pack, PC_Carton, Weight_KG,
 *   Status (ACTUAL|SELLOUT|NEW), Packshot (nur Dateiname, KEINE volle URL —
 *   Basis-URL bisher unbekannt, siehe TODO), EAN, ManufacturerSKU
 *
 * Sheet "Style List" (Master-/Parent-Daten, per "Style" verknüpft):
 *   Style, Name1, Name2 (german), Material-Description (german),
 *   Product-Description (german), ... (weitere Sprachen), Categories, ...
 *   ACHTUNG: eigene "Status"-Spalte ist selbst als deprecated markiert
 *   ("Use SKU List Status") — wird hier ignoriert.
 *
 * KEIN Stock-Feld in diesem Export — supplierStock bleibt bewusst leer
 * (bestätigt die Vermutung im Mapping-Dokument).
 *
 * OFFEN / TODO:
 *  - Packshot-Basis-URL unbekannt (Website nutzt ein komplett anderes
 *    Namensschema, GUID aus dem Export lässt sich nicht ableiten) — bei
 *    Cotton Classics nachfragen, dann fetchImageUrl() unten ergänzen.
 *  - Preise (VKEinzel/VK10/VK100/VK500/VK1000) werden hier NICHT importiert,
 *    da laut Architektur separat/optional gehalten (siehe Canonical-Dokument,
 *    "Preise teils per Mail"). Separates Preis-Update-Sheet noch zu klären.
 *  - matnr-Äquivalent: hier gibt es kein separates "ManufacturerSKU" vs.
 *    "SKU"-Dilemma wie bei Makito — SKU ist eindeutig der Bestellcode.
 */
class CottonClassicsAdapter extends AbstractAdapter {

    /** Size-Werte, die keine echte Variantenausprägung sind. */
    private const NO_SIZE_VALUES = ['ONESIZE', ''];

    /** @return Product[] */
    public function fetchProducts(): array {
        $filePath = $this->config['file_path'] ?? '';
        if (!$filePath || !is_file($filePath)) {
            throw new \RuntimeException(
                "Cotton Classics Export-Datei nicht gefunden unter: {$filePath}. " .
                "Datei muss vor dem Sync manuell dort abgelegt werden (jährlicher Export, siehe Mapping-Dokument)."
            );
        }

        $styleData = $this->readStyleList($filePath);
        return $this->readSkuList($filePath, $styleData);
    }

    /**
     * @return array<string,array{title:string,short:string,description:string,categories:string[]}>
     */
    private function readStyleList(string $filePath): array {
        $reader = new Reader();
        $reader->open($filePath);

        $data = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== 'Style List') {
                continue;
            }

            $headerSkipped = false;
            foreach ($sheet->getRowIterator() as $row) {
                if (!$headerSkipped) {
                    $headerSkipped = true;
                    continue;
                }
                $cells = $row->toArray();

                $styleCode = trim((string) ($cells[0] ?? ''));
                if ($styleCode === '') continue;

                $name1              = trim((string) ($cells[1] ?? ''));
                $name2German        = trim((string) ($cells[2] ?? ''));
                $materialDescDE     = trim((string) ($cells[3] ?? ''));
                $productDescDE      = trim((string) ($cells[4] ?? ''));
                $categoriesRaw      = trim((string) ($cells[20] ?? ''));

                $description = trim($materialDescDE . "\n" . $productDescDE);

                $data[$styleCode] = [
                    'title'       => $name1,
                    'short'       => $name2German,
                    'description' => $description,
                    'categories'  => $categoriesRaw !== '' ? [$categoriesRaw] : [],
                ];
            }
        }

        $reader->close();
        return $data;
    }

    /**
     * @param array<string,array{title:string,short:string,description:string,categories:string[]}> $styleData
     * @return Product[]
     */
    private function readSkuList(string $filePath, array $styleData): array {
        $reader = new Reader();
        $reader->open($filePath);

        $products = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== 'SKU List') {
                continue;
            }

            $headerSkipped = false;
            foreach ($sheet->getRowIterator() as $row) {
                if (!$headerSkipped) {
                    $headerSkipped = true;
                    continue;
                }
                $cells = $row->toArray();

                // Spaltenreihenfolge: SKU(0) Style(1) Manufacturer(2) Name(3) Colour(4)
                // Size(5) Farbtyp(6) Groessentyp(7) VKEinzel(8) VK10(9) VK100(10)
                // VK500(11) VK1000(12) PC_Pack(13) PC_Carton(14) Weight_KG(15)
                // Status(16) Packshot(17) EAN(18) ManufacturerSKU(19)
                $sku       = trim((string) ($cells[0] ?? ''));
                $styleCode = trim((string) ($cells[1] ?? ''));
                if ($sku === '') continue;

                $manufacturer = trim((string) ($cells[2] ?? ''));
                $name         = trim((string) ($cells[3] ?? ''));
                $colour       = trim((string) ($cells[4] ?? ''));
                $size         = trim((string) ($cells[5] ?? ''));
                $status       = trim((string) ($cells[16] ?? ''));
                $ean          = trim((string) ($cells[18] ?? ''));

                $style = $styleData[$styleCode] ?? null;

                $product = new Product();
                $product->supplierCode = $this->config['supplier_code']; // 'LCC'
                $product->supplierSku  = $sku;
                $product->masterCode   = $styleCode;
                $product->gtinEan      = $ean !== '' ? $ean : null;

                $product->productTitle           = $name !== '' ? $name : ($style['title'] ?? $styleCode);
                $product->productShortDescription = $style['short'] ?? '';
                $product->productDescription       = $style['description'] ?? '';
                $product->brand                     = $manufacturer !== '' ? $manufacturer : null;
                $product->categories                 = $style['categories'] ?? [];

                $attrs = [];
                if ($colour !== '') {
                    $attrs['pa_color'] = $colour;
                }
                if (!in_array(strtoupper($size), self::NO_SIZE_VALUES, true)) {
                    $attrs['pa_size'] = $size;
                }
                $product->variationAttributes = $attrs;

                // TODO: Packshot-Basis-URL unbekannt — siehe Klassenkommentar.
                // Dateiname liegt in $cells[17], aktuell nicht in eine URL umgewandelt.
                $product->imageMain    = null;
                $product->imageGallery = [];
                $product->imageSource  = null;

                // Kein Stock-Feld in diesem Export — bewusst leer, siehe Klassenkommentar.
                $product->supplierStock      = null;
                $product->supplierStockTotal = null;

                // Status als Tag durchreichen statt eigenes Feld — ermöglicht
                // später z.B. eine "Restposten"-Kennzeichnung im Shop, analog
                // zum stock_inhouse-Badge aus dem Canonical-Dokument.
                if (strtoupper($status) === 'SELLOUT') {
                    $product->tags[] = 'sellout';
                } elseif (strtoupper($status) === 'NEW') {
                    $product->tags[] = 'new';
                }

                $product->lastImportSource = 'cotton-classics';

                $products[] = $product;
            }
        }

        $reader->close();
        return $products;
    }
}
