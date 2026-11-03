<?php
/* ============================================================
 * FILE: src/Services/MakitoDecorations.php
 *
 * Bringt die Makito-Veredelungsdaten in dasselbe Format wie
 * MidOceanDecorations (Preisliste einmal pro Lieferant, pro Produkt
 * nur Positionen + erlaubte Techniken).
 *
 * Quellen (XML, verifiziert 03.11.2026):
 *   - ItemPrintingFile.php -> <catalog><product><ref/><name/><printjobs><printjob>
 *                              <teccode/><tecname/><areas><area>
 *                              <areacode/><maxcolour/><areaname/><areawidth/><areahight/><areaimg/>
 *                              Pro Produkt: Techniken (teccode) mit ihren Druckflächen.
 *                              Maße in cm (Gegenprobe: "DIGITAL W8 (-250 cm2)" hat Flächen mit 240-250).
 *   - PrintJobsPrices.php  -> <printprices><date/><currency/><printjobs><printjob>
 *                              <teccode/><name/><cliche/><clicherep/><minjob/><minamount/>
 *                              <amountunder1..7/><price1..7/><priceaditionalcol1..7/><pricecm1..7/>
 *                              <minpriceunit/><termlist>
 *                              Preise pro TECHNIK (teccode).
 *
 * Besonderheit gegenüber MidOcean: Die Flächenstufen sind bei Makito eigene
 * Techniken (TAMPONDRUCK A-K, DIGITAL W1-W11, DOMING V1-V4 ...). Das Produkt
 * nennt in ItemPrintingFile schon die passende Stufe, es gibt also nur einen
 * Bereich (from/to = null) pro Technik.
 *
 * Preisformel (aus den Daten abgeleitet, vor Go-Live gegen das Makito-Tarif-PDF
 * prüfen - tarifa_general_marcaje_2026_ing.pdf):
 *   pricing 'positions': Stückpreis = price
 *   pricing 'colours'  : Stückpreis = price + next * (Farben - 1)
 *   pricing 'area'     : Stückpreis = price + cm * Druckfläche_cm2 (+ next * (Farben - 1))
 *   Stückpreis mindestens min_unit_price, Auftrag mindestens min_job.
 *   Gesamt = Klischee (setup, bei Wiederholung setup_repeat) + Menge * Stückpreis
 * Staffeln: Eintrag gilt bis einschließlich "upto" (Annahme, siehe Makito-
 * Produktpreise im MakitoAdapter, "-500" = "bis 500"). "qty" ist die Untergrenze,
 * "upto" = null bedeutet nach oben offen.
 * ============================================================ */

namespace SupplierSync\Services;

class MakitoDecorations {

    /** Ab dieser Obergrenze gilt eine Staffel als "nach oben offen" (Makito: 999999 bzw. 100000). */
    private const OPEN_END = 99999;

    /** @var \Closure(string): \SimpleXMLElement  'printing' | 'prices' => geladenes XML */
    private \Closure $fetch;

    private string $supplierCode;

    /** @var string[] */
    private array $warnings = [];

    public function __construct(\Closure $fetch, string $supplierCode = 'TKM') {
        $this->fetch        = $fetch;
        $this->supplierCode = $supplierCode;
    }

    public function build(): array {
        $printing = ($this->fetch)('printing');
        $prices   = ($this->fetch)('prices');
        return $this->normalize($printing, $prices);
    }

    public function normalize(\SimpleXMLElement $printing, \SimpleXMLElement $prices): array {
        $this->warnings = [];

        if (!isset($printing->product)) {
            throw new \RuntimeException('Makito ItemPrintingFile: unerwartetes Format (keine <product>-Elemente)');
        }
        if (!isset($prices->printjobs->printjob)) {
            throw new \RuntimeException('Makito PrintJobsPrices: unerwartetes Format (keine <printjob>-Elemente)');
        }

        [$techniques, $notes] = $this->buildTechniques($prices);
        $products = $this->buildProducts($printing, $techniques);

        $currency = trim((string) $prices->currency);

        return [
            'pricelist' => [
                'supplier'      => $this->supplierCode,
                'currency'      => ($currency === '€' || $currency === '') ? 'EUR' : $currency,
                'valid_from'    => trim((string) $prices->date),
                'valid_until'   => '',
                'area_unit'     => 'cm2',
                'generated_at'  => gmdate('c'),
                'manipulations' => [],
                'notes'         => $notes,
                'techniques'    => $techniques,
            ],
            'products' => $products,
            'warnings' => $this->warnings,
        ];
    }

    /** Zahl mit Dezimalpunkt (Makito) -> float, null bei leer/ungültig. */
    public static function num($value): ?float {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : null;
    }

    private function buildTechniques(\SimpleXMLElement $prices): array {
        $out   = [];
        $notes = [];

        foreach ($prices->printjobs->printjob as $pj) {
            $id = trim((string) $pj->teccode);
            if ($id === '') {
                continue;
            }

            $tiers   = [];
            $prevTop = 0;
            $hasNext = false;
            $hasCm   = false;

            for ($i = 1; $i <= 7; $i++) {
                $upto = self::num($pj->{"amountunder{$i}"});
                if ($upto === null || $upto <= 0) {
                    continue;
                }
                $price = self::num($pj->{"price{$i}"}) ?? 0.0;
                $next  = self::num($pj->{"priceaditionalcol{$i}"}) ?? 0.0;
                $cm    = self::num($pj->{"pricecm{$i}"}) ?? 0.0;

                $hasNext = $hasNext || $next > 0;
                $hasCm   = $hasCm || $cm > 0;

                $tiers[] = [
                    'qty'   => $prevTop + 1,
                    'upto'  => $upto >= self::OPEN_END ? null : (int) $upto,
                    'price' => $price,
                    'next'  => $next > 0 ? $next : null,
                    'cm'    => $cm > 0 ? $cm : null,
                ];
                $prevTop = (int) $upto;
            }

            if (!$tiers) {
                $this->warnings[] = "Technik {$id}: keine Preisstaffeln - übersprungen";
                continue;
            }

            // Genau eine Staffel (z. B. DIGITALDRUCK mit "bis 1"): gilt für alle Mengen
            if (count($tiers) === 1) {
                $tiers[0]['upto'] = null;
            }

            $type = $hasCm ? 'area' : ($hasNext ? 'colours' : 'positions');

            // Zuschlagstexte (Einzelverpackung etc.) sind bei vielen Techniken identisch -> einmal ablegen
            $noteKey = null;
            $terms   = [];
            if (isset($pj->termlist->term)) {
                foreach ($pj->termlist->term as $t) {
                    $text = trim((string) $t->text);
                    if ($text === '') {
                        continue;
                    }
                    $extra   = trim((string) $t->extra);
                    $terms[] = $extra !== '' ? ['text' => $text, 'extra' => $extra] : ['text' => $text];
                }
            }
            if ($terms) {
                $noteKey = substr(md5(json_encode($terms, JSON_UNESCAPED_UNICODE)), 0, 8);
                $notes[$noteKey] = $terms;
            }

            $minAmount = self::num($pj->minamount);

            $out[$id] = [
                'name'           => ['de' => $this->prettyName((string) $pj->name)],
                'pricing'        => $type,
                'next_colour'    => $hasNext,
                'setup'          => self::num($pj->cliche) ?? 0.0,
                'setup_repeat'   => self::num($pj->clicherep) ?? 0.0,
                'min_job'        => (($v = self::num($pj->minjob)) !== null && $v > 0) ? $v : null,
                'min_qty'        => ($minAmount !== null && $minAmount > 0) ? (int) $minAmount : null,
                'min_unit_price' => (($v = self::num($pj->minpriceunit)) !== null && $v > 0) ? $v : null,
                'notes'          => $noteKey,
                'ranges'         => [[
                    'id'     => '',
                    'from'   => null,
                    'to'     => null,
                    'scales' => $tiers,
                ]],
            ];
        }

        return [$out, $notes];
    }

    private function buildProducts(\SimpleXMLElement $printing, array $techniques): array {
        $out      = [];
        $unpriced = [];

        foreach ($printing->product as $p) {
            $ref = trim((string) $p->ref);
            if ($ref === '' || !isset($p->printjobs->printjob)) {
                continue;
            }

            // Im Feed steht Technik -> Flächen. Wir drehen das um: Fläche -> Techniken.
            $positions = [];
            foreach ($p->printjobs->printjob as $job) {
                $tid = trim((string) $job->teccode);
                if (!isset($techniques[$tid])) {
                    $unpriced[$tid] = true;
                    continue; // ohne Preis nicht anbieten
                }
                if (!isset($job->areas->area)) {
                    continue;
                }
                foreach ($job->areas->area as $area) {
                    $aid = trim((string) $area->areacode);
                    if ($aid === '') {
                        continue;
                    }
                    if (!isset($positions[$aid])) {
                        $w = (float) $area->areawidth;
                        $h = (float) $area->areahight;
                        $positions[$aid] = [
                            'id'         => $aid,
                            'name'       => trim((string) $area->areaname),
                            'w'          => $w,
                            'h'          => $h,
                            'unit'       => 'cm',
                            'shape'      => 'Rectangle',
                            'category'   => 'Regular',
                            'max_area'   => ($w > 0 && $h > 0) ? round($w * $h, 1) : null, // cm²
                            'image'      => trim((string) $area->areaimg),
                            'techniques' => [],
                        ];
                    }
                    if (!isset($positions[$aid]['techniques'][$tid])) {
                        $positions[$aid]['techniques'][$tid] = [
                            'id'          => $tid,
                            'max_colours' => (int) $area->maxcolour,
                            'default'     => false,
                        ];
                    }
                }
            }

            if (!$positions) {
                continue;
            }
            foreach ($positions as &$pos) {
                $pos['techniques'] = array_values($pos['techniques']);
            }
            unset($pos);

            $out[$ref] = [
                'manipulation' => null,
                'template'     => null,
                'positions'    => array_values($positions),
            ];
        }

        if ($unpriced) {
            $this->warnings[] = 'Techniken in ItemPrintingFile ohne Preis (nicht angeboten): ' . implode(', ', array_keys($unpriced));
        }

        return $out;
    }

    /** "TAMPONDRUCK A" -> "Tampondruck A", "(-5  cm2)" -> "(-5 cm²)" */
    private function prettyName(string $raw): string {
        $n = preg_replace('/\s+/', ' ', trim($raw)) ?? $raw;
        $n = mb_convert_case(mb_strtolower($n, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $n = preg_replace('/cm2\b/iu', 'cm²', $n) ?? $n;
        $n = preg_replace('/\bpcs\b/iu', 'Stk.', $n) ?? $n;
        $n = preg_replace('/\bDtf(\d*)\b/u', 'DTF$1', $n) ?? $n;
        return $n;
    }
}
