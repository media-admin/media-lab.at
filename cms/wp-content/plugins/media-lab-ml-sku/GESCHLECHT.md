# Geschlechtsfilter (media-lab-ml-sku)

Stand 16.11.2026. Setzt das Attribut `pa_geschlecht` ("Geschlecht") an den Elternprodukten: Herren, Damen, Unisex, Kinder. Das Attribut
ist unsichtbar (kein Eintrag im Tab "Zusätzliche Informationen") und dient nur dem Filter. Die Filterleiste nimmt alle `pa_*`-Attribute
automatisch auf, das Plugin media-lab-woocommerce bleibt unverändert. Ändert nur `pa_geschlecht`.

Entscheidungen (Markus, 16.11.2026): Der Feed wird zuerst nach Hinweisen durchsucht. "Kinder" ist ein eigener Wert. Produkte ohne Hinweis
bekommen **gar keinen Wert** (kein "Unisex" als Auffüllwert).

## Dateien

| Datei | Inhalt |
|---|---|
| `inc/geschlecht.php` | Logik: Regeln lesen, Wert bestimmen, Sweep nach dem Import, WP-CLI-Befehle, Reihenfolge der Werte |
| `data/geschlecht.csv` | Regeltabelle (Quelle, Muster, Wert, Länge des durchsuchten Textanfangs) |
| `supplier-sync` | Cotton: Spalte `gender_hint` im Feed (Name2 deutsch), siehe unten |

## Quellen (Reihenfolge, die erste mit eindeutigem Ergebnis gewinnt)

1. `hinweis`: Meta `_ml_gender_hint` (nur Cotton Classics: Name2 deutsch aus der Style List, z. B. "Herren Piqué Polo")
2. `titel`: Produkttitel ("BASILE WOMEN", "Kinder Hut …", "B&C | ID.334 Zip Hood /kids")
3. `groesse`: `pa_size`-Slugs (Makito: `hom` = Herren, `muj` = Damen; beides an einem Produkt, zum Beispiel Flip Flops, ergibt beide Werte)
4. `kategorie`: Lieferantenkategorien (gemerkt in `_ml_kat_quelle`, sonst die aktuellen `product_cat`): MidOcean "… > Kids", Makito
   "Kinder" und "Damenartikel", Cotton "Baby"
5. `beschreibung`: "Unisex" in den ersten 400 Zeichen, "Herren" oder "Damen" nur als Zusatz ganz vorn ("SOL'S SPRING II, Herren Polo-Shirt"),
   nicht in "geeignet für Damen- und Kindermodelle"

Treffer einer Quelle: genau ein Wert = dieser Wert. Herren und Damen zusammen = Unisex. Unisex mit Kinder = Kinder. Kinder zusammen mit
Herren oder Damen ist ein Widerspruch: Die Quelle zählt nicht, die nächste entscheidet (gezählt als "mit Widerspruch").

## Ergebnis aus den Feeds vom 27.10.2026 (Trockenrechnung, Eltern je Feed-Zeile)

| Lieferant | Herren | Damen | Unisex | Kinder | beide (Größe) | ohne Wert |
|---|---|---|---|---|---|---|
| MidOcean (2.768) | 97 | 92 | 91 | 34 | 0 | 2.454 |
| Makito (4.515) | 0 | 78 | 57 | 308 | 9 | 4.063 |
| Cotton Classics (4.009) mit `gender_hint` | 706 | 661 | 164 | 223 | 0 | 2.255 |
| Cotton Classics ohne `gender_hint` | 164 | 189 | 54 | 111 | 0 | 3.491 |

Die meisten Produkte ohne Wert sind keine Textilien (Becher, Stifte, Technik). Unter den Textilien bleiben rund 850 ohne Hinweis
(Cotton 626, MidOcean 122, Makito 100, zum Beispiel "Erwachsene T-Shirt Dynamic" oder "Jacke Cornal"); sie bekommen bewusst keinen Wert.
Makito: Die Kategorie "Kinder" (283 Produkte) enthält auch Spielzeug und Schreibwaren, sie zählen als Kinder.

## Die Tabelle `data/geschlecht.csv`

Semikolon-getrennt, UTF-8, Spalten `quelle;muster;wert;max_zeichen;hinweis`.

- `quelle`: eine oder mehrere Quellen, getrennt durch Komma (`hinweis,titel`).
- `muster`: regulärer Ausdruck ohne Trennzeichen, Groß-/Kleinschreibung egal, Unicode. **Kein Semikolon im Muster** (Spaltentrenner).
  Wortgrenzen mit `\b`, damit "men" nicht in "women" trifft.
- `wert`: `Herren`, `Damen`, `Unisex` oder `Kinder`.
- `max_zeichen`: Länge des durchsuchten Textanfangs, 0 = ganzer Text.
- `hinweis`: Notiz. Zeilen, deren Muster mit `#` beginnt, werden übersprungen.

## Cotton Classics: Name2 im Feed

Der Titel (Name1) nennt das Geschlecht nur bei 439 von 4.009 Styles, Name2 (deutsch) bei 1.665. Der Adapter schreibt Name2 in die neue
Feed-Spalte `gender_hint` (am Ende der Parent-CSV, die bestehende Zuordnung der Imports bleibt unberührt). Damit sie ankommt, muss im
Cotton-Parent-Import (Import 11) ein benutzerdefiniertes Feld `_ml_gender_hint` mit dem Wert `{gender_hint[1]}` angelegt werden
(WP All Import, Schritt "Benutzerdefinierte Felder"). Bis dahin gilt die Zeile "ohne `gender_hint`" aus der Tabelle oben.

## Befehle

- `wp ml-geschlecht anwenden [--dry-run] [--limit=N]`: `pa_geschlecht` an allen Lieferanten-Elternprodukten setzen. Beim ersten echten Lauf
  werden das Attribut und die vier Werte angelegt. Produkte ohne Hinweis verlieren einen früher gesetzten Wert.
- `wp ml-geschlecht bericht --out=<datei>`: je Produkt SKU, Lieferant, Titel, Wert, Quelle und Widerspruch (ändert nichts), zum Prüfen der Regeln.

Nach jedem Import läuft der Sweep (Priorität 25, nach Größen, vor Kategorien) und schreibt eine Zeile `[ML GESCHLECHT] …` ins PHP-Log.
