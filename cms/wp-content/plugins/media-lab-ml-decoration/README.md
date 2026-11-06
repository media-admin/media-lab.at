# Media Lab ML Decoration

Projekt-Plugin für at.media-lab (nicht im Starter-Kit). Zeigt auf der WooCommerce-Produktseite den Tab
**Veredelung** mit Druckpositionen, Techniken und – optional – Preisen (MidOcean, Makito).

## Changelog

- 1.5.1: Die Bilder der Druckpositionen stehen nur noch im Auswahlbereich oben, nicht mehr zusätzlich im Überblick darunter (ohne Rechner bleiben sie im Überblick; Filter `ml_deco_overview_images`).
- 1.5.0: Bilder der Druckpositionen auch im Bereich "Veredelung wählen" (neben den Feldern, mit Vergrößerung). MidOcean liefert ein Beispielbild je Position (benötigt MidOceanDecorations ab dieser Version im Sync und einen neuen Import), Makito hatte die Bilder schon.
- 1.4.1: Gesamtzeile in Anfrage und Wunschliste heißt jetzt "Veredelung gesamt (ca., netto, zusätzlich zum Produktpreis)", damit sie nicht mit der Produkt-Gesamtsumme verwechselt wird.
- 1.4.0: Veredelung wird auch auf der Wunschlisten-Seite angezeigt (benötigt den Filter mlw_wishlist_display_items in media-lab-woocommerce). Die Auswahl liegt am selben Ort wie die Wunschliste: Gast = Session, eingeloggt = User-Meta (dauerhaft, 90 Tage), Gast-Auswahl wird beim Login übernommen.
- 1.3.0: Auswahl wird mit der Anfrage übermittelt (Anfrage-Mail und Anfrage-Eintrag), serverseitig geprüft und neu berechnet. Auswahl bleibt in der WooCommerce-Session erhalten und wird auf der Produktseite wieder angezeigt.
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
- Filter: `ml_deco_suppliers`, `ml_deco_feeds_dir`, `ml_deco_include_handling`, `ml_deco_position_label`, `ml_deco_is_full_colour`, `ml_deco_overview_images`.

## Preisrechner

- Menge: übernimmt das Mengenfeld der Produktseite (Selector per Filter `ml_deco_qty_selector`, Standard `.mlw-wishlist-qty__input, .configurator-number__input[x-model\.number*="quantity"], form.cart input.qty, input[name="quantity"]`) und folgt Änderungen; das Feld im Rechner ist zusätzlich änderbar.
- Stückpreis wie in den Tabellen (inkl. Handling, weitere Farben, cm²-Preise, Mindeststückpreis); Summe = Einrichtung + max(Menge × Stückpreis, Mindestauftragswert).
- Flächenstufe (MidOcean): Vorbelegung = größte erreichbare Stufe (vorsichtige Schätzung). Preis je cm² (Makito): Motivfläche, Vorbelegung = max. Druckfläche.
- Über der letzten Makito-Staffel: "Preis auf Anfrage". Einstellung: WooCommerce → Veredelung → "Auswahl & Preisrechner".

## Übergabe an die Anfrage

- Der Browser schickt nur die AUSWAHL (Position, Technik, Farben, Stufe, Fläche, Wiederholung) per Ajax
  (`mld_selection_save`, mit Nonce). Sie wird je Produkt in der WooCommerce-Session gespeichert (max. 100 Produkte).
  Gäste: WooCommerce-Session (dieselbe wie bei der Wunschliste, kein neuer Cookie). Eingeloggte Nutzer: User-Meta
  `ml_deco_selection` (dauerhaft wie ihre Wunschliste, Einträge älter als 90 Tage entfallen, Filter
  `ml_deco_selection_ttl_days`). Beim Login wird die Gast-Auswahl übernommen.
- Der Server prüft jede Linie gegen die Produktdaten (Position, erlaubte Technik, max. Farben, erreichbare Stufe,
  max. Fläche). Preise aus dem Browser werden nie verwendet.
- Beim Absenden einer Anfrage (Warenkorb, Konfigurator, Wunschliste) hängt der Filter `mlw_before_save_inquiry`
  der Inquiry-Engine je Produktzeile Zeilen an `config_display` an: `Veredelung – <Position>`, bei aktivem
  "Preise anzeigen" mit Stückpreis, Einrichtung und Summe, dazu `Veredelung gesamt (ca., netto)`. Es wird mit der
  Menge der Anfragezeile gerechnet. Ohne "Preise anzeigen" stehen nur Position, Technik und Farben in der Mail
  (die Kundenmail enthält dieselbe Produktliste).
- Beim Laden der Produktseite wird die gemerkte Auswahl wieder angezeigt (`mld_selection_get`).
- Wunschlisten-Seite: Der Filter `mlw_wishlist_display_items` (media-lab-woocommerce, Ende von
  `MediaLab_Wishlist_Storage::get_items_for_display()`) liefert die Items; das Plugin ergänzt `config_display`.
  Gilt nur für die eigene Liste, nie für die geteilte Token-Ansicht. Die Wunschlisten-Summe enthält die Veredelung nicht.

## Vor Go-Live prüfen (Annahmen)

- MidOcean: Handling-Zuschlag (`print_manipulation`) wird pro Stück zum Preis addiert (Filter `ml_deco_include_handling`).
- Makito: Staffel gilt „bis einschließlich"; Preis bei cm²-Techniken = Grundpreis + cm²-Preis × Fläche, mindestens
  Mindeststückpreis; „Mindestauftragswert" = `minjob`. Gegen `tarifa_general_marcaje_2026_ing.pdf` prüfen.
- Mindestauftragswert (Makito, `minjob`) wird je Linie einmal angewendet (nicht je Farbe); gegen das Tarif-PDF prüfen.
- Makito-Zuschlagstexte (Einzelverpackung usw.) werden bewusst nicht ausgegeben.
- Flächenstufen bei MidOcean sind in cm² (aus den Größenordnungen abgeleitet, nicht dokumentiert).
