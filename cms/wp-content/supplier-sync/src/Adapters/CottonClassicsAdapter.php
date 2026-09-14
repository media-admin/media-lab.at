<?php
namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;
use SupplierSync\Models\ProductVariant;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Cotton Classics (LCC) — Datei-basiert (jährlicher Stammdaten-Export als
 * .xlsx). Kein API-Endpoint — die Datei muss manuell unter config['file_path']
 * abgelegt werden, bevor sync.php läuft.
 *
 * Struktur verifiziert gegen "Article_Export_2026-09__EUR_.xlsx" (14.09.2026):
 *
 * Sheet "SKU List" (eine Zeile = eine Variante, 116.102 Zeilen):
 *   SKU(0) Style(1) Manufacturer(2) Name(3) Colour(4) Size(5) Farbtyp(6)
 *   Groessentyp(7) VKEinzel(8) VK10(9) VK100(10) VK500(11) VK1000(12)
 *   PC_Pack(13) PC_Carton(14) Weight_KG(15) Status(16) Packshot(17) EAN(18)
 *   ManufacturerSKU(19)
 *
 * Sheet "Style List" (Master-/Parent-Daten, 4.009 Zeilen, per "Style"
 * verknüpft = ein Product pro Style, analog MidOcean/Makito):
 *   Style(0) Name1(1) Name2 german(2) Material-Desc german(3)
 *   Product-Desc german(4) ... Picture(14) ... Categories(21)
 *
 * BILDER: Kein öffentlicher HTTP-Zugriff — nur FTP. Packshot (SKU List,
 * pro Variante) bzw. Picture (Style List, Fallback auf Parent-Ebene)
 * referenzieren Dateien unter picture_DB/GUID/Alle_72dpi/<dateiname>.
 * Diese Klasse schreibt nur die spätere LOKALE URL (config['image_base_url']
 * + Dateiname) — download_cotton_images.php muss VOR dem WP-All-Import-Lauf
 * laufen, damit die Dateien dort tatsächlich existieren.
 *
 * NOCH NICHT ABGEBILDET (bewusst zurückgestellt, siehe Chat):
 *  - Preise (VKEinzel/VK10/VK100/VK500/VK1000) — folgt als nächster Schritt.
 *  - Status (ACTUAL/SELLOUT/NEW) als Tag — Product/ProductVariant haben
 *    aktuell keine tags-Property, betrifft ggf. alle drei Adapter.
 *  - EAN — laut Canonical-Dokument ohnehin nicht exportiert (Privacy-first).
 *  - Stock — Cotton Classics liefert keinen Stock-Feed.
 */
class CottonClassicsAdapter extends AbstractAdapter {

    private const SUPPLIER_CODE = 'LCC';

    /** Size-Werte, die keine echte Variantenausprägung sind. */
    private const NO_SIZE_VALUES = ['ONESIZE', ''];

    /** @return Product[] */
    public function fetchProducts(): array {
        $filePath = $this->config['file_path'] ?? '';
        if (!$filePath || !is_file($filePath)) {
            throw new \RuntimeException(
                "Cotton Classics Export-Datei nicht gefunden unter: {$filePath}. " .
                "Datei muss vor dem Sync manuell dort abgelegt werden (Export siehe Mapping-Dokument)."
            );
        }

        $styleData    = $this->readStyleList($filePath);
        $rowsByStyle  = $this->groupSkuRowsByStyle($filePath);

        $products = [];
        foreach ($rowsByStyle as $styleCode => $rows) {
            $products[] = $this->transformToProduct([
                'style_code' => $styleCode,
                'style'      => $styleData[$styleCode] ?? null,
                'rows'       => $rows,
            ]);
        }

        return $products;
    }

    /**
     * @return array<string,array{title:string,description:string,categories:string[],picture:string}>
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

                $name1          = trim((string) ($cells[1] ?? ''));
                $materialDescDE = trim((string) ($cells[3] ?? ''));
                $productDescDE  = trim((string) ($cells[4] ?? ''));
                $picture        = trim((string) ($cells[14] ?? ''));
                $categoriesRaw  = trim((string) ($cells[21] ?? ''));

                $data[$styleCode] = [
                    'title'       => $name1,
                    'description' => trim($materialDescDE . "\n" . $productDescDE),
                    'categories'  => $categoriesRaw !== '' ? [$categoriesRaw] : [],
                    'picture'     => $picture,
                ];
            }
        }

        $reader->close();
        return $data;
    }

    /**
     * Liest die SKU List und gruppiert alle Zeilen nach Style-Code - eine
     * Product-Instanz pro Style, analog zu MidOcean (Parent + N Varianten).
     *
     * @return array<string, array<int, array<int,mixed>>> Style-Code => Liste roher Zeilen
     */
    private function groupSkuRowsByStyle(string $filePath): array {
        $reader = new Reader();
        $reader->open($filePath);

        $grouped = [];
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

                $sku       = trim((string) ($cells[0] ?? ''));
                $styleCode = trim((string) ($cells[1] ?? ''));
                if ($sku === '' || $styleCode === '') continue;

                $grouped[$styleCode][] = $cells;
            }
        }

        $reader->close();
        return $grouped;
    }

    /**
     * @param array{style_code:string, style: array{title:string,description:string,categories:string[],picture:string}|null, rows: array<int,array<int,mixed>>} $rawData
     */
    protected function transformToProduct(array $rawData): Product {
        $styleCode = $rawData['style_code'];
        $style     = $rawData['style'];
        $rows      = $rawData['rows'];

        $product = new Product();
        $product->supplierCode = self::SUPPLIER_CODE;
        $product->supplierSku  = $styleCode;
        $product->importUid    = self::SUPPLIER_CODE . '|' . $styleCode;

        $product->productTitle       = $style['title'] ?? $styleCode;
        $product->productDescription = $style['description'] ?? '';
        $product->categories         = $style['categories'] ?? [];

        foreach ($rows as $cells) {
            $product->variants[] = $this->transformVariant($cells);
        }

        // Parent-Bild: bevorzugt das Style-Level "Picture", sonst Fallback
        // auf das Bild der ersten Variante mit gesetztem imageMain (analog
        // MidOcean-Adapter, dort aber als einzige Option, da MidOcean kein
        // eigenes Parent-Bild liefert).
        $imageBaseUrl = rtrim((string) ($this->config['image_base_url'] ?? ''), '/');
        $stylePicture = $style['picture'] ?? '';
        if ($stylePicture !== '' && $imageBaseUrl !== '') {
            $product->imageMain = $imageBaseUrl . '/' . $stylePicture;
        } else {
            foreach ($product->variants as $variant) {
                if ($variant->imageMain !== '') {
                    $product->imageMain = $variant->imageMain;
                    break;
                }
            }
        }

        return $product;
    }

    private function transformVariant(array $cells): ProductVariant {
        // Spaltenreihenfolge siehe Klassenkommentar.
        $sku      = trim((string) ($cells[0] ?? ''));
        $colour   = trim((string) ($cells[4] ?? ''));
        $size     = trim((string) ($cells[5] ?? ''));
        $vkEinzel = $cells[8] ?? null;
        $packshot = trim((string) ($cells[17] ?? ''));

        $variant = new ProductVariant();
        $variant->supplierVariantSku = $sku;
        $variant->variantId          = $sku; // Cotton liefert keine separate numerische Varianten-ID

        $attrs = [];
        if ($colour !== '') {
            $attrs['pa_color'] = $colour;
        }
        if (!in_array(strtoupper($size), self::NO_SIZE_VALUES, true)) {
            $attrs['pa_size'] = $size;
        }
        $variant->attributes = $attrs;

        // Preis: nur Einzelpreis (VKEinzel), keine Mengenstaffel - Cotton
        // Classics liefert Preise PRO VARIANTE (bestätigt per Stichprobe:
        // >80 Styles mit abweichenden Preisen zwischen Farben/Größen
        // desselben Styles), anders als bei Makito (Preis pro Parent).
        if ($vkEinzel !== null && $vkEinzel !== '') {
            $variant->price = (float) $vkEinzel;
        }

        $imageBaseUrl = rtrim((string) ($this->config['image_base_url'] ?? ''), '/');
        if ($packshot !== '' && $imageBaseUrl !== '') {
            $variant->imageMain = $imageBaseUrl . '/' . $packshot;
        }

        // Kein Stock-Feed, keine Preise (folgt als nächster Schritt) -
        // stock/price bleiben auf ihren Defaults (0 bzw. null).

        return $variant;
    }
}
