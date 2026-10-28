# 07 – XML-Export

> **Stand 2026-09-24: implementiert und getestet** in `wp-plugin/includes/class-eventcrawler-exporter.php`
> (`Eventcrawler_Exporter`), Download über den Admin-Button „XML exportieren“ (06-admin.md).
> Seit Schema-Version 2: `<h1>` aus `start_date`, `<h2>` aus `city` (nie `venue_name`), Ort im `<p>` aus `venue_name`,
> „Info:“ aus `event_url` mit Fallback `source_url`. Das Format ist unverändert, und die Endzeit wird nicht exportiert.
>
> **Stand 2026-09-30 (Kundenfeedback zum ersten Test):** Encoding analysiert (Abschnitt 10.1: die Datei ist korrektes
> UTF-8, „Caf√©“ entsteht erst beim Öffnen als Mac Roman), sichtbare URL ohne `http://`/`https://` (Abschnitt 10.2),
> kleine sichere Textregeln (Abschnitt 10.3). Struktur `<Root>/<Textabschnitt>/<h1>/<h2>/<p>` unverändert.
>
> **Stand 2026-10-02 (zweites Kundenfeedback, Abschnitt 12):** sichtbare URL nur noch als **Hostname**
> (`pages.et4.de`), Adresse aus `venue_address` (Schema-Version 3), Ort/Adresse nicht doppelt (auch nicht zur
> Beschreibung oder zur Stadt), **mehrere Uhrzeiten** identischer Termine in einer Zeile. Äußere Form unverändert.
>
> **Stand 2026-10-06 (Abschnitt 13):** Nach „Info:“ steht statt der Domain die im Backend gepflegte
> **Link-Bezeichnung** dieser Domain, falls eine aktiv ist (z. B. „Info: Bad Steben“ statt „Info: pages.et4.de“).
> Ohne aktive Bezeichnung bleibt die Domain; ohne Einträge ist der Export bytegleich mit dem Stand vom 2026-10-02.

## 1. Verbindlichkeit und Referenzdatei

- Referenz für das Format ist die bestehende Kundendatei **`veranstaltungen-export-2026-09-30.xml`**.
- **Stand 2026-09-24 (Phase 4): Die Referenzdatei liegt weiterhin NICHT vor.** Auch `docs/referenz/` existiert nicht.
  Deshalb gibt es **keinen Abgleich mit der Referenz**. Implementiert ist der dokumentierte Minimalstandard mit den
  Vorgaben des Auftraggebers aus Phase 4. Alles, was nur die Referenz klären kann, steht in Abschnitt 9.
  → Liegt die Datei vor: unter `docs/referenz/` ablegen, Abschnitt 9 abarbeiten und Abweichungen hier dokumentieren.
- **Das Format darf nicht eigenmächtig durch ein anderes XML-Schema ersetzt oder erweitert werden.** Es gibt keine
  Elemente wie `<event>`, `<date>`, `<title>` oder `<location>`, keine IDs, keine Metadaten und keine Attribute.

## 2. Struktur (implementiert)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Root>
<Textabschnitt>
<h1>14. November Samstag</h1>
<h2>Ahorntal</h2>
<p>18:00 Uhr Feuriger Abend 4-Gänge-Menü &amp; Feuershow, Genießen Sie einen Abend, …, Burg Rabenstein. Info: www.burg-rabenstein.de/events/alle-events-im-ueberblick/feuriger-abend-4-gaenge-menue-feuershow-20261114</p>
<p>18:00 Uhr Whisky-Dinner Rare &amp; Old im Waffensaal, Im exklusivem Rahmen …, Burg Rabenstein. Info: www.burg-rabenstein.de/events/alle-events-im-ueberblick/whisky-dinner-rare-old-im-waffensaal-20261114</p>
<h1>15. November Sonntag</h1>
…
</Textabschnitt>
</Root>
```

(Echter Ausschnitt aus dem Test-Export, Beschreibungen hier mit „…“ gekürzt. Seit 2026-09-30 steht die URL ohne
`https://` im XML, Abschnitt 10.2; die Beispiele in Abschnitt 8 zeigen den Stand vom 2026-09-24.)

| Element | Bedeutung |
|---|---|
| `<?xml version="1.0" encoding="UTF-8"?>` | XML-Deklaration. Entscheidung, weil die Referenz fehlt (A26) |
| `<Root>` | Wurzelelement, genau einmal |
| `<Textabschnitt>` | genau **ein** Container für den gesamten Export. h1, h2 und p stehen **flach hintereinander** als Geschwister |
| `<h1>` | Datum, genau einmal je Kalendertag |
| `<h2>` | Stadt/Gemeinde (`city`), genau einmal je Stadt innerhalb eines Tages |
| `<p>` | genau **eine** Veranstaltung |

## 3. Datenauswahl

- Quelle ist **ausschließlich** die Tabelle `{prefix}eventcrawler_events`. Beim Export wird **nichts gecrawlt**.
- Ohne Filter werden **alle gespeicherten Veranstaltungen** exportiert, auch vergangene und aus allen Quellen, **ohne
  Paginierung** (`Eventcrawler_Repository::get_events_for_export( $filters )`). Mit Filter nur die passenden
  (Abschnitt 3a). Sortierung, Paginierung, Filter und Suche der Admin-Liste haben keinen Einfluss auf den Export.
- Events **ohne Stadt** (oder mit ungültigem Datum) werden **nicht** exportiert, weil sie keinem `<h2>` zugeordnet
  werden können. Sie werden nie unter einem erfundenen Ort einsortiert. Die Anzahl wird bei `WP_DEBUG` per `error_log`
  protokolliert. Über den Crawler kann ein solcher Datensatz nicht entstehen, weil `city` dort Pflicht ist.
- Der Export ist eine **reine Leseoperation**. Getestet: Die Tabellen-Prüfsumme (`CHECKSUM TABLE`) ist vor und nach
  dem Export identisch.

Später (nicht implementiert): Export nach frei wählbarem Datumsbereich, Stadt, Freitext oder Checkbox-Auswahl.

## 3a. Exportfilter (implementiert, 2026-09-24)

Das Exportformular im Admin (06-admin.md Abschnitt 5) hat drei eigene Felder:

| Filter | Feld | Werte | Wirkung |
|---|---|---|---|
| Quelle | `eventcrawler_export_source` | leer = alle, sonst Slug aus dem Adapter-Register (`burg-rabenstein`, `bad-steben`, künftig automatisch weitere) | nur `source = Slug` |
| Monat | `eventcrawler_export_month` | leer = alle, sonst 1–12 | siehe Kombinationsregeln |
| Jahr | `eventcrawler_export_year` | leer = alle, sonst 2000–2100 | siehe Kombinationsregeln |

**Kombinationsregeln** (UND-Verknüpfung, jeweils auf `start_date`):

| Auswahl | Exportiert |
|---|---|
| nichts | alle Events |
| nur Quelle | alle Events dieser Quelle |
| nur Monat, z. B. Oktober | alle Oktober-Termine **aller Jahre** |
| nur Jahr, z. B. 2027 | alle Termine 2027 |
| Monat + Jahr, z. B. 10/2026 | nur Oktober 2026 |
| Quelle + Monat + Jahr | nur diese Quelle in diesem Monat/Jahr |

- Die Auswahl passiert **in SQL** (`Eventcrawler_Repository::where_clause()`, dieselbe Methode wie für die Liste).
  Das XML wird nicht nachträglich gefiltert. Jahr und Monat + Jahr als Datumsbereich (indexfreundlich), Monat allein
  als `MONTH(start_date)`.
- Eine Freitextsuche gibt es im Export **nicht**.
- Ungültige Werte → kein Export, rote Meldung („Export abgebrochen: …“).
- Keine Treffer → kein Download, gelbe Meldung „Keine Veranstaltungen für den gewählten Exportfilter vorhanden.“
- **Dateiname unverändert** `veranstaltungen-export-JJJJ-MM-TT.xml` (Exportdatum), auch mit Filter. Der Filter wird
  bewusst nicht in den Namen aufgenommen (Stabilität; optionale spätere Erweiterung).
- **XML-Format unverändert:** Nur die Auswahl ändert sich. Datumsausgabe, Ort, Text, Escaping und die fehlende Endzeit
  bleiben gleich. Ohne Filter ist der Export identisch mit dem bisherigen Gesamtexport.

Tests mit echten Daten (680 Events; jede Datei neu geparst; `<p>` = unabhängige DB-Zählung; Reihenfolge und
Zuordnung jeder Zeile gegen die DB geprüft; Quellen anhand der Info-Links, Monate anhand der `<h1>`):

| Filter | exportierte Events | `<h1>` | `<h2>` | `<p>` | enthaltene Quellen / Monate |
|---|---|---|---|---|---|
| alle | 680 | 155 | 222 | 680 | beide / Sep.–Aug. |
| nur Burg Rabenstein | 124 | 109 | 109 | 124 | nur burg-rabenstein.de |
| nur Bad Steben | 556 | 92 | 113 | 556 | nur pages.et4.de / Sep.–Dez. |
| nur Monat Oktober | 227 | 31 | 58 | 227 | beide / nur Oktober |
| nur Jahr 2027 | 65 | 57 | 57 | 65 | nur Burg Rabenstein (Bad Steben reicht nur 3 Monate) |
| Monat + Jahr 12/2026 | 154 | 30 | 45 | 154 | beide / nur Dezember |
| Bad Steben + 10/2026 | 213 | 31 | 44 | 213 | nur pages.et4.de / nur Oktober |
| Burg Rabenstein + 3/2027 | 15 | 12 | 12 | 15 | nur burg-rabenstein.de / nur März |
| Burg Rabenstein + August | 1 | 1 | 1 | 1 | nur August (Mittelaltermarkt 01.08.2027) |
| Bad Steben + 2027 / Burg Rabenstein + Juni | – | – | – | – | kein Download, Meldung „Keine Veranstaltungen für den gewählten Exportfilter vorhanden.“ |
| Quelle „foo“, Monat 13 oder 0, Jahr 1999 oder „2026abc“, SQL in Quelle | – | – | – | – | kein Download, „Export abgebrochen: …“ |

Die Datenbank blieb bei allen Exporten unverändert (Prüfsumme gleich).

### Test mit drei Quellen (Burg Rabenstein + Bad Steben + Coburg, 2026-09-24)

Coburg erscheint ohne Codeänderung am Exporter im Export und im Exportfilter. Format unverändert, keine Endzeit.

| Filter | exportierte Events | `<h1>` | `<h2>` | `<p>` | Städte (`<h2>`) | Größe |
|---|---|---|---|---|---|---|
| alle Quellen | 1047 | 155 | 340 | 1047 | Ahorntal, Bad Steben, Coburg, Bad Rodach, Seßlach, Hof, Lichtenberg, Rödental, Neustadt bei Coburg, Weitramsdorf, Meeder, Hirschberg, Münchberg, Ahorn, Berg, Untersiemau | 264 330 B |
| nur Coburg | 365 | 45 | 118 | 365 | Coburg, Bad Rodach, Seßlach, Rödental, Neustadt bei Coburg, Weitramsdorf, Meeder, Ahorn, Untersiemau | 84 656 B |
| Coburg + Oktober | 264 | 31 | 82 | 264 | 8 Städte | 60 740 B |
| Coburg + 2026 | 365 | 45 | 118 | 365 | wie „nur Coburg“ | 84 656 B |
| Coburg + 11/2026 | 38 | 7 | 17 | 38 | 6 Städte | 9 327 B |
| Coburg + 2027 | – | – | – | – | kein Download, „Keine Veranstaltungen für den gewählten Exportfilter vorhanden.“ | – |

Jede Datei ist wohlgeformt. `<p>` = unabhängige DB-Zählung, Reihenfolge und Zuordnung jeder Zeile stimmen, nur die
erwarteten Quellen (per Info-Link) und Monate sind enthalten, die DB ist unverändert.

Hinweis: Coburg-Beschreibungen können mit „...“ beginnen (Quelltext). Die Zeile lautet dann z. B.
„…, ...seinen Märchenparaphrasen …“. Das ist der Originaltext und wird nicht verändert.

## 4. Sortierung und Gruppierung (implementiert)

SQL-Reihenfolge, deterministisch:

```
ORDER BY start_date ASC, city ASC, start_time IS NULL ASC, start_time ASC, title ASC, id ASC
```

1. **Datum** aufsteigend → ein `<h1>` je Tag
2. **Stadt** aufsteigend (Datenbank-Sortierung `utf8mb4_unicode_*`, Umlaute wie der Grundbuchstabe) → ein `<h2>` je
   Stadt und Tag
3. **Uhrzeit** aufsteigend. Events **ohne Uhrzeit** stehen am Ende ihrer Stadt
4. **Titel**, zuletzt `id` als stabiler Schlüssel

Die Gruppierung erfolgt beim Durchlauf der sortierten Liste. Ein neues `<h1>` entsteht bei Datumswechsel, ein neues
`<h2>` bei Stadtwechsel oder nach einem neuen `<h1>`.

## 5. Datumsformat `<h1>` (implementiert)

```
{TT}. {Monat} {Wochentag}
```

- `TT` zweistellig mit führender Null: `04.`, `25.`
- deutsche Monats- und Wochentagsnamen aus **festen Arrays** in `Eventcrawler_Exporter` (`MONTHS`, `WEEKDAYS`),
  unabhängig von Server-Locale und WordPress-Sprache
- **kein Jahr**
- Beispiele (echte Exportwerte): `24. September Donnerstag`, `25. September Freitag`, `09. Oktober Freitag`,
  `28. Mai Freitag`

## 6. Veranstaltungszeile `<p>` (implementiert)

Die Daten liegen strukturiert in der Datenbank. Der `<p>`-Text wird erst beim Export deterministisch daraus erzeugt
(`Eventcrawler_Exporter::format_line()`):

```
[HH:MM Uhr[, HH:MM Uhr] & HH:MM Uhr ]Titel[, Beschreibung][, Veranstaltungsort][, Adresse][. Info: Domain]
```

(Stand 2026-10-02; mehrere Uhrzeiten und Adresse siehe Abschnitt 12.)

| Bestandteil | Regel |
|---|---|
| Uhrzeit | `HH:MM Uhr ` aus `start_time`, nur wenn gesetzt. **Nur die Startzeit**, siehe unten |
| Titel | immer |
| Beschreibung | die **gespeicherte Kurzbeschreibung**, nur wenn vorhanden. Beim Export wird nichts nachgeladen |
| Veranstaltungsort | `venue_name`, nur wenn vorhanden. Die Stadt (`city`) steht nicht in der Zeile, sie ist das `<h2>`. Entfällt, wenn er als ganze Wortfolge schon im Titel (seit 2026-09-30) oder in der Beschreibung steht oder nur die Stadt wiederholt (seit 2026-10-02, Abschnitt 12) |
| Adresse | `venue_address`, nur wenn vorhanden (seit 2026-10-02, Schema-Version 3); gleiche Dopplungsregel wie beim Ort |
| Info | `Info: {event_url}`; ist `event_url` leer, `Info: {source_url}`; fehlen beide, entfällt der Teil (Entscheidung A27). **Seit 2026-10-02 sichtbar nur der Hostname** (Abschnitt 12.1; vorher ab 2026-09-30 ohne Protokoll, Abschnitt 10.2). **Seit 2026-10-06** statt des Hostnamens die aktive Link-Bezeichnung dieser Domain, falls vorhanden (Abschnitt 13) |

Reihenfolge im Code (`group_times()` → `format_body()` → `format_times()`), Stand 2026-10-02:

1. Titel, Beschreibung, Ort, Adresse und Stadt je Feld bereinigen (`clean()`)
2. Ort bzw. Adresse verwerfen, wenn sie nur die Stadt sind oder als ganze Wortfolge in Titel oder Beschreibung stehen
3. Beginnt die Beschreibung mit dem Titel, wird der Titel nicht wiederholt (Titel + Rest der Beschreibung)
4. Bestandteile, die wortgleich einem vorherigen entsprechen, entfallen
5. Verbinden mit `, ` bzw. nur Leerzeichen nach `.`/`!`/`?`/`…`
6. `. Info: {sichtbare URL}` anhängen: Hostname → aktive Link-Bezeichnung (Abschnitt 13) → Filter
   `eventcrawler_export_display_url`
7. Termine mit identischer Zeile zusammenfassen (Abschnitt 12.3) und die Uhrzeit(en) voranstellen

**Endzeit, Enddatum und Ganztag werden nicht exportiert (Stand Schema-Version 2).** Sie sind intern gespeichert
(02-datenbank.md), aber der bestehende Kundenstandard wird nicht eigenmächtig geändert. Eine Ausgabe wie
„18:00–22:00 Uhr“ oder „ganztägig“ im `<p>` kommt erst, wenn die Referenz oder eine ausdrückliche Anforderung sie
verlangt. Getestet: Die Endzeit kommt im Export nicht vor, und der Export der 124 Events ist nach der Migration
**byte-identisch** mit dem Export davor, weil `event_url` bei Burg Rabenstein gleich `source_url` ist.

Bereinigung je Bestandteil: HTML-Tags entfernen, HTML-Entities **einmal** dekodieren, unsichtbare Zeichen U+200B
(Zero-Width-Space) und U+FEFF entfernen (seit 2026-09-30), geschützte Leerzeichen, Zeilenumbrüche, Tabs und mehrfache
Leerzeichen zu einem Leerzeichen zusammenfassen, Komma/Semikolon/Doppelpunkt am Rand entfernen. Leere Bestandteile entfallen, und ein
Bestandteil, der wortgleich einem vorherigen entspricht, wird nicht wiederholt. Deshalb gibt es keine doppelten Kommata
und keine leeren Bestandteile (getestet).

Satzzeichen-Regel: Endet der bisherige Text bereits mit `.`, `!`, `?` oder `…`, folgt nur ein Leerzeichen statt `, `
bzw. `. `. Beispiel: „… in den Rittersälen der Burg! Burg Rabenstein. Info: …“ statt „Burg!, Burg Rabenstein“.

**Info: URL (A27):** Die Referenz fehlt, deshalb gilt die Vorgabe des Auftraggebers für den MVP: Die Zeile endet mit
`. Info: {URL}`, bevorzugt mit dem öffentlichen Link `event_url`, sonst mit `source_url` (getestet für beide Fälle).
Das ist gegen die Referenz zu prüfen. Seit 2026-09-30 ohne sichtbares Protokoll (A62, Abschnitt 10.2).

Hinweis: Text, der wie ein HTML-Tag aussieht (z. B. `<live>`), wird entfernt. Über den Crawler kommt so etwas nicht
in die Datenbank, weil `sanitize_text_field` Tags schon beim Import entfernt.

## 7. Technische Umsetzung (implementiert)

- Erzeugung mit **`XMLWriter`** im Speicher (`openMemory`), kein String-Zusammenbau von XML. **Seit 2026-09-30 (Phase InDesign-Referenz):** ein
  Element je Zeile, LF, **keine Einrückung**, kein Zeilenumbruch nach `</Root>` (wie WP Kulmbacher Land, Abschnitt 11);
  vorher mit 4 Leerzeichen eingerückt und mit abschließendem LF.
- Escaping übernimmt XMLWriter: `&` → `&amp;`, `<` → `&lt;`, `>` → `&gt;`, `"` → `&quot;`. Das ist korrektes XML;
  ein Parser liefert wieder `"`. Es wird nicht doppelt escaped (getestet, kein `&amp;amp;` / `&amp;quot;`).
  Apostrophe bleiben literal. **Umlaute werden als UTF-8-Zeichen ausgegeben**, nicht als Entities.
- **Kontrolle vor dem Download:** Das erzeugte XML wird mit `DOMDocument::loadXML()` erneut eingelesen. Ist es nicht
  wohlgeformt, wird **kein** Download gesendet, sondern eine Fehlermeldung angezeigt.
- Fehlt die PHP-Erweiterung XMLWriter, erscheint die Meldung „Die PHP-Erweiterung XMLWriter ist nicht verfügbar.“
  und es gibt keinen Download. Dieser Pfad ist nicht getestet, weil XMLWriter in der Testumgebung vorhanden ist.
- **Keine Events:** kein Download, gelbe Meldung „Keine Veranstaltungen für den Export vorhanden.“ (getestet)
- Download-Response (`Eventcrawler_Admin::handle_export()`):
  - Ausgaben während der Erzeugung werden per Output-Buffer verworfen, und vor den Headern werden alle Buffer geleert.
    Dadurch landen PHP-Hinweise nie in der Datei (getestet: keine Meldungen in der Datei).
  - `nocache_headers()`, `Content-Type: application/xml; charset=utf-8`,
    `Content-Disposition: attachment; filename="…"`, `Content-Length`
- **Dateiname:** `veranstaltungen-export-JJJJ-MM-TT.xml` mit dem **Exportdatum in der WordPress-Zeitzone**
  (`wp_date( 'Y-m-d' )`), z. B. `veranstaltungen-export-2026-09-24.xml`.

## 8. Test mit echten Daten (2026-09-24)

124 gespeicherte Burg-Rabenstein-Events, Export über den Admin-Button, Datei unabhängig mit DOMDocument und
Datenbankabfragen geprüft:

| Wert | Ergebnis |
|---|---|
| Dateiname | `veranstaltungen-export-2026-09-24.xml` |
| Größe | 49 992 Bytes |
| `<h1>` | 109 (= Anzahl verschiedener Tage in der DB) |
| `<h2>` | 109 (= Anzahl Tag/Stadt-Kombinationen; alle „Ahorntal“) |
| `<p>` | 124 (= alle Events, **keine Begrenzung auf 50**) |

Bestanden:
- gültiges UTF-8, kein BOM, wohlgeformt, nur h1/h2/p unter `<Textabschnitt>`
- kein `<h1>` doppelt, kein `<h2>` doppelt je Tag
- alle 124 `<p>` in der erwarteten Reihenfolge mit richtigem h1, h2, Uhrzeit, Titel, Ort und URL
- `<h2>` ist nie „Burg Rabenstein“
- Stichproben mit richtigem h1 und h2:
  - 25.09.2026 19:00 Murder Mystery Dinner "Kripo TV"
  - 09.10.2026 17:00 Burgkonzert Gregorianische Gesänge - im großen Burgsaal
  - 28.05.2027 10:00 Großer Mittelaltermarkt
- Sonderzeichen `& < > " '`, Entity-Text in der Beschreibung, URL mit `&`, Event ohne Uhrzeit/Beschreibung/Ort,
  Event ohne Stadt (übersprungen) – mit temporären Testdatensätzen geprüft, die danach wieder gelöscht wurden

### Test mit zwei Quellen (Burg Rabenstein + Bad Steben, 2026-09-24)

Der Export ist **unverändert**. Es gibt keine quellenspezifische Logik; alle Quellen laufen über dieselbe Sortierung
(`start_date`, `city`, `start_time`, `title`, `id`) und dieselbe `<p>`-Regel. Endzeiten erscheinen weiterhin nicht.

| Wert | Ergebnis |
|---|---|
| Events in der DB | 681 (124 Burg Rabenstein + 557 Bad Steben) |
| `<p>` | 681 (= alle Events) |
| `<h1>` | 155 (= verschiedene Tage) |
| `<h2>` | 222 (= Tag/Stadt-Kombinationen) |
| Städte im Export | Ahorntal 109, Bad Steben 92, Hof 10, Lichtenberg 6, Hirschberg 3, Münchberg 1, Berg 1 (Anzahl `<h2>`) |
| Größe | 181 260 Bytes |
| Validierung | alle 24 Prüfungen bestanden (UTF-8, wohlgeformt, Reihenfolge und Zuordnung jeder Zeile gegen die DB, kein doppeltes `<h1>`/`<h2>`, Stichproben) |
| DB durch Export verändert | nein (Prüfsumme gleich) |

Beispiel (24.09.2026, echter Ausschnitt, gekürzt):

```xml
<h1>24. September Donnerstag</h1>
<h2>Ahorntal</h2>
<p>18:00 Uhr Wildschwein-Grillbuffet, Wildschwein vom Spieß …, Burg Rabenstein. Info: https://www.burg-rabenstein.de/…</p>
<h2>Bad Steben</h2>
<p>18:30 Uhr Nordic-Walking-Lauftreff für Fortgeschrittene, Bayerisches Staatsbad Bad Steben GmbH. Info: https://pages.et4.de/…</p>
<p>19:00 Uhr Aqua Zumba, Therme Bad Steben - Mit allen Sinnen genießen. Info: https://pages.et4.de/…</p>
<h2>Lichtenberg</h2>
<p>19:00 Uhr Meisterkonzert Christoph Prégardien (Tenor) und Yaara Tal (Klavier), Musikbegegnungsstätte Haus Marteau. Info: https://pages.et4.de/…</p>
```

Hinweis: Bad-Steben-Termine haben keine Beschreibung. Die Zeile besteht daher aus Uhrzeit, Titel, Ort und Info-Link.
Der „Ort“ ist dort oft der Veranstalter (04-quellen.md).

## 9. Offene Punkte (gegen die Referenzdatei zu prüfen)

- [ ] XML-Deklaration: gewählt `<?xml version="1.0" encoding="UTF-8"?>`. Stimmt das mit der Referenz überein?
- [x] Einrückung/Zeilenumbrüche: seit 2026-09-30 wie der WP-Kulmbacher-Land-Export (keine Einrückung, LF, Abschnitt 11). Relevant, falls der Import beim Empfänger
      Whitespace auswertet.
- [ ] ein `<Textabschnitt>` für den ganzen Export (gewählt) oder eines je Tag?
- [ ] Zusammensetzung der `<p>`-Zeile: Kurzbeschreibung ja/nein, „Info: URL“ ja/nein, Schlusspunkt
- [ ] **Kurzbeschreibungen der Quelle sind oft mitten im Satz abgeschnitten** (Burg Rabenstein kürzt den Teaser mit
      „[...]“), z. B. „… Freuen, Burg Rabenstein.“ Soll die Beschreibung im Export bleiben?
- [ ] `&quot;` statt `"` im Rohtext: XML-technisch gleichwertig. Relevant nur, falls der Empfänger die Datei nicht mit
      einem XML-Parser liest.
- [ ] Umgang mit ganztägigen und mehrtägigen Events im `<p>` (die Daten dafür sind seit Schema-Version 2 vorhanden)
- [ ] Soll die Endzeit exportiert werden („18:00–22:00 Uhr“)? Derzeit nicht, bis die Referenz oder der Auftraggeber es verlangt
- [ ] Reihenfolge der Orte: alphabetisch (gewählt) oder redaktionell fest?
- [ ] **`<h1>` ohne Jahr:** Der Gesamtexport umfasst derzeit ca. 12 Monate (24.09.2026–17.09.2027), die Jahre sind im
      Export nicht unterscheidbar. Ein Export nach Zeitraum würde das lösen (spätere Erweiterung).
- [ ] Welchen Zeitraum soll ein Export typischerweise umfassen (Woche, Monat)?
- [ ] **Welche Datei hat Katharina geöffnet und wie (XML importieren oder platzieren)?** Die WPEM-Referenz ist bytegleich bei Sonderzeichen, ohne BOM (Abschnitt 11). Neuer Export im selben Workflow testen; kein BOM ohne Beleg.
      Die frühere Vermutung „Mac Roman“ (10.1) ist damit nicht widerlegt, aber auch nicht belegt.
- [ ] Wird unser XML in WP Kulmbacher Land importiert? Dann volle URL per Filter (Abschnitt 10.2, 09-wpem-integration.md)
- [ ] Welche Inhalte empfindet der Kunde als „zu viel“ (Abschnitt 10.4)?

## 10. Kundenfeedback zum ersten Test (2026-09-30)

Der Kunde meldete: (1) Zeichenfehler wie „Caf√©“ statt „Café“, (2) `https://` soll in den Links möglichst nicht
sichtbar sein, (3) manche Einträge enthalten zu viele Informationen.

### 10.1 Encoding

**Ergebnis: Die Kette Quelle → Parser → Datenbank → XML ist korrektes UTF-8. „Caf√©“ entsteht erst beim Öffnen der
Datei in einem Programm, das die Datei als Mac Roman (klassische Mac-Kodierung) statt als UTF-8 liest.**

> **Nachtrag (Abschnitt 11):** Der in InDesign funktionierende WPEM-Export hat bei „Café“ dieselben Bytes, dieselbe
> Deklaration und ebenfalls keinen BOM. Die Mac-Roman-Deutung erklärt das Schadensbild, aber nicht, warum dieselbe
> Kodierung bei der Referenz funktioniert. Die Ursache beim Kunden ist daher offen, bis die tatsächlich geöffnete Datei
> bzw. der Import-Weg bekannt ist. Die Formatunterschiede (Einrückung, LF am Ende) sind angeglichen.

Nachweis (Testinstallation, 1048 Events, alle drei Quellen):

| Stufe | Prüfung | Ergebnis |
|---|---|---|
| Quelle (HTTP) | Content-Type, `<meta charset>`, Body | alle drei `text/html; charset=utf-8`, `<meta charset="utf-8">`, Body gültiges UTF-8, kein BOM (Details 04-quellen.md) |
| Rohquelle Coburg | `Café / Galerie M` im HTML | Bytes `43 61 66 C3 A9` = „Café“ in UTF-8 |
| Parser | `DOMDocument::loadHTML()` mit vorangestelltem `<?xml encoding="UTF-8">` (`create_xpath()`) | korrekt. Gegenprobe: ohne diesen Hinweis **und** ohne `<meta charset>` würde libxml als ISO-8859-1 lesen und „CafÃ©“ erzeugen (Rabenstein 524, Bad Steben 320, Coburg 50 Fälle). Der Hinweis verhindert das |
| Normalisierung | `html_entity_decode(…, 'UTF-8')`, `sanitize_text_field` | keine Umkodierung |
| Datenbank | Tabelle und Spalten `utf8mb4` / `utf8mb4_unicode_520_ci`, Verbindung `utf8mb4` (`$wpdb->charset`), `blog_charset` UTF-8 | 1048 Events: **0** ungültiges UTF-8, **0** Mojibake-Muster (`Ã…`, `Â`, `â€`, `√`), **0** U+FFFD, **0** HTML-Entities. `venue_name` „Café Ribollita“ = `43 61 66 C3 A9 …` |
| Repository | `$wpdb->get_results()` | unverändert |
| Exporter | `XMLWriter` mit `startDocument('1.0', 'UTF-8')`, keine eigene Kodierung, kein `htmlentities`/`utf8_encode`/`utf8_decode` | im XML `Café` = `C3 A9`, `–` = `E2 80 93`; Umlaute als Zeichen, nicht als Entities |
| Download | `Content-Type: application/xml; charset=utf-8`, Deklaration `<?xml version="1.0" encoding="UTF-8"?>`, **kein BOM** | per HTTP geprüft, Datei byte-identisch mit der erzeugten |

Warum genau Mac Roman: Die UTF-8-Bytes von „é“ sind `C3 A9`. Mac Roman liest `C3` als „√“ und `A9` als „©“ →
„√©“. Windows-1252 bzw. ISO-8859-1 ergäben dagegen „Ã©“. Aus der fehlerfreien Datei entsteht mit Mac Roman exakt
„Caf√© Ribollita“ (nachgestellt mit `iconv -f MACINTOSH`). Das Programm beim Kunden ignoriert also die
XML-Deklaration und nimmt die Mac-Standardkodierung an.

**Maßnahmen:**

- Keine Zeichen-Mappings, keine `utf8_encode()`/`utf8_decode()`, keine Entities, keine Datenkorrektur (es gibt keine
  falschen Daten). Kein erneuter Crawl nötig.
- **Kein BOM eingeführt** (bisher keiner; Vorgabe). Ein UTF-8-BOM (`EF BB BF`) ist die übliche Abhilfe für Programme,
  die UTF-8 sonst nicht erkennen; WP Kulmbacher Land setzt ihn selbst beim CSV-Export „für Excel“. `simplexml` und
  `DOMDocument` lesen XML mit BOM problemlos. Einführung nur nach Freigabe und Test beim Kunden (Abschnitt 9).
- Absicherung für künftige Quellen im Abruf (`Eventcrawler_Source::to_utf8()`, 03-crawler.md): gültiges UTF-8 bleibt
  unverändert (auch bei falschem Header); ungültiges UTF-8 wird nur umgewandelt, wenn der Content-Type einen bekannten
  Zeichensatz nennt (z. B. ISO-8859-1, Windows-1252); sonst Quellenfehler statt falscher Zeichen. Die drei Quellen
  sind davon nicht betroffen (Live-Crawl identisch, siehe Regression).

### 10.2 URL-Darstellung

- **Gespeichert** wird weiterhin die vollständige URL (`event_url`, `source_url` mit `https://`). Matching,
  Herkunft und spätere Integration bleiben unberührt.
> **Überholt seit 2026-10-02:** sichtbar ist nur noch der Hostname (Abschnitt 12.1). Der folgende Stand vom
> 2026-09-30 bleibt zur Nachvollziehbarkeit stehen.

- **Sichtbar im XML** (`Eventcrawler_Exporter::display_url()`): nur ein führendes `http://` bzw. `https://` wird
  entfernt (Groß-/Kleinschreibung egal). `www.`, Pfad, Query und Fragment bleiben. Andere Werte (`mailto:`, `ftp://`,
  ohne Schema) bleiben unverändert. In den Daten kommen nur `https://`-URLs vor (1048 von 1048).

| gespeichert | im XML |
|---|---|
| `https://www.example.de/event` | `Info: www.example.de/event` |
| `https://example.de/event` | `Info: example.de/event` |
| `http://example.de/event` | `Info: example.de/event` |
| `https://example.de/event?id=12#info` | `Info: example.de/event?id=12#info` |
| `mailto:info@example.de` | `Info: mailto:info@example.de` |

- Das Label `Info:` bleibt.
- WordPress-Filter für die volle URL (z. B. für einen Import in WP Kulmbacher Land, 09-wpem-integration.md):
  ```php
  add_filter( 'eventcrawler_export_display_url', function ( $display, $url ) { return $url; }, 10, 2 );
  ```
- **Kompatibilität mit dem XML-Importer von WP Kulmbacher Land:** Dessen Importer erkennt die Website nur mit
  `Info: https?://…` am Zeilenende. Getestet mit unserem Gesamtexport (1048 `<p>`): mit Protokoll 1048 Links erkannt,
  ohne Protokoll **0**; „… Info“ landet dann im Titel und die URL in der Beschreibung. Mit dem Filter wieder 1048.
  **Entscheidung A62:** Standard ist die Darstellung ohne Protokoll, weil das XML laut Konzept (01-konzept.md) für
  Druck/Redaktion bestimmt ist, der Kunde die Datei selbst liest und es keinen dokumentierten Import unseres XML in
  WP Kulmbacher Land gibt. Das Kundenplugin wurde nicht verändert.

### 10.3 Sichere Textregeln (umgesetzt)

Alle Regeln sind deterministisch, arbeiten nur beim Export und verändern die Datenbank nicht. Der Titel wird nie
entfernt.

| Regel | Beispiel | Treffer in echten Daten (1048) |
|---|---|---|
| Unsichtbare Zeichen U+200B/U+FEFF entfernen | „​​​​​​​Der Tod gehört zum Leben“ (7 × U+200B am Anfang) | 2 (Coburg) |
| Zeilenumbrüche, Tabs, mehrfache Leerzeichen → ein Leerzeichen | „Zeile 1\nZeile 2“ → „Zeile 1 Zeile 2“ | 0 (Adapter normalisieren schon) |
| HTML-Reste entfernen, Entities einmal dekodieren | `<b>Live</b> &amp; laut` → „Live & laut“ | 0 |
| Ort entfällt, wenn er als ganze Wortfolge im Titel steht (seit 2026-10-02 auch Beschreibung/Stadt, Abschnitt 12.2) | „Kurkonzert … im Prinzregent-Luitpold-Saal, Prinzregent-Luitpold-Saal.“ → „… im Prinzregent-Luitpold-Saal.“ | 32 (Rabenstein 3, Bad Steben 13, Coburg 16) |
| Beschreibung beginnt mit dem Titel (Wortgrenze) → Titel nicht wiederholen | „Whisky-Dinner, Whisky-Dinner mit …“ → „Whisky-Dinner mit …“ | 0 (Schutz für künftige Quellen) |
| Bestandteil wortgleich zu einem vorherigen → entfällt (seit Phase 4) | Beschreibung = Titel | 0 |

Wortgrenze heißt: Ort „Hof“ wird im Titel „Hofgarten-Fest“ **nicht** erkannt und bleibt stehen.

**Bewusst nicht umgesetzt** (keine aggressive Kürzung, keine KI/NLP):

- keine feste Maximallänge der Beschreibung (Abschnitt 10.4)
- ~~Ort in der **Beschreibung** wird nicht entfernt~~ – **geändert am 2026-10-02** (Abschnitt 12.2): Steht der Ort
  wörtlich in der Beschreibung, wird er nicht noch einmal angehängt (Kundenbeispiel „Burg Rabenstein … Burg Rabenstein“).
  Aus der Beschreibung selbst wird weiterhin nichts entfernt
- Stadt aus Titel, Ort oder Beschreibung wird nicht entfernt („Tourist-Information Coburg“ unter `<h2>Coburg</h2>`)
- Veranstalter statt Ort und Werbeslogans im Ort (Bad Steben) werden nicht umgeschrieben – das wäre
  quellenspezifische Redaktion, Entscheidung beim Kunden (Abschnitt 10.4)
- keine Entfernung von Preisen, Telefonnummern usw. – kommen in den gespeicherten Daten nicht vor

### 10.4 Analyse „zu viele Informationen“ (echte Daten, 2026-09-30)

Längen in Zeichen (min / Ø / max):

| Quelle | Events | Titel | Beschreibung | Ort | `<p>` vorher | Anteil „Info: URL“ an `<p>` |
|---|---|---|---|---|---|---|
| Burg Rabenstein | 124 | 20 / 37 / 74 | 65 / 139 / 153 (123 mit Text) | 15 | 178 / 323 / 409 | Ø 36 % |
| Bad Steben | 559 | 7 / 41 / 106 | keine | 11 / 37 / 88 | 123 / 209 / 309 | Ø 55 % |
| Coburg | 365 | 8 / 31 / 88 | 7 / 24 / 87 (296 mit Text) | 4 / 22 / 70 | 124 / 200 / 322 | Ø 56 % |

Gefundene Muster:

| Muster | Quelle | Häufigkeit | Maßnahme |
|---|---|---|---|
| lange Links (URL ist 31–70 % der Zeile) | alle | 1048 | Protokoll entfernt (−8 Zeichen); kürzere Links nur nach Kundenentscheidung (z. B. nur Domain) |
| Ort wiederholt den Titel | alle | 32 | entfernt (10.3) |
| Veranstalter als Ort („Bayerisches Staatsbad Bad Steben GmbH“) | Bad Steben | 233 | dokumentiert, Kundenentscheidung |
| Werbeslogan im Ort („Therme Bad Steben - Mit allen Sinnen genießen“) | Bad Steben | 211 | dokumentiert, Kundenentscheidung |
| langer, von der Quelle abgeschnittener Teaser (65–153 Zeichen) | Burg Rabenstein | 123 | dokumentiert; Beschreibung > 100 Zeichen: 113 |
| Ort (Burg Rabenstein) auch in der Beschreibung | Burg Rabenstein, Coburg | 30 / 4 | dokumentiert, nicht entfernt |
| Stadt im Ort bzw. Titel, obwohl `<h2>` = Stadt | Bad Steben, Coburg | Ort 490 / 192, Titel 321 / 96 | nicht entfernt (Eigennamen) |
| Beschreibung endet mit Stadt | Coburg | 15 | nicht entfernt |
| Jahr im Titel („… 2026“) | alle | 12 | nicht entfernt |
| unsichtbare Zeichen | Coburg | 2 | entfernt (10.3) |
| Beschreibung beginnt mit „...“ | Coburg | 1 | Originaltext, unverändert |
| Preise, Ticketpreise, Telefon, E-Mail, Cookie-/Webseitenreste, Social Media, „Weitere Informationen…“, Disclaimer, Anmeldehinweise | alle | **0** | nicht vorhanden (Adapter speichern Preis & Co. nicht, 04-quellen.md) |

**Vorschlag Beschreibungslänge (nicht umgesetzt):** Eine harte Grenze betrifft praktisch nur Burg Rabenstein
(Bad Steben hat keine Beschreibung, Coburg max. 87 Zeichen). Die Quelle kürzt ihren Teaser bereits auf ≈150 Zeichen,
oft mitten im Satz. Wenn der Kunde kürzere Zeilen will, wäre „am Satzende bzw. Wortende nach höchstens 100 Zeichen
kürzen, mit ‚…‘“ die kleinste Regel (träfe 113 von 124 Rabenstein-Events). Alternativ die Beschreibung für
Rabenstein ganz weglassen (offene Frage in todo.md). Den größten Längengewinn bringt allerdings ein kürzerer Link.

### 10.5 Regression (2026-09-30)

Gesamtexport und Exporte je Quelle vor und nach der Änderung, jede Datei mit `DOMDocument` geparst und Zeile für Zeile
verglichen:

| Export | `<p>` | `<h1>` | `<h2>` | h1/h2-Folge und Reihenfolge | Größe vorher → nachher | `https://` im XML |
|---|---|---|---|---|---|---|
| alle | 1048 / 1048 | 155 / 155 | 340 / 340 | identisch | 264 500 → 255 378 B (−3,4 %) | 0 |
| Burg Rabenstein | 124 / 124 | 109 / 109 | 109 / 109 | identisch | 49 992 → 48 949 B | 0 |
| Bad Steben | 559 / 559 | 92 / 92 | 113 / 113 | identisch | 133 520 → 128 741 B | 0 |
| Coburg | 365 / 365 | 45 / 45 | 118 / 118 | identisch | 84 656 → 81 356 B | 0 |

- Jede der 1048 geänderten Zeilen ist erklärt: 1014 × nur Protokoll entfernt, 32 × Ort im Titel entfernt, 2 ×
  Zero-Width-Space entfernt; keine andere Änderung. Keine Endzeit im XML.
- Wohlgeformt, gültiges UTF-8, kein BOM; Download über `admin-post.php` mit `application/xml; charset=utf-8`,
  byte-identisch mit der geprüften Datei; keine PHP-Warnings.
- Datenbank durch den Export unverändert (`CHECKSUM TABLE` gleich).
- Crawler: Live-Crawl aller drei Quellen mit `to_utf8()` in einer Transaktion mit Rollback: keine Zeichensatzfehler,
  0 Mojibake in 1117 Events, DB danach unverändert.
- 61 Einzeltests (Encoding-Roundtrip mit Café, Crème brûlée, Fränkischer Abend, Münchberg, Schützenhaus, Straße,
  „Künstler – live“, „Konzert“, L’été; URL-Fälle; Textregeln; `to_utf8()`) bestanden.
- Sichtprüfung der exportierten Datei mit einem UTF-8-Reader: é, ä, ö, ü, ß, „ “ und – korrekt dargestellt.

## 11. WP Kulmbacher Land als technische Format-Referenz für InDesign (2026-09-30)

**Grundsatz:** Der XML-Export von WP Kulmbacher Land wird von Katharina (Kundin) bereits erfolgreich in Adobe InDesign
verwendet. Er ist deshalb die **technische Referenz für die äußere XML-Form** (Bytes, Zeilen, Einrückung, Deklaration).
Er ist **keine** Referenz für die fachliche Datenqualität (Telefonnummern, lange Texte, Stammtische, volle URLs usw.).

Referenzdatei: `C:\xampp\htdocs\kulmbacher\veranstaltungen-export-2026-09-30.xml` (WP Kulmbacher Land, 95 087 Bytes,
30 `<h1>`, 380 `<h2>`, 596 `<p>`). Code: `wp-events-manager/includes/class-wpem-export.php`, `export_xml()`.

### 11.1 Bytevergleich

| Merkmal | WP Kulmbacher Land | Eventcrawler vorher | Eventcrawler nachher |
|---|---|---|---|
| erste Bytes | `3C 3F 78 6D 6C` (`<?xml`) | gleich | gleich |
| BOM | nein | nein | nein |
| XML-Deklaration | `<?xml version="1.0" encoding="UTF-8"?>` | gleich | gleich |
| Encoding der Bytes | gültiges UTF-8 | gültiges UTF-8 | gültiges UTF-8 |
| Zeilenenden | LF (0 × CR) | LF | LF |
| Einrückung | keine | 4 / 8 Leerzeichen (1545 Zeilen) | **keine** |
| ein Element je Zeile | ja | ja | ja |
| Dateiende | `…</Textabschnitt>\n</Root>` ohne LF | `</Root>\n` | **`</Root>` ohne LF** |
| Erzeugung | `echo` + `esc_html()` | `XMLWriter` | `XMLWriter` (Einrückungsstring leer) |
| `&amp;` | ja | ja | ja |
| `"` | `&quot;` | `&quot;` | `&quot;` |
| `'` | `&#039;` (1 ×) | literal `'` | literal `'` (unverändert, s. u.) |
| andere Entities | **`&nbsp;` (in XML undefiniert)** | keine | keine |
| NBSP U+00A0 | 146 × als Zeichen | 0 | 0 |
| U+200B / U+202F / U+FEFF | 0 / 0 / 0 | 0 | 0 |
| wohlgeformt | **nein** (Zeile 503: `Entity 'nbsp' not defined`) | ja | ja |
| „Café“ | `43 61 66 C3 A9` | `43 61 66 C3 A9` | `43 61 66 C3 A9` |
| „Münchberg“ / „Straße“ | `4D C3 BC …` / `… C3 9F 65` | identische Bytes | identische Bytes |
| „ “ – … | `E2 80 9E` / `E2 80 9C` / `E2 80 93` / `E2 80 A6` | identische Bytes | identische Bytes |
| Mac-Roman-Mojibake „√“ | 0 | 0 | 0 |
| URL | gemischt `www.…` und `https://…` (51 × `http(s)://`) | ohne Protokoll | ohne Protokoll |
| Content-Type | `application/xml; charset=utf-8` | gleich | gleich |
| Content-Disposition / Dateiname | `attachment; filename="veranstaltungen-export-JJJJ-MM-TT.xml"` | gleich | gleich |
| Cache-Header | `Pragma: no-cache`, `Expires: 0` | `nocache_headers()` + `Content-Length` | unverändert |

**Ergebnis:** Die Zeichen selbst sind in beiden Exporten **bytegleich und korrekt UTF-8** (auch „Café“). Beide haben
dieselbe Deklaration, keinen BOM und LF. Unterschiede in der äußeren Form waren nur Einrückung und das LF am
Dateiende; beides ist angeglichen. Ein technischer Grund für „Caf√©“ ist im Dateivergleich **nicht** zu finden; die
Datei, die Katharina tatsächlich geöffnet hat, liegt nicht vor (Abschnitt 9).

### 11.2 Entscheidungen

- **Angeglichen:** keine Einrückung, kein LF nach `</Root>` (Whitespace zwischen Elementen, keine inhaltliche Änderung;
  Elementfolge und alle Texte identisch, geprüft).
- **Kein BOM:** Die funktionierende Referenz hat keinen. Es gibt keinen reproduzierbaren Beleg, dass ein BOM nötig ist.
- **UTF-8 bleibt UTF-8:** keine Umwandlung nach Mac Roman, Windows-1252 oder ISO-8859-1, keine Zeichen-Mappings.
- **Apostroph nicht auf `&#039;` umgestellt:** literal `'` und `&#039;` sind in XML gleichwertig; die Referenz nutzt
  `&#039;` nur einmal, ein Einfluss auf InDesign ist nicht belegt. `&quot;` ist bereits gleich.
- **`&nbsp;` und NBSP nicht übernommen:** `&nbsp;` macht die Referenz formal ungültig; wir erzeugen weiterhin
  wohlgeformtes XML. Dass InDesign die Referenz trotz `&nbsp;` verarbeitet, wurde nicht nachgeprüft (kein InDesign in
  der Entwicklungsumgebung).
- **Header und Dateiname unverändert:** gleicher Content-Type und Dateiname; Cache-Header ohne Grund nicht geändert.
- **Fachlich bleibt der Eventcrawler besser:** URL ohne Protokoll (10.2), sichere Textregeln (10.3), Filter,
  keine weiteren Kürzungen.

### 11.3 Tests

- Testexport A (vorher) und B (nachher) aus denselben Daten: 1048 `<p>`, 155 `<h1>`, 340 `<h2>`, Elementfolge und
  Texte identisch (auch je Quelle), wohlgeformt, UTF-8, keine Einrückung, nur LF, kein LF am Ende; 255 378 → 243 025 Bytes.
- HTTP-Download (gesamt und je Quelle) byte-identisch mit B, `application/xml; charset=utf-8`, keine PHP-Warnings.
- DB-Prüfsumme vor/nach allen Exporten gleich.
- 75 Einzeltests, u. a. Café, Münchberg, Straße, Ödön von Horváth, Fränkisch, Weißenstadt, „Konzert“,
  Schützengesellschaft, Gedankenstrich –, Apostroph ’, ein `<p>` mit über 4 000 Zeichen voller Mehrbyte-Zeichen
  (vollständig, nichts abgeschnitten), Formattests (keine Einrückung, nur LF, kein LF nach `</Root>`).

## 12. Zweites Kundenfeedback: Links, Ortsangaben, mehrere Uhrzeiten (2026-10-02)

Grundsatz (A66): Umgesetzt wird nur, was mit **strukturierten Quelldaten** oder einer **exakten, deterministischen
Regel** lösbar ist. Alles, was ein Verständnis des Textes bräuchte, wird nicht automatisiert (Abschnitt 12.5).
Alle Regeln wirken **nur im Export**; gespeicherte Werte (`event_url`, `source_url`, Texte) bleiben unverändert.
Äußere Form (Deklaration, kein BOM, LF, keine Einrückung, kein LF am Ende, keine `&nbsp;`) unverändert.

### 12.1 Links: nur Hostname

`Eventcrawler_Exporter::display_url()`:

| gespeichert | im XML |
|---|---|
| `https://pages.et4.de/de/bad-steben/default/detail/Event/e_123` | `Info: pages.et4.de` |
| `https://www.burg-rabenstein.de/events/foo` | `Info: www.burg-rabenstein.de` |
| `https://veranstaltungen.coburg.de/details/foo?a=1` | `Info: veranstaltungen.coburg.de` |
| `HTTPS://WWW.Example.DE/Pfad#info` | `Info: www.example.de` (Host klein) |
| `https://example.de:8443/event` | `Info: example.de` (Port und Zugangsdaten entfallen) |
| `mailto:foo@example.de`, `ftp://…`, `www.example.de/ohne-schema` | unverändert (kein Host-Parsing ohne `http(s)://`) |
| `https://` (kein Host erkennbar) | unverändert |

- Nur bei `http://`/`https://` wird der Host per `wp_parse_url()` gelesen. `www.` bleibt.
- Der Filter `eventcrawler_export_display_url` liefert weiterhin bei Bedarf die volle URL (getestet).
- Folge: Bei Bad Steben steht `pages.et4.de` (Plattform destination.one), nicht `bad-steben.de` – so wie im
  Kundenbeispiel; die Detailseiten liegen tatsächlich dort. **Seit 2026-10-06** lässt sich dafür im Backend ein
  verständlicher Text hinterlegen (Abschnitt 13).

### 12.2 Ort, Stadt, Adresse

| Regel | Beispiel vorher → nachher | Treffer (1048 Termine, Cache-Stand) |
|---|---|---|
| Ort steht als ganze Wortfolge im Titel → entfällt (seit 2026-09-30) | „Kurkonzert … im Prinzregent-Luitpold-Saal, Prinzregent-Luitpold-Saal“ → ohne Ort | wie bisher |
| **neu:** Ort steht als ganze Wortfolge in der Beschreibung → entfällt | „… Abend mit, Burg Rabenstein. Info: …“ → „… Abend mit. Info: www.burg-rabenstein.de“ | 30 Burg Rabenstein, 4 Coburg |
| **neu:** Ort = Stadt (steht schon im `<h2>`) → entfällt | `<h2>Bad Rodach</h2>` „Pflanzung … 2026, Bad Rodach“ → „Pflanzung … 2026“ | 1 |
| **neu:** Adresse (`venue_address`) nach dem Ort; gleiche Dopplungsregeln wie beim Ort | „…, Tourist-Information Coburg, Herrngasse 4. Info: veranstaltungen.coburg.de“ | 340 (nur Coburg) |
| Ort im Titel, Adresse vorhanden → nur Adresse | Test: „Murder Mystery Dinner auf Burg Rabenstein, Rabenstein 33. Info: www.burg-rabenstein.de“ | 0 echte Fälle (nur Coburg hat Adressen) |

- „Ganze Wortfolge“: Groß-/Kleinschreibung egal, Grenze = kein Buchstabe/keine Ziffer davor/danach; kein
  Fuzzy-Matching. „Hof“ im Titel „Hofgarten-Fest“ zählt nicht.
- Die Stadt wird **nie** aus Eigennamen entfernt: „Tourist-Information Coburg“ unter `<h2>Coburg</h2>` bleibt.
  Eine Stadt wird ohnehin nie separat in `<p>` angehängt.
- Aus Titel und Beschreibung wird nichts entfernt.
- Adressen gibt es nur, wo die Quelle sie strukturiert liefert (Coburg, seit 2026-10-15 Theater Selb). Burg Rabenstein und Bad Steben liefern in
  den abgerufenen Seiten keine Adresse je Termin (04-quellen.md, „Ort, Adresse, Kontakt je Quelle“). „Rabenstein 33“
  wird nicht hartcodiert.

### 12.3 Mehrere Uhrzeiten in einer Zeile

`Eventcrawler_Exporter::group_times()`: Termine werden **nur im XML** zusammengefasst; die Datensätze in der
Datenbank bleiben getrennt.

Zusammengefasst wird nur, wenn **alles außer der Startzeit exakt übereinstimmt**:

- Quelle (`source`), `start_date`, `city`, `title`, `description`, `venue_name`, `venue_address` (jeweils bereinigt,
  aber mit Groß-/Kleinschreibung verglichen)
- und die fertige Zeile ohne Uhrzeit, also auch die sichtbare Domain
- nur Termine **mit** Uhrzeit; ganztägige und solche ohne Uhrzeit werden nie zusammengefasst

Format: `18:30 Uhr & 20:00 Uhr …`, ab drei `18:30 Uhr, 20:00 Uhr & 21:30 Uhr …`; chronologisch sortiert, doppelte
Uhrzeiten nur einmal. Die Zeile steht an der Stelle des ersten (frühesten) Termins; `<h1>`/`<h2>` bleiben gleich.
Im XML steht `&` als `&amp;` (korrektes XML-Escaping; ein XML-Parser bzw. InDesign zeigt „&“).

Folge der Domain-Darstellung: Coburg-Termine mit verschiedener Termin-URL, aber sonst identischem Text werden
zusammengefasst (sichtbar ist dieselbe Domain). Mit dem Filter für volle URLs werden sie nicht zusammengefasst.

Echte Daten (Cache-Stand, 1048 Termine): **104 Zeilen** fassen 210 Termine zusammen – 103 × zwei Uhrzeiten (Bad Steben
92, z. B. „11:00 Uhr & 16:00 Uhr Aqua Fit in der Therme Bad Steben …“; Coburg 11, z. B. „11:00 Uhr & 16:00 Uhr Der
Elefantenpups …“), 1 × vier Uhrzeiten („11:00 Uhr, 12:00 Uhr, 14:00 Uhr & 15:00 Uhr Reformationstag auf der Veste
Coburg …“). Das Kundenbeispiel „Rock Rising“ kommt in keiner angebundenen Quelle vor; als Testdatensatz:
2 DB-Datensätze → 1 Zeile „18:30 Uhr & 20:00 Uhr Rock Rising - The Supreme Classic Rock Show, Festsaal der
Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de“.

Nicht zusammengefasst (getestet): anderer Ort, andere Adresse, andere Beschreibung, anderer Tag, andere Stadt, andere
Quelle, andere Domain, Titel nur in Groß-/Kleinschreibung anders, ganztägig vs. mit Uhrzeit, zwei Termine ohne
Uhrzeit.

`Eventcrawler_Exporter::$exported` zählt weiterhin Termine, `$lines` die `<p>`-Zeilen.

Hinweis WP Kulmbacher Land: Dessen XML-Importer (09-wpem-integration.md) liest nur **eine** Uhrzeit am Zeilenanfang;
„18:30 Uhr & 20:00 Uhr Titel“ würde als 18:30 mit dem Titel „& 20:00 Uhr Titel“ gelesen. Ein Import ist weiterhin
keine Anforderung.

### 12.4 Treffpunkt, Telefon, Ansprechpartner, Anmeldung

- Der Export **entfernt keine Inhalte** aus Titel oder Beschreibung (nur unsichtbare Zeichen und Whitespace,
  Abschnitt 10.3). Texte wie „Treffpunkt: Rathaus, Marktplatz.“, „Tel.: 09221/9588-0“, „Telefon:“, „Telefonnummer:“,
  „Ansprechpartner:“, „Kontakt:“, „Anmeldung bei:“, „Anmeldung erforderlich“, „Anmeldung unter:“, „Begrenzte
  Teilnehmerzahl“, „Karten:“, „Tickets:“, „Dauer …“ bleiben vollständig erhalten (13 Einzeltests und das
  Kundenbeispiel „Kellerführung“).
- Es gibt **kein** Telefon-, Treffpunkt- oder Ansprechpartner-Parsing. In den gespeicherten Daten der drei Quellen
  kommen diese Marker nicht vor (0 Treffer); strukturiert gäbe es sie nur auf Detailseiten (Kosten: 04-quellen.md).
- Personennamen werden nie als Ansprechpartner gedeutet.

### 12.5 Nicht automatisiert (bräuchte semantisches Verständnis)

| Fall | Warum nicht |
|---|---|
| Marketingtext entfernen („Erleben Sie einen unvergesslichen Abend …“) | nicht strukturiert erkennbar; der Crawler weiß nicht, was redaktionell unerwünscht ist |
| Beschreibung kürzen bzw. „wichtige“ Infos auswählen | Bewertung von Inhalten; Gefahr, Treffpunkt, Preis oder Anmeldung zu verlieren |
| teilweise Titelwiederholung in der Beschreibung („Murder Mystery Dinner "Kripo TV"“ / „"Kripo TV" - ein neues Murder Mystery Dinner …“) | keine exakte Wiederholung |
| Ort, der nur sinngemäß im Text vorkommt („im Renaissance-Saal der Burg“) | keine exakte Wortfolge |
| Adresse aus Fließtext oder Seitenfooter ableiten | nicht je Termin belegt |
| Zusammenfassen bei ähnlichem, aber nicht identischem Text | Gefahr, verschiedene Veranstaltungen zu verschmelzen |
| Veranstalter statt Ort (Bad Steben „Bayerisches Staatsbad Bad Steben GmbH“), Slogan im Ort („Therme Bad Steben - Mit allen Sinnen genießen“) | ist laut Quelle (auch im JSON-LD der Detailseite) der Ortsname; Umschreiben wäre Redaktion |

### 12.6 Offene Formatfrage

Die Kundenbeispiele enden ohne „Info:“ („…, Kulmbacher Str. 4, www.freiheitshalle.de“). Das Label „Info:“ ist Teil
des bisherigen Formats (A27, ebenso im WPEM-Export) und wurde **nicht** eigenmächtig entfernt. Offene Frage an den
Kunden (todo.md).

### 12.7 Regression (2026-10-02)

| Export | Termine | `<p>` vorher → nachher | `<h1>` | `<h2>` | Größe vorher → nachher |
|---|---|---|---|---|---|
| alle | 1048 | 1048 → 942 | 155 / 155 | 340 / 340 | 243 025 → 150 056 B (−38 %) |
| Burg Rabenstein | 124 | 124 → 124 | 109 | 109 | 46 204 → 35 612 B |
| Bad Steben | 559 | 559 → 467 | 92 | 113 | 122 620 → 62 943 B |
| Coburg | 365 | 365 → 351 | 45 | 118 | 77 123 → 54 423 B |

- Jede der 1048 alten Zeilen ist erklärt (Skriptvergleich je `<h1>`/`<h2>`-Block, Blockfolge identisch):
  676 × nur Link gekürzt (davon 184 gruppiert), 337 × Coburg-Adresse ergänzt (davon 26 gruppiert), 32 × Ort entfernt,
  3 × Ort entfernt + Adresse ergänzt. Keine andere Änderung; keine Endzeit im XML.
- Wohlgeformt, gültiges UTF-8, kein BOM, Deklaration gleich, nur LF, keine Einrückung, kein LF nach `</Root>`, keine
  `&nbsp;`; keine „, ,“, „. ,“, „..“ (außer „...“ aus dem Quelltext) und keine doppelten Leerzeichen.
- HTTP-Download (gesamt und je Quelle) byte-identisch mit dem geprüften Ergebnis, `application/xml; charset=utf-8`.
  Exportfilter (Quelle, Monat + Jahr) funktionieren. Keine PHP-Warnings.
- Datenbank durch den Export unverändert (`CHECKSUM TABLE` gleich).
- `tools/test-export-rules.php`: 97 Tests bestanden (URL, Dopplungen, Gruppierung, Rock Rising über die DB mit
  Rollback, Kontaktinfos, Filter, Regelergänzung, Gesamtexport).

## 13. Link-Bezeichnungen: Domain → Ausgabe im XML (2026-10-06)

Problem: Seit 12.1 steht nach „Info:“ nur der Hostname. Technische Domains wie `pages.et4.de` (Plattform
destination.one) sind für Leser unverständlich. Lösung: Im Backend (**Eventcrawler → Link-Bezeichnungen**,
06-admin.md Abschnitt 10) kann der Administrator je Domain einen frei wählbaren Ausgabetext hinterlegen.

### 13.1 Regeln

- **Global, domain-basiert:** Ein Eintrag gilt für jeden Termin, dessen sichtbare Domain exakt passt, unabhängig von
  der Quelle (A72). Kein Eintrag ist an einen Adapter gebunden; nichts ist im Exporter hartcodiert.
- **Freier Text:** z. B. „Bad Steben“, „Tourismus Bad Steben“, „www.bad-steben.de“, „Veranstaltungskalender Coburg“.
  Keine URL-Prüfung. Gespeichert ohne HTML, Whitespace zusammengefasst, höchstens 100 Zeichen.
- **Nur XML:** `event_url`, `source_url`, Matching, Crawler, Admin-Liste und Datenbank bleiben unverändert; die
  vollständigen URLs sind weiterhin gespeichert.
- **Ablauf je Zeile** (`Eventcrawler_Exporter::format_body()`):
  1. volle URL (`event_url`, sonst `source_url`)
  2. Hostname per `display_url()` (nur bei `http://`/`https://`, klein, `www.` bleibt; Abschnitt 12.1)
  3. aktive Link-Bezeichnung für genau diesen Hostnamen suchen (keine Subdomain-/Teiltreffer:
     `www.pages.et4.de` ≠ `pages.et4.de`)
  4. vorhanden → Bezeichnung ausgeben; sonst → Hostname (**Fallback**, auch bei deaktiviertem oder gelöschtem Eintrag
     und bei beschädigter Option)
  5. Filter `eventcrawler_export_display_url` erhält das Ergebnis und hat das letzte Wort (z. B. volle URL für einen
     Import in WP Kulmbacher Land, 09-wpem-integration.md)
- URLs ohne `http(s)://` (z. B. `mailto:`) werden wie bisher unverändert ausgegeben und nie ersetzt.
- Das Präfix **„Info:“ bleibt** unverändert (offene Kundenfrage 12.6, nicht Teil dieser Änderung).
- **Escaping:** XMLWriter wie für alle Texte; „Kultur & Tourismus“ steht als `Info: Kultur &amp; Tourismus` im XML
  (wohlgeformt, getestet). Umlaute als UTF-8-Zeichen.
- **Performance:** Die Option wird **einmal je Export** gelesen (`Eventcrawler_Domain_Mappings::get_lookup()` in
  `build_xml()`, getestet: 1 Zugriff bei 983 Zeilen) und als Lookup `domain → label` an `group_times()`/`format_body()`
  übergeben (A74).
- **Zusammenfassen mehrerer Uhrzeiten (12.3)** vergleicht weiterhin die fertige Zeile ohne Uhrzeit. Bekommen zwei
  Domains dieselbe Bezeichnung, können sonst identische Termine derselben Quelle zusätzlich zusammengefasst werden;
  bei den drei angebundenen Quellen kommt das nicht vor (Zeilenzahl mit und ohne Bezeichnungen gleich).
- **Keine Default-Einträge** (A73): Nach dem Update ist der Export unverändert, bis jemand Bezeichnungen anlegt.

### 13.2 Beispiele (echte Testdaten, Bezeichnungen wie vom Auftraggeber vorgeschlagen)

| Domain | Link-Bezeichnung | XML vorher | XML nachher |
|---|---|---|---|
| `pages.et4.de` | Bad Steben | `10:00 Uhr Kurkonzert am Vormittag im Prinzregent-Luitpold-Saal, Bayerisches Staatsbad Bad Steben GmbH. Info: pages.et4.de` | `… Bayerisches Staatsbad Bad Steben GmbH. Info: Bad Steben` |
| `veranstaltungen.coburg.de` | Coburg | `09:30 Uhr Blumen etc. – Malerei von MO, Ausstellung, Café / Galerie M, Judengasse 8. Info: veranstaltungen.coburg.de` | `… Judengasse 8. Info: Coburg` |
| `www.burg-rabenstein.de` | www.burg-rabenstein.de | `17:00 Uhr Burgmenü mit Märchenzauber, …, Burg Rabenstein. Info: www.burg-rabenstein.de` | unverändert |
| `www.example.de` (kein Eintrag) | – | `… Info: www.example.de` | `… Info: www.example.de` (Fallback) |
| `pages.et4.de`, Eintrag deaktiviert oder gelöscht | – | `… Info: Bad Steben` | `… Info: pages.et4.de` (Fallback) |

### 13.3 Tests (2026-10-06)

- `tools/test-domain-mappings.php` (90 Tests, Option wird danach wiederhergestellt, Tabelle nur gelesen):
  Normalisierung (Groß-/Kleinschreibung, `https://`, Pfad, Query, Port, Punkt am Ende, ungültige Hosts, `@`, fremde
  Schemes), Dubletten (auch beim Ändern), leere Domain/Ausgabe, XSS, Länge 100/101 mit Umlauten, Ausgabe mit Umlaut,
  `&` und `www.`-Text, Ändern/Deaktivieren/Löschen → Fallback, Filter hat Vorrang, beschädigte Option, Gesamtexport
  mit drei Bezeichnungen.
- Gesamtexport (1 090 Termine, 983 `<p>`): mit Bezeichnungen unterscheiden sich **nur** die Info-Werte
  (467 Bad-Steben- und 392 Coburg-Zeilen; Rabenstein mit Bezeichnung = Domain unverändert). Elementfolge, `<h1>`,
  `<h2>`, Zeilenzahl, Uhrzeiten, Titel, Beschreibungen, Adressen, Deklaration, kein BOM, keine Einrückung, kein LF
  am Ende: gleich.
- Ohne Einträge (sowie mit deaktiviertem bzw. gelöschtem Eintrag) ist der Export **bytegleich** mit dem Exporter vom
  2026-10-02: gesamt, je Quelle, 10/2026, 2027, Dezember (Vergleich gegen eine Kopie des alten Exporters, auch per
  HTTP-Download).
- Datenbank durch Pflege der Bezeichnungen und Export unverändert (`CHECKSUM TABLE` gleich).
- `tools/test-export-rules.php`: 97 Tests weiterhin bestanden.

## 14. Vierte Quelle Theater Selb im Export (2026-10-15)

Keine Änderung am Exporter. Theater Selb erscheint automatisch im Gesamtexport und im Exportfilter „Quelle“.

| Prüfung | Ergebnis |
|---|---|
| Export nur Theater Selb | 29 `<p>`, 28 `<h1>`, 28 `<h2>Selb</h2>`; wohlgeformt; UTF-8 ohne BOM, nur LF, keine Einrückung, kein LF nach `</Root>` |
| Gesamtexport 4 Quellen | wohlgeformt, 1250 `<p>`; „Selb“ wird je Datum alphabetisch zwischen den anderen Städten einsortiert |
| Sonderzeichen | „ä ö ü ß“, „„…““, „–“, „‟“, „…“, „&amp;“ korrekt (z. B. „Heißmann“, „Zuckerguss &amp; Kräuterkekse“); „é“ kommt in dieser Quelle nicht vor |
| mehrere Uhrzeiten | kein Fall (keine Produktion zweimal am selben Tag); am 28.11. stehen zwei verschiedene Termine (17:00, 19:30) korrekt als zwei Zeilen |
| Link | `Info: www.theater-selb.de` (Domain-Fallback, A67) |

Bestehende Textregeln greifen auch hier (keine Sonderlogik):
- Ort entfällt, wenn er in der Beschreibung steht: „Plan B!: Capones Vermächtnis (Nachholtermin), … jetzt im
  Rosenthal-Theater Selb. Hohenberger Straße 9. Info: …“ (A69).
- Titel am Beschreibungsanfang wird nicht wiederholt: „19:30 Uhr Neujahrskonzert der Hofer Symphoniker: Die goldenen
  Zwanziger Ein Abend voller Klangkultur: …“ (A63).
- Die Adresse steht in der Schreibweise der Quelle („Hohenberger Straße 9“, „Hohenberger Strasse 9“,
  „Hohenbergerstrasse 9“).

**Link-Bezeichnung getestet:** Testeintrag `www.theater-selb.de` → „Theater Selb“ über die Seite „Link-Bezeichnungen“
angelegt: 29 × „Info: Theater Selb“ statt „Info: www.theater-selb.de“, sonst bytegleich; Coburg unverändert. Danach
wieder gelöscht (keine Default-Einträge, A73); Export wieder bytegleich mit vorher. Ob der Eintrag dauerhaft angelegt
wird, entscheidet die Redaktion (todo.md).

Beispiel (echte Daten):

```
<h1>05. November Donnerstag</h1>
<h2>Selb</h2>
<p>19:30 Uhr Theater Hof in Selb: „Evviva Mamma!“, Sitten und Unsitten am Theater – Komische Oper von Geatano Donizetti, Rosenthal-Theater Selb, Hohenberger Straße 9. Info: www.theater-selb.de</p>
```

## 15. Fünfte Quelle DAS ZENTRUM Bayreuth im Export (2026-10-15)

Keine Änderung am Exporter.

| Prüfung | Ergebnis |
|---|---|
| Export nur DAS ZENTRUM | 73 Termine → **72 `<p>`**, 64 `<h1>`, 64 `<h2>Bayreuth</h2>`; wohlgeformt, UTF-8 ohne BOM, nur LF, keine Einrückung, kein LF nach `</Root>` |
| mehrere Uhrzeiten (A70) | erster echter Fall dieser Quelle: „14:00 Uhr &amp; 19:00 Uhr Jahreskonzert – Erster Harmonika-Club Bayreuth, DAS ZENTRUM – Europasaal, Äußere Badstraße 7a. Info: …“ (zwei Datensätze 281/1032, alle Felder gleich) |
| Ort / Adresse | „DAS ZENTRUM – Europasaal, Äußere Badstraße 7a“; Kleinkunstbühne ohne Adresse („DAS ZENTRUM – Kleinkunstbühne.“) |
| Sonderzeichen | „ß“, „ä ö ü“, „è“, „’“, „–“, „&amp;“ korrekt („Pippo Pollina &amp; Quartetto Acustico – La vita è bella così com’è.“) |
| Gesamtexport 5 Quellen | wohlgeformt, 1323 `<p>` |

**Link-Bezeichnung getestet:** Testeintrag `www.das-zentrum.de` → „DAS ZENTRUM Bayreuth“: 72 × „Info: DAS ZENTRUM
Bayreuth“ statt „Info: www.das-zentrum.de“, sonst bytegleich, Theater Selb unverändert. Danach wieder gelöscht; Export
wieder bytegleich (keine Default-Einträge, A73).

## 16. Sechste Quelle Schützenhaus Münchberg im Export (2026-10-15)

Keine Änderung am Exporter. Export nur Schützenhaus Münchberg: 46 `<p>`, 46 `<h1>`, 46 `<h2>Münchberg</h2>`,
wohlgeformt, UTF-8 ohne BOM, nur LF, keine Einrückung, kein LF nach `</Root>`; Sonderzeichen („ä ö ü ß é“, „„…““, „’“,
„–“, „&amp;“) korrekt. Gesamtexport 6 Quellen: 1370 `<p>`. Kein Fall mehrerer Uhrzeiten am selben Tag (der
Stadtspaziergang liegt an zwei Tagen). Die Regel „Titel am Beschreibungsanfang nicht wiederholen“ (A63) greift z. B. bei
„20:00 Uhr Dr. Mark Benecke zählt zu den … Kriminalbiologen …“.

Link-Bezeichnung getestet: `schuetzenhaus-muenchberg.de` → „Schützenhaus Münchberg“: 46 × „Info: Schützenhaus
Münchberg“, sonst bytegleich; danach gelöscht, Export wieder bytegleich (A73).

## 17. Siebte Quelle Stadtsteinach im Export (2026-10-15)

Keine Änderung am Exporter. Export nur Stadtsteinach: 32 `<p>`, 29 `<h1>`, 29 `<h2>Stadtsteinach</h2>`; Format
unverändert (UTF-8 ohne BOM, LF, keine Einrückung, kein LF am Ende). Termine ohne Uhrzeit stehen ohne Zeitangabe
(„Gardenachmittag, Steinachtalhalle Stadtsteinach. Info: www.stadtsteinach.de“). Keine Mehrfachzeiten. Gesamtexport
7 Quellen: 1402 `<p>`. Link-Bezeichnung getestet (`www.stadtsteinach.de` → „Stadtsteinach“, 32 ×), danach gelöscht,
Export wieder bytegleich.

## 18. Achte Quelle Helmbrechts im Export (2026-10-15)

Keine Änderung am Exporter. Export nur Helmbrechts: 47 `<p>`, 31 `<h1>`, 31 `<h2>Helmbrechts</h2>`; Format unverändert.
Termine ohne Uhrzeit (Dorfmeisterschaft) ohne Zeitangabe; Teaser als Beschreibung („19:30 Uhr Kulturwelten: LaLeLu,
A-Cappella Comedy, Bürgersaal Helmbrechts, Luitpoldstraße 21. Info: stadt-helmbrechts.de“). Keine Mehrfachzeiten.
Gesamtexport 8 Quellen: 1459 `<p>`. Link-Bezeichnung getestet (`stadt-helmbrechts.de` → „Helmbrechts“, 47 ×), danach
gelöscht, Export wieder bytegleich.

## 19. Neunte Quelle Kronach im Export (2026-10-15)

Keine Änderung am Exporter. Export nur Kronach: 102 `<p>` aus 103 Datensätzen (die Quelldublette „Gesundheit trifft
Informatik …“ 27.10. 17:30 mit zwei IDs wird als identische Zeile zusammengefasst), 20 `<h1>`, `<h2>Kronach</h2>` und
einmal `<h2>Nordhalben</h2>` (Heeresmusikkorps, Nordwaldhalle). Ohne Beschreibung, z. B. „19:30 Uhr Ninotschka,
Kreiskulturraum, Siechenangerstraße 13. Info: www.kronach.de“. Sonderzeichen „„…““, „”“, „ø“, „ß“, „&amp;“ korrekt.
Gesamtexport 9 Quellen: 1561 `<p>`, wohlgeformt, Format unverändert. Link-Bezeichnung getestet (`www.kronach.de` →
„Kronach“, 102 ×), danach gelöscht, Export wieder bytegleich.

## 20. Zehnte Quelle Bayreuth Tourismus im Export (2026-10-15)

Keine Änderung am Exporter. Export nur Bayreuth Tourismus: 529 `<p>` aus 538 Datensätzen (identische Zeilen
zusammengefasst), 28 `<h1>`, alle `<h2>Bayreuth</h2>`; Kurztext als Beschreibung, z. B. „19:30 Uhr Stuttgarter
Philharmoniker, Mozart · Saint-Saëns · Strawinsky, Friedrichsforum, Friedrichstraße 19. Info: region-bayreuth.de“.
Statusangaben stehen wie in der Quelle im Titel („ENTFÄLLT: …“, „AUSVERKAUFT: …“).
**DAS-ZENTRUM-Doppelungen:** Jeder Titel der Quelle DAS ZENTRUM im Zeitraum (Bosetti, Rolf Miller, Claus von Wagner …)
steht im Gesamtexport genau **einmal**; die bei Bayreuth Tourismus gelisteten Termine dieses Orts fehlen (A91).
Gesamtexport 10 Quellen: 2091 `<p>`, wohlgeformt, Format unverändert. Link-Bezeichnung getestet (`region-bayreuth.de` →
„Bayreuth Tourismus“, 529 ×), danach gelöscht, Export wieder bytegleich.

## 21. Elfte Quelle Concertbüro Franken im Export (2026-10-15)

Keine Änderung am Exporter. Export nur Concertbüro Franken: 322 `<p>` aus 324 Datensätzen (die zwei Quelldubletten
CLAWFINGER und NAPALM DEATH werden als identische Zeilen zusammengefasst), 210 `<h1>`, **ausschließlich**
`<h2>Nürnberg</h2>` (210) und `<h2>Fürth</h2>` (1). Ohne Beschreibung und Adresse, z. B. „20:00 Uhr THE KILKENNYS,
Hirsch. Info: www.concertbuero-franken.de“. Link-Bezeichnung getestet (`www.concertbuero-franken.de` → „Concertbüro
Franken“, 322 ×), danach gelöscht, Export wieder bytegleich.

## 22. Manuelle Termine im Export (2026-10-28)

Seit dem WPEM-Ersatz (10-frontend.md) stehen auch manuell gepflegte Termine (`source = manual`) in der Tabelle. Der
Export behandelt sie **genau wie gecrawlte**: gleiche Gruppierung, Sortierung und Zeilenregeln (Abschnitte 6 und 12),
**Format unverändert**. Der Exportfilter „Quelle“ bietet zusätzlich „Manuell“.

- `source_url` ist bei manuellen Terminen immer `NULL`, damit „Info:“ nur aus dem Webseiten-Link (`event_url`) entsteht.
- Übernommene WPEM-Termine erscheinen im Eventcrawler-Zeilenformat (`Titel, Beschreibung, Ort. Info: domain`), nicht
  mehr im WPEM-Format (`Titel: Beschreibung Info: https://…`). Ob das für Katharina passt, ist offen (10-frontend.md E8).
- Regression 2026-10-28: Gesamtexport aller 2562 Termine vor/nach der Änderung **bytegleich**; Export mit Filter
  „Manuell“ getestet (`tools/test-frontend.php`).
