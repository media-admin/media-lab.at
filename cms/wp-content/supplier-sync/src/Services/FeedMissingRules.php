<?php
/* ============================================================
 * FILE: src/Services/FeedMissingRules.php
 *
 * Regel für Produkte, die der Lieferant aus dem Feed genommen hat (Entscheidung Markus Tritremmel, 21.11.2026):
 *
 *   - Fehlt ein veröffentlichtes Produkt im Feed, bleibt es sichtbar, solange es Hausbestand (stock_inhouse) hat.
 *   - Ohne Hausbestand wird es auf Entwurf gesetzt (nie gelöscht).
 *   - Taucht es im Feed wieder auf, wird es NICHT automatisch wieder veröffentlicht (nur ein Hinweis im Bericht).
 *   - Schutzgrenze: Fehlen auf einmal mehr als $maxPercent Prozent der veröffentlichten Produkte des Lieferanten
 *     (leerer oder defekter Feed), ändert die Regel nichts.
 *
 * Reine Entscheidungslogik ohne Datenbank und ohne WordPress, damit tools/feed_missing.php und
 * tests/feed_missing_rules_test.php dieselbe Datei nutzen.
 * ============================================================ */

namespace SupplierSync\Services;

class FeedMissingRules {

    /** Untergrenze der Schutzgrenze: bei kleinen Lieferanten dürfen mindestens so viele Produkte auf einmal fehlen. */
    public const MIN_ALLOWED = 3;

    /**
     * @param array<string,true> $feedSkus   Lieferanten-Artikelnummern aus dem Feed (Schlüssel = Artikelnummer)
     * @param array<int,array{sku:string,title:string,inhouse:bool}> $published veröffentlichte Produkte des Lieferanten (Schlüssel = Produkt-ID)
     * @param float $maxPercent              Schutzgrenze in Prozent der veröffentlichten Produkte
     * @return array{
     *   missing: array<int,array>, keep: array<int,array>, draft: array<int,array>,
     *   limit: int, blocked: bool, reason: string
     * }
     */
    public static function evaluate(array $feedSkus, array $published, float $maxPercent = 5.0): array {
        $missing = [];
        foreach ($published as $id => $p) {
            if (!isset($feedSkus[trim((string) $p['sku'])])) {
                $missing[$id] = $p;
            }
        }

        $limit = max(self::MIN_ALLOWED, (int) floor(count($published) * $maxPercent / 100));

        $blocked = false;
        $reason  = '';
        if ($feedSkus === [] && $published !== []) {
            $blocked = true;
            $reason  = 'Der Feed enthält keine Produkte.';
        } elseif (count($missing) > $limit) {
            $blocked = true;
            $reason  = sprintf(
                '%d von %d veröffentlichten Produkten fehlen im Feed, erlaubt sind höchstens %d (%s %%, mindestens %d). Das sieht nach einem defekten oder unvollständigen Feed aus.',
                count($missing), count($published), $limit, rtrim(rtrim(number_format($maxPercent, 2, '.', ''), '0'), '.'), self::MIN_ALLOWED
            );
        }

        $keep  = [];
        $draft = [];
        foreach ($missing as $id => $p) {
            if (!empty($p['inhouse'])) {
                $keep[$id] = $p;
            } else {
                $draft[$id] = $p;
            }
        }

        return ['missing' => $missing, 'keep' => $keep, 'draft' => $draft, 'limit' => $limit, 'blocked' => $blocked, 'reason' => $reason];
    }

    /**
     * Wieder im Feed aufgetauchte Entwürfe (zuvor durch diese Regel auf Entwurf gesetzt). Nur für den Bericht, nichts wird veröffentlicht.
     *
     * @param array<string,true> $feedSkus
     * @param array<int,array{sku:string,title:string}> $drafted Entwürfe mit der Markierung _ml_feed_missing
     * @return array<int,array>
     */
    public static function reappeared(array $feedSkus, array $drafted): array {
        $out = [];
        foreach ($drafted as $id => $p) {
            if (isset($feedSkus[trim((string) $p['sku'])])) {
                $out[$id] = $p;
            }
        }
        return $out;
    }
}
