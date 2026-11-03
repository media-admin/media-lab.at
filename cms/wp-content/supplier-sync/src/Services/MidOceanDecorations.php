<?php
/* ============================================================
 * FILE: src/Services/MidOceanDecorations.php
 *
 * Holt die MidOcean-Veredelungsdaten und bringt sie in ein
 * lieferantenunabhängiges Format (später auch für Makito nutzbar).
 *
 * Quellen (verifiziert 02.11.2026):
 *   - printdata/1.0       -> {printing_technique_descriptions[], products[]}
 *                            Positionen + erlaubte Techniken pro master_code
 *   - printpricelist/2.0  -> {currency, pricelist_valid_from/until,
 *                             print_manipulations[], print_techniques[]}
 *                            Preise pro TECHNIK (nicht pro Produkt!)
 *
 * Ergebnis von build():
 *   'pricelist' => Preistabelle (einmal pro Lieferant, ~100 KB)
 *   'products'  => master_code => {manipulation, template, positions[]}
 *   'warnings'  => Auffälligkeiten für das Log
 *
 * WICHTIG - Zahlenformat bei MidOcean: deutsch, mit Tausenderpunkt
 * ("1.000" = 1000, "0,09" = 0.09, "999.999" = "nach oben offen").
 * Das Kommazeichen-Ersetzen aus parseMidOceanDecimal() reicht hier NICHT.
 * ============================================================ */

namespace SupplierSync\Services;

class MidOceanDecorations {

    private const SUPPLIER_CODE = 'DIM';

    /**
     * Ab dieser Obergrenze gilt eine Flächenstufe als "nach oben offen".
     * MidOcean nutzt dafür "999.999" (meist) bzw. "99.999" (Technik E).
     */
    private const OPEN_END = 99999;

    private const PRICING_TYPES = [
        'NumberOfPositions' => 'positions',          // Festpreis je Position (z. B. Prägung)
        'NumberOfColours'   => 'colours',            // 1. Farbe + next_price je weitere Farbe
        'AreaRange'         => 'area_range',         // Preis nach Flächenstufe
        'ColourAreaRange'   => 'colour_area_range',  // Farbe + Flächenstufe
        'Area'              => 'area',               // Preis je Flächeneinheit
    ];

    /** @var \Closure(string): array  Liefert die dekodierten Nutzdaten zu einem Endpoint-Pfad */
    private \Closure $fetch;

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param \Closure $fetch Beispiel: fn(string $path) => $adapter->fetchViaPresignedUrl("{$baseUrl}/{$path}")
     */
    public function __construct(\Closure $fetch) {
        $this->fetch = $fetch;
    }

    public function build(): array {
        $printData = ($this->fetch)('printdata/1.0');
        $priceList = ($this->fetch)('printpricelist/2.0');
        return $this->normalize((array) $printData, (array) $priceList);
    }

    public function normalize(array $printData, array $priceList): array {
        $this->warnings = [];

        if (!isset($printData['products'], $printData['printing_technique_descriptions'])) {
            throw new \RuntimeException(
                'MidOcean printdata: unerwartetes Format. Keys: ' . implode(', ', array_keys($printData))
            );
        }
        if (!isset($priceList['print_techniques'])) {
            throw new \RuntimeException(
                'MidOcean printpricelist: unerwartetes Format (evtl. Warning-Wrapper bei Erstnutzung). Keys: '
                . implode(', ', array_keys($priceList))
            );
        }

        $names      = $this->buildNames($printData['printing_technique_descriptions']);
        $techniques = $this->buildTechniques($priceList['print_techniques'], $names);

        $manipulations = [];
        foreach ($priceList['print_manipulations'] ?? [] as $m) {
            $code = (string) ($m['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $manipulations[$code] = [
                'label' => (string) ($m['description'] ?? $code),
                'price' => self::parseDecimal($m['price'] ?? null) ?? 0.0,
            ];
        }

        $products = $this->buildProducts($printData['products'], $techniques);

        return [
            'pricelist' => [
                'supplier'      => self::SUPPLIER_CODE,
                'currency'      => (string) ($priceList['currency'] ?? 'EUR'),
                'valid_from'    => (string) ($priceList['pricelist_valid_from'] ?? ''),
                'valid_until'   => (string) ($priceList['pricelist_valid_until'] ?? ''),
                // Einheit der Flächenstufen ist in den Daten nicht angegeben, nach
                // Größenordnung cm². Vor Go-Live gegen den MidOcean-Webshop prüfen.
                'area_unit'     => 'cm2',
                'generated_at'  => gmdate('c'),
                'manipulations' => $manipulations,
                'techniques'    => $techniques,
            ],
            'products'  => $products,
            'warnings'  => $this->warnings,
        ];
    }

    /**
     * Zahl im MidOcean-Format -> float. null bei leer/ungültig.
     * "1.000" => 1000, "0,09" => 0.09, "999.999" => 999999, "0.5" => 0.5
     */
    public static function parseDecimal($value): ?float {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',')) {
            // Komma = Dezimaltrenner, Punkte sind Tausendertrenner
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            // nur Punkte im Tausenderraster, z. B. "1.000" oder "20.000"
            $v = str_replace('.', '', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * [{"cs":"..."},{"de":"..."},{"en":"..."}] => ['de' => '...', 'en' => '...']
     */
    private function buildNames(array $descriptions): array {
        $byId = [];
        foreach ($descriptions as $d) {
            $id = (string) ($d['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $flat = [];
            foreach ((array) ($d['name'] ?? []) as $entry) {
                foreach ((array) $entry as $lang => $text) {
                    $flat[$lang] = trim((string) $text);
                }
            }
            $byId[$id] = array_filter([
                'de' => $flat['de'] ?? '',
                'en' => $flat['en'] ?? '',
            ], fn($t) => $t !== '');
        }
        return $byId;
    }

    private function buildTechniques(array $rawTechniques, array $names): array {
        $out = [];

        foreach ($rawTechniques as $t) {
            $id = (string) ($t['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $type = self::PRICING_TYPES[$t['pricing_type'] ?? ''] ?? null;
            if ($type === null) {
                $this->warnings[] = "Technik {$id}: unbekannte pricing_type '" . ($t['pricing_type'] ?? '') . "' - übersprungen";
                continue;
            }

            $hasArea = in_array($type, ['area_range', 'colour_area_range'], true);
            $ranges  = [];

            foreach ($t['var_costs'] ?? [] as $vc) {
                $scales = [];
                foreach ($vc['scales'] ?? [] as $s) {
                    $qty   = self::parseDecimal($s['minimum_quantity'] ?? null);
                    $price = self::parseDecimal($s['price'] ?? null);
                    if ($qty === null || $price === null) {
                        continue;
                    }
                    $scales[] = [
                        'qty'   => (int) $qty,
                        'price' => $price,
                        'next'  => self::parseDecimal($s['next_price'] ?? null), // Aufpreis je weitere Farbe
                    ];
                }
                if (!$scales) {
                    continue; // z. B. E3D: var_costs ohne scales
                }

                $from = self::parseDecimal($vc['area_from'] ?? null) ?? 0.0;
                $to   = self::parseDecimal($vc['area_to'] ?? null);

                $ranges[] = [
                    'id'     => (string) ($vc['range_id'] ?? ''),
                    'from'   => $hasArea ? $from : null,
                    'to'     => ($hasArea && $to !== null && $to < self::OPEN_END) ? $to : null, // null = nach oben offen
                    'scales' => $scales,
                ];
            }

            if (!$ranges) {
                $this->warnings[] = "Technik {$id}: keine Preise - übersprungen";
                continue;
            }

            if ($hasArea) {
                usort($ranges, fn($a, $b) => $a['from'] <=> $b['from']);
            }

            $name = $names[$id] ?? [];
            if (!$name) {
                $name = ['en' => (string) ($t['description'] ?? $id)];
            }

            $out[$id] = [
                'name'        => $name,
                'pricing'     => $type,
                'next_colour' => ($t['next_colour_cost_indicator'] ?? '') === 'true',
                'setup'       => self::parseDecimal($t['setup'] ?? null) ?? 0.0,
                'setup_repeat'=> self::parseDecimal($t['setup_repeat'] ?? null) ?? 0.0,
                'ranges'      => $ranges,
            ];
        }

        return $out;
    }

    private function buildProducts(array $rawProducts, array $techniques): array {
        $out      = [];
        $unpriced = [];

        foreach ($rawProducts as $p) {
            $code = trim((string) ($p['master_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $positions = [];
            foreach ($p['printing_positions'] ?? [] as $pos) {
                $techs = [];
                foreach ($pos['printing_techniques'] ?? [] as $pt) {
                    $tid = (string) ($pt['id'] ?? '');
                    if ($tid === '') {
                        continue;
                    }
                    if (!isset($techniques[$tid])) {
                        $unpriced[$tid] = true;
                        continue; // ohne Preis nicht anbieten
                    }
                    $techs[] = [
                        'id'          => $tid,
                        'max_colours' => (int) ($pt['max_colours'] ?? 0), // 0 = Farbanzahl egal (z. B. Digitaldruck)
                        'default'     => !empty($pt['default']),
                    ];
                }
                if (!$techs) {
                    continue;
                }

                $w     = (float) ($pos['max_print_size_width'] ?? 0);
                $h     = (float) ($pos['max_print_size_height'] ?? 0);
                $shape = (string) ($pos['print_position_type'] ?? 'Rectangle');

                $positions[] = [
                    'id'        => (string) ($pos['position_id'] ?? ''),
                    'w'         => $w,
                    'h'         => $h,
                    'unit'      => (string) ($pos['print_size_unit'] ?? 'mm'),
                    'shape'     => $shape,
                    'category'  => (string) ($pos['category'] ?? 'Regular'),
                    'max_area'  => $this->maxAreaCm2($w, $h, (string) ($pos['print_size_unit'] ?? 'mm'), $shape),
                    'techniques'=> $techs,
                ];
            }

            if (!$positions) {
                continue;
            }

            $out[$code] = [
                'manipulation' => ($p['print_manipulation'] ?? null) ?: null,
                'template'     => ($p['print_template'] ?? null) ?: null,
                'positions'    => $positions,
            ];
        }

        if ($unpriced) {
            $this->warnings[] = 'Techniken in printdata ohne Preis (nicht angeboten): ' . implode(', ', array_keys($unpriced));
        }

        return $out;
    }

    /**
     * Maximale Druckfläche in cm² (obere Grenze; bei Polygonen die Bounding-Box).
     */
    private function maxAreaCm2(float $w, float $h, string $unit, string $shape): ?float {
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $factor = match ($unit) {
            'mm'    => 0.01,   // mm² -> cm²
            'cm'    => 1.0,
            default => null,
        };
        if ($factor === null) {
            return null;
        }
        $area = $w * $h * $factor;
        if ($shape === 'Ellipse') {
            $area *= M_PI / 4;
        }
        return round($area, 1);
    }
}
