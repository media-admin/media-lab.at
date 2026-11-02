# Farbfamilien (media-lab-ml-sku ab 0.4.0)

Die Lieferantenfarben (Attribut `pa_color`, am 28.10.2026 2.459 Begriffe, Deutsch und Englisch gemischt, zum Beispiel `schwarz` und `black`)
werden 17 **Farbfamilien** zugeordnet (Attribut `pa_farbfamilie`, „Farbfamilie"). Die Originalfarbe bleibt an Produkt und Variante, die Farbfamilie dient
nur dem Filter und ist unsichtbar (kein Eintrag im Tab „Zusätzliche Informationen", nicht für Variationen).

## Dateien

| Datei | Inhalt |
|---|---|
| `inc/farbfamilien.php` | Logik: Sweep nach dem Import, Zuordnung, WP-CLI-Befehle, Filter-Hooks |
| `data/farbfamilien.csv` | Zuordnungstabelle (Stand 29.10.2026: 2.459 Zeilen, 186 ohne Familie) |

## Die 17 Familien (Reihenfolge im Filter)

Schwarz, Weiß, Grau, Blau, Navy / Marine, Rot, Orange, Gelb, Grün, Türkis, Lila / Violett, Pink / Rosa, Braun, Beige / Natur, Silber / Metall,
Transparent, Mehrfarbig / Muster.

Entscheidungen vom 29.10.2026: Navy bleibt von Blau getrennt. Zweifarbige Namen (`black/white`) und Khaki stehen in **beiden** Familien.

## Die Tabelle `data/farbfamilien.csv`

Semikolon-getrennt, UTF-8, Spalten `slug;name;produkte;familien;hinweis`. Gelesen werden nur `slug` und `familien`, der Rest ist Information.

- `slug`: Slug des `pa_color`-Begriffs (wie in `wp term list pa_color`).
- `familien`: eine oder mehrere Familien, getrennt durch `|`, als Name oder Slug (`Grün | Beige / Natur`, `gruen | beige-natur`).
- Leere `familien`: Die Farbe ist bekannt, aber noch offen. Sie wird nicht zugeordnet und gemeldet.
- `hinweis`: `automatisch`, `automatisch, mehrere Familien`, `manuell vorgeschlagen` oder `offen`. Reine Notiz.
- Unbekannte Familiennamen werden im PHP-Log gemeldet (`[ML FARBFAMILIE] Zuordnungstabelle Zeile …`), die übrigen Familien der Zeile gelten weiter.

## Die Schleife bei neuen Farben

1. Ein Import läuft. Der Sweep setzt die Familien und schreibt eine Zeile ins PHP-Log:
   `[ML FARBFAMILIE] Import N abgeschlossen - … Eltern geprueft, … geaendert, … ohne Farbe, … nur mit unbekannten Farben, … Farben ohne Zuordnung (haeufigste: …)`
2. Liste der Farben ohne Zuordnung, mit automatischem Vorschlag:
   `wp ml-farbfamilien offen --out="$HOME/Desktop/farben-offen.csv"` (besser `$HOME` als `~`)
3. Die Datei prüfen, die Spalte `familien` ausfüllen oder korrigieren und die Zeilen in `data/farbfamilien.csv` übernehmen (Zeilen ersetzen, wenn der `slug` schon
   vorkommt, sonst unten anhängen).
4. `wp ml-farbfamilien anwenden --dry-run`, dann `wp ml-farbfamilien anwenden` (setzt die Familien an allen Lieferantenprodukten, rund 2 Minuten bei 11.299 Eltern).
5. Tabelle committen.

## Befehle

- `wp ml-farbfamilien anwenden [--dry-run] [--limit=N]`: Familien an allen Lieferanten-Elternprodukten setzen. Mit `--dry-run` wird nur gezählt (auch das Attribut wird
  nicht angelegt). Beim ersten echten Lauf werden das Attribut und die 17 Begriffe angelegt.
- `wp ml-farbfamilien offen [--out=Datei]`: Farben ohne Zuordnung im Format der Tabelle. Der Vorschlag kommt aus Schlüsselwörtern (Deutsch und Englisch,
  `ml_ff_rules()`), er wird nie ungeprüft angewendet.

## Hooks

- `pmxi_import_complete` und `pmxi_after_xml_import`, Priorität 20: Sweep nach dem Import, nach `sweep_variation_attributes` (Priorität 10), das die Farben an die
  Elternprodukte bringt. Je Import läuft er nur einmal, auch wenn beide Hooks feuern.
- `mlwf_auto_filter_attributes` und `mlwf_search_facet_taxonomies` (media-lab-woocommerce): Die Farbfamilie tritt in der automatischen Filterliste und in den Facetten der
  Suche an die Stelle der Farbe. Ausdrücklich eingetragene Filter-Attribute bleiben, wie sie sind.

## Was der Sweep tut und nicht tut

- Liest die `pa_color`-Begriffe am Elternprodukt und setzt `pa_farbfamilie`. Ändert nie `pa_color`.
- Nur Lieferantenprodukte (`_ml_supplier_code`).
- Produkte ohne `pa_color` werden nicht angefasst. Am 29.10.2026 waren das 2.694 von 11.299 Eltern, davon 2.685 einfache Produkte ganz ohne Merkmale (TKM 2.413,
  LCC 272) und 9 variable TKM-Produkte nur mit Größe. Sie fehlten auch vorher in der Farbe-Leiste.
- Produkte, deren Farben alle ohne Zuordnung sind (43 am 29.10.2026), behalten ihre bisherigen Familien.
- Fehlt die Tabelle oder ist sie leer, tut der Sweep nichts und meldet es im Log.
- Die Begriffszähler (`wp term list pa_farbfamilie --fields=name,count`) zählen nur **veröffentlichte** Produkte.

## Bekannte Grenzen

- Formen und Motive, die die Lieferanten als „Farbe" führen (`circle`, `rechteck`, `baum`, `stern`, …), bleiben als „offen" in der Liste. Eine Markierung „keine
  Farbe, bewusst ohne Familie" gibt es noch nicht.
- Produkte ohne Farbangabe erscheinen nicht in der Farbfamilien-Auswahl.
- Das Zurücksetzen (Attribut und Begriffe wieder entfernen) ist nicht vorbereitet und nicht getestet.

## Stand

Eingebaut und ausgeführt am 29.10.2026 (Trockenlauf, 50 Produkte, voller Lauf mit 8.531 geänderten Produkten). Die Logik wurde zusätzlich in einer
Testumgebung ohne WordPress geprüft, die Regeln gegen eine zweite Umsetzung aller 2.459 Farbnamen.
