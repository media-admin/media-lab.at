# Größenfilter (media-lab-ml-sku)

Stand 12.11.2026. Ordnet die Lieferantengrößen (Attribut `pa_size`, rund 290 Begriffe) normalisierten Werten in Gruppen zu
(Attribut `pa_groesse`, "Größe"). Die Originalgröße bleibt an Produkt und Variante, `pa_groesse` dient nur dem Filter
und ist unsichtbar (kein Eintrag im Tab "Zusätzliche Informationen"). Ändert nur `pa_groesse`, nie `pa_size`.

## Dateien

| Datei | Inhalt |
|---|---|
| `inc/groessen.php` | Logik: Sweep nach dem Import, Zuordnung, WP-CLI-Befehle, Filter-Hooks |
| `data/groessen.csv` | Zuordnungstabelle (Stand 12.11.2026: 290 Zeilen, alle mit Gruppe) |

## Gruppen (Reihenfolge im Filter)

Kleidergrößen, Zahlengrößen (Konfektion, Schuhe), Kinder (Körpergröße in cm), Kinder (Alter in Jahren), Baby (Alter in Monaten),
Schuh- und Sockengrößen, Weitere Größen. In der Gruppe "Kleidergrößen" stehen 14 Werte: 3XS, XXS, XS, S, M, L, XL, XXL, 3XL … 8XL.

Entscheidungen vom 12.11.2026: Angezeigt wird XXL (nicht 2XL), `xxxl` zählt als 3XL. Doppelgrößen (S/M, L/XL) zählen für beide
Größen. Damengrößen mit britischer Zahl (10_S, 12_M) zählen als Buchstabengröße. Zahlen ab 65 gelten als Körpergröße in cm.

## Die Tabelle `data/groessen.csv`

Semikolon-getrennt, UTF-8, Spalten `slug;name;produkte;gruppe;werte;hinweis`. Gelesen werden `slug`, `gruppe` und `werte`.

- `slug`: Slug des `pa_size`-Begriffs (wie in `wp term list pa_size`).
- `gruppe`: Name der Gruppe wie oben.
- `werte`: ein oder mehrere Werte, getrennt durch `|` (`S | M`). Der Wert ist das, was in der Leiste steht.
- Leere `gruppe`: Die Größe ist bekannt, aber noch offen. Sie wird nicht zugeordnet und gemeldet.
- `hinweis`: Notiz (`Buchstabengröße`, `Doppelgröße, beide Größen`, `nicht erkannt, bleibt unter Weitere` …).

## Die Schleife bei neuen Größen

1. Ein Import läuft. Der Sweep setzt die Werte und schreibt eine Zeile ins PHP-Log:
   `[ML GROESSEN] Import N abgeschlossen - … Eltern geprueft, … geaendert, … ohne Groesse, … nur mit unbekannten Groessen, … Groessen ohne Zuordnung (haeufigste: …)`
2. Liste der Größen ohne Zuordnung, mit automatischem Vorschlag nach der Form des Namens:
   `wp ml-groessen offen --out="$HOME/Desktop/groessen-offen.csv"`
3. Datei prüfen, `gruppe` und `werte` ausfüllen oder korrigieren, die Zeilen in `data/groessen.csv` übernehmen (Zeile ersetzen,
   wenn der `slug` schon vorkommt, sonst anhängen).
4. `wp ml-groessen anwenden --dry-run`, dann `wp ml-groessen anwenden`.
5. Tabelle committen.

## Befehle

- `wp ml-groessen anwenden [--dry-run] [--limit=N]`: `pa_groesse` an allen Lieferanten-Elternprodukten setzen. Beim ersten echten Lauf
  werden das Attribut und alle Werte angelegt.
- `wp ml-groessen offen [--out=Datei]`: Größen ohne Zuordnung im Format der Tabelle, mit Vorschlag. Der Vorschlag wird nie ungeprüft angewendet.

## Term-Metafelder (für die Filterleiste in media-lab-woocommerce)

`mlwf_group` (Gruppenname), `mlwf_group_order` (Nummer der Gruppe), `mlwf_order` (Reihenfolge in der Gruppe), `order_pa_groesse`
(Gesamtreihenfolge, Gruppe mal 1000 plus Platz).

## Gruppen in der Filterleiste (ohne Änderung an media-lab-woocommerce)

Das Plugin `media-lab-woocommerce` bleibt unverändert. Die Gruppen entstehen im Projekt:

- **Reihenfolge** (auch ohne JavaScript): `ml_gr_sort_terms` am Filter `get_terms` (Shop, Kategorie, Marke) und `ml_gr_sort_facets` am Filter
  `mlwf_search_facets` (Suche) bringen die Werte von `pa_groesse` in die natürliche Reihenfolge.
- **Überschriften**: `ml_gr_print_term_groups` (Hook `wp_footer`) schreibt auf Seiten mit Filterleiste ein JSON
  (`<script type="application/json" id="mlwf-term-groups">`, rund 12 KB, Format `{"pa_groesse": {"<slug>": ["Gruppe", Nummer, Platz]}}`).
  Das Theme-Skript `assets/src/js/components/mlwf-term-groups.js` (eingebunden mit einer `import`-Zeile am Anfang von
  `mlwf-filters.js`) hängt die vorhandenen Listeneinträge in Gruppen um: jede Gruppe (auch die Kleidergrößen) ist einklappbar
  (`<details>`) und beim ersten Öffnen eingeklappt, mit Zähler gewählter Werte in der Überschrift. Offen ist nur eine Gruppe mit
  gewähltem Wert, oder die einzige Gruppe im aktuellen Kontext. Die Einträge werden nur
  umgehängt, `mlwf-filters.js` (Ereignisse, Zähler, Filtern, URL) bleibt unverändert. Ohne JSON oder ohne JavaScript bleibt die Liste
  flach, aber sortiert.
- **Styles**: Abschnitt "Gruppen in der Checkbox-Liste" in `assets/src/scss/woocommerce/_woocommerce.scss` (nur Theme-Tokens). Der Abschnitt
  setzt auch für alle Filter: Dropdown so breit wie der längste Eintrag (nie ein Umbruch), mehr Höhe (70 % des Fensters, höchstens 26 rem)
  und einen kompakteren Zeilenabstand (mit Maus; auf Touch-Geräten bleibt die Zeile hoch genug für den Finger).

## Hooks

- `pmxi_import_complete` und `pmxi_after_xml_import`, Priorität 20: Sweep nach dem Import.
- `mlwf_auto_filter_attributes` und `mlwf_search_facet_taxonomies`: `pa_groesse` tritt an die Stelle von `pa_size` (Shop, Kategorien,
  Marken, Suche).
