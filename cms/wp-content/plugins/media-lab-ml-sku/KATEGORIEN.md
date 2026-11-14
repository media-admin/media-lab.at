# Kategorien (media-lab-ml-sku ab 0.6.0)

Stand 13.11.2026. Ordnet jedes Produkt in einen eigenen Zielbaum ein (12 Hauptkategorien, 76 Unterkategorien, zwei Ebenen) und setzt
eine Kollektion als Filter-Merkmal (`pa_kollektion`). Die drei Lieferanten bringen drei Bäume mit (MidOcean tief, Makito flach und
deutsch, Cotton Classics flach und englisch, zusammen 392 Begriffe). Geändert wird nur `product_cat` und `pa_kollektion`.

## Wie es arbeitet

1. Jeder Import (WP All Import, `full_update`) setzt `product_cat` auf die Lieferantenkategorien zurück.
2. Danach läuft der Sweep (Hooks `pmxi_import_complete` und `pmxi_after_xml_import`, Priorität 30). Er liest die Lieferantenkategorien,
   merkt sie am Produkt (`_ml_kat_quelle`), setzt die Zielkategorien (`_ml_kat_ziel`) und die Kollektionen und löscht leere
   Lieferantenbegriffe.
3. Änderungen an den Tabellen wirken mit `wp ml-kategorien anwenden`, ohne neuen Import (der Sweep nimmt die gemerkten Quellen).

## Regel je Produkt (Reihenfolge)

1. **Ausnahme** aus `data/kategorien-ausnahmen.csv` (über die SKU) gewinnt immer. Steht in der Spalte `kollektionen` nichts, bleiben die berechneten
   Kollektionen des Produkts erhalten.
2. **Makito** (SKU `ML-TKM-…`): zuerst die **Titelregel** (die Warenart steht am Anfang des Titels), dann die Unterkategorie der Quelle.
   **MidOcean und Cotton Classics**: zuerst die Quelle, die Titelregel nur, wenn die Quelle keine Unterkategorie liefert.
3. **Fallback-Regeln** (Spalte `fallback` = 1 in `kategorien-titelregeln.csv`) sind allgemeine Wörter wie "Halter", "Tasche", "Clip" oder "Thermometer".
   Sie greifen erst, wenn weder eine starke Titelregel noch die Quelle eine Unterkategorie liefern, und überstimmen eine Quelle also nie.
4. Allgemeine Quellkategorien (Geschenke und Werbegaben, OUTDOOR-SAISON, Freizeit und Unterhaltung) zählen nur, wenn nichts anderes da ist.
5. Bleibt nur eine Hauptkategorie, gilt der **Standard** der Hauptkategorie (Spalte `standard` in `kategorien-baum.csv`): Taschen & Reisen →
   Einkaufstaschen & Beutel, Kinder & Spiele → Spiele, Büro & Schreibgeräte → Schreibtisch-Zubehör, Essen & Trinken → Tassen & Becher,
   Werkzeug, Auto & Schlüsselanhänger → Werkzeuge & Taschenlampen. Die anderen Hauptkategorien haben keinen Standard und bleiben bei der Hauptkategorie.
6. Ergibt sich nichts, **bleiben die Kategorien des Produkts unverändert** und das Produkt steht im Bericht.

## Dateien in `data/` (Semikolon, UTF-8, mit Kopfzeile)

| Datei | Inhalt |
|---|---|
| `kategorien.csv` | Lieferantenkategorie als Pfad (`A > B > C`, die Namen) → `aktion` (zuordnen, haupt, kollektion, streichen, offen), `zielkategorie` (`Haupt > Unter`, mehrere mit ` \| `), `kollektion` |
| `kategorien-baum.csv` | Zielbaum mit Slugs; `standard` je Hauptkategorie |
| `kategorien-titelregeln.csv` | `muster` (Regex, auf den Titel in Kleinschreibung) → `haupt`, `unter`, `fallback` (0 oder 1). Der Treffer, der im Titel am weitesten links steht, gewinnt |
| `kategorien-ausnahmen.csv` | `sku;zielkategorien;kollektionen;notiz` für einzelne Produkte von Hand |

Der Pfad in `kategorien.csv` besteht aus den Namen, nicht aus Slugs oder IDs, damit die Tabelle nach der Kopie auf den Live-Server und
bei neu angelegten Begriffen stimmt. Namen mit `&` werden ohne `&amp;` geschrieben.

## Nachträglich eingreifen

- **Eine Lieferantenkategorie anders zuordnen:** Zeile in `kategorien.csv` ändern, dann `wp ml-kategorien anwenden`.
- **Ein Produkttyp wird falsch erkannt:** Muster in `kategorien-titelregeln.csv` ändern oder eine Zeile ergänzen (spezifische Regeln nach oben
  hilft bei gleicher Stelle im Titel), dann `anwenden`.
- **Einzelnes Produkt:** Zeile in `kategorien-ausnahmen.csv` (`ML-TKM-001234;Technologie > Powerbanks;Premium;Grund`), dann `anwenden`.
  Eine Kategorie von Hand im Produkt zu ändern hält nur bis zum nächsten Import oder Lauf.
- **Neue Kategorie vom Lieferanten:** steht im Log (`[ML KATEGORIEN] … Lieferantenkategorien ohne Eintrag`) und in `wp ml-kategorien offen`.
- **Zielbaum ändern** (Name, neue Unterkategorie): `kategorien-baum.csv`, dazu die Zeilen in `kategorien.csv` und die Regeln, die darauf zeigen.
  Das Skript prüft alle Tabellen beim Laden und bricht mit einer Meldung ab, wenn eine Zielkategorie fehlt.

## Befehle

- `wp ml-kategorien anwenden --dry-run [--limit=N] [--bericht=Datei]`: zählt nur, ändert und legt nichts an.
- `wp ml-kategorien anwenden`: setzt alles, legt den Zielbaum und das Merkmal Kollektion beim ersten Lauf an.
- `wp ml-kategorien offen [--out=Datei]`: Lieferantenkategorien mit Produkten, die in `kategorien.csv` fehlen.
- Danach optional die Zähler neu berechnen: `wp term recount product_cat`.

## Hinweise

- Zielbegriffe, die genauso heißen wie ein Lieferantenbegriff (Textilien, Technologie, Taschen & Reisen, Kinder & Spiele und vier
  Unterkategorien), werden weiterverwendet; Reste von Lieferanten darunter löscht der Sweep, wenn sie leer sind.
- Gehört ein gewünschter Slug einem Lieferantenbegriff (zum Beispiel `t-shirts`), bekommt dieser den Slug `quelle-t-shirts`.
- Bei Produkten ohne Zielkategorie bleiben die Lieferantenkategorien sichtbar, bis eine Regel oder Ausnahme greift.
