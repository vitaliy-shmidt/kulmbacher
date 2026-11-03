# 11 – Dateiimport (CSV/XML)

> **Stand 2026-11-03: implementiert und getestet** (`tools/test-import.php`, 134 Tests; HTTP-Durchlauf in der
> Testinstallation). Code: `wp-plugin/includes/class-eventcrawler-importer.php` (Logik),
> `wp-plugin/includes/admin/class-eventcrawler-import-admin.php` (Seite „Eventcrawler → Import“).
> Entscheidung: A104 in [01-konzept.md](01-konzept.md).

## 1. Ziel und Grundsätze

Redaktionen können Veranstaltungen aus Dateien übernehmen (Ersatz für den CSV-/XML-Import von WP Kulmbacher Land,
Entscheidungspunkt E3 in [10-frontend.md](10-frontend.md)).

- Ziel ist **ausschließlich** `{$wpdb->prefix}eventcrawler_events`. Kein `wpem_event`, keine WordPress-Posts, keine
  Synchronisation (A94).
- **Keine Schemaänderung** für den Import: importierte Termine sind normale Zeilen mit eigener `source`.
- Jede Zeile durchläuft dieselben Regeln wie manuelle Termine (`Eventcrawler_Manual_Events::prepare()`, nicht strikt
  wie bei der WPEM-Übernahme): NULL statt Ersatzwerten, nie künstlich 00:00, Endzeit ohne Enddatum = gleicher Tag,
  Ende nicht nach Beginn → Ende verworfen (Hinweis), Links nur http/https (sonst verworfen, Hinweis).
- Immer erst **Probelauf**, dann Import. Mehrfaches Importieren derselben Datei ändert nichts (idempotent).
- Fehlt ein Termin in einer späteren Datei, wird er **nicht** gelöscht (Prinzip wie beim Crawler, 02-datenbank.md
  Abschnitt 7).
- Keine Filterregeln (wie manuelle Termine: redaktionelle Entscheidung), kein unscharfer Abgleich, keine KI.

## 2. CSV

| Thema | Regel |
|---|---|
| Zeichensatz | **UTF-8**, BOM wird toleriert und entfernt (Excel „CSV UTF-8“). Anderes Encoding → Fehler „nicht UTF-8-kodiert“ (keine Umkodierung nach Vermutung, A61) |
| Trennzeichen | Semikolon, Komma oder Tabulator. **Deterministische Erkennung:** ein Kandidat gilt, wenn die Kopfzeile damit ≥ 2 Spalten hat **und jede** Datenzeile genau so viele. Passt genau einer, wird er verwendet; passen keiner oder mehrere, wählt der Admin (Auswahlfeld „Trennzeichen“) |
| Felder | RFC 4180: Anführungszeichen, `""` als Escape, Zeilenumbrüche in Feldern |
| Kopfzeile | Zeile 1 = Spaltennamen (Pflicht) |
| Zeilennummer | wie in der Tabellenkalkulation: Kopfzeile = Zeile 1, eine Zelle mit Zeilenumbruch bleibt eine Zeile; leere Zeilen werden übersprungen, aber mitgezählt |
| abweichende Spaltenzahl | (nur bei gewähltem Trennzeichen) Zeile = fehlerhaft „Die Zeile hat 7 statt 8 Spalten.“ |
| Inhalte | werden nur als Text gespeichert und escaped ausgegeben; Formeln wie `=HYPERLINK(…)` bleiben Text, es wird nichts ausgeführt; HTML wird entfernt (`sanitize_text_field`) |

### Feldzuordnung (Mapping)

Je CSV-Spalte wählt der Admin ein Feld oder „nicht übernehmen“. Vorschläge gibt es **nur** für exakt bekannte
Spaltennamen (Groß/Klein und Leerzeichen am Rand egal, keine Teilstrings, keine Ähnlichkeit), z. B.
`Titel`→title, `Datum`→start_date, `Beginn`/`Uhrzeit`→start_time, `Ende`/`Endzeit`→end_time, `Ort`/`Veranstaltungsort`
→venue_name, `Adresse`→venue_address, `Stadt`→city, `URL`/`Link`/`Webseiten-Link`→event_url, sowie die technischen
Feldnamen selbst (`external_id`, `start_date` …). Liste: `Eventcrawler_Importer::HEADER_SUGGESTIONS`. Jedes Feld darf
nur einer Spalte zugeordnet sein; Titel, Datum und Stadt sind Pflicht.

## 3. XML – neutrales Eventcrawler-Importformat

```xml
<?xml version="1.0" encoding="UTF-8"?>
<events source="kulturamt-import" generated_at="2026-11-03T10:00:00Z">
  <event>
    <external_id>12345</external_id>
    <title>Beispielveranstaltung</title>
    <description></description>
    <start_date>2026-11-15</start_date>
    <start_time>20:00</start_time>
    <end_date>2026-11-15</end_date>
    <end_time>22:00</end_time>
    <all_day>0</all_day>
    <venue_name>DAS ZENTRUM</venue_name>
    <venue_address>Äußere Badstraße 7a</venue_address>
    <city>Bayreuth</city>
    <event_url>https://example.org/event</event_url>
    <source_url>https://example.org/</source_url>
  </event>
</events>
```

- Wurzel `<events>`, Einträge `<event>`, Elementnamen = Zielfelder (Abschnitt 4). Alle Elemente optional außer
  Titel, Datum, Stadt. Unbekannte Elemente werden ignoriert und im Mapping-Schritt genannt; ein Feld doppelt in einem
  `<event>` → Eintrag fehlerhaft. CDATA erlaubt.
- `source` (optional): wird, wenn es eine gültige Importkennung ist, als Importquelle **vorgeschlagen**; der Admin
  bestätigt sie. `generated_at` wird nur gelesen.
- Zeilennummer in Fehlermeldungen = Zeile des `<event>`-Elements.
- Das ist **nicht** das Exportformat `<Root>/<Textabschnitt>` (07-export.md); das XML-Exportformat bleibt unverändert.

### Sicherheit (XXE)

- Dateien mit `<!DOCTYPE` oder `<!ENTITY` (Groß/Klein egal) werden **abgelehnt**, bevor libxml sie liest: keine
  DTD-Auswertung, keine externen oder internen Entities (auch kein „Billion Laughs“).
- `DOMDocument::loadXML()` mit `LIBXML_NONET` (keine Netzwerkzugriffe), **ohne** `LIBXML_NOENT`/`LIBXML_DTDLOAD`;
  Fehler über `libxml_use_internal_errors()`, verständliche Meldung mit Zeilennummer, keine Stacktraces.
- Getestet: externe Entity auf eine lokale Datei (Inhalt erscheint nirgends), interne Entity-Kaskade, Netz-DTD,
  undefinierte Entity.

## 4. Felder und Werte

| Feld | Format | Regel |
|---|---|---|
| `external_id` | Text ≤ 191 | stabile ID der Datei; Grundlage des Matchings |
| `title` | Text | Pflicht; HTML entfernt; ≤ 255 |
| `description` | Text | Klartext (öffentlich nur bei Freigabe, A100) |
| `start_date` | `JJJJ-MM-TT` oder `TT.MM.JJJJ` | Pflicht; andere Formate (`15/11/2026`) sind ungültig – kein Raten |
| `start_time`, `end_time` | `HH:MM`, `H:MM`, `HH.MM`, `HH:MM:SS`, optional „ Uhr“ | leer = NULL (nie 00:00) |
| `end_date` | wie `start_date` | |
| `all_day` | `1`/`0`, `ja`/`nein`, `true`/`false`, `yes`/`no`, `x`, leer = 0 | anderes → Fehler |
| `venue_name`, `venue_address`, `city` | Text | `city` Pflicht (XML-`<h2>`) |
| `event_url`, `source_url` | `http(s)://…` | sonst verworfen (Hinweis); `esc_url_raw` |

Ein Wert, der genau `NULL` lautet (Groß/Klein egal, z. B. aus einem Datenbankexport), gilt als leer.

Fehlermeldungen je Zeile, z. B. „Zeile 18: Startdatum fehlt“, „Zeile 91: Ungültiges Datum „32.10.2026““,
„Zeile 154: Titel fehlt“, „Ungültige Uhrzeit „25:00““, „Externe ID „7“ kommt mehrfach in der Datei vor (Zeilen 8, 9)“.

## 5. Importquelle (`source`), Matching, Idempotenz

**Importquelle:** Der Admin wählt eine vorhandene Importquelle oder legt eine neue an (Bezeichnung, z. B.
„Kulturamt Kulmbach“; Kennung wird daraus gebildet – `kulturamt-kulmbach` – oder unter „Erweitert“ festgelegt).
Gespeichert in der Option `eventcrawler_import_sources` (Kennung → Bezeichnung, letzter Import, Zählung).

- Kennung: `[a-z0-9]+(-[a-z0-9]+)*`, ≤ 50 Zeichen; **nicht** `manual`, `dev-test`, `all` und **kein** Slug einer
  Crawler-Quelle. So können Importe weder manuelle noch gecrawlte Termine überschreiben.
- Importierte Termine sind im Admin **nicht** bearbeitbar (wie gecrawlte, A96), aber löschbar. Sie erscheinen in
  Liste, Listenfilter, Export und Frontend (`sources`-Attribut) unter ihrer Bezeichnung.

**Matching** (nur innerhalb der Importquelle, deterministisch):

1. Zeile mit `external_id` → Termin mit derselben `source + external_id` (UNIQUE-Index). Kein Treffer → neu.
2. Zeile ohne `external_id` → `event_key` (sha1 aus source, Titel, Stadt, Datum – dieselbe Formel wie der Crawler,
   02-datenbank.md Abschnitt 4) unter den Terminen der Quelle **ohne** `external_id`, die in diesem Lauf noch nicht
   zugeordnet sind; gleiche Uhrzeit bevorzugt, sonst kleinste ID. Kein Treffer → neu.
3. Gleiche `external_id` mehrfach in einer Datei → **alle** betroffenen Zeilen fehlerhaft (unklar, welche gilt).

**Update:** Treffer mit anderem `content_hash` → UPDATE aller Inhaltsfelder (fehlende Werte → NULL), `updated_at`,
`last_seen_at`; eine gespeicherte `external_id` wird nie mit NULL überschrieben. **Gleicher Hash → kein Schreiben**
(auch `last_seen_at` bleibt). **Nie Löschen.**

Probelauf und Import verwenden dieselbe Funktion (`analyze()`); der Probelauf schreibt nichts. Vorhandene Termine
der Quelle werden einmal geladen (`SELECT id, external_id, event_key, start_time, content_hash … WHERE source = %s`,
Index `source_external`), keine Abfrage je Zeile.

## 6. Admin-Seite „Eventcrawler → Import“

Schritte (oben als Leiste angezeigt):

1. **Datei auswählen** – Upload (`.csv`, `.txt`, `.xml`), Hinweise zu Format und Grenzen, letzter Import.
2. **Format erkennen / Felder zuordnen** – erkanntes Format; CSV: Trennzeichen (erkannt oder Auswahl), Tabelle
   „CSV-Spalte | Beispielwerte | Eventcrawler-Feld“; XML: Anzahl, Quelle laut Datei, unbekannte Elemente.
   Importquelle wählen/anlegen.
3. **Vorschau und Probelauf** – Zählung (Zeilen gelesen, Neu, Aktualisierung, Unverändert, Fehlerhaft), die ersten
   20 gültigen Zeilen so, wie sie gespeichert würden, Liste der fehlerhaften Zeilen (max. 200 angezeigt). Buttons
   „Import durchführen“ (Rückfrage), „Zuordnung ändern“, „Abbrechen“.
4. **Ergebnis** – Meldung „Import abgeschlossen: n neu angelegt, n aktualisiert, n unverändert, n fehlerhaft
   übersprungen“, Link zur gefilterten Liste.

**Zwischenspeicher:** Die Datei liegt bis zum Import in einem Transient je Benutzer und Zufallstoken (Datenbank, nicht
öffentlich erreichbar, 1 Stunde), danach wird sie gelöscht. Ein fremdes Token findet nichts.

## 7. Sicherheit und Grenzen

| Thema | Umsetzung |
|---|---|
| Rechte | Seite und alle Aktionen nur `manage_options` (Gast 400/403, Abonnent/Redakteur 403) |
| Nonces | je Schritt eigener Nonce (`eventcrawler_import_upload`, `…_map`, `…_run`) im Feld `eventcrawler_import_nonce`; nur POST an `admin-post.php` (GET 405) |
| Upload | `is_uploaded_file()`, Upload-Fehler verständlich, Dateiname per `sanitize_file_name(wp_basename())` |
| Dateityp | Browser-MIME wird **nicht** verwendet; Endung `.csv/.txt/.xml` und Inhalt entscheiden (ZIP/XLSX erkannt und abgelehnt) |
| Größe | **2 MB** bzw. das kleinere Upload-Limit des Servers; höchstens **10.000** Zeilen; Meldung „Die Datei ist zu groß … Bitte aufteilen“ |
| XML | Abschnitt 3 „Sicherheit (XXE)“ |
| Ausgabe | alle Werte escaped (`esc_html`/`esc_attr`), getestet mit Skript im Dateinamen und in Zellen |
| SQL | nur `$wpdb->prepare()` bzw. `Repository::insert()/update()` |

Kein Excel/XLSX in dieser Phase (bräuchte eine ZIP/XML-Bibliothek; „CSV UTF-8“ aus Excel reicht).

## 8. Tests

`tools/test-import.php` (Transaktion mit Rollback, Optionen werden wiederhergestellt, Tabellenprüfsumme gleich):
leere Datei, Endung, ZIP, Nicht-UTF-8, Größe, BOM, Semikolon/Komma/Tab, mehrdeutiges und uneinheitliches
Trennzeichen, Spaltenzahl, Zeilenumbruch im Feld, Zeilenlimit, Mapping-Vorschläge (exakt, keine Teilstrings),
Pflicht-/Doppelzuordnung, Pflichtfelder, ungültiges Datum/Uhrzeit/Endzeit, NULL, all_day, Ende vor Beginn,
URL-Sanitizing (javascript:, ftp:, esc_url_raw), HTML/Skript im Titel, CSV-Formel, XML gültig/ungültig/Wurzel/leer/
CDATA/unbekannte Elemente, XXE, Entity-Kaskade, Netz-DTD, Importquellen-Regeln, Probelauf ohne Schreiben,
neue Termine, Updates (gleiche ID), unverändert ohne Schreiben, fehlende Termine nicht gelöscht, doppelte
external_id, mehrfacher Import, Matching ohne external_id (zwei Vorstellungen am Tag, Uhrzeitänderung, anderer Titel),
manuelle und Crawler-Termine unverändert, keine Posts, XML-Export enthält importierte Termine, Admin-Handler
(Capability, Nonce, GET, fremdes Token, Escaping).

HTTP (Testinstallation, 2026-11-03): Upload per multipart als Admin → Zuordnung (Semikolon erkannt, 7 Vorschläge) →
Probelauf (3 gelesen, 2 neu, 1 fehlerhaft „Zeile 4: Titel fehlt“) → Import (2 angelegt, UTF-8 `ü` = `C3 BC`); als
Abonnent Seite und Upload 403, als Gast 400.
