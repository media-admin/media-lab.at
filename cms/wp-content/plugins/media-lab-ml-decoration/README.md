# Media Lab ML Decoration

Projekt-Plugin für at.media-lab (nicht im Starter-Kit). Zeigt auf der WooCommerce-Produktseite den Tab
**Veredelung** mit Druckpositionen, Techniken und – optional – Preisen (MidOcean, Makito).

## Changelog

- 1.0.2: Bei Vollfarbtechniken (Fullcolor/Vollfarbe im Namen) wird keine Farbanzahl mehr angezeigt.
- 1.0.1: Aufeinanderfolgende Mengenstufen mit gleichem Preis werden zu "ab X" zusammengefasst.
- 1.0.0: Erste Version (Tab, Preisliste, Faktor, Preis-Schalter je Lieferant).

## Datenfluss

1. `supplier-sync` schreibt je Lieferant
   - `feeds/<lieferant>_products.csv` mit der Spalte `decoration_json` (Positionen + erlaubte Techniken je Produkt)
   - `feeds/<lieferant>_decoration_pricelist.json` (Preise je Technik, einmal pro Lieferant)
2. **WP All Import** (Parent-Import) schreibt `decoration_json` in das Custom Field `_ml_decoration`.
3. Das Plugin liest die Preisliste automatisch aus `wp-content/supplier-sync/feeds/` (Prüfung alle 15 Minuten) oder per
   Button / Upload unter **WooCommerce → Veredelung**.

## Einrichtung

1. Ordner nach `wp-content/plugins/media-lab-ml-decoration/` kopieren und aktivieren.
2. WP All Import, Parent-Import (CSV `*_products.csv`): unter *Custom Fields* ein neues Feld anlegen
   - Name: `_ml_decoration`
   - Wert: `{decoration_json[1]}`
   und bei *Existing Items* sicherstellen, dass Custom Fields aktualisiert werden. Import neu ausführen.
3. **WooCommerce → Veredelung**: „Preislisten jetzt einlesen", Faktor je Lieferant setzen, „Preise anzeigen" nach Bedarf.

## Verhalten

- Preis = Einkaufspreis der Veredelung × Faktor (getrennt vom Produktpreis-Faktor `ml_markup_factor_*`).
- „Preise anzeigen" ist standardmäßig **aus**. Bei „aus" werden Preise gar nicht erst in den HTML-Code geschrieben.
- MidOcean: Flächenstufen werden angezeigt, soweit sie auf der Position erreichbar sind (Untergrenze < max. Druckfläche).
- Makito: Die Flächenstufe steckt in der Technik (Tampondruck A–K, Digital W1–W11 …), das Produkt nennt die passende.
- Shortcode für Themes ohne Tabs: `[ml_decoration]` oder `[ml_decoration id="123"]`.
- Filter: `ml_deco_suppliers`, `ml_deco_feeds_dir`, `ml_deco_include_handling`, `ml_deco_position_label`, `ml_deco_is_full_colour`.

## Vor Go-Live prüfen (Annahmen)

- MidOcean: Handling-Zuschlag (`print_manipulation`) wird pro Stück zum Preis addiert (Filter `ml_deco_include_handling`).
- Makito: Staffel gilt „bis einschließlich"; Preis bei cm²-Techniken = Grundpreis + cm²-Preis × Fläche, mindestens
  Mindeststückpreis; „Mindestauftragswert" = `minjob`. Gegen `tarifa_general_marcaje_2026_ing.pdf` prüfen.
- Makito-Zuschlagstexte (Einzelverpackung usw.) werden bewusst nicht ausgegeben.
- Flächenstufen bei MidOcean sind in cm² (aus den Größenordnungen abgeleitet, nicht dokumentiert).
