# Media Lab ML Decoration

Projekt-Plugin für at.media-lab (nicht im Starter-Kit). Zeigt auf der WooCommerce-Produktseite den Tab
**Veredelung** mit Druckpositionen, Techniken und – optional – Preisen (MidOcean, Makito).

## Changelog

- 1.2.2: Menge wird auch aus dem Mengenfeld des Konfigurators (Makito, Alpine.js, +/- Knöpfe) übernommen.
- 1.2.1: Menge wird aus dem Mengenfeld des Wunschlisten-Blocks (.mlw-wishlist-qty__input) übernommen; Suche zuerst innerhalb des eigenen Produkts.
- 1.2.0: Auswahl + Preisrechner im Tab: Position, Technik, Farben, (Flächenstufe / Motivfläche), Menge und Wiederholungsauftrag. Mehrere Positionen kombinierbar, Summe mit Einrichtung. Ohne "Preise anzeigen" nur die Auswahl, ohne Beträge im Quelltext. Beträge im Shop-Format (WooCommerce-Einstellungen).
- 1.1.0: Bilder der Druckpositionen (Makito) öffnen per Klick in einer Vergrößerung (Lightbox, ohne Fremdbibliothek, ESC/Klick schließt).
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

## Preisrechner

- Menge: übernimmt das Mengenfeld der Produktseite (Selector per Filter `ml_deco_qty_selector`, Standard `.mlw-wishlist-qty__input, .configurator-number__input[x-model\.number*="quantity"], form.cart input.qty, input[name="quantity"]`) und folgt Änderungen; das Feld im Rechner ist zusätzlich änderbar.
- Stückpreis wie in den Tabellen (inkl. Handling, weitere Farben, cm²-Preise, Mindeststückpreis); Summe = Einrichtung + max(Menge × Stückpreis, Mindestauftragswert).
- Flächenstufe (MidOcean): Vorbelegung = größte erreichbare Stufe (vorsichtige Schätzung). Preis je cm² (Makito): Motivfläche, Vorbelegung = max. Druckfläche.
- Über der letzten Makito-Staffel: "Preis auf Anfrage". Einstellung: WooCommerce → Veredelung → "Auswahl & Preisrechner".
- Noch ohne Übergabe an Anfrage/Wunschliste (geplant, Etappe 2).

## Vor Go-Live prüfen (Annahmen)

- MidOcean: Handling-Zuschlag (`print_manipulation`) wird pro Stück zum Preis addiert (Filter `ml_deco_include_handling`).
- Makito: Staffel gilt „bis einschließlich"; Preis bei cm²-Techniken = Grundpreis + cm²-Preis × Fläche, mindestens
  Mindeststückpreis; „Mindestauftragswert" = `minjob`. Gegen `tarifa_general_marcaje_2026_ing.pdf` prüfen.
- Mindestauftragswert (Makito, `minjob`) wird je Linie einmal angewendet (nicht je Farbe); gegen das Tarif-PDF prüfen.
- Makito-Zuschlagstexte (Einzelverpackung usw.) werden bewusst nicht ausgegeben.
- Flächenstufen bei MidOcean sind in cm² (aus den Größenordnungen abgeleitet, nicht dokumentiert).
