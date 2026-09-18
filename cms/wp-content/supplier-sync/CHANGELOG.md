# Changelog — Supplier Sync (media-lab.at)

Dokumentiert die Entwicklung des projektspezifischen Lieferanten-Sync-Systems
(`cms/wp-content/supplier-sync/`) für den media-lab.at Webshop. Kein
wiederverwendbares Starter-Kit-Modul (taucht daher bewusst nicht im
Root-`CHANGELOG.md` auf) — projektspezifischer Code für dieses eine Deployment.

Ausführliche technische Hintergründe, Bugs und Architektur-Entscheidungen:
siehe Notion „WooCommerce Import – Entscheidungen & Setup" →
„Technische Erkenntnisse & bekannte Probleme".

---

## 2026-09-18

### Removed
- Toter `middleware/`-Codebaum entfernt (Vorgängerarchitektur inkl. eigenem
  `vendor/`, `.env`, Adapter-Kopien für MidOcean/Makito, altes
  `Product`-Modell ohne Parent/Varianten-Split) — vollständig durch diesen
  Baum ersetzt, keine Code-Referenzen mehr vorhanden

### Changed
- Alle drei Lieferanten (MidOcean, Makito, Cotton Classics) vollständig
  produktiv: insgesamt ~13.000 Parent-Produkte, ~146.000 Varianten

---

## 2026-09-14

### Added
- Cotton-Classics-Adapter komplett neu aufgebaut: Parent/Varianten-Split
  (Gruppierung nach `Style`, analog MidOcean/Makito), Bilder via
  FTP-Spiegelung (`download_cotton_images.php`, GUID-Packshot-Mechanismus,
  `Alle_72dpi`-Auflösung), Einzelpreis pro Variante (`VKEinzel`)
- `Product->brand` als neues, gemeinsames Feld für alle drei Adapter
  (bei Cotton Classics aus der `Manufacturer`-Spalte)
- `sync.php` unterstützt jetzt einen optionalen CLI-Supplier-Filter
  (`php sync.php <supplier_key>`) statt immer alle Lieferanten zu syncen

### Fixed
- `FeedGenerator`-CSV-Header war hartcodiert statt aus `Product::toArray()`
  abgeleitet — das Hinzufügen von `brand` verschob dadurch stillschweigend
  alle nachfolgenden Spalten in allen drei Feeds (MidOcean, Makito, Cotton
  Classics gleichermaßen betroffen)
- Cotton-Classics-Null-GUID-Platzhalter (`00000000-...`) wird jetzt wie
  „kein Bild" behandelt statt als kaputte Bild-Referenz (betraf 6 Produkte,
  371 Varianten)
- Versehentliche `openspout`-Installation im Projekt-Root rückgängig gemacht
  (gehört nur in `supplier-sync/composer.json`)

---

## 2026-09-12 – 2026-09-13

### Changed
- Makito komplett auf den `lookup_skus.php`-Workflow umgestellt (ersetzt
  einen nicht näher dokumentierten Legacy-Matching-Mechanismus über
  `parent_actual_sku`)
- Makito-Parent-Katalog von versehentlich limitierten 30 auf vollständige
  4.512 Produkte nachimportiert (`Import only specified records` war
  fälschlich aktiv)

### Added
- `has_variants`-Filter (XPath `parent_has_variants[1] = "1"`) für den
  Makito-Varianten-Import, um Einzelfarb-Produkte korrekt als „simple
  product" statt als kaputte Variation zu behandeln

---

## 2026-09-10

### Fixed
- WP All Import „Update all Custom Fields" löschte `_ml_parent_number`/
  `_ml_variant_number` vor jedem erneuten Sync-Lauf — auf allen Imports
  (MidOcean, Makito) auf „Update only these Custom Fields" mit expliziter
  Feldliste umgestellt

---

## 2026-09-08 – 2026-09-09

### Added
- MidOcean-Varianten-Import produktiv (15.273 Varianten), inkl. WP-CLI-
  Workflow (`wp all-import run <id>`) statt Browser-Import (~50% schneller,
  läuft im Hintergrund weiter)

### Fixed
- WP All Import Timing-Race-Condition bei Varianten-Erstellung: neuer
  Sweep-Hook (`pmxi_import_complete`) in `media-lab-ml-sku` korrigiert SKUs,
  die während der zweistufigen `product`→`product_variation`-Konvertierung
  im rohen Lieferantenformat hängen geblieben sind

---

## 2026-09-01 – 2026-09-05

### Added
- `lookup_skus.php <supplier_key>`: supplier-agnostisches Skript, das nach
  dem Parent-Import die tatsächlich vergebenen ML-SKUs aus der DB liest und
  als `parent_current_sku`-Spalte in einen angereicherten Varianten-Feed
  schreibt — löst das Grundproblem, dass WP All Import Parent↔Variante nur
  über die echte WooCommerce-SKU matched, diese aber erst nach dem
  Parent-Import feststeht
- MidOcean-Adapter fertiggestellt: `fetchViaPresignedUrl()` erkennt alle
  drei unterschiedlichen Presigned-URL-Formate der MidOcean-API automatisch
  (`products/2.0`, `stock/2.0`, `pricelist/2.0`)

### Fixed
- WordPress Hook-Reihenfolge-Bug: `save_post_{post_type}` feuert vor dem
  generischen `save_post`, wodurch WooCommerce die per Custom-Hook gesetzte
  SKU überschrieb — zusätzlicher Safety-Net-Hook mit Priorität 999 in
  `media-lab-ml-sku`
