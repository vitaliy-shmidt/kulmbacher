# 04 – Veranstaltungsquellen

## Regeln für dieses Dokument

- **Keine technischen Eigenschaften einer Website erfinden.** Was nicht geprüft ist, steht hier als **„noch zu prüfen“**.
- Eine Quelle bekommt den Status „funktioniert“ erst, wenn ihr Adapter tatsächlich mit echten Daten getestet wurde.
- Werden bei der Analyse Erkenntnisse gewonnen (Seitenstruktur, Paginierung, IDs, JSON-LD, robots.txt, abgedeckte
  Orte), werden sie in der Tabelle der Quelle eingetragen.

### Statuswerte

| Status | Bedeutung |
|---|---|
| nicht analysiert | Website noch nicht untersucht |
| analysiert | Struktur untersucht, Erkenntnisse in den Bemerkungen, noch kein Adapter |
| in Entwicklung | Adapter wird gebaut |
| funktioniert | Adapter mit echten Daten getestet |
| fehlerhaft | Adapter vorhanden, aber derzeit defekt, z. B. wegen einer geänderten Seitenstruktur |
| zurückgestellt | bewusst nicht umgesetzt (Grund in den Bemerkungen) |

## Übersicht

Stand 2026-10-15: **10 Quellen sind analysiert und angebunden** (Burg Rabenstein, Bad Steben, Coburg, Theater Selb,
DAS ZENTRUM Bayreuth, Schützenhaus Münchberg, Stadtsteinach, Helmbrechts, Kronach, Bayreuth Tourismus). Neustadt bei
Coburg und Kulmbach sind zurückgestellt, Hof, Thurnau und Nürnberg/Fürth sind nicht analysiert.

| # | Slug (`source`) | Name | Status |
|---|---|---|---|
| 1 | `bad-steben` | Bad Steben | **funktioniert** (2026-09-24) |
| 2 | `coburg` | Coburg | **funktioniert** (2026-09-24) |
| 3 | `burg-rabenstein` | Burg Rabenstein / Ahorntal | **funktioniert** (Proof of Concept, 2026-09-24) |
| 4 | `theater-selb` | Theater Selb | **funktioniert** (2026-10-15) |
| 5 | `das-zentrum-bayreuth` | DAS ZENTRUM Bayreuth | **funktioniert** (2026-10-15) |
| 6 | `neustadt-bei-coburg` | Neustadt bei Coburg | **zurückgestellt** (2026-10-15: Server blockiert den Crawler, Abschnitt 6) |
| 7 | `kulmbach` | Kulmbach | **zurückgestellt** (2026-10-15: Server sperrt den Crawler mit HTTP 403, siehe „Zurückgestellte Quellen“) |
| 8 | `bayreuth-tourismus` | Bayreuth Tourismus | **funktioniert** (2026-10-15; Termine am Ort DAS ZENTRUM ausgeschlossen, A91) |
| 9 | `schuetzenhaus-muenchberg` | Schützenhaus Münchberg | **funktioniert** (2026-10-15) |
| 10 | `hof` | Hof | nicht analysiert |
| 11 | `stadtsteinach` | Stadtsteinach | **funktioniert** (2026-10-15; 6 von 47 Terminen ohne Ort übersprungen) |
| 12 | `helmbrechts` | Helmbrechts | **funktioniert** (2026-10-15) |
| 13 | `thurnau` | Thurnau | nicht analysiert |
| 14 | `kronach` | Kronach | **funktioniert** (2026-10-15) |
| 15 | `nuernberg-fuerth` | Nürnberg/Fürth | URL noch nicht bekannt |

Die Slugs sind ein Vorschlag. Final festgelegt sind bisher `burg-rabenstein`, `bad-steben`, `coburg`, `theater-selb`, `das-zentrum-bayreuth`, `schuetzenhaus-muenchberg`, `stadtsteinach`, `helmbrechts`, `kronach` und `bayreuth-tourismus`. Sobald Daten mit
einem Slug gespeichert sind, darf er nicht mehr geändert werden.

## Zurückgestellte / schwer erreichbare Quellen

Quellen, die technisch nicht sauber zugänglich sind, werden hier gesammelt. Grundsatz (A85): **Eine Sperre des
Betreibers wird nicht umgangen** – keine vorgetäuschte Browser-Kennung, keine Änderung des Plugin-User-Agents, um eine
Sperre zu umgehen, kein Ausweichen auf Proxys. Für solche Quellen wird kein Adapter gebaut, bis der Zugang geklärt ist.

Prüfmethode (für jede neue Quelle **vor** der Analyse): ein GET mit dem User-Agent des Plugins
(`Eventcrawler/<version> (WordPress; <home_url>)`); bei einem Fehler Wiederholung im Abstand; zur Unterscheidung
„Sperre“ / „Störung“ höchstens ein einzelner Vergleichsabruf der Startseite bzw. robots.txt mit neutralem
curl-User-Agent (keine Browser-Kennung, keine Inhalte des Kalenders).

| Quelle | URL | Status | technischer Grund | was geprüft wurde | bewusst NICHT gemacht | mögliche nächste Schritte |
|---|---|---|---|---|---|---|
| Neustadt bei Coburg | https://www.neustadt-bei-coburg.de/freizeit-erholung/kunst-kultur/veranstaltungskalender | zurückgestellt (2026-10-15) | Der Server (TYPO3, Apache) antwortet auf jeden User-Agent mit „Eventcrawler“ reproduzierbar mit **HTTP 503** („maintenance downtime or capacity problems“); neutrale User-Agents erhalten im selben Zeitfenster **HTTP 200**. Kein Website-Ausfall | 10 Minuten lang jede Minute ein Abruf mit Eventcrawler-Kennung (14:04–14:13 Uhr): 10 × 503; Plugin-User-Agent und `Mozilla/5.0 (compatible; Eventcrawler/0.1.0)` ebenfalls 503; neutraler curl-User-Agent um 14:08: 200; robots.txt existiert nicht (404) | keine Browser-Kennung vorgetäuscht; User-Agent des Plugins nicht geändert; Kalender nicht mit neutraler Kennung abgerufen; Sperre nicht umgangen | 1. Stadt kontaktieren und Freigabe anfragen; 2. nach offizieller API / iCal / RSS / Export fragen; 3. Quelle dauerhaft verwerfen (Coburg liefert einen Teil der Neustädter Termine, Vollständigkeit unbekannt). **Nicht:** User-Agent ändern, um die Sperre zu umgehen |
| Kulmbach | https://www.kulmbach.de/veranstaltungen/index.php | zurückgestellt (2026-10-15) | Der Server (nginx) antwortet auf den User-Agent des Plugins reproduzierbar mit **HTTP 403 Forbidden**, auch für `/robots.txt`; ein neutraler User-Agent erhält **HTTP 200**. Die robots.txt nennt „Eventcrawler“ nicht (Sperrliste benannter Bots mit `Disallow: /`, keine Regel für `*`) – die Sperre liegt im Server, vermutlich auf „crawler“ im User-Agent | Veranstaltungsseite und robots.txt mit Plugin-User-Agent: 403; 3 Wiederholungen der Startseite im 20-s-Abstand (14:29–14:30 Uhr): 3 × 403; neutraler curl-User-Agent um 14:30: Startseite 200, robots.txt 200 | wie oben; der Kalender wurde nicht analysiert | 1. Stadt Kulmbach um Freigabe bzw. API/iCal/RSS bitten; 2. Alternative prüfen: Landkreis Kulmbach (`www.landkreis-kulmbach.de`) antwortet dem Plugin-User-Agent mit 200, die TLS-Kette wird aber von OpenSSL/Git-curl nicht verifiziert („self signed certificate in certificate chain“, Windows-curl: ok) – vor einer Analyse prüfen, ob `wp_remote_get()` verbindet; es wäre eine andere Quelle (Landkreis statt Stadt); 3. Quelle verwerfen |

Beobachtungen ohne Analyse (je **ein** Abruf mit dem Plugin-User-Agent, 2026-10-15): `https://www.hof.de/veranstaltungen`
→ HTTP 503 (vor einer Analyse nach der Prüfmethode oben klären); `https://www.thurnau.de/…/veranstaltungen` → TLS-Kette
von Git-curl/OpenSSL nicht verifiziert (keine Sperre, vor einer Analyse prüfen, ob `wp_remote_get()` verbindet);
Stadtsteinach, Helmbrechts und Kronach → HTTP 200 (inzwischen angebunden).

### Zeichenkodierung der angebundenen Quellen (geprüft 2026-09-30)

Anlass: Kundenmeldung „Caf√©“ im XML (07-export.md Abschnitt 10.1). Je Quelle ein Live-GET der Crawl-URL:

| Quelle | HTTP Content-Type | Meta im HTML | Body | BOM | DOMDocument im Adapter | gespeicherte Daten |
|---|---|---|---|---|---|---|
| Burg Rabenstein | `text/html; charset=utf-8` | `<meta charset="utf-8">` | gültiges UTF-8 | nein | korrekt | 124 Events, 0 fehlerhafte Zeichen |
| Bad Steben (pages.et4.de) | `text/html; charset=utf-8` | `<meta charset='utf-8'>` | gültiges UTF-8 | nein | korrekt | 559 Events, 0 fehlerhafte Zeichen |
| Coburg | `text/html; charset=utf-8` | `<meta charset="utf-8">` | gültiges UTF-8 | nein | korrekt | 365 Events, 0 fehlerhafte Zeichen |
| DAS ZENTRUM Bayreuth (API, geprüft 2026-10-15) | `application/json; charset=UTF-8` | – (JSON, Sonderzeichen teils als HTML-Entities `&#8211;`, `&#038;`, werden von `clean_text()` dekodiert) | gültiges UTF-8 | nein | – (kein HTML-Parser) | 73 Events, 0 fehlerhafte Zeichen („Äußere Badstraße“, „è“, „’“, „–“) |
| Theater Selb (geprüft 2026-10-15) | `text/html; charset=UTF-8` | `<meta charset="UTF-8">` | gültiges UTF-8 | nein | korrekt („Heißmann“, „„Evviva Mamma!““, „–“, „…“) | 29 Events, 0 fehlerhafte Zeichen |

- Die Quellen sind **nicht** Ursache des Zeichenfehlers. Realer Fall: Coburg „Café / Galerie M“ (39 Termine) und
  „Café Ribollita“ (1) – im HTML, in der DB und im XML jeweils `C3 A9`.
- Coburg liefert in 2 Einträgen unsichtbare Zero-Width-Spaces (U+200B) am Textanfang. Sie bleiben gespeichert und
  werden erst beim Export entfernt (07-export.md 10.3).

### Ort, Adresse, Kontakt je Quelle (geprüft 2026-10-02)

Anlass: Kundenfeedback zum XML (doppelte Ortsangaben, Adresse, Treffpunkt, Telefon, Ansprechpartner; 07-export.md
Abschnitt 12). Geprüft am gespeicherten HTML (Cache vom 2026-09-24), an den gespeicherten Daten (1048 Termine) und
mit **einem** zusätzlichen GET einer Bad-Steben-Detailseite. Nur echte Felder:

| Quelle | venue | address | city | organizer | meeting_point | phone / contact | strukturiert? |
|---|---|---|---|---|---|---|---|
| Burg Rabenstein | fest „Burg Rabenstein“ (kein Feld je Termin) | **nein**. „Rabenstein 33, 95491 Ahorntal“ steht nur im Seitenfooter (Betreiber / Gutschein-Widerruf), nicht je Termin; die ICS-Datei hat kein `LOCATION` | fest „Ahorntal“ | nein | nur im Fließtext der Langbeschreibung (`data-title`, 5 ×), nicht im gespeicherten Teaser | nur im Fließtext der Langbeschreibung („Tel. 09202/9700440“, 25 ×) | nein |
| Bad Steben (Liste) | `p.result_city > span[1]` | **nein** in der Liste | `span[2]` | nein in der Liste | nein | `small.phone` ist **versteckt** (`display:none`) und keinem Ort/Veranstalter zugeordnet → nicht verwendet | Ort und Stadt ja |
| Bad Steben (Detailseite, nicht abgerufen) | JSON-LD `location.name` (z. B. „Bayerisches Staatsbad Bad Steben GmbH“) | JSON-LD `location.address.streetAddress` („Badstraße 31“) | `addressLocality` | JSON-LD `author` („Markt Bad Steben“, Tel., E-Mail) | nein | beim `author` | ja, aber nur je Serie auf der Detailseite |
| Coburg (Liste) | Link im Block „Veranstaltungsort“ | **ja**: Zeile zwischen Ortsname und „PLZ Stadt“ (341 von 365, live 321 von 335) | Zeile „PLZ Stadt“ | nein | nein | nein | **ja** |
| Coburg (Detailseite, nicht abgerufen) | `div.location` | `div.location` | `div.location` | `div.organizer` „Ansprechpartner“ mit Anschrift | nein | Telefon in `div.location` und `div.organizer` | ja, aber je Termin eine Detailseite |
| DAS ZENTRUM Bayreuth (API, seit 2026-10-15) | `venue.venue` (Raum: Europasaal, Kleinkunstbühne, Ganzes Haus) | **ja**: `venue.address` (54/73); Kleinkunstbühne ohne Adresse | `venue.city` (54/73), Kleinkunstbühne über die Ort-ID (19) | `organizer` (getrennt) – nicht gespeichert | nein | `venue.phone` (Haus), nicht je Termin – nicht gespeichert | ja |
| Theater Selb (Liste + Detailseite, seit 2026-10-15) | **kein Feld je Termin**; „Rosenthal-Theater Selb“ nur, wenn die Adresse des Termins die Theateradresse ist (Abschnitt 4) | **ja**: „Adresse:“ im Kasten „Auf einen Blick“ der Detailseite (30/30) | nur in 3 Adressen („95100 Selb“), sonst über die Theateradresse | „Veranstalter:“ (Liste und Detail) – nicht gespeichert | nein | nur im Langtext einzelner Termine – nicht gespeichert | Adresse ja |

In den **gespeicherten** Daten (Titel, Beschreibung, Ort) kommen „Treffpunkt“, „Tel.“, „Telefon“, „Anmeldung“,
„Ansprechpartner“, „Kontakt“, „Karten“, „Tickets“ und „Teilnehmer“ **0-mal** vor. Die Beispiele des Kunden
(„Kellerführung … Treffpunkt: Rathaus …“, „Rock Rising … Freiheitshalle“) stammen aus den manuell gepflegten Daten
von WP Kulmbacher Land, nicht aus einer angebundenen Quelle.

Folgen (umgesetzt bzw. bewusst nicht umgesetzt):

- **Coburg:** Adresse wird seit Schema-Version 3 als `venue_address` gespeichert (siehe Abschnitt 2).
- **Bad Steben:** Das Listen-Mapping ist **quellentreu**: „Bayerisches Staatsbad Bad Steben GmbH“ und „Therme Bad
  Steben - Mit allen Sinnen genießen“ sind auch auf der Detailseite der **Ortsname** (`location.name`), nicht der
  Veranstalter (der steht separat als `author`). Es gibt also nichts zu korrigieren. Adressen gäbe es nur über die
  Detailseiten: **ca. 88 zusätzliche Requests je Crawl** (eine je Serie, Seite ca. 230 KB; bei 1 s Pause ca. 2–3 Minuten
  statt heute ca. 2 s). Nicht umgesetzt, Entscheidung beim Auftraggeber.
- **Coburg-Detailseiten** (Veranstalter/Ansprechpartner, Telefon, Endzeit): **eine Detailseite je Termin, ca. 335–365
  zusätzliche Requests je Crawl** (bei ~1,7 s je Seite + 1 s Pause ca. 15–16 Minuten statt heute ~46 s; überschreitet
  das Limit von 120 s je Request). Für den MVP unverhältnismäßig, nicht umgesetzt.
- **Burg Rabenstein:** Keine Adresse je Termin. „Rabenstein 33“ wird **nicht** hartcodiert. Möglich wäre eine
  bewusste, quellenspezifische feste Venue-Adresse im Adapter (wie heute schon `venue_name`/`city`), wenn der
  Auftraggeber das so entscheidet (todo.md). Treffpunkt/Telefon stehen nur in der Langbeschreibung; sie zu übernehmen
  hieße, statt des Teasers die (viel längere) Langbeschreibung zu speichern – ebenfalls Kundenentscheidung.

---

## 1. Bad Steben

| Eigenschaft | Wert |
|---|---|
| Name | Bad Steben (Anzeige im Admin: „Bad Steben“) |
| Slug (`source`) | **`bad-steben`** (final) |
| Ursprungs-URL | https://www.bad-steben.de/erlebnis/veranstaltungskalender.html (HTTP 200, keine Weiterleitung; WordPress-Seite, **enthält selbst keine Events**) |
| **Crawl-URL** | **https://pages.et4.de/de/bad-steben/default/search/Event/view:gallery/sort:chronological/calView:agendaWeek/Offset:{n}/Limit:250** |
| Status | **funktioniert** (live getestet am 2026-09-24) |
| Plattform | Die Kalenderseite bettet per Skript/iframe **eT4 „pages“ von destination.one** ein (`pages.et4.de`, Server nginx). Diese Plattform liefert die Trefferliste als **serverseitig gerendertes HTML** |
| Abrufmethode | `wp_remote_get` auf die HTML-Trefferliste, seitenweise (Limit 250, Timeout 30 s, 1 s Pause zwischen Seiten, max. 20 Seiten). Aktuell 3 Requests pro Crawl. Kein JavaScript, keine Browserautomation |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Bad_Steben` |
| Detailseite vorhanden | **ja, aber je Serie**, nicht je Termin: `/de/bad-steben/default/detail/Event/{e_ID}/{slug}` (der Parameter `?From=…` unterscheidet Termine) |
| externe ID | **ja, je Serie**: `data-guid="e_100052347"` (destination.one-ID). Die ICS-Funktion wurde nicht benötigt |
| Matching-Methode | **Priorität 1: `external_id` = `{e_ID}_{Datum}_{n}`**, n = laufende Nummer der Serie an diesem Tag nach Beginn (z. B. `e_100052347_2026-09-25_2`) |
| Letzter technischer Test | 2026-09-24: 560 Termine live, 557 gespeichert, 3 fehlerhaft (ohne Stadt); zweiter Crawl 557 unverändert, keine Duplikate |

### Technische Analyse (2026-09-24)

| Punkt | Ergebnis |
|---|---|
| robots.txt bad-steben.de | erlaubt alles außer `/wp-content/uploads/media-from-ftp-tmp/` (Yoast-Block) |
| robots.txt pages.et4.de | sperrt nur fremde Magazine (`/de/outdoor-magazin/` usw.), **nicht** `/de/bad-steben/` |
| Zeichencodierung | UTF-8 (beide Hosts) |
| Einbindung | `…/search/Event/…?i_target=et4pages` liefert JavaScript (iFrame-Resizer), das ein iframe auf dieselbe URL **ohne** `i_target` lädt. Diese liefert HTML |
| JSON-LD | auf der Trefferliste nur allgemeine Seitenangaben, **keine Event-Daten** |
| Zeitraum | Standard der Plattform: **ab jetzt für 3 Monate** (`mode: next_months`, `From`/`Until` in `window.PARAMS`). Bereits begonnene Termine des heutigen Tages fehlen |
| Paginierung | im Browser „Endlos-Scrollen“: `application.js` hängt `/Offset:{n}` und `/Limit:{n}` an die URL (**Groß-O und Groß-L**; `/offset:20` klein liefert 0 Treffer). Der Adapter ruft Seiten ab, bis eine Seite weniger als 250 Treffer hat |
| Umfang | 560 Termine aus 88 Serien (24.09.–24.12.2026); mit Limit 500 dauerte eine Seite ca. 11 s, deshalb Limit 250 |
| Beschreibung | in der Trefferliste **keine**. `description` bleibt leer. Detailseiten werden nicht abgerufen (wären 88+ zusätzliche Requests) |

### Verwendete Selektoren (am echten HTML verifiziert)

| Feld | Quelle im HTML | Beispiel |
|---|---|---|
| Termin-Container | `div.media[data-guid]` (`data-type="Event"`) | 560 Treffer |
| Serien-ID | `@data-guid` | `e_101344903` |
| Titel | `h4.media-heading` | `Meisterkonzert Christoph Prégardien (Tenor) und Yaara Tal (Klavier)` |
| Ort, Stadt | `p.result_city > span` (1. = Ort, 2. = Stadt) | `Musikbegegnungsstätte Haus Marteau`, `Lichtenberg` |
| Beginn | `div.date-interval/@data-start` | `2026-09-24T19:00:00+02` (lokale Zeit) |
| Ende | `div.date-interval/@data-end`, **nur** wenn der sichtbare Text einen Bereich zeigt | Text `Heute 19:00 - 20:30` |
| Detail-Link | `h4.media-heading > a/@href`, ohne Query | `https://pages.et4.de/de/bad-steben/default/detail/Event/e_101344903/meisterkonzert-…` |

### Datum, Uhrzeit, Ganztag

| Feld | Regel | Ergebnis (560 Termine) |
|---|---|---|
| `start_date`, `start_time` | aus `data-start`; die Uhrzeit im sichtbaren Text muss übereinstimmen, sonst „fehlerhaft“ | 560 / 560 |
| `end_time`, `end_date` | aus `data-end`, **nur** wenn der Text „HH:MM - HH:MM“ zeigt. Ohne sichtbares Ende steht in `data-end` immer **23:59**. Das ist ein Platzhalter und wird **nicht** übernommen | 544 mit Ende (in der DB 541, weil 3 fehlerhafte Termine ein Ende hatten), 16 ohne |
| mehrtägig | `data-end`-Datum > `data-start`-Datum | 0 |
| über Mitternacht | Ende ≤ Beginn | 0 |
| `all_day` | Die Quelle kennzeichnet keine Ganztagstermine; alle haben eine Uhrzeit | 0 |

### Veranstaltungsort und Stadt

- `venue_name` = erster Span, unverändert. Die Quelle trägt oft den **Veranstalter** statt eines Raums ein:
  „Bayerisches Staatsbad Bad Steben GmbH“ (233 Termine), „Therme Bad Steben - Mit allen Sinnen genießen“ (209).
  Übernommen wird, was die Quelle liefert; nichts wird umgeschrieben oder aus dem Titel abgeleitet.
- `city` = zweiter Span. **Der Kalender enthält nicht nur Bad Steben**: Stand 2026-09-24 534 × Bad Steben,
  10 × Hof, 8 × Lichtenberg, 3 × Hirschberg, 1 × Münchberg, 1 × Berg. Gespeichert wird die **echte Stadt**, nicht
  pauschal „Bad Steben“. Ob Nachbarorte übernommen werden sollen, ist eine offene Frage an den Auftraggeber (todo.md).
  Technisch wäre eine Beschränkung über `get_allowed_cities()` möglich (05-filter.md).
- Zusätze in Klammern entfallen: „Hof (95028 Hof/Saale)“ → „Hof“, „Bad Steben (95138 Bad Steben)“ → „Bad Steben“,
  „Bad Steben (Bobengrün)“ → „Bad Steben“, „Berg (Eisenbühl)“ → „Berg“.
- Stadtfeld leer → Stadt nur aus einer Postanschrift im Ortsfeld („…, 95138 Bad Steben“ → „Bad Steben“, 1 Termin).
  Sonst bleibt sie leer, und der Termin wird **übersprungen** („fehlerhaft“): 3 Termine in der „Wehrkirche St. Walburga“
  ohne jede Ortsangabe.

### Links

- `event_url` = `source_url` = Detailseite der Serie **ohne** `?From=…`. Der Parameter enthält für heutige Termine die
  Abrufzeit statt der Terminzeit und würde sonst bei jedem Crawl ein UPDATE auslösen.
- Die Detailseite ohne `From` zeigt die Serie, nicht zwingend den konkreten Termin.
- Die Detail-URL ist **nicht** termin-eindeutig (`has_unique_detail_urls() = false`). Matching deshalb über `external_id`.

### Matching und Wiedererkennung (verifiziert)

- Die `data-guid` bezeichnet die **Serie**: Kurkonzert am Vormittag 78 Termine, Aqua Fit bis zu 2 Termine am Tag.
  **92** Kombinationen aus Serie und Tag haben mehrere Termine. Eine reine Serien-ID als `external_id` würde der
  UNIQUE-Index ablehnen, und Serie + Datum wäre nicht eindeutig.
- Deshalb `external_id = {e_ID}_{JJJJ-MM-TT}_{n}`: eindeutig bei allen 557 gespeicherten Terminen (getestet).
- Auch der event_key (`source|title|city|start_date`) ist hier **nicht eindeutig** (92 Doppelungen). Der Fallback wäre
  nutzbar (siehe unten), die echte ID ist aber vorzuziehen.
- Verhalten, getestet mit veränderten Testdaten:

| Fall | Ergebnis |
|---|---|
| A: gleicher Titel, gleiche Stadt, anderes Datum (Serie) | getrennte Datensätze |
| B: gleiche Serie, gleicher Tag, Uhrzeit geändert (Aqua Fit 16:00 → 17:00) | **derselbe Datensatz** aktualisiert (laufende Nummer bleibt) |
| Endzeit, Ort, Link geändert | derselbe Datensatz aktualisiert |
| C: Termin auf einen anderen Tag verschoben (Casino LIVE 26.09. → 27.09.) | **neuer Datensatz**, der alte bleibt mit altem `last_seen_at` stehen (bekannte Grenze, wie bei jeder datumsbezogenen Kennung) |

- Grenzfall: Kommt an einem Tag ein **früherer** zusätzlicher Termin derselben Serie hinzu, verschieben sich die
  laufenden Nummern. Die Datensätze werden dann umgeschrieben statt dupliziert. Die Daten stimmen danach wieder, nur
  die interne Zuordnung Datensatz ↔ Termin wechselt.
- **Vorschlag für Grenzfall C** (nicht umgesetzt): Termine, die im aktuellen Lauf nicht mehr gesehen wurden und deren
  Serie (`e_ID`) im selben Lauf einen neuen Termin bekam, könnten als „vermutlich verschoben“ markiert werden. Das
  bräuchte ein zusätzliches Feld oder eine Prüfung im Crawler und wird erst bei Bedarf umgesetzt.

### Filter

- In den echten Daten gibt es keine Stammtische und keine politischen Veranstaltungen. 0 Termine gefiltert.
- Der Titel „Geführte Wanderung … entlang des **Grünen** Bandes“ wird korrekt **nicht** gefiltert, weil der Filter nur
  „Die Grünen“ als ganze Wortfolge prüft.
- Zentraler Filter mit echtem Datenweg geprüft: Ein zu „Musikerstammtisch am Feuerwehrhaus“ umbenannter Termin
  (Testdaten) wurde gefiltert. Der bestehende Datensatz blieb unverändert.

### Bekannte Einschränkungen

- Nur der Plattform-Standardzeitraum „nächste 3 Monate“. Spätere Termine kommen mit späteren Crawls hinzu.
- Keine Beschreibung (Trefferliste enthält keine).
- `venue_name` ist oft der Veranstalter (siehe oben).
- Nachbarorte sind enthalten (Hof, Lichtenberg, …), offene Frage an den Auftraggeber.
- 3 Termine ohne Stadtangabe werden übersprungen.
- Terminverschiebung auf einen anderen Tag erzeugt einen neuen Datensatz (Fall C).
- Abhängigkeit von eT4/destination.one: Ändert die Plattform das HTML, die Pfadsegmente oder den Zeitraum, meldet der
  Adapter einen Fehler, und die Daten bleiben unverändert.

## 2. Coburg

| Eigenschaft | Wert |
|---|---|
| Name | Coburg (Veranstaltungsportal der Stadt Coburg; Anzeige im Admin: „Coburg“) |
| Slug (`source`) | **`coburg`** (final) |
| Ausgangs-URL | https://veranstaltungen.coburg.de/alle-veranstaltungen.html (HTTP 200, keine Weiterleitung) |
| **Crawl-URL** | dieselbe als Startseite; danach die von der Seite verlinkten Folgeseiten `…/alle-veranstaltungen.html?tx_events2_events[controller]=Event&tx_events2_events[offsetdate]={Unix-Zeit}&cHash={…}` |
| Status | **funktioniert** (live getestet am 2026-09-24) |
| Plattform | **TYPO3** mit der Kalender-Extension **events2** (`tx_events2_events`), Server Apache, UTF-8 |
| Abrufmethode | `wp_remote_get` auf serverseitiges HTML; Folgeseiten über den Link `a.nextDay` (1 s Pause), bis `offsetdate` das Zeitfenster überschreitet; max. 25 Seiten |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Coburg` |
| Detailseite vorhanden | **ja, je Termin**: `/details/veranstaltung/{serien-slug}/datum/{termin-id}.html`. Wird **nicht** abgerufen |
| externe ID | Termin-ID (`datum/{n}`) und Serien-ID (`event`, nur auf der Detailseite) vorhanden; **Termin-ID nicht als `external_id` verwendet** (siehe Matching) |
| Matching-Methode | **Priorität 1: `external_id = {Serien-Slug}_{Datum}_{n}`**, n = laufende Nummer der Serie an diesem Tag nach Beginn (z. B. `pippi-langstrumpf-1_2026-10-11_2`) |
| Letzter technischer Test | 2026-09-24: 365 Termine live (24.09.–07.11.2026), 365 gespeichert, 0 gefiltert, 0 fehlerhaft; zweiter Crawl 365 unverändert |

### Technische Analyse (2026-09-24)

| Punkt | Ergebnis |
|---|---|
| robots.txt | sperrt `/typo3/`, `/typo3_src/` und einzelne SEO-Bots (SemrushBot, AhrefsBot, …); die Veranstaltungsseiten sind erlaubt |
| JSON-LD / schema.org | **nicht vorhanden** (nur `data-vocabulary.org/Breadcrumb`) |
| ICS/iCal | kein Export-Link gefunden |
| Paginierung | „Endlos-Scrollen“: je Seite ca. **3 Tage**. Die nächste Seite steht in `a.nextDay` (versteckt) mit `offsetdate` (Unix-Zeit = Mitternacht Europe/Berlin) und **`cHash`**. Der cHash wird von TYPO3 gebildet, deshalb folgt der Adapter den Links, statt URLs zu bauen |
| Zeitraum-Suche | Das Formular hat `search[startdate]`/`[enddate]`; auch damit kommen nur 3 Tage je Seite (geprüft). Kein Vorteil, nicht verwendet |
| Zeitfenster | **42 Tage ab heute** (`WINDOW_DAYS`); am 2026-09-24 **15 Seiten** (~1,7 s je Seite + 1 s Pause, Coburg allein ~47 s) |
| Tagesüberschrift | `span.datumaufecke` **ohne Jahr** („Donnerstag, 24. September“). Das Jahr kommt aus dem Referenzdatum der Seite (heute bzw. `offsetdate`): gleiches Jahr, oder das Folgejahr bei kleinerem Monat. Der Wochentag muss passen, sonst gilt der Termin als fehlerhaft (`Eventcrawler_Source::resolve_german_date()`) |
| Veranstalter | steht nur auf der Detailseite (`div.organizer`), getrennt vom Veranstaltungsort. Wird nicht übernommen |

### Verwendete Selektoren (am echten HTML verifiziert)

| Feld | Quelle im HTML | Beispiel |
|---|---|---|
| Termin | `div.listviewItem` | 365 Treffer auf 15 Seiten |
| Datum | vorangehender `span.datumaufecke` + Jahr aus Referenzdatum | „Sonntag, 11. Oktober“ → 2026-10-11 |
| Uhrzeit | `div.time-blue` | „14:00 Uhr“ oder **„ganztägig“** |
| Titel | `h3` | „Pippi Langstrumpf“ |
| Kurzbeschreibung | `div.ym-gbox-right > p` (Untertitel) | „Figurentheater für die ganze Familie“ |
| Veranstaltungsort | `div.event-location small[strong="Veranstaltungsort"] > a` | „Kongresshaus Rosengarten“ |
| Stadt | Zeile „PLZ Stadt“ im selben Block | „96450 Coburg“ → „Coburg“ |
| Adresse (`venue_address`, seit 2026-10-02) | Zeile **zwischen** Ortsname (Link) und „PLZ Stadt“ im selben Block; nur bei genau diesem Aufbau (3 Zeilen), sonst leer | „Herrngasse 4“ |
| Link | `h3 > a/@href` | `/details/veranstaltung/pippi-langstrumpf-1/datum/106259.html` |

### Felder (Stand 2026-09-24, 365 Termine)

| Feld | Regel | Ergebnis |
|---|---|---|
| `start_date` | Tagesüberschrift + Jahr (siehe oben) | 365 |
| `start_time` | „HH:MM Uhr“ | 362 |
| `end_time`, `end_date` | Die **Liste enthält keine Endzeit**, nur die Detailseite. Ein Bereich „HH:MM - HH:MM Uhr“ würde übernommen, kam aber nicht vor. **Keine Platzhalter** (00:00/23:59) gefunden | 0 / 0 |
| `all_day` | **„ganztägig“** ist ausdrücklich gekennzeichnet → `all_day = 1`, keine Uhrzeit | 3 (z. B. Maus-Türöffner-Tag, Tag der Restaurierung) |
| mehrtägig | Mehrtägige Veranstaltungen (z. B. Food Truck Festival 25.–27.09.) listet die Quelle **je Tag als eigenen Termin** mit eigener Uhrzeit und Termin-ID. Der Zeitraum steht als Zusatz davor. Gespeichert wird **je Tag ein Termin** ohne künstliches `end_date` | – |
| `description` | Untertitel der Liste, sonst leer | 296 |
| `venue_name` | Link-Text im Block „Veranstaltungsort“ | 365 |
| `venue_address` (seit 2026-10-02) | Zeile zwischen Ortsname und „PLZ Stadt“, unverändert übernommen. Meist Straße + Hausnummer; die Quelle führt dort teils auch Ortsteile oder Beschreibungen („OT Mirsdorf“, „c/o Kunstsammlungen der Veste Coburg“, „Wiese zwischen COJE & Itzpark“) – so übernommen, nicht umgedeutet | Cache 341 / 365, live (2026-10-02) 321 / 335 |
| `city` | Zeile „PLZ Stadt“; Ortsteile entfernt („Bad Rodach-OT Elsa“ → „Bad Rodach“, „Seßlach-StT Dietersdorf“ → „Seßlach“, „Untersiemau-OT Meschenbach“ → „Untersiemau“); „Neustadt b. Coburg“ → „Neustadt bei Coburg“ | 365 |
| `event_url` = `source_url` | öffentliche Detailseite des Termins | 365 |

**Nicht nur Coburg:** Stand 2026-09-24 enthält die Quelle Coburg 271, Bad Rodach 51, Seßlach 20, Neustadt bei Coburg 7,
Rödental 6, Weitramsdorf 5, Meeder 3, Ahorn 1 und Untersiemau 1 Termin. Gespeichert wird die **echte Stadt**. Quelle
und geografischer Filter sind getrennte Konzepte (05-filter.md).

**Fragwürdige Ortsangaben der Quelle** (so übernommen, nicht umgedeutet): Einige Termine nennen als
„Veranstaltungsort“ eine Region oder Behörde, z. B. „Landkreis Coburg“ (PLZ 96450 Coburg) bei einer Wanderung
„von Rauenstein zum Rennsteig“ oder „Region Coburg / Südthüringen“. Die Stadt stammt dann aus dieser Anschrift und
ist nicht zwingend der tatsächliche Ort. Offene Frage an den Auftraggeber (todo.md).

### Identität und Matching (verifiziert)

- **Termin-ID** (`datum/{n}` in der URL; im Detailformular `eventdate`): eindeutig bei allen 365 Terminen.
  **Serien-ID**: `event` (z. B. 23088), nur auf der Detailseite. In der Liste ist die Serie über den **Slug**
  erkennbar (`die-historische-innenstadt-von-coburg-1`).
- 169 Serien, 44 davon mit mehreren Terminen. **6 Serien haben mehrere Termine am selben Tag** (z. B. Pippi
  Langstrumpf 14:00 und 16:00). Gleicher Titel am selben Tag in **verschiedenen** Serien kommt auch vor
  („Die historische Innenstadt von Coburg“ 26.09. 11:00 `…-coburg-1` und 14:00 `…-coburg-11`).
  Der event_key wäre 14-mal doppelt.
- **Warum nicht die Termin-ID als `external_id`:** Die Termin-IDs einer Serie sind fortlaufend (z. B. 104359–104395).
  Das deutet darauf hin, dass events2 sie beim Speichern einer Serie neu vergibt. Belegen lässt sich das ohne eine
  echte Änderung an der Quelle nicht. Würden sie neu vergeben, entstünden bei jeder Bearbeitung einer Serie Duplikate.
  Deshalb `external_id = {Serien-Slug}_{Datum}_{n}`: unabhängig von der Termin-ID. Eine geänderte Termin-ID ändert nur
  `event_url`/`source_url` desselben Datensatzes (getestet).
- Der Slug bleibt stabil, solange die Redaktion ihn nicht neu erzeugt (in TYPO3 nicht automatisch bei Titeländerung).
  Ein geänderter Slug würde einen neuen Datensatz ergeben (nicht verifizierbar, Grenzfall).

Getestet mit veränderten Kopien der echten Seiten (nur Coburg gecrawlt):

| Änderung | Ergebnis |
|---|---|
| Titel („Kidical Mass Coburg“ → „… – Familienradtour“) | derselbe Datensatz aktualisiert |
| Startzeit (Pippi 16:00 → 17:00, zweite Vorstellung des Tages) | derselbe Datensatz |
| Endzeit („20:00 Uhr“ → „20:00 - 22:30 Uhr“, Urban Priol) | derselbe Datensatz, `end_time` 22:30, `end_date` = Starttag |
| Veranstaltungsort (Una Festa Italiana) | derselbe Datensatz |
| Stadt (Solowegge, „96450 Coburg“ → „96472 Rödental“) | derselbe Datensatz |
| Termin-ID in der URL (`datum/108684` → `datum/999999`, Andrei Gologan) | derselbe Datensatz, nur `event_url`/`source_url` neu |
| **Datum** (How To Date A Feminist 25.09. → 26.09.) | **neuer Datensatz**, der alte bleibt mit altem `last_seen_at` stehen (bekannte Grenze, wie bei Bad Steben) |
| Titel „Öffentliche Stadtratssitzung“ | zentral **gefiltert**, bestehender Datensatz unverändert |
| Wiederholung mit denselben Daten | 0 neu, 0 aktualisiert |

### Filter

- In den echten Daten (365 Termine) gibt es **keine** Stammtische, Stadtratssitzungen, Ausschüsse,
  Bürgerversammlungen oder Parteiveranstaltungen. 0 gefiltert.
- Der zentrale Filter greift auch für Coburg (Test: „Öffentliche Stadtratssitzung“ gefiltert). Der Adapter enthält
  keine Filterlogik.

### Nicht gespeicherte Daten der Quelle

PLZ (Straße seit 2026-10-02 als `venue_address`), Veranstalter/Ansprechpartner und Telefon (Detailseite), Kategorien, Bilder, Zeitraum-Zusatz mehrtägiger Veranstaltungen,
Endzeit (nur Detailseite), Preise/Eintritt (Detailseite), Barrierefreiheit/Familienfreundlichkeit (Suchfilter).

### Bekannte Einschränkungen

- Nur **42 Tage** im Voraus (15 Seitenabrufe). Mehr würde den Crawl stark verlängern, weil die Plattform nur 3 Tage je
  Seite liefert. Spätere Termine kommen mit späteren Crawls hinzu.
- Keine Endzeiten (nur auf Detailseiten; 365 Detailabrufe je Crawl wären unverhältnismäßig).
- Datumsverschiebung ergibt einen neuen Datensatz.
- Umlandorte sind enthalten; teils nennt die Quelle Regionen statt Orten.
- Abhängigkeit von TYPO3/events2-Markup und vom Link `a.nextDay`. Ändert sich das, meldet der Adapter einen Fehler, und
  die Daten bleiben unverändert (getestet: Netzwerkfehler, HTTP 500, kaputte Struktur).
- Möglicher quellenübergreifender Doppelbestand mit einer späteren Quelle „Neustadt bei Coburg“.

## 3. Burg Rabenstein / Ahorntal – Proof of Concept

| Eigenschaft | Wert |
|---|---|
| Name | Burg Rabenstein / Ahorntal |
| Slug (`source`) | **`burg-rabenstein`** (final) |
| Ursprungs-URL | https://www.burg-rabenstein.de/events (Themenseite mit Kategorien, HTTP 200; wird nicht gecrawlt) |
| **Crawl-URL** | **https://www.burg-rabenstein.de/events/alle-events-im-ueberblick** |
| Status | **funktioniert** (live getestet am 2026-09-24) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Burg_Rabenstein` |
| Detailseite vorhanden | **ja**, je Termin eine eigene URL. Wird **nicht** abgerufen, weil die Übersicht alle benötigten Daten enthält |
| externe ID bekannt | **nein**, keine unabhängige ID. Die ICS-UID der Detailseite lautet `calendarize-<url-slug>`, ist also nur der URL-Slug |
| Matching-Methode | **Priorität 2: `source` + `source_url` (Detail-URL)** (`has_unique_detail_urls() = true`); `event_url` = dieselbe Seite |
| Letzter technischer Test | 2026-09-24 (nach Schema-Version 2): 124 Events live, 105 mit Endzeit gespeichert, zweiter Crawl 124 unverändert (siehe 03-crawler.md Abschnitt 11) |

### Technische Analyse (2026-09-24)

| Punkt | Ergebnis |
|---|---|
| robots.txt | nicht vorhanden (HTTP 404, TYPO3-Fehlerseite), also keine Einschränkungen |
| Erreichbarkeit | HTTP/2 200, keine Weiterleitung, Server Apache |
| Zeichencodierung | UTF-8 (`Content-Type: text/html; charset=utf-8`, `<meta charset="utf-8">`) |
| System | TYPO3 mit der Kalender-Erweiterung **calendarize** (`tx_calendarize_calendar`) |
| Größe | ca. 550 KB, alle Termine serverseitig in **einem** HTML (ca. 12 Monate im Voraus) |
| Paginierung / Lazy Loading | **keine**. Die Kategorie- und Datumsfilter der Seite arbeiten clientseitig per JavaScript auf demselben HTML |
| JSON-LD / schema.org | **nicht vorhanden**, weder auf der Übersicht noch auf der Detailseite |
| Datenquelle | stabile HTML-Struktur mit strukturierten `data-`-Attributen (Priorität 2 der Vorgaben, da es kein JSON-LD gibt) |
| Monatsüberschriften | `div.single-news.divider.datecontainer > article > h2` („September 2026“); werden ignoriert, weil das Jahr im Datumsattribut steht |

### Verwendete Selektoren (am echten HTML verifiziert)

| Feld | Quelle im HTML | Beispiel |
|---|---|---|
| Event-Container | `div` mit den Klassen `single-news` **und** `event` | 124 Treffer |
| Datum | Attribut `data-startDate` (`TT.MM.JJJJ`); zusätzlich `data-endDate` | `24.09.2026` |
| Titel | `h3.event-headline` (Textinhalt, teils mit `<mark>`) | `Wildschwein-Grillbuffet` |
| Start-/Endzeit | `span.date`: `Wochentag - TT.MM.JJJJ HH:MM[ - HH:MM]`. Das Datum im Text muss `data-startDate` entsprechen, sonst wird der Eintrag übersprungen | `Donnerstag - 24.09.2026 18:00 - 20:30` |
| Kurzbeschreibung | erster `div.content > p`, der nicht mit „Preis:“ beginnt; endet auf `[...]`, das entfernt wird | `Wildschwein vom Spieß mit üppigem Salatbuffet, …` |
| Preis | `div.content > p` mit `<strong>Preis:</strong>` | `Preis: 49,00 €` |
| Detail-Link | `a.stretched-link[href]` (relativ, wird absolut gemacht) | `/events/alle-events-im-ueberblick/wildschwein-grillbuffet-1-20260924` |
| Bild | `img.img-fluid[src]` | `/fileadmin/_processed_/…/csm_Wildschwein2024_….jpg` |
| Kategorie | Attribut `data-themen` (z. B. `c25`, `c7`) | – |
| Langbeschreibung | Attribut `data-title`, enthält Titel und kompletten Beschreibungs-HTML (entity-kodiert) | – |

### Ort

- Es gibt **keine** strukturierte Ortsangabe pro Event. Footer und Impressum nennen den Betreiber
  „Burg Rabenstein Event GmbH, Rabenstein 33, 95491 Ahorntal“.
- Gespeichert wird deshalb für alle Events: **venue_name = „Burg Rabenstein“, city = „Ahorntal“**.
- Laut Beschreibung finden einige Events in Räumen der Burg statt (Rittersäle, Prunksaal, Kaminzimmer, Waffensaal,
  Beletage). Höhlen-Events beziehen die **Sophienhöhle** mit ein, die ebenfalls zum „Naturparadies Burg Rabenstein“
  in Ahorntal gehört. Das steht nur im Fließtext und wird im MVP nicht ausgewertet. Die Stadt ist in allen Fällen Ahorntal.

### Beginn, Ende, Ganztag, Links (seit Schema-Version 2 gespeichert)

Geprüft am 2026-09-24 gegen die lokale HTML-Kopie und live. Nichts wird aus Titeln geraten:

| Feld | Quelle im HTML | Ergebnis (124 Termine) |
|---|---|---|
| `start_date` | `data-startDate` | 124 |
| `start_time` | `span.date`, erste Uhrzeit | 124 |
| `end_time` | `span.date`, zweite Uhrzeit („18:00 - 20:30“) | **105** (in der Doku von Phase 3 stand fälschlich 104) |
| `end_date` | `data-endDate`, **nur** gesetzt, wenn es eine Endzeit gibt oder `data-endDate` nach dem Startdatum liegt | 105, alle = `start_date` |
| `all_day` | Die Quelle kennzeichnet keine ganztägigen Termine (keine Texte wie „ganztägig“, alle haben eine Uhrzeit) | 0 × ganztägig |
| mehrtägig | `data-startDate` ≠ `data-endDate` | 0 |
| über Mitternacht | Endzeit ≤ Startzeit | 0 |
| `event_url` | öffentliche Detailseite (`a.stretched-link`) | 124, identisch mit `source_url` |
| `source_url` | dieselbe Detailseite, Grundlage des Matchings | 124 |

- Bei 19 Terminen ohne Endzeit (z. B. Gruseldinner) steht `data-endDate` = Startdatum. Das wird **nicht** als
  Endinformation gewertet: `end_date` und `end_time` bleiben NULL.
- Würde die Quelle ein späteres `data-endDate` liefern, übernimmt der Adapter es als `end_date`. Bei einer Endzeit am
  Folgetag ist das Ende damit eindeutig. Kam bisher nicht vor; getestet mit veränderten Testdaten.
- `data-endDate` vor `data-startDate` → Eintrag wird übersprungen („fehlerhaft“).

### Daten der Quelle, die im MVP nicht gespeichert werden

| Wert | Verfügbarkeit | Stand |
|---|---|---|
| Preis | bei allen Terminen, Freitext („49,00 €“, „12,00 € im Vorverkauf, 14,00 € an der Tageskasse“) | nicht gespeichert |
| Bild | bei allen Terminen | nicht gespeichert |
| Ticket-URL | Detailseite bindet ein pretix-Widget ein (`tickets.burg-rabenstein.de`) | nicht gespeichert, nicht abgerufen; `event_url` ist die Detailseite |
| Kategorie | `data-themen` (Codes wie `c25`) | nicht gespeichert |
| Langbeschreibung | `data-title` bzw. Detailseite | nicht gespeichert; gespeichert wird der Teaser |

### Stabilität der URL und bekannte Einschränkungen

- Stand 2026-09-24: 124 Termine mit **124 verschiedenen** Detail-URLs. Jeder Termin einer Serie hat seine eigene URL,
  z. B. `wildschwein-grillbuffet-1-20260924`, `…-20261015`.
- Die URLs enthalten **meist das Datum** (`-JJJJMMTT`); bei 2 von 124 fehlt es (z. B. `burgkonzert-someday-chopin-will-swing`).
  Der Datumsteil ist also Teil eines von calendarize erzeugten Slugs und kein festes Schema.
- **Offener Matching-Grenzfall:** Ob der Slug bei einer **Terminverschiebung** gleich bleibt, lässt sich ohne eine
  echte Änderung an der Quelle nicht feststellen. calendarize erzeugt die Slugs der Termine aus Titel und Datum,
  deshalb ist eine neue URL wahrscheinlich. Dann entsteht ein **neuer Datensatz**, und der alte bleibt mit veraltetem
  `last_seen_at` stehen. Bleibt die URL gleich, wird der bestehende Datensatz aktualisiert. Das ist mit Testdaten
  nachgewiesen.
- Titel ändern die URL vermutlich ebenfalls. Auch das ist nicht verifizierbar und bleibt als Grenzfall offen.
- Wiederkehrende Termine (z. B. Wildschwein-Grillbuffet, 17 Termine) sind durch die eindeutigen URLs sauber getrennt.
- Die Quelle hat teils Tippfehler bzw. Dubletten im Titel (z. B. „Adventskonzert zum Dinner mit Wulli & Sonja“ und
  „Adventskonzert zum Dinnermit Wulli und Sonja“ am selben Abend mit verschiedenen URLs). Die Einträge werden so
  übernommen, wie die Quelle sie liefert.
- Der Titel enthält teils Zusätze wie „- im großen Burgsaal“ oder „2026“. Er wird unverändert übernommen.
- Die Kurzbeschreibung ist der von der Quelle gekürzte Teaser (mit „[...]“ gekürzt, das entfernt wird).
- Ändert sich das HTML-Layout, liefert der Adapter einen Fehler („Seitenstruktur geändert“), und die Daten bleiben
  unverändert.

## 4. Theater Selb

| Eigenschaft | Wert |
|---|---|
| Name | Theater Selb (Website des Rosenthal-Theaters Selb; Anzeige im Admin: „Theater Selb“) |
| Slug (`source`) | **`theater-selb`** |
| Ausgangs-URL | https://www.theater-selb.de/ (Startseite, zeigt nur die nächsten 3 Termine; wird nicht gecrawlt) |
| **Crawl-URL** | **https://www.theater-selb.de/spielplan/** (Menü „Programm → Ticketshop“) + je Termin die verlinkte Detailseite |
| Status | **funktioniert** (live getestet am 2026-10-15) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Theater_Selb` |
| Abrufmethode | `wp_remote_get`: 1 × Liste + **1 Detailseite je Termin** (1 s Pause vor jeder Detailseite), nacheinander. Am 2026-10-15: **31 Requests**, live **45,6–46,3 s** |
| Matching-Methode | **Priorität 1: `external_id` = WordPress-Post-ID** des Termins (z. B. `53060`) |
| Letzter technischer Test | 2026-10-15: 30 Termine live, 29 gespeichert, 1 gefiltert, 0 fehlerhaft; zweiter Crawl 29 unverändert |
| Plattform | **WordPress** (Theme `gmkchildtheme`, Page Builder SiteOrigin, Widget `gmk-theater`), Apache, UTF-8. Keine eingebettete Fremdplattform; Tickets verlinken auf okticket.de bzw. Veranstalterseiten |
| Detailseite vorhanden | **ja, je Termin** ein eigener WordPress-Beitrag (Kategorie „veranstaltungen“) |
| externe ID | **ja, je Termin**: WordPress-Post-ID (`article#post-53060`) auf der Detailseite |

### Technische Analyse (2026-10-15)

Analysiert mit 37 lesenden GETs (Startseite, robots.txt, Spielplan, „Spielplan & Ausstellungen“, 2 × REST, 31 Detailseiten
inkl. einer Wiederholung), alles lokal zwischengespeichert; die Parsertests laufen gegen diese Kopie.

| Punkt | Ergebnis |
|---|---|
| robots.txt | `Allow: /` für alle, Sitemap per Yoast |
| Startseite | „In kürze im Theater“: nur die nächsten **3** Termine, Link „Gesamter Spielplan“ → `/spielplan/` |
| `/spielplan/` („Ticketshop“) | **alle kommenden Termine** serverseitig in einem HTML (ca. 125 KB): `div.gmk-theater-single` je Termin mit Datum, Titel, Genre, Beginn, Veranstalter, Detail-Link, optional Ticket-Link und Badge. Am 2026-10-15: **30 Termine** vom 17.10.2026 bis 25.09.2027. Enthält auch Termine **ohne** Ticket-Link (Heimatbühne, Musikschule) |
| Vollständigkeit | Die REST-API meldet für die Kategorie „veranstaltungen“ (ID 4) genau **30** Beiträge (`X-WP-Total: 30`), alle 30 stehen im Spielplan. Vergangene Termine verlassen die Kategorie (Menü „Archiv“). Die Liste ist also vollständig und enthält nur kommende Termine |
| Paginierung / „mehr laden“ / AJAX | **keine** |
| Saison- / Monatsseiten | keine; die Liste reicht über die ganze Spielzeit 2026/27 (plus ein Nachholtermin im September 2027) |
| JSON-LD | nur Yoast-Seitenangaben (`WebPage`, `BreadcrumbList`), **keine Event-Daten** |
| REST-API (`/wp-json/wp/v2/posts`) | liefert Titel, Slug, Link, Inhalt, Kategorie – **aber kein Datum, keine Uhrzeit, keine Adresse** der Veranstaltung (Theme-Felder sind nicht freigegeben). Nicht verwendet |
| RSS (`/feed/`), iCal | RSS nur Beiträge ohne Termindaten; kein iCal-Export gefunden |
| Detailseite `/{slug}/` | Kasten „Auf einen Blick“ (`div.single-play-infos-overview-item`): **Datum** („05.11.2026“), **Beginn** („19:30“), **Adresse**, **Genre**, **Veranstalter**; Untertitel `div.single-post-main-text`; Langtext „Veranstaltung Beschreibung“; Preis „Tickets ab“; Ticket-Link |
| „Spielplan & Ausstellungen“ | PDF-Jahresprogramm und Ausstellungen nur als **Fließtext** („Vernissage am Sonntag, den 20.09.2026 um 16.00 Uhr, Dauer: 20. September bis 01. November 2026“), keine strukturierten Termine → **nicht gecrawlt** |

### Datenlage (30 Termine, 2026-10-15)

| Feld | Liste | Detailseite | Befund |
|---|---|---|---|
| Datum | „Samstag, 17. Oktober 2026“ (mit Jahr) | „17.10.2026“ | 30/30, Liste = Detail |
| Beginn | „Beginn: 19:30“ | „Beginn: 19:30“ | 30/30, Liste = Detail. **Kein Einlass**, keine Endzeit, keine Dauer, keine Pause angegeben |
| Titel | `a.gmk-theater-single-title` | `h1` | identisch |
| Untertitel | – | `div.single-post-main-text` | 25/30, kurz (z. B. „Revueoperette von Franz Lehár und Leo Stein“) |
| Genre | „Genre: Konzert“ | ebenso | Kategorie, teils unpassend („Konzert“ bei Operette/Show), einmal „Mehr ...“ |
| Veranstalter | „Veranstalter: …“ | ebenso | meist „Stadt Selb - Kulturamt“ |
| Adresse | – | „Adresse: …“ | 30/30 **Hohenberger Straße 9** in vier Schreibweisen: „Hohenberger Straße 9“ (23), „Hohenberger Strasse 9“ (4), „Hohenbergerstrasse 9 95100 Selb“ (2), „Hohenberger Strasse 9 95100 Selb“ (1) |
| Spielstätte (Name) | – | – | **nicht je Termin vorhanden.** Der Seitenfooter nennt „Rosenthal-Theater Selb, Hohenberger Str. 9, 95100 Selb“. Räume (z. B. „kleiner Saal“) nur im Fließtext |
| Stadt | – | nur in 3 Adressen („95100 Selb“) | nicht als eigenes Feld |
| Badge | `gmk-theater-single-badges` | ebenso | „AUSVERKAUFT“ (1), „ABGESAGT“ (1), „NEU“ (1) |
| Ticket-Link | „Tickets Kaufen“ | ebenso | 22/30 (21 × okticket.de, 1 × Veranstalterseite) |
| Post-ID | – | `article#post-{ID}`, Shortlink `?p={ID}` | 30 verschiedene IDs |

Identität (belegt):
- Jede Aufführung ist ein **eigener Beitrag** mit eigener URL und ID, auch bei derselben Produktion
  („Zuckerguss & Kräuterkekse“ am 07.11. = Post 53299, am 28.11. = Post 53302 mit Slug `…-2`).
- Bei Verschiebungen bearbeitet die Redaktion den **bestehenden** Beitrag: Post 52794 hat noch den alten Slug
  `lissi-herr-timpe-veronika-advent-ist-da`, heißt heute „Lissi & Herr Timpe: „Ich liebe das Leben!“ (Nachholtermin)“
  und liegt am 25.09.2027 (verschoben vom 05.12.2025). Post-ID und URL blieben bei Titel- und Datumsänderung gleich.
- Absagen/Verschiebungen stehen als Badge („ABGESAGT“) bzw. im Titel/Untertitel („(Nachholtermin)“, „Die Veranstaltung
  wurde VERSCHOBEN! Nachholtermin vom 27.10.2026“).

### Warum Detailseiten (30 zusätzliche Requests)

Die Liste allein enthält **weder Spielstätte noch Stadt noch Adresse**. Ohne Detailseiten müsste der Adapter Ort und
Stadt für jeden Termin pauschal setzen, ohne Beleg je Termin. Die Detailseite liefert mit „Adresse:“ das einzige
strukturierte Ortsfeld je Termin, dazu den kurzen Untertitel (Beschreibung) und die Post-ID (stabile Identität).
Kosten: ca. 0,25 s je Seite + 1 s Pause → ca. 46 s für 30 Termine (Limit 120 s je Quelle). Die REST-API ist kein
Ersatz (keine Termindaten, siehe oben).

### Verwendete Selektoren (am echten HTML verifiziert)

| Feld | Quelle im HTML | Beispiel |
|---|---|---|
| Termin | `div.gmk-theater-single` (Liste) | 30 Treffer |
| Datum | `div.gmk-theater-single-date` „Wochentag, TT. Monat JJJJ“ (Jahr aus der Quelle, Wochentag wird geprüft, `resolve_german_date()`); muss dem „Datum:“ der Detailseite entsprechen | „Donnerstag, 05. November 2026“ → 2026-11-05 |
| Beginn | Liste: `p` mit `span` „Beginn: “ + `span` Wert; muss dem „Beginn:“ der Detailseite entsprechen | „19:30“ |
| Titel | `a.gmk-theater-single-title` (Liste), unverändert | „Theater Hof in Selb: „Evviva Mamma!““ |
| Detail-Link | `a.gmk-theater-single-title/@href` | `https://www.theater-selb.de/theater-hof-in-selb-evviva-mamma/` |
| Post-ID | Detailseite `article/@id` = `post-{ID}` | `53060` |
| Beschreibung | Detailseite `div.single-post-main-text` (Untertitel), sonst leer | „Sitten und Unsitten am Theater – Komische Oper von Geatano Donizetti“ |
| Adresse | Detailseite `div.single-play-infos-overview-item` mit `p` „Adresse: “ + `span` | „Hohenberger Straße 9“ |

### Felder (Stand 2026-10-15, 30 Termine)

| Feld | Regel | Ergebnis |
|---|---|---|
| `title` | Listentitel, unverändert (keine Kürzung, kein Zusatz „Theater Selb – …“) | 30 |
| `description` | Untertitel der Detailseite, unverändert (auch „Die Veranstaltung wurde VERSCHOBEN! …“). Genre, Veranstalter, Langtext, Preis werden **nicht** übernommen | 25 mit Text, 5 leer |
| `start_date` | Liste, mit Detailseite abgeglichen; Abweichung → Termin „fehlerhaft“ | 30 |
| `start_time` | nur die als **„Beginn“** bezeichnete Uhrzeit; „Einlass“ wird nie als Beginn verwendet (kommt derzeit nicht vor). Ohne „Beginn“ → NULL | 30 |
| `end_date`, `end_time` | Die Quelle nennt **kein Ende** und keine Dauer → NULL | 0 / 0 |
| `all_day` | Die Quelle kennzeichnet keine Ganztagstermine → 0 | 0 |
| `venue_address` | „Adresse:“ der Detailseite, **Schreibweise der Quelle**; eine angehängte „PLZ Stadt“ wird abgetrennt („Hohenbergerstrasse 9 95100 Selb“ → „Hohenbergerstrasse 9“). Fehlt die Adresse → Termin „fehlerhaft“ | 30 |
| `venue_name`, `city` | **„Rosenthal-Theater Selb“ / „Selb“ nur**, wenn die Adresse des Termins die Theateradresse ist (Vergleich ohne Leerzeichen/Punkt, „Straße“/„Strasse“/„Str.“ gleich: `hohenbergerstr9`) und eine evtl. angehängte Stadt „Selb“ ist. Name und Adresse stehen so im Seitenfooter („Rosenthal-Theater Selb, Hohenberger Str. 9, 95100 Selb“). Andere Adresse mit „PLZ Stadt“ → diese Stadt, Ort leer. Andere Adresse ohne Stadt → Termin „fehlerhaft“ (nicht raten) | 30 × Rosenthal-Theater Selb / Selb |
| `event_url` = `source_url` | öffentliche Detailseite des Termins (nicht der Ticketshop) | 30 |
| `external_id` | WordPress-Post-ID | 30 verschiedene |

**Nicht verfügbar** (bleibt leer bzw. wird nicht gespeichert): Spielstätte als eigenes Feld, Saal/Raum, Endzeit, Dauer,
Einlass, Ganztag, Telefon/Ansprechpartner (nur im Langtext einzelner Termine). **Vorhanden, aber nicht gespeichert**
(kein Feld im Modell): Genre, Veranstalter, Badge „AUSVERKAUFT“/„ABGESAGT“/„NEU“, Ticket-Link (okticket.de bzw.
Veranstalterseite), Preis „Tickets ab“, Bild, Langbeschreibung.

**Stadt:** Alle 30 Termine finden laut Adresse im Rosenthal-Theater in Selb statt. Gastspielorte kommen derzeit nicht
vor; der Adapter setzt „Selb“ aber nicht pauschal, sondern nur über die Adresse (siehe oben, getestet mit
`tools/test-theater-selb.php`). Quelle und Ort bleiben getrennte Konzepte; es gibt keine Ortsregel für diese Quelle.

### Identität und Matching (verifiziert)

- `external_id` = Post-ID. Jede Aufführung hat einen eigenen Beitrag (z. B. „Zuckerguss & Kräuterkekse“ 53299 und 53302).
  Eine reine Produktions-ID gibt es nicht.
- Die Post-ID bleibt bei Titel-, Datums- und URL-Änderung gleich (WordPress vergibt sie einmal; real belegt mit Post
  52794, siehe oben). Deshalb wird eine **Terminverschiebung als UPDATE desselben Datensatzes** erkannt – anders als
  bei Bad Steben und Coburg.
- `has_unique_detail_urls()` = `false`: Matching nur über die Post-ID; die URL wird mit gespeichert und aktualisiert.
- Der event_key-Fallback wird nicht benutzt (jede Detailseite hat eine Post-ID; fehlt sie, ist der Termin „fehlerhaft“).
- Kopiert die Redaktion für einen neuen Termin einen alten Beitrag (Slug `…-2`), entsteht ein neuer Beitrag mit neuer
  ID → neuer Datensatz. Das ist fachlich richtig (neue Aufführung).

Getestet mit veränderten Kopien der echten Seiten (nur Theater Selb gecrawlt, Mock in der Testinstallation):

| Änderung | Ergebnis |
|---|---|
| Titel (Evviva Mamma + „– Zusatzvorstellung“) | derselbe Datensatz (2048) aktualisiert |
| Uhrzeit (Voice Passion 19:30 → 20:00, Liste und Detail) | derselbe Datensatz (2047) |
| **Datum** (Zuckerguss 28.11. → 29.11., Liste und Detail) | **derselbe Datensatz** (2053), kein Duplikat |
| Ort + Adresse (Weihnachtskonzert: „Ludwigstraße 6, 95100 Selb“) | derselbe Datensatz (2056): Adresse „Ludwigstraße 6“, Ort leer, Stadt „Selb“ |
| Beschreibung (Sherlock Holmes, Untertitel ergänzt) | derselbe Datensatz (2058) |
| URL (Django Asül, neuer Slug, gleiche Post-ID) | derselbe Datensatz (2059), `event_url`/`source_url` neu |
| Titel „SPD Ortsverein Testevent“ (statt Christina Rommel) | zentral **gefiltert** (`ortsverein`), bestehender Datensatz unverändert |
| Ergebnis | 30 gefunden, 0 neu, **6 aktualisiert**, 22 unverändert, 2 gefiltert; Wiederholung: 0 aktualisiert, 28 unverändert; zurück auf Originaldaten: 6 aktualisiert, Daten wieder identisch |

### Filter

- Keine Stammtische, Partei- oder Ratsveranstaltungen in den echten Daten.
- **Realer Fehlalarm:** „Stars von Morgen Ein Konzert der Hofer Symphoniker mit Landes- und Bundespreisträgern von
  „Jugend musiziert““ (08.11.2026) wird **gefiltert**, weil der Untertitel „unter der Schirmherrschaft des
  Bezirkstagspräsidenten Henry Schramm, MdL a.D.“ die Default-Regel `bezirkstag` (enthält) trifft. Es ist ein
  Jugendkonzert, keine politische Veranstaltung. **Nicht umgangen** (keine Ausnahme für die Quelle); Entscheidung über
  die Regel beim Auftraggeber (05-filter.md, todo.md).
- „Theatergruppe Australia Selb „Minister im Quadrat““ wird nicht gefiltert („Minister“ ist keine Regel).

### Bekannte Einschränkungen

- **Absagen:** Das Badge „ABGESAGT“ steht nur neben dem Titel und wird **nicht** gespeichert (kein Statusfeld). „Plan
  B!: Capones Vermächtnis (Nachholtermin)“ (28.11.2026, abgesagt) erscheint deshalb wie ein normaler Termin im XML.
  Ebenso „AUSVERKAUFT“ (Lesung mit Beate Roth). Bewusst keine Interpretation; offene Frage an den Auftraggeber.
- Verschiebungen stehen nur als Text im Titel/Untertitel („(Nachholtermin)“, „VERSCHOBEN!“) und werden so übernommen.
- Die Liste heißt auf der Website „Ticketshop“, enthält aber auch Termine ohne Ticket-Link. Dass sie alle Termine der
  Kategorie „veranstaltungen“ enthält, ist über die REST-Zählung belegt (30 = 30); das gedruckte Jahresprogramm (PDF)
  wurde nicht abgeglichen.
- Ausstellungen (Seite „Spielplan & Ausstellungen“) stehen nur als Fließtext und werden nicht übernommen.
- Ein Detailabruf je Termin: Bei ca. 80 Terminen läge die Laufzeit nahe am Limit von 120 s je Quelle (todo.md). Ein
  Abbruch wegen Zeitüberschreitung ändert keine Daten (die Quelle wird vor dem Speichern vollständig gelesen).
- Ist eine einzelne Detailseite nicht abrufbar (z. B. HTTP 404), wird nur dieser Termin übersprungen („fehlerhaft“),
  sein gespeicherter Datensatz bleibt unverändert (getestet).
- Abhängigkeit vom Theme-Markup (`gmk-theater-*`, `single-play-infos-*`). Ändert es sich, meldet der Adapter einen Fehler
  bzw. überspringt Termine; die Daten bleiben unverändert (getestet: HTTP 500, Netzwerkfehler, kaputte Struktur).

## 5. DAS ZENTRUM Bayreuth

| Eigenschaft | Wert |
|---|---|
| Name | DAS ZENTRUM Bayreuth (Internationales Jugendkulturzentrum Bayreuth e.V.; Anzeige im Admin: „DAS ZENTRUM Bayreuth“) |
| Slug (`source`) | **`das-zentrum-bayreuth`** (Vorgabe des Auftraggebers; ersetzt den früheren Vorschlag `das-zentrum`, unter dem nie Daten gespeichert wurden) |
| Ausgangs-URL | https://www.das-zentrum.de/veranstaltungen.html → **HTTP 404** („Seite nicht gefunden“, Stand 2026-10-15). Die Veranstaltungsseite ist `https://www.das-zentrum.de/veranstaltungen/` |
| **Crawl-URL** | **https://www.das-zentrum.de/wp-json/tribe/events/v1/events?per_page=50&page={n}** (öffentliche REST-API des Kalender-Plugins) |
| Status | **funktioniert** (live getestet am 2026-10-15) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Das_Zentrum_Bayreuth` (JSON, kein HTML) |
| Abrufmethode | `wp_remote_get` auf die API, seitenweise (`per_page=50`, 1 s Pause zwischen Seiten, max. 10 Seiten). Am 2026-10-15: **2 Requests**, live **3,3–3,4 s** |
| Matching-Methode | **Priorität 1: `external_id` = TEC-Post-ID** des Termins (z. B. `185`) |
| Letzter technischer Test | 2026-10-15: 73 Termine live, 73 neu; zweiter Crawl 73 unverändert |
| Plattform | **WordPress 7.1.3** (Theme Impreza, WPBakery) mit dem Kalender-Plugin **The Events Calendar** (TEC). Die API kündigt sich selbst an (HTTP-Header `x-tec-api-root`, `<meta name="tec-api-version" content="v1">`) |
| Detailseite vorhanden | ja, je Termin `https://www.das-zentrum.de/veranstaltung/{slug}/`; wird **nicht** abgerufen |
| externe ID | **ja, je Termin**: TEC-Post-ID (`id`, z. B. 185) |

### Technische Analyse (2026-10-15)

Analysiert mit 13 lesenden GETs bei das-zentrum.de (Liste 404, robots.txt, `/veranstaltungen/`, 2 × API, Ort-Seite,
Raumseite, 6 Detailseiten), alles lokal zwischengespeichert.

| Punkt | Ergebnis |
|---|---|
| robots.txt | Yoast, `Disallow:` leer (alles erlaubt) |
| `/veranstaltungen/` (HTML) | TEC-Listenansicht, 12 Termine je Seite (`/veranstaltungen/liste/seite/2/` …), JSON-LD `Event` je Termin (`eventStatus` = `EventScheduled` bei allen 12). Zusätzlich ein Inline-JSON des Themes (`eventData`) mit allen 74 Terminen: Datum, Zeit, Preis, Kategorie, Ort, `highlight`, **`sold_out`** |
| REST-API TEC `/wp-json/tribe/events/v1/events` | **vollständig strukturiert**: `id`, `url`, `title`, `start_date`/`end_date` (Ortszeit, `timezone` Europe/Berlin), `all_day`, `venue` (Name, Straße, PLZ, Stadt, ID), `organizer`, `categories`, `cost`, `website` (meist Ticketlink), `description` (HTML), `excerpt` |
| Zeitraum der API | Standard: heute 00:00 bis heute + 2 Jahre (`next_rest_url` zeigt `start_date`/`end_date`). Keine vergangenen Termine |
| Paginierung | `per_page=50`, Header `x-tec-total: 73`, `x-tec-totalpages: 2` → **2 Requests** (je ca. 0,8–0,9 s) |
| Umfang | **73 Termine** vom 15.10.2026 bis 09.12.2027; 73 verschiedene IDs und URLs |
| Ausnahme Zeitraum | 1 Termin (Jonas Greiner, 23.11.2028) steht im Theme-JSON, liegt aber nach dem API-Standardfenster (+2 Jahre) → kommt mit späteren Crawls |
| RSS / iCal | `/feed/` nur Beiträge; TEC bietet iCal, nicht nötig (API ist strukturierter) |
| Detailseite | Kasten „Veranstaltungsdetails“: **Beginn**, **Einlass**, Eintritt, Ort (+ Adresse beim Europasaal), Kategorie, ggf. Reihe, Veranstalter. Einlass steht **nur hier**, nicht in der API |

### Datenlage (73 Termine, API, 2026-10-15)

| Feld | Befund |
|---|---|
| Beginn | `start_date` = „Beginn“ der Detailseite (**6 von 6 Stichproben** gleich, auch ungewöhnliche Zeiten wie 19:19 und 19:33; Einlass jeweils anders: 18:30, 19:30, 13:30 …). Uhrzeiten: 20:00 (53), 19:30 (6), 19:00 (4), Einzelwerte 10:00, 13:00, 14:00, 15:00, 16:00, 19:19, 19:33, 20:30, 21:00, 22:30 |
| Ende | `end_date` = `start_date` bei **67** Terminen (Ende = Beginn, also keine Endangabe); echtes Ende bei **6** (z. B. 10:00–17:00, 19:00–21:00). Kein Ende an einem anderen Tag |
| Ganztag | `all_day` = false bei allen 73 |
| Ort (`venue`) | „DAS ZENTRUM – Europasaal“ (53, Äußere Badstraße 7a, 95448 Bayreuth), „DAS ZENTRUM – Kleinkunstbühne“ (19, **ohne Straße, PLZ, Stadt** im Ort-Datensatz ID 60), „DAS ZENTRUM – Ganzes Haus“ (1, Adresse wie Europasaal). Keine externen Spielstätten |
| Kleinkunstbühne | Belege, dass sie im Haus liegt: Raumseite „Die Kleinkunstbühne im ZENTRUM Bayreuth …“ (`/kleinkunstbuehne-mieten-bayreuth/`); Beschreibung „auf der Kleinkunstbühne im Zentrum“; in der Regionaldatenbank von Bayreuth Tourismus ist sie ein **Raum** des Orts „DAS ZENTRUM - Internationales Jugendkulturzentrum Bayreuth e.V.“ (Äußere Badstraße 7a, 95448 Bayreuth) |
| Veranstalter | `organizer` (27 verschiedene, z. B. „Motion Kommunikationsgesellschaft mbH“ 29, „DAS ZENTRUM Bayreuth“ 7) – getrennt vom Ort |
| Beschreibung | `excerpt` bei **allen leer**; `description` = langer HTML-Werbetext (389–2613 Zeichen), Anfang uneinheitlich (mal Untertitel in `<strong>`, mal ganzer Absatz) – **kein abgegrenzter Kurztext** |
| Kategorie | Comedy 30, Konzerte 26, Sonstiges 10, Lesungen 8, Theater 4, Familie 3, Krimi 2, ohne 2 |
| Status | Die API hat **kein** Statusfeld. „Ausverkauft“ nur im Theme-JSON der HTML-Liste (`sold_out`: 3 Termine – NightWash 14.11., Claus von Wagner 28.10., Rolf Miller 23.10.), als Badge per JavaScript. „Abgesagt“/„verschoben“: kein Feld, in keinem Titel; JSON-LD `eventStatus` überall `EventScheduled` |
| Titel | 56 von 73 mit HTML-Entities (`&#8211;` usw.); Muster meist „Künstler – Programm“ |
| Mehrere Termine | dieselbe Produktion mit eigenen IDs je Termin (Mamaladnamala 3 ×, NightWash 2 ×, HUEBNOTIX 2 ×); **am selben Tag**: „Jahreskonzert – Erster Harmonika-Club Bayreuth“ 21.11. 14:00 (ID 281) und 19:00 (ID 1032) |
| Ticket | `website` (64) bzw. „Ticket kaufen“: Fremdshops (z. B. motion.online-ticket.de) – nicht als `event_url` |

### Entscheidung Abruf

Die API liefert alle benötigten Felder strukturiert. **Keine Detailseiten** (Beginn = API, Einlass wird nicht gebraucht,
Adresse steht in der API bzw. fehlt auch auf der Detailseite der Kleinkunstbühne). Kein HTML-Parsing.

### Felder (Stand 2026-10-15, 73 Termine)

| Feld | Regel | Ergebnis |
|---|---|---|
| `title` | `title`, Entities dekodiert, sonst unverändert | 73 |
| `description` | **leer**: `excerpt` ist bei allen leer, `description` ist ein langer Werbetext ohne abgegrenzten Kurztext. Nicht übernommen, nicht gekürzt | 0 |
| `start_date`, `start_time` | aus `start_date` („Y-m-d H:i:s“, Ortszeit); Zeitzone muss `Europe/Berlin` sein, sonst „fehlerhaft“. Ungewöhnliche Zeiten (19:19, 19:33) werden so übernommen, sie stehen auch auf der Detailseite als „Beginn“ | 73 |
| `end_date`, `end_time` | nur, wenn `end_date` **nach** dem Beginn liegt; Ende = Beginn bedeutet „kein Ende“ | 6 |
| `all_day` | aus `all_day`; bei ganztägig keine Uhrzeiten, Enddatum nur bei späterem Tag | 0 |
| `venue_name` | `venue.venue` unverändert (Raum mit Hausname, z. B. „DAS ZENTRUM – Kleinkunstbühne“). **Nicht** der Veranstalter | 73 |
| `venue_address` | `venue.address` („Äußere Badstraße 7a“); fehlt sie, NULL | 54 |
| `city` | `venue.city`. **Ausnahme Ort-ID 60** („DAS ZENTRUM – Kleinkunstbühne“, in der Quelle ohne Adresse und Stadt): `city` = „Bayreuth“, belegt durch die Raumseite und die Regionaldatenbank (siehe Datenlage); keine Straße ergänzt. Jeder andere Ort ohne Stadt → Termin „fehlerhaft“ | 54 + 19 = 73 × Bayreuth |
| `event_url` = `source_url` | `url` = öffentliche Detailseite (nicht der Ticketshop `website`) | 73 |
| `external_id` | `id` (TEC-Post-ID, je Termin) | 73 verschiedene |

Nicht gespeichert (kein Feld bzw. bewusst): Einlass (nur Detailseite), Veranstalter, Kategorie, Preis, Ticketlink,
Bild, Langtext, „Ausverkauft“ (nur im HTML-Theme-JSON), Reihe.

### Identität und Matching (verifiziert)

- `external_id` = TEC-Post-ID. Jeder Termin ist ein eigener Beitrag, auch bei derselben Produktion (Jahreskonzert
  21.11. 14:00 = 281, 19:00 = 1032). Eine Produktions-ID gibt es nicht.
- Die Post-ID bleibt bei Änderungen eines Beitrags gleich (WordPress-Prinzip). Ein reales Beispiel einer Verschiebung
  liegt bei dieser Quelle nicht vor; getestet mit veränderten Kopien.
- `has_unique_detail_urls()` = `false`; der event_key-Fallback wird nicht benutzt.

Getestet mit einer veränderten Kopie der API-Seite 1 (nur DAS ZENTRUM gecrawlt, Mock in der Testinstallation):

| Änderung | Ergebnis |
|---|---|
| Titel (Bosetti + „– Zusatzshow“) | derselbe Datensatz (2078) |
| Beginn (Simon & Garfunkel 20:00 → 20:30) | derselbe Datensatz (2079) |
| **Datum** (Rolf Miller 23.10. → 24.10.) | **derselbe Datensatz** (2083), kein Duplikat |
| Ort (Mamaladnamala Kleinkunstbühne → Europasaal) | derselbe Datensatz (2080), jetzt mit Adresse |
| Adresse (Rudelsingen „Äußere Badstraße 9“) | derselbe Datensatz (2082) |
| Stadt (Kneipenfestival → „Gemeindehalle, Hauptstraße 1, Bindlach“) | derselbe Datensatz (2084), Stadt Bindlach (nicht „Bayreuth“) |
| URL (Willi live, neuer Slug) | derselbe Datensatz (2125), URLs neu |
| Langtext geändert (To Athena) | **kein** UPDATE (Langtext wird nicht gespeichert) |
| Titel „SPD Ortsverein Testevent“ | zentral gefiltert, Datensatz unverändert |
| Titel leer / Beginn fehlt / Ort ohne Stadt | je „fehlerhaft“, Datensätze unverändert (kein neues `last_seen_at`) |
| Ergebnis | 73 gefunden, 0 neu, **7 aktualisiert**, 62 unverändert, 1 gefiltert, 3 fehlerhaft; Wiederholung 0 aktualisiert; zurück auf Originaldaten 7 aktualisiert, Daten identisch |

### Filter

- In den gespeicherten Daten (nur Titel, Beschreibung leer) **0 Treffer**; auch kein `bezirkstag`-Fall.
- Politische Begriffe in Kabaretttiteln werden nicht gefiltert: „Sarah Bosetti – Make Democracy Great Again“,
  „Ingmar Stadelmann – liest Höcke“ (keine Regel trifft).
- **Würde der Langtext gespeichert**, träfen die Regeln `AfD` (Bosetti: „AfD-Wähler“; Stadelmann: „AfD-Chefs“) und
  `stammtisch` (Wolfgang Krebs: „… die Lufthoheit über die Stammtische …“) – drei Fehlalarme bei Kabarett
  (05-filter.md Abschnitt 10).

### Status (abgesagt, ausverkauft, verschoben)

| Status | Wo in der Quelle | Gespeichert / im XML |
|---|---|---|
| ausverkauft | `sold_out` im Inline-JSON der HTML-Liste `/veranstaltungen/`, angezeigt als Badge „Ausverkauft“ (3 Termine: Rolf Miller 23.10., Claus von Wagner 28.10., NightWash 14.11.). **Nicht** in der API, nicht im Titel | nein – geht im XML verloren |
| abgesagt / verschoben | kein Feld; JSON-LD `eventStatus` bei allen sichtbaren Terminen `EventScheduled`; kein Titel mit „abgesagt“, „verschoben“, „Nachholtermin“ | – (kommt derzeit nicht vor) |

### Bekannte Einschränkungen

- Die angegebene Ausgangs-URL `veranstaltungen.html` liefert HTTP 404; gecrawlt wird die API.
- Keine Beschreibung (kein Kurztext in der Quelle).
- Kleinkunstbühne: Stadt über die dokumentierte Ort-ID 60, **keine Adresse** (die Quelle nennt keine). Bekommt der
  Ort in der Quelle eine Adresse, wird diese übernommen.
- „Ausverkauft“ wird nicht übernommen (nur im HTML, kein Feld).
- Zeitraum = API-Standard (heute bis +2 Jahre): 1 Termin am 23.11.2028 kommt erst mit späteren Crawls.
- Abhängigkeit von der TEC-REST-API v1: Ändert sie sich oder wird sie abgeschaltet, meldet der Adapter einen Fehler
  („Antwort der Veranstaltungs-API nicht lesbar“), die Daten bleiben unverändert (getestet). Ein Fehler auf Seite 2 bricht
  die ganze Quelle ab (keine Teildaten).
- Etwa 40 der 73 Termine stehen auch bei Bayreuth Tourismus (siehe „DAS ZENTRUM vs. Bayreuth Tourismus“).

## 6. Neustadt bei Coburg

| Eigenschaft | Wert |
|---|---|
| Name | Neustadt bei Coburg |
| URL | https://www.neustadt-bei-coburg.de/freizeit-erholung/kunst-kultur/veranstaltungskalender |
| Slug (`source`) | `neustadt-bei-coburg` (vorgesehen, Vorgabe des Auftraggebers; noch keine Daten) |
| Status | **zurückgestellt** – Zugriff durch den Betreiber blockiert, Entscheidung beim Auftraggeber |
| Plattform | TYPO3 (aus der 404-Seite für `/robots.txt`: `<meta name="generator" content="TYPO3 CMS">`) |
| Parser vorhanden | nein |
| Detailseite vorhanden | nicht geprüft (Seite nicht abrufbar) |
| externe ID bekannt | nicht geprüft |

### Befund 2026-10-15: User-Agent wird blockiert

Zusammenfassung und nächste Schritte: Abschnitt „Zurückgestellte / schwer erreichbare Quellen“ oben.

| Anfrage | Ergebnis |
|---|---|
| Veranstaltungskalender, Startseite, robots.txt mit `Eventcrawler/0.1.0 (Analyse)` | **HTTP 503** „Service Unavailable … maintenance downtime or capacity problems“ (Apache-Standardseite), durchgehend bei 10 Wiederholungen im Minutenabstand (14:04–14:13 Uhr) |
| `Eventcrawler/0.1.0 (WordPress; <home_url>)` (User-Agent des Plugins) | 503 |
| `Mozilla/5.0 (compatible; Eventcrawler/0.1.0)` | 503 |
| Startseite mit curl-Standard-User-Agent | 200 |
| robots.txt mit neutralem User-Agent | 404 (TYPO3-Fehlerseite) – es gibt **keine** robots.txt |

Folgerung: Der Server weist gezielt Anfragen ab, deren User-Agent „Eventcrawler“ (vermutlich „crawler“) enthält. Es
ist **keine** Störung der Website. Der Kalender wurde deshalb **nicht** analysiert, ein Adapter wird nicht gebaut.

Bewusst **nicht** gemacht: den User-Agent zu tarnen (Browser-Kennung) oder den Plugin-User-Agent zu ändern, um die
Sperre zu umgehen. Das wäre eine Umgehung einer Zugriffsentscheidung des Betreibers und beträfe zudem alle Quellen
(der User-Agent steht zentral in `Eventcrawler_Source::fetch_html()`).

Mögliche Wege (Entscheidung beim Auftraggeber, todo.md):
1. Stadt Neustadt bei Coburg um Freigabe bitten (z. B. Freischaltung des User-Agents) oder nach einer offiziellen
   Schnittstelle bzw. einem Export (iCal/RSS/Datei) fragen.
2. Auf die Quelle verzichten: Die Quelle Coburg enthält bereits Neustädter Termine (am 2026-10-15: 16 Termine, z. B.
   kultur.werk.stadt, Museum der Deutschen Spielzeugindustrie, Mediathek; einer davon mit Stadt „Neustadt“ statt
   „Neustadt bei Coburg“). Ob diese vollständig sind, ist ohne Zugriff auf die Neustädter Seite nicht prüfbar.
3. Nur mit ausdrücklicher Freigabe: einen anderen, weiterhin ehrlichen User-Agent (ohne „crawler“, mit Kontakt-URL)
   für alle Quellen einführen. Das wäre eine Core-Änderung und rechtlich/fachlich vorher zu klären.

## 7. Kulmbach

| Eigenschaft | Wert |
|---|---|
| Name | Kulmbach |
| URL | https://www.kulmbach.de/veranstaltungen/index.php |
| Slug (`source`) | `kulmbach` (vorgesehen, noch keine Daten) |
| Status | **zurückgestellt** – HTTP 403 für den Plugin-User-Agent (Details: „Zurückgestellte / schwer erreichbare Quellen“) |
| Plattform | nginx; CMS nicht geprüft (Seite mit Plugin-User-Agent nicht abrufbar) |
| Parser vorhanden | nein |
| Detailseite vorhanden | nicht geprüft |
| externe ID bekannt | nicht geprüft |

## 8. Bayreuth Tourismus

| Eigenschaft | Wert |
|---|---|
| Name | Bayreuth Tourismus (Eventkalender von bayreuth-tourismus.de; Anzeige im Admin: „Bayreuth Tourismus“) |
| Slug (`source`) | **`bayreuth-tourismus`** |
| Ausgangs-URL | https://www.bayreuth-tourismus.de/veranstaltungen-bayreuth/ (Themenseite), Kalender https://www.bayreuth-tourismus.de/eventkalender/ |
| **Crawl-URL** | **Regional-API** `https://api.region-bayreuth.de/event-management/events/search?page=0&size=1000&sort=eventDateTimes.date:asc&districts=…(4)&from={heute}&to={heute+27}` + derselbe Abruf mit `&venueIds=e7cac5bf-eefe-4b23-af39-d06aba69e0bd` (DAS ZENTRUM) |
| Status | **funktioniert** (live getestet am 2026-10-15) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Bayreuth_Tourismus` |
| Abrufmethode | **2 Requests** (Suche + Ortsfilter DAS ZENTRUM, 1 s Pause), live **3–4 s**; keine Detailseiten |
| Matching-Methode | **`external_id` = `{eventId}_{JJJJ-MM-TT}_{n}`** (eventId gilt je Veranstaltung) |
| Letzter technischer Test | 2026-10-15: 563 Termine in der API, 15 DAS ZENTRUM ausgeschlossen, 548 an den Crawler, 538 gespeichert, 3 gefiltert, 7 ohne Ort; zweiter Crawl 538 unverändert |
| Plattform | WordPress-Seite (nginx) mit Vue-Widget `rbi-events`; Daten aus der **Regionaldatenbank Region Bayreuth** (JSON-API) |
| Detailseite | `https://region-bayreuth.de/veranstaltung/{slug}` (`detailLink`), **je Veranstaltung** |

### Zugriff und TLS (geprüft 2026-10-15, Plugin-User-Agent)

| Abruf | Ergebnis |
|---|---|
| `/veranstaltungen-bayreuth/`, `/eventkalender/` | **200**, keine Weiterleitung, `Server: nginx`, 0,3 s |
| robots.txt bayreuth-tourismus.de | 200; sperrt nur `/wp-admin/` und eine Rückmeldeseite |
| `api.region-bayreuth.de` (Suche) | **200**, JSON, 0,1–0,7 s; robots.txt der API: 404 (nicht vorhanden) |
| Detailseite region-bayreuth.de | 200, 0,3 s |
| Bot-Schutz / Rate Limit / Captcha | keine Anzeichen (ca. 40 API-Abrufe in Analyse und Tests, alle 200, keine Rate-Limit-Header) |
| **TLS** | Kette: `*.region-bayreuth.de` → Sectigo Public Server Authentication CA DV R36 → **Root R46**. Git-curl/OpenSSL (ältere CA-Liste): Fehler 20 „unable to get local issuer certificate“. **Mit dem CA-Bundle von WordPress** (`wp-includes/certificates/ca-bundle.crt`) verifiziert: curl `ssl_verify_result=0`; **`wp_remote_get()` mit Standard `sslverify=true`: HTTP 200** (PHP-cURL 8.7, OpenSSL 3.0.15). Keine Zertifikatsprüfung abgeschaltet |

### Technische Analyse (2026-10-15)

| Punkt | Ergebnis |
|---|---|
| Widget-Konfiguration (`/eventkalender/`) | `filterBy.districts` = 4 Stadtteil-UUIDs (Bayreuth 95444/95445/95447/95448), `itemsPerPage: 10`, Benutzerfilter Datum/Kategorie/Ort |
| API `events/search` | GET, Parameter `page` (ab 0), `size`, `sort=eventDateTimes.date:asc`, `districts` (mehrfach), `from`/`to` (`JJJJ-MM-TT`), `venueIds`. Antwort `{values, totalItems, totalPages, currentPage}`. **`size=1000` wird angenommen** → 28 Tage in einer Seite |
| Mengen | 28 Tage: **563 Termine** (298 Veranstaltungen); 2 Monate: 1122; ohne Ende: 1922 |
| Eintrag | `eventId` (UUID **je Veranstaltung**), `detailLink`, `title`, `description` (Kurztext, 18–120 Zeichen), `dateInfo` (`date.start/end`, `start`, `end`, `type` UNIQUE/MULTIPLE/INDIVIDUAL, `interval`), `venue` (`name`, `room`, `address.street/houseNumber/postalCode/city`, **keine Venue-ID**) oder `null`, `districtCity`, `price` |
| Termine | jeder Termin ein eigener Eintrag; **eventId + Datum eindeutig** (563/563), auch eventId + Datum + Beginn |
| Zeiten | Beginn bei allen; **Ende bei allen** (Häufung 17:00, 22:00, 18:00 …; Herkunft nicht belegbar, wird wie geliefert übernommen); 3 × Beginn 00:00 = echte Fortsetzung nach Mitternacht (Ende 05:00 bzw. 23:45/04:00, „Tag 2“, „42-Stunden-Spielemarathon“) |
| Orte | 79 Orte, alle in der Stadt Bayreuth; **7 Termine ohne Ort** (`venue: null`, z. B. Tageswanderungen, „34. Bayreuther Kneipenfestival“) |
| Status | nur im Titel/Kurztext: „AUSVERKAUFT:“ (2), „ENTFÄLLT:“ (1), „AUSGEBUCHTT:“ (1, Tippfehler der Quelle), „(Termin vorverlegt, statt 22.10.)“ (1). Kein Statusfeld |
| Venue-Liste | `/venue-management/venues/status/approved`: 633 Orte mit `id`, `title`, `rooms`. DAS ZENTRUM = genau **ein** Ort: `e7cac5bf-eefe-4b23-af39-d06aba69e0bd` „DAS ZENTRUM - Internationales Jugendkulturzentrum Bayreuth e.V.“ (Räume Europasaal, Kleinkunstbühne, Seminarraum 6 / 2. Stock) |

### Entscheidungen (A91)

- **Quelle mit Vorrang je Ort:** Termine am Ort DAS ZENTRUM werden **nicht** übernommen; dafür ist die Quelle
  `das-zentrum-bayreuth` zuständig. Grundlage ist die **stabile Venue-ID** der Regionaldatenbank: Der Adapter ruft
  dieselbe Suche zusätzlich mit `venueIds=e7cac5bf-…` ab und übernimmt keine `eventId` aus dieser Menge (Venue-ID
  steht nicht im Eintrag, nur als Filter der API). Sicherung: Trägt ein Eintrag exakt den Namen von DAS ZENTRUM, ist aber
  nicht in der Menge, wird er als fehlerhaft übersprungen. Kein `contains` auf „Zentrum“ (andere Orte wie „Schoko e.V. -
  Jugendkultur- und Sportzentrum“ bleiben), kein Titelabgleich, keine Ähnlichkeit, keine Datenübernahme zwischen
  Quellen, keine gemeinsame ID.
- Fällt der Ortsfilter-Abruf aus, bricht die Quelle ab (sonst würden DAS-ZENTRUM-Termine doppelt gespeichert).
- Zeitraum **heute + 27 Tage**, Stadtteile wie im Widget; Seitengröße 1000, höchstens 5 Seiten.
- `external_id` = `{eventId}_{Datum}_{n}`: Titel, Beginn, Ende, Ort, Adresse, Stadt, URL, Kurztext ändern sich →
  UPDATE; **Datumsverschiebung → neuer Datensatz** (die Quelle hat keine Termin-ID; wie Coburg/Helmbrechts).
- Kurztext `description` als Beschreibung (die Quelle hat nur diesen); Raum wird nicht gespeichert.
- Ende wie geliefert; Ende nicht nach Beginn verwirft der Crawler. Beginn 00:00 bleibt (belegt echte Uhrzeit).
- Ohne Ort → übersprungen; keine Stadt aus `districtCity` (Stadtteil des Eintrags, kein Veranstaltungsort).
- „gefunden“ im Admin zählt die Termine **nach** dem DAS-ZENTRUM-Ausschluss (eigene Kategorie gibt es im Ergebnis nicht;
  keine Core-Erweiterung); die Zahl der Ausschlüsse liefert `get_excluded_count()` und steht in den Tests.

### Felder (Stand 2026-10-15, 538 gespeichert)

| Feld | Regel | Ergebnis |
|---|---|---|
| `title` | `title`, unverändert (auch „AUSVERKAUFT:“, „ENTFÄLLT:“) | 538 |
| `description` | `description` (Kurztext, Zeilenumbrüche bereinigt) | 538 |
| `start_date` | `dateInfo.date.start` | 538 (15.10.–11.11.2026) |
| `start_time` | `dateInfo.start` | 538 (3 × 00:00 echt) |
| `end_date` / `end_time` | `dateInfo.date.end` + `dateInfo.end`, nur mit Beginn | 538 |
| `all_day` | nicht vorhanden → 0 | 0 |
| `venue_name` | `venue.name` | 538 |
| `venue_address` | `venue.address.street` + `houseNumber` | 538 |
| `city` | `venue.address.city` | 538 × Bayreuth |
| `event_url` = `source_url` | `detailLink` (region-bayreuth.de) | 538, 276 verschiedene |

Nicht gespeichert: Raum, Preis, Ticketlink, Bild, Koordinaten, Kategorie.

### Überschneidung mit DAS ZENTRUM nach der Anbindung (28 Tage, 2026-10-15)

| | Anzahl |
|---|---|
| Bayreuth-Tourismus-Termine am Ort DAS ZENTRUM (Ortsfilter = exakter Name, beide 15) | **15**, alle ausgeschlossen |
| davon mit Gegenstück in der Quelle DAS ZENTRUM | 11 (Bosetti, Simon & Garfunkel, Rolf Miller, Claus von Wagner, Robert Alan, Mina Richman, Techno Classics 19:15/19:19, Matthias Walz, To Athena, Herbstnebel, Bochum) |
| nur bei Bayreuth Tourismus | 3 × „Tanzhaus im ZENTRUM“ (Seminarraum); 1 × „Die kleine Eule feiert Weihnachten“ mit Datum 23.10. (DAS ZENTRUM: 23.11.) |
| nur bei DAS ZENTRUM im Zeitraum | 5 (Mamaladnamala, Fest für die Sinne, Rudelsingen, Kneipenfestival-Abend, Stadt.Land.Mord.) |
| verbleibende Dubletten im XML | **0**: jeder DAS-ZENTRUM-Titel steht genau einmal im Gesamtexport. „Kneipenfestival“ erscheint bei Bayreuth Tourismus nur mit anderen Bands an anderen Orten |
| andere aktive Quellen | keine Termine in Bayreuth außer DAS ZENTRUM; keine gleichen Titel |

### Filter / Status

- Echte Treffer im Zeitraum: „Bürgerinnen- und Bürgerversammlung“ (`bürgerversammlung`), 2 × „Kreativstammtisch
  „Gemeinsam statt einsam““ (`stammtisch`, regelkonform: Treffen unter dem Namen Stammtisch). Kein Treffer allein über
  den Kurztext, keine Fehlalarme.
- Status nur als Text (siehe oben), wird nicht ausgewertet; Titel bleibt unverändert.

### Bekannte Einschränkungen

- Datumsverschiebung → neuer Datensatz, der alte bleibt (keine Termin-ID).
- Endzeiten: bei allen Terminen vorhanden, teils auffällig rund; nicht prüfbar, ob schematisch.
- Ausschluss nur für DAS ZENTRUM; Termine dieses Hauses, die die Quelle einem anderen Ort zuordnet, würden übernommen
  (2026-10-15: keiner gefunden).
- Die 4 Stadtteil-IDs stammen aus der Widget-Konfiguration; ändert die Seite sie, muss der Adapter angepasst werden.
- Hohe Terminmenge (rund 540 je 28 Tage) → größerer Anteil im XML.

## 9. Schützenhaus Münchberg

| Eigenschaft | Wert |
|---|---|
| Name | Schützenhaus Münchberg (Anzeige im Admin: „Schützenhaus Münchberg“) |
| Slug (`source`) | **`schuetzenhaus-muenchberg`** |
| Ausgangs-URL = **Crawl-URL** | **https://schuetzenhaus-muenchberg.de/veranstaltungen/** |
| Status | **funktioniert** (live getestet am 2026-10-15) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Schuetzenhaus_Muenchberg` |
| Abrufmethode | `wp_remote_get` auf die Programmseite, **1 Request**, live 5,8–6,0 s (die Seite ist ca. 677 KB groß) |
| Matching-Methode | **Priorität 1: `external_id` = `{Post-ID}-{n}`** |
| Letzter technischer Test | 2026-10-15: 47 Termine live, 46 gespeichert, 1 fehlerhaft (Tippfehler der Quelle); zweiter Crawl 46 unverändert |
| Plattform | **WordPress 6.7.1** mit **Elementor 4.0.7** und „Ele Custom Skin“ (Post-Loop `article.ecs-post-loop`); Custom Post Type `veranstaltung`; Apache |
| Detailseite vorhanden | ja, `https://schuetzenhaus-muenchberg.de/veranstaltung/{slug}/`; wird **nicht** abgerufen |
| externe ID | **ja**: WordPress-Post-ID (`article#post-{ID}`) je Eintrag; ein Eintrag kann mehrere Termine haben |

### Zugriff (geprüft 2026-10-15, mit dem Plugin-User-Agent)

| Abruf | Ergebnis |
|---|---|
| `/veranstaltungen/` | **200**, keine Weiterleitung, ca. 677 KB, 5,8 s |
| Startseite | 200 |
| `/robots.txt` | 200: `User-agent: *`, `Disallow: /wp-admin/` – Veranstaltungen erlaubt |
| WAF / Bot-Schutz / Captcha | keine Anzeichen; Server Apache |

### Technische Analyse (2026-10-15)

Analysiert mit 7 lesenden GETs (Liste, Startseite, robots.txt, REST-Typen, 2 × REST-Liste, 2 Detailseiten), alles lokal
zwischengespeichert.

| Punkt | Ergebnis |
|---|---|
| Datenquelle | **serverseitiges HTML** der Seite `/veranstaltungen/`: Elementor-Post-Loop, je Beitrag `article.ecs-post-loop#post-{ID}`. Alle kommenden Termine auf **einer** Seite: **46 Einträge** (16.10.2026–05.12.2027), gruppiert nach Monatsüberschriften |
| Paginierung / „mehr laden“ / AJAX | keine |
| REST-API | `/wp-json/wp/v2/veranstaltung` (48 Beiträge) liefert **kein Datum, keine Uhrzeit** (ACF-Felder leer, Inhalt leer, nur Taxonomien `jahr`, `monat`, `kategorie`). Nicht verwendet. `gastveranstaltungen`: 0 Beiträge. 2 REST-Beiträge stehen nicht in der Liste (`fasching-2027`, `tribute-festival-2026`, ohne Jahr) – die veröffentlichte Liste ist maßgeblich |
| JSON-LD / iCal / RSS | nur Seitenangaben (Yoast/Elementor); kein Event-JSON-LD, kein iCal |
| Detailseite | Preis, „Raum: Großer Saal“ bzw. „Treffpunkt: …“, Beginn/Einlass, Langtext; Adresszeile „Schützenhaus Münchberg / Hofer Straße 69, 95213 Münchberg“ ist ein **fester Text im Seiten-Template** (gleiches Elementor-Widget `0b595d8` auf allen geprüften Detailseiten), kein Feld je Termin |
| Felder je Eintrag (Liste) | Überschrift mit Kategorie-Begriffen (`<span>`, fehlt bei „Hör Hin“), **Titel**, **Datum** („Fr, 16.10.26“, Jahr zweistellig; mehrere Tage als „Di, 27.10. & Mi, 28.10.26“), **„Beginn: 19:00 Uhr“** und getrennt **„Einlass: 18:00 Uhr“**, **Kurztext** (116–250 Zeichen, ganze Sätze), Buttons „Mehr Infos“ (Detailseite) und „Zum Ticket VVK“ (okticket.de/oktx.de) |
| Status | „AUSVERKAUFT“ als eigener Textblock statt der Buttons (2 Einträge: Stadtspaziergang 27./28.10., Dr. Mark Benecke 04.11.); „Mehr Infos“ ist dann ausgeblendet. Kein „abgesagt“/„verschoben“ |
| Ort | in der Liste **kein** Ortsfeld. Die Seite ist das Programm des Hauses („bietet das Schützenhaus Münchberg 2026 …“); die Ticket-Slugs nennen „muenchberg-schuetzenhaus“ (22 von 22 okticket-Links); das Detail-Template nennt für jeden Termin die Hausadresse, auch beim Stadtspaziergang als Treffpunkt |
| Datenfehler der Quelle | Huebnotix: „Sa, 27.02.07“ – der 27.02.2007 war ein Dienstag, der Wochentag passt nicht → wird übersprungen, nicht korrigiert |

### Entscheidung

- **1 Request** (Liste), keine Detailseiten: Diese liefern keine zusätzlichen Termin-Daten außer Raum/Langtext/Preis;
  44 Detailabrufe (ca. 1,7 s + 1 s Pause) würden das Limit von 120 s je Quelle überschreiten.
- Ort fest: „Schützenhaus Münchberg“, „Hofer Straße 69“, „Münchberg“ – belegt durch Seite und Template (A86).
- `external_id` = `{Post-ID}-{n}` (n = laufende Nummer des Termins im Eintrag, meist 1).

### Felder (Stand 2026-10-15, 46 Einträge → 47 Termine)

| Feld | Regel | Ergebnis |
|---|---|---|
| `title` | erste Überschrift **ohne** `<span>` (Überschriften mit `<span>` sind Kategorie-Begriffe), die weder Datum noch „Beginn:“/„Einlass:“ ist; unverändert | 46 |
| `description` | erster Textblock des Eintrags, außer dem Status „AUSVERKAUFT“; Kurztext der Quelle (ganze Sätze, keine Kürzung) | 46 |
| `start_date` | Datumszeile „Wd, TT.MM.JJ“ bzw. „Wd, TT.MM. & Wd, TT.MM.JJ“; zweistelliges Jahr = 20JJ, gilt für Tage ohne eigenes Jahr; **Wochentag muss passen**, sonst Termin „fehlerhaft“ | 47 Termine, 1 fehlerhaft |
| `start_time` | nur „Beginn: HH:MM Uhr“ (auch „HH.MM“); „Einlass“ wird nie verwendet | 46 |
| `end_date`, `end_time` | nicht vorhanden → NULL | 0 |
| `all_day` | nicht vorhanden → 0 | 0 |
| `venue_name`, `venue_address`, `city` | fest „Schützenhaus Münchberg“, „Hofer Straße 69“, „Münchberg“ (A86) | 46 |
| `event_url` = `source_url` | Button „Mehr Infos“ (Detailseite); fehlt er (ausverkauft), die Programmseite `/veranstaltungen/` | 43 Detailseite, 3 Programmseite |
| `external_id` | `{Post-ID}-{n}` | 46 verschiedene |

Nicht gespeichert: Kategorie, Einlass, Ticketlink (okticket.de / oktx.de), Preis und Raum (nur Detailseite), Status
„AUSVERKAUFT“.

### Identität und Matching (verifiziert)

- Jeder Eintrag ist ein Beitrag mit eigener Post-ID; dieselbe Produktion an zwei Tagen hat zwei Beiträge („Classic
  Rock meets Gospel“ 9186 / 10093). Ausnahme: ein Beitrag mit zwei Tagen (Stadtspaziergang 8572 → `8572-1`, `8572-2`).
- Datum, Uhrzeit, Titel und URL gehören zum Beitrag; eine Änderung behält die Post-ID → UPDATE (getestet).
  Grenzfall: Bekommt ein Mehrtagesbeitrag einen zusätzlichen früheren Tag, verschieben sich die Nummern n.
- Getestet mit einer veränderten Kopie der Liste:

| Änderung | Ergebnis |
|---|---|
| Titel (Joe Bausch + „– Zusatztermin“) | derselbe Datensatz (2157) |
| Beschreibung (André Puchta) | derselbe Datensatz (2154) |
| **Datum** (True Crime Magic 23.10. → 24.10.) | **derselbe Datensatz** (2156) |
| Beginn (Rüdiger Hoffmann 20:00 → 20:30) | derselbe Datensatz (2164) |
| URL (Feuerbach Quartett, neuer Slug) | derselbe Datensatz (2165) |
| Titel „SPD Ortsverein Testevent“ | zentral gefiltert, Datensatz unverändert |
| Titel entfernt / ungültiges Datum („32.01.27“) | je „fehlerhaft“, Datensätze unverändert |
| Ort / Adresse / Stadt | nicht aus der Quelle änderbar (fest, A86) |
| Ergebnis | 47 gefunden, 0 neu, **5 aktualisiert**, 38 unverändert, 1 gefiltert, 3 fehlerhaft; Wiederholung 0 aktualisiert; zurück auf Originaldaten identisch |

### Filter

- In den echten Daten (Titel + Kurztext) **0 Treffer**, kein Fehlalarm.

### Status

| Status | Quelle | gespeichert |
|---|---|---|
| ausverkauft | Textblock „AUSVERKAUFT“ statt der Buttons (2 Einträge, 3 Termine) – als Text erkennbar, kein strukturiertes Feld | nein (nicht in Titel/Beschreibung) |
| abgesagt / verschoben / entfällt | kommt nicht vor | – |

### Überschneidungen mit anderen Quellen

- Keine. In der Datenbank gibt es keine anderen Termine in Münchberg (auch nicht aus Bad Steben) und keinen Ort
  „Schützenhaus“. Gleiche Künstler bei DAS ZENTRUM (Ines Procter, HUEBNOTIX) sind andere Tourneetermine in Bayreuth.
- Eine Quelle Hof bzw. Landkreis Hof ist nicht angebunden (Hof: 503, siehe „Zurückgestellte Quellen“).

### Bekannte Einschränkungen

- Ort, Adresse und Stadt sind fest (A86). Würde das Haus Termine an anderen Orten in die Liste aufnehmen, wären sie
  falsch zugeordnet; derzeit ist das nicht der Fall (der Stadtspaziergang beginnt laut Quelle am Schützenhaus).
- Huebnotix (Datum „Sa, 27.02.07“) wird übersprungen, bis die Quelle das Jahr korrigiert.
- Ausverkaufte Einträge haben keinen „Mehr Infos“-Link; `event_url` ist dann die Programmseite.
- 2 REST-Beiträge erscheinen nicht in der Liste und werden nicht übernommen.
- Abhängigkeit vom Elementor-/Ele-Custom-Skin-Markup (`article.ecs-post-loop`); ändert es sich, meldet der Adapter einen
  Fehler, die Daten bleiben unverändert (getestet).

## 10. Hof

| Eigenschaft | Wert |
|---|---|
| Name | Hof |
| URL | https://www.hof.de/veranstaltungen |
| Status | nicht analysiert |
| Parser vorhanden | nein |
| Detailseite vorhanden | noch zu prüfen |
| externe ID bekannt | noch zu prüfen |
| Bemerkungen | – |

## 11. Stadtsteinach

| Eigenschaft | Wert |
|---|---|
| Name | Stadtsteinach (Veranstaltungskalender der Stadt; Anzeige im Admin: „Stadtsteinach“) |
| Slug (`source`) | **`stadtsteinach`** |
| Ausgangs-URL = **Crawl-URL** | **https://www.stadtsteinach.de/freizeit-kultur-tourismus/veranstaltungen** + Folgeseiten über „nächste“ + je Termin die Detailseite |
| Status | **funktioniert** (live getestet am 2026-10-15) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Stadtsteinach` |
| Abrufmethode | `wp_remote_get`: 5 Listenseiten + 47 Detailseiten = **52 Requests**, 1 s Pause, live **73,7–73,8 s** (längste Quelle) |
| Matching-Methode | **Priorität 1: `external_id` = Index-ID** (calendarize) |
| Letzter technischer Test | 2026-10-15 (mit Ort→Stadt-Zuordnung, A88): 47 gefunden, 41 gespeichert (9 neu, 32 unverändert), 6 übersprungen (ohne Ort); zweiter Crawl 41 unverändert |
| Plattform | **TYPO3** mit der Kalender-Extension **calendarize** (`tx_calendarize_calendar`), Apache, UTF-8 |
| Detailseite vorhanden | ja, je Termin: `…/veranstaltungen-detailansicht?tx_calendarize_calendar[action]=detail&…[index]={Index-ID}&cHash=…` |
| externe ID | **Index-ID** von calendarize je Termin (z. B. `26182`); ICS-UID nur je Veranstaltung (`calendarize-herbstball`) |

### Zugriff (geprüft 2026-10-15, Plugin-User-Agent)

| Abruf | Ergebnis |
|---|---|
| Veranstaltungsseite (2 × im Abstand) | **200**, keine Weiterleitung, ca. 33 KB, 0,6 s |
| Startseite | 200 (1 Weiterleitung) |
| `/robots.txt` | 200: `Disallow` nur für TYPO3-Systemordner und `/fileadmin/`; `GPTBot` und `Scrapy` ganz gesperrt – „Eventcrawler“ nicht genannt |
| Bot-Schutz / Rate Limit | keine Anzeichen (59 Abrufe während der Analyse, alle 200) |

### Technische Analyse (2026-10-15)

Analysiert mit 59 lesenden GETs (5 Listenseiten, 47 Detailseiten, 1 ICS, Startseite, robots.txt, Wiederholungen),
lokal zwischengespeichert.

| Punkt | Ergebnis |
|---|---|
| Liste | serverseitiges HTML, `div.calendarize-item` je Termin: `span.event-date` („15.10.2026“), `span.event-time1` („09:30“), `span.event-time3` („- 11:00 Uhr“ bzw. „Uhr“), Titel `p.event-list-title > a`, Bild. **Kein Ort, kein Text** |
| Paginierung | 10 Termine je Seite, Link „nächste“ (`currentPage` + **cHash**) → am 2026-10-15 **5 Seiten, 47 Termine** (15.10.2026–08.12.2027); nur kommende Termine |
| Detailseite | Überschrift, Datum/Zeit, Langtext (107–1497 Zeichen), `dl.meta` mit **„Veranstaltungsort“** (41/47) und **„Veranstalter“** (40/47), ICS-Link |
| ICS je Termin | `UID:calendarize-{slug}` (Veranstaltung, nicht Termin), `LOCATION` = derselbe Freitext. Kein Mehrwert |
| JSON-LD / API / RSS | nicht vorhanden |
| Zeiten | nur Datum (5, z. B. Fasching), „19:00 Uhr“ (32), „08:00 - 12:00 Uhr“ (10). Kein Einlass, keine mehrtägigen Termine, kein „ganztägig“ |
| Ort (Freitext, keine Adresse, keine Stadt) | „Cafeteria des Klinikums Stadtsteinach“ 14, „Steinachtalhalle Stadtsteinach“ 7, „Bauernmarktscheune“ 5, „Sitzungssaal im Rathaus Stadtsteinach“ 4, „Evang. Gemeindehaus Stadtsteinach“ 2, je 1 × „Kirche St. Michael Stadtsteinach“, „Marktplatz Stadtsteinach“, „Friedrich-Baur-Schule Stadtsteinach“, „Rathaus Stadtsteinach“, „Feuerwehrzentrum Stadtsteinach“, „Steinachtalhalle“, „Burgruine Nordeck“, „Festplatz in der Knollenstraße“, **„Pfarrzentrum St. Hedwig Kulmbach“** (Herbstball – findet in Kulmbach statt); **6 ohne Ort** (u. a. Herbstwanderung auf dem Walberla-Weg) |
| Index-ID | je Termin verschieden; alte IDs (Bauernmarkt 18970–18974) bestehen neben neuen (262xx) → bleiben bei unveränderten Terminen über Neuindizierungen erhalten. Ob eine Datumsänderung die ID ändert, ist nicht belegbar |

### Entscheidungen (A87)

- **Abruf:** Liste (5 Seiten) + **eine Detailseite je Termin**, weil der Ort nur dort steht (ca. 52 Requests, 1 s Pause,
  ca. 65–75 s; Limit 120 s je Quelle).
- **Stadt:** Die Quelle nennt keine Stadt. `city` = „Stadtsteinach“ **nur**, wenn der Ortstext das Wort „Stadtsteinach“
  enthält (32 von 47). Alle anderen Termine werden **übersprungen** („fehlerhaft“), statt eine Stadt zu raten – auch der
  Termin in Kulmbach und die Bauernmarktscheune. Pauschal „Stadtsteinach“ wäre nachweislich falsch (Herbstball in Kulmbach).
- `venue_name` = Ortstext unverändert; `venue_address` = NULL (nicht vorhanden).
- `description` leer: kein Kurztext; der Langtext wird nicht übernommen.
- `external_id` = Index-ID; `event_url` = `source_url` = Detail-URL mit Query (cHash).

### Felder (Stand 2026-10-15)

| Feld | Regel | Ergebnis (32 gespeichert) |
|---|---|---|
| `title` | `p.event-list-title > a`, unverändert | 32 |
| `description` | leer (kein Kurztext; Langtext der Detailseite nicht übernommen) | 0 |
| `start_date` | `span.event-date` „TT.MM.JJJJ“ (strikt) | 32 |
| `start_time` | `span.event-time1`; ohne Zeitangaben NULL (kein Ganztag) | 27, 5 NULL |
| `end_time` / `end_date` | „- HH:MM Uhr“ in `event-time3`; `end_date` = Starttag (Regel des Crawlers) | 6 |
| `all_day` | nicht vorhanden → 0 | 0 |
| `venue_name` | „Veranstaltungsort“ der Detailseite, unverändert | 32 |
| `venue_address` | nicht vorhanden → NULL | 0 |
| `city` | 1. belegte Zuordnung Ort → Stadt bei exaktem Ortstext (A88), 2. „Stadtsteinach“, wenn der Ortstext das Wort enthält (A87), sonst Termin übersprungen | 40 × Stadtsteinach, 1 × Kulmbach (Stand nach A88) |
| `event_url` = `source_url` | Detail-URL mit Query und cHash | 32 |
| `external_id` | Index-ID | 32 verschiedene |

### Ort → Stadt-Zuordnung (A88, seit 2026-10-15)

Von den zunächst 15 übersprungenen Terminen hatten 9 einen Ortstext ohne (bzw. mit fremdem) Stadtnamen. Jeder dieser
fünf Orte wurde **einzeln extern belegt** und ist als feste, nur für diese Quelle geltende Zuordnung im Adapter
hinterlegt (`VENUE_CITIES`). Vergleich: Ortstext nach `clean_text()` (Entities dekodiert, geschützte Leerzeichen und
Whitespace vereinheitlicht, getrimmt), ohne Groß-/Kleinschreibung, **exakt** – kein Teilstring, kein Fuzzy-Matching.
Gesetzt wird nur `city`; `venue_name` bleibt Quelltext, `venue_address` bleibt NULL (keine Adresse aus der Recherche).
Keine HTTP-Abfrage und kein Geocoding im Crawl.

| Ortstext (exakt) | Stadt | Beleg (geprüft 2026-10-15) | Termine |
|---|---|---|---|
| Bauernmarktscheune | Stadtsteinach | Stadtsteinacher Bauernmarkt e. V. (stadtsteinacher-bauernmarkt.de): „Knollenstraße 8 (neben Getränkestadel Schübel) 95346 Stadtsteinach“; Veranstalter laut Quelle „Verein zur Förderung und Betreuung des Bauernmarktes in Stadtsteinach“ | 5 (Stadtsteinacher Bauernmarkt) |
| Steinachtalhalle | Stadtsteinach | Stadt Stadtsteinach, Seite „Steinachtalhalle“: „Badstraße 4 - 95346 Stadtsteinach“; in derselben Quelle 7 × als „Steinachtalhalle Stadtsteinach“ | 1 (Böhmischer Abend) |
| Festplatz in der Knollenstraße | Stadtsteinach | Stadtsteinacher Anzeiger 11/2024 (Amtsblatt der Stadt, Veranstaltungsliste): „Stanicher Weihnachtszauber – Festplatz in der Knollenstraße“; Knollenstraße 8, 95346 Stadtsteinach (Bauernmarkt e. V.) | 1 (Weihnachtszauber) |
| Burgruine Nordeck | Stadtsteinach | Frankenwald Tourismus: „Am Hochofen, 95346 Stadtsteinach“; Stadt Stadtsteinach, Seite „Burgruine Nordeck“: „etwa zwei Kilometer nordostwärts von Stadtsteinach“ | 1 (Gruselwanderung) |
| Pfarrzentrum St. Hedwig Kulmbach | **Kulmbach** | Erzbistum Bamberg, Pfarreienverbund Kulmbach, Seite „Pfarrzentrum St. Hedwig“: „Am Galgenberg 4 in 95326 Kulmbach“ | 1 (Herbstball) |

Bewusst **nicht** zugeordnet (bleiben übersprungen, „fehlerhaft“): die **6 Termine ohne Veranstaltungsort** –
Herbstwanderung auf dem Walberla-Weg, Schafkopfrennen der Feuerwehr, St. Martinsumzug, Krippenausstellung,
Adventskonzert des Musikvereins (2 ×). Ohne Ortsfeld gibt es nichts zuzuordnen; der Langtext nennt teils Orte, wird aber
nicht ausgewertet (A66). Beispiel, warum das richtig ist: Laut Langtext findet das Adventskonzert am 11.12. in St. Michael
**Stadtsteinach**, am 13.12. aber in Unsere Liebe Frau **Kulmbach** statt.

Ergebnis: 47 gefunden, **41 gespeichert** (vorher 32), 6 übersprungen. Die Zuordnung gilt nur für `stadtsteinach`; neue
Orte werden erst nach eigener Prüfung ergänzt.

Nicht gespeichert: Veranstalter (40/47), Langtext, Bild, ICS.

### Identität und Matching

- Index-ID je Termin (Bauernmarkt 18970–18974: fünf Termine einer Veranstaltung mit fünf IDs).
- Getestet mit veränderten Kopien: Titel, Beginn, **Datum** (bei gleicher Index-ID) und Ort → derselbe Datensatz;
  **neue Index-ID → neuer Datensatz**, der alte bleibt stehen. Ob calendarize bei einer Datumsänderung die Index-ID neu
  vergibt, ist nicht belegbar (Grenzfall, wie bei Coburg).
- Ort ohne „Stadtsteinach“ → übersprungen, bestehender Datensatz unverändert; „SPD Ortsverein Testevent“ → zentral
  gefiltert. Ergebnis: 1 neu, 4 aktualisiert, 1 gefiltert, 16 fehlerhaft; Wiederholung 0 Änderungen.

### Filter / Status

- 0 Filtertreffer in den echten Titeln (Beschreibung leer). Kein Fehlalarm.
- Kein Status (abgesagt/ausverkauft/verschoben) in Liste oder Detailseite gefunden.

### Überschneidungen

- Keine: Die anderen Quellen enthalten keine Termine in Stadtsteinach oder Kulmbach und keinen gleichen Titel am
  gleichen Tag. Der übersprungene Herbstball findet in Kulmbach statt (Quelle Kulmbach ist zurückgestellt).

### Bekannte Einschränkungen

- 6 von 47 Terminen werden übersprungen, weil die Quelle keinen Veranstaltungsort angibt (siehe oben).
- Neue Ortstexte ohne „Stadtsteinach“ werden übersprungen, bis ihre Stadt geprüft und in `VENUE_CITIES` ergänzt ist.
- Keine Beschreibung, keine Adresse.
- **Laufzeit:** eine Detailseite je Termin, ca. 1,6 s je Termin; bei ca. 70 Terminen würde das Limit von 120 s je
  Quelle erreicht. Ein Abbruch ändert keine Daten (die Quelle wird vor dem Speichern vollständig gelesen).
- Neue Index-ID (z. B. nach Neuanlage oder evtl. Datumsänderung) → neuer Datensatz.

## 12. Helmbrechts

| Eigenschaft | Wert |
|---|---|
| Name | Helmbrechts (Termine & Veranstaltungen der Stadt; Anzeige im Admin: „Helmbrechts“) |
| Slug (`source`) | **`helmbrechts`** |
| Ausgangs-URL | https://stadt-helmbrechts.de/termine-veranstaltungen |
| **Crawl-URL** | **Monatsansichten** `https://stadt-helmbrechts.de/termine-veranstaltungen/monat/{JJJJ}/{M}` (aktueller + nächster Monat) + je Veranstaltung die Detailseite `/news-details-aktuell/{slug}` |
| Status | **funktioniert** (live getestet am 2026-10-15) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Helmbrechts` |
| Abrufmethode | 2 Monatsansichten + 1 Detailseite je Veranstaltung = **44 Requests** (1 s Pause), live **73–77 s** |
| Matching-Methode | **Priorität 1: `external_id` = `{Slug}_{JJJJ-MM-TT}_{n}`** |
| Letzter technischer Test | 2026-10-15: 49 Termine, 47 gespeichert, 2 ohne Ort übersprungen; zweiter Crawl 47 unverändert |
| Plattform | **TYPO3** mit **news** (Veranstaltungen als News-Datensätze) und **calendarize** (Monatsansicht), Apache, UTF-8 |
| Detailseite vorhanden | ja, **je Veranstaltung** (nicht je Termin): eine Detail-URL kann mehrere Termine haben |
| externe ID | keine numerische ID im HTML; Slug je Veranstaltung |

### Zugriff (geprüft 2026-10-15, Plugin-User-Agent)

| Abruf | Ergebnis |
|---|---|
| Veranstaltungsseite (2 × im Abstand) | **200**, keine Weiterleitung, ca. 77 KB, 0,6 s |
| Startseite / robots.txt | 200 / 200: `Allow: /`, gesperrt nur TYPO3-Ordner und `/*?id=*` |
| Bot-Schutz / Rate Limit / Captcha / JS-Pflicht | keine Anzeichen (ca. 45 Abrufe in der Analyse, alle 200; Inhalte serverseitig) |

### Technische Analyse (2026-10-15)

| Punkt | Ergebnis |
|---|---|
| News-Liste `/termine-veranstaltungen` | 54 Einträge ab heute bis 23.11.2027 (Titel, erstes Datum, „Beginn“, Preis, teils „bis …“). **Je Veranstaltung nur einmal** – weitere Termine derselben Veranstaltung fehlen (FGH-Theater: 1 von 5 Vorstellungen); einzelne Veranstaltungen fehlen ganz (z. B. „Kreativkurs: Boro“ 17.10.). Als Terminquelle **unvollständig** |
| Monatsansicht (calendarize) | `ul.events > li > a`: Titel (`div.caltitle`), Datum, „Beginn: HH:MM Uhr“ (`span.caltime`), Link zur Detailseite. **Jeder Termin einzeln**, auch Folgevorstellungen mit eigener Uhrzeit (FGH-Theater 16.10. 19:30, 17.10. 19:00, 18.10. 16:00 …) und mehrtägige Veranstaltungen je Tag (Kreisschau 14. und 15.11.). Navigation `/monat/JJJJ/M` ohne cHash |
| „Beginn: 00:00 Uhr“ | Platzhalter: Dorfmeisterschaft zeigt in der Monatsansicht 00:00, die Detailseite „Beginn: 08:00 Uhr“ (für den ersten Tag) → **00:00 wird als „keine Uhrzeit“ (NULL) behandelt** |
| Detailseite | Block „Daten zur Veranstaltung“: Datum (erster Termin), Preis, Beginn, **„Ende:“** („16:00 Uhr“ oder „15.11.2026 15:00 Uhr“); `div.location` mit **„Veranstaltungsort:“** Name `<br>` „Straße[,] PLZ Stadt“ und „Veranstalter:“; `div.teaser-text` (kurz, meist Genre, z. B. „Kabarett“; 22/42 gefüllt); Langtext |
| Orte (42 Veranstaltungen im Fenster) | strukturiert mit Straße und PLZ/Stadt bei 40, z. B. „Bürgersaal Helmbrechts / Luitpoldstraße 21, 95233 Helmbrechts“, „Schützenhaus Wüstenselbitz / Schützenstraße 22, 95233 Helmbrechts“, „Vereinsheim FC Ort / Am Kirchspitz 21 95233 Helmbrechts / Ort“; **2 ohne Ort** (Herbstmarkt zur Kärwa, Letzte-Hilfe-Kurs). Alle Städte: Helmbrechts |
| Datenfehler der Quelle | „Kreativkurs: Boro“: Datum 17.10.2026, „Ende: 12.10.2026 15:00 Uhr“ (vor Beginn) |
| JSON-LD / API / ICS / RSS | nicht vorhanden |

### Entscheidungen (A89)

- **Termine aus den Monatsansichten** (aktueller + nächster Monat, ab heute), weil nur sie jeden Termin enthalten.
- **Detailseite je Veranstaltung** (nicht je Termin) für Ort, Adresse, Stadt, Teaser und Ende: im Fenster am 2026-10-15
  **42 Detailseiten + 2 Monatsseiten = 44 Requests** (je ca. 0,5 s + 1 s Pause, ca. 65–70 s).
- `external_id` = `{Slug}_{JJJJ-MM-TT}_{n}` (n = Nummer des Termins der Veranstaltung an diesem Tag), wie Coburg, weil
  die Detail-URL mehrere Termine bezeichnet und keine numerische ID sichtbar ist.
- Ende nur für den Termin, dessen Datum dem „Datum“ der Detailseite entspricht; Folgetermine ohne Ende.
- Stadt aus der PLZ-Zeile; Zusatz „ / Ortsteil“ wird abgetrennt („Helmbrechts / Ort“ → „Helmbrechts“). Ohne Ort →
  Termin übersprungen. Eine Ort→Stadt-Zuordnung (A88) ist **nicht nötig** (kein Ortstext ohne Stadt).

### Felder (Stand 2026-10-15, 47 gespeichert)

| Feld | Regel | Ergebnis |
|---|---|---|
| `title` | `div.caltitle` der Monatsansicht, unverändert | 47 |
| `description` | `div.teaser-text` der Detailseite (kurz, oft Genre); Langtext nicht übernommen | 30 mit Text, 17 leer |
| `start_date` | Datum der Monatsansicht „TT.MM.JJJJ“ (strikt), nur ab heute | 47 |
| `start_time` | „Beginn: HH:MM Uhr“ der Monatsansicht je Termin; **„00:00“ = keine Uhrzeit → NULL**; Einlass (nur im Langtext) wird nie verwendet | 44, 3 NULL (Dorfmeisterschaft) |
| `end_date` / `end_time` | „Ende:“ der Detailseite, nur für den Termin mit dem „Datum“ der Detailseite; Ende vor Beginn verwirft der Crawler (Quellfehler „Kreativkurs: Boro“) | 10 |
| `all_day` | nicht vorhanden → 0 | 0 |
| `venue_name` | erste Zeile nach „Veranstaltungsort:“ | 47 |
| `venue_address` | Straße vor PLZ | 47 |
| `city` | Stadt nach PLZ, ohne „ / Ortsteil“ | 47 × Helmbrechts |
| `event_url` = `source_url` | Detailseite der Veranstaltung | 47 |

Nicht gespeichert: Preis, Veranstalter mit Telefon und Webseite (strukturiert vorhanden), Einlass, Langtext, Bild.

### Identität und Matching (verifiziert)

- Mehrere Termine einer Veranstaltung (FGH-Theater 5 Vorstellungen mit eigenen Uhrzeiten) → je Termin ein Datensatz.
- Getestet mit veränderten Kopien: Titel, Beginn (17.10. 19:00 → 19:15), Ort + Adresse + Ende, Teaser, Adresse +
  Stadt (→ Münchberg) → **derselbe Datensatz**; **Datumsverschiebung** und **neuer Slug** → **neuer Datensatz**, der alte
  bleibt (Datum bzw. Slug sind Teil der ID; dokumentierte Grenze wie Coburg). Filterbegriff → gefiltert.

### Filter / Status

- Echter, korrekter Treffer: „Bürgerversammlung 2026“ (12.10.2026, `bürgerversammlung`) – lag vor dem Stichtag und
  wurde daher nicht abgerufen. Im Zeitfenster 0 Treffer, kein Fehlalarm.
- Verwaltungstermine wie „Bürgermeistersprechstunde“ und „Rentensprechtag“ fallen unter keine Regel und werden
  gespeichert (offene Frage an den Auftraggeber, todo.md).
- Status (abgesagt/ausverkauft/verschoben): in Monatsansicht und Detailseiten nicht gefunden.

### Überschneidungen

- Keine: Keine andere Quelle enthält Termine in Helmbrechts; keine Titelübereinstimmung (auch nicht bei Künstlernamen)
  mit den aktiven Quellen. Eine Hof-Quelle ist nicht angebunden.

### Bekannte Einschränkungen

- Zeitraum: aktueller + nächster Monat (Laufzeit); spätere Termine kommen mit späteren Crawls.
- Datumsverschiebung oder neuer Slug → neuer Datensatz.
- 2 Termine ohne Veranstaltungsort werden übersprungen (Herbstmarkt zur Kärwa, Letzte-Hilfe-Kurs).
- Mehrtägige Veranstaltung erscheint wie in der Quelle je Tag (Kreisschau 14./15.11., A53); Ende nur am ersten Tag.
- **Laufzeit ~75 s** (eine Detailseite je Veranstaltung); bei deutlich mehr Veranstaltungen im Zwei-Monats-Fenster
  nahe am Limit von 120 s je Quelle.

## 13. Thurnau

| Eigenschaft | Wert |
|---|---|
| Name | Thurnau |
| URL | https://www.thurnau.de/freizeit-kultur-tourismus/veranstaltungen |
| Status | nicht analysiert |
| Parser vorhanden | nein |
| Detailseite vorhanden | noch zu prüfen |
| externe ID bekannt | noch zu prüfen |
| Bemerkungen | – |

## 14. Kronach

| Eigenschaft | Wert |
|---|---|
| Name | Kronach (Veranstaltungskalender der Stadt Kronach; Anzeige im Admin: „Kronach“) |
| Slug (`source`) | **`kronach`** |
| Ausgangs-URL | https://www.kronach.de/tourismus-kultur/veranstaltungen/index.html |
| **Crawl-URL** | **Datumssuche** `…/veranstaltungen/index.html?ev%5Bstart%5D={TT.MM.JJJJ}&ev%5Bend%5D={TT.MM.JJJJ}&ev%5Bsearch%5D=` (heute bis heute + 27 Tage), Folgeseiten `&page=1 …`; dazu Detailseiten `…/index.html?ev%5Bid%5D={ID}` |
| Status | **funktioniert** (live getestet am 2026-10-15) |
| Parser vorhanden | **ja**: `Eventcrawler_Source_Kronach` |
| Abrufmethode | 9 Listenseiten + 36 Detailseiten = **45 Requests** (1 s Pause), live **68–71 s** |
| Matching-Methode | **Priorität 1: `external_id` = Termin-ID `ev[id]`** (je Termin eine eigene ID) |
| Letzter technischer Test | 2026-10-15: 106 Termine, 103 gespeichert, 2 gefiltert (Ausschusssitzungen), 1 ohne Ort; zweiter Crawl 103 unverändert |
| Plattform | CMS **„recon“** (Kommunal-CMS, `recon.revision="8.16.142"`), Apache, UTF-8, serverseitiges HTML |
| Detailseite vorhanden | ja, **je Termin** (eigene ID) |
| externe ID | ja: numerische Termin-ID im Link (`ev%5Bid%5D=16259`) |

### Quellenwahl (2026-10-15)

- **Stadt Kronach** (`www.kronach.de`, Bereich „Tourismus & Kultur“): eigener Kalender der Stadt mit allen Terminen
  der Stadt (Kultur, Märkte, Führungen, Sitzungen), Termin-IDs, strukturierter Adresse → **Primärquelle**.
- Landkreis Kronach (`www.landkreis-kronach.de`, „Kulturelle Veranstaltungen“: Kulturring, Kronacher Sommer, Galerie)
  und Frankenwald Tourismus (`www.frankenwald-tourismus.de/bei-uns/veranstaltungen`, ganze Region) antworten mit 200,
  sind aber andere Quellen (Landkreis-Programm bzw. regionaler Kalender). Nur die Startseiten wurden je einmal abgerufen,
  nicht analysiert und nicht angebunden.

### Zugriff (geprüft 2026-10-15, Plugin-User-Agent)

| Abruf | Ergebnis |
|---|---|
| Veranstaltungsseite / Startseite | **200** / **200**, keine Weiterleitung, 0,3 s, `Server: Apache`, Session-Cookie `recon` (nicht nötig) |
| robots.txt | 200; sperrt nur Funktions-Parameter (`?fCMS`, `_func=md`, `contrast=` …), nicht die Kalenderparameter `ev[...]` |
| Bot-Schutz / WAF / Rate Limit / Captcha / JS-Pflicht | keine Anzeichen (ca. 80 Abrufe in Analyse und Tests, alle 200; Liste und Details serverseitig) |

### Technische Analyse (2026-10-15)

| Punkt | Ergebnis |
|---|---|
| Startansicht ohne Parameter | nur **9 hervorgehobene Termine**, nach Kategorie gruppiert, ohne Jahr → als Terminquelle ungeeignet |
| Datumssuche (Formular `EventForm`, GET) | `ev[start]`/`ev[end]` im Format `TT.MM.JJJJ`; Ergebnisliste chronologisch, **10–13 Termine je Seite**, Pagination „(N Seiten)“ mit `&page=n` (n ab 1 für Seite 2). 28 Tage: 9 Seiten / 106 Termine; 2 Monate: 15 Seiten / 179 Termine; 12 Monate: 35 Seiten |
| Listeneintrag `a.events-list-link` | Termin-ID (`ev%5Bid%5D`), Tag + Monatskürzel („15“ „Okt“), Uhrzeit („19:30 Uhr“ oder leer), Titel (`h2`), „Ort: …“ (kann fehlen). **Kein Jahr**, keine Adresse, kein Teaser |
| Termin-IDs | **je Termin eine eigene ID**, auch bei Serien (Festungsführung 15966, 15967, 15968 …; Sommerhalle täglich 16237 …); 179 Termine = 179 IDs. Ein Termin mit Ausstellungs-Datumsbereich wird nicht als Bereich gezeigt (Probe: Suche 10.10.2026 zeigt den Termin 15.10. nicht) |
| Detailseite | Titel, „Kategorie“, Tag/Monat/Uhrzeit (wieder ohne Jahr), Block **„Wo?“** aus dem Adressmodul (`.events-address-title`, `-str`, `-ort` „96317 Kronach“, Telefon, E-Mail), Langtext, „Veranstalter“, bei Serien **„Weitere Termine“** mit den IDs der übrigen Termine derselben Veranstaltung |
| Orte | 24 Orte, fast alle in Kronach; **Fremdort belegt:** „Nordwaldhalle, Kronacher Straße 57, 96365 Nordhalben“. „Innenstadt Kronach“ ohne Straße/PLZ. 2 Termine ohne Ort (Gedenktag 09.11., Senioren-Café 13.12.) |
| Monatskürzel | belegt: Jan, Feb, Mai, Jul, Okt, Nov, Dez (März nicht gesehen) |
| Ende / Ganztägig / Einlass | nicht vorhanden |
| JSON-LD / API / ICS / RSS | nur JSON-LD für die Breadcrumb; kein Kalender-Export gefunden |

### Entscheidungen (A90)

- **Termine aus der Datumssuche**, Fenster **heute + 27 Tage** (alle Seiten, höchstens 12). Zwei Monate wären ca.
  15 Seiten + ca. 60 Detailseiten ≈ 100 s und damit zu nah am Limit von 120 s je Request.
- **Jahr:** aus dem Suchbeginn wie `resolve_german_date()` (gleiches Jahr bzw. Folgejahr bei Monat vor dem
  Startmonat); eindeutig, weil das Suchfenster kürzer als ein Jahr ist. Unbekanntes Monatskürzel → Termin fehlerhaft.
- **Adresse und Stadt von der Detailseite**, aber **nicht je Termin**: Eine Detailseite nennt unter „Weitere Termine“
  die IDs der übrigen Termine derselben Veranstaltung; für diese wird keine eigene Detailseite abgerufen
  (106 Termine → 36 Detailseiten). Übernommen nur, wenn der Ortsname der Detailseite exakt dem „Ort:“ der Liste
  entspricht. Kein Rückschluss über gleiche Titel oder gleiche Ortsnamen.
- Stadt = Ort nach der PLZ („96365 Nordhalben“ → Nordhalben). **Nicht pauschal Kronach.** Straße nur zusammen mit PLZ/Ort.
- Ort→Stadt-Zuordnung (A88) nur für **„Innenstadt Kronach“ → Kronach** (Ortsname nennt die Stadt; Detailseite ohne
  Adresse). Exakter Vergleich, nur `city`, keine Adresse.
- Beschreibung leer: Die Quelle hat keinen Teaser; der Langtext wird nicht übernommen und nicht gekürzt.

### Felder (Stand 2026-10-15, 103 gespeichert)

| Feld | Regel | Ergebnis |
|---|---|---|
| `title` | `h2` der Ergebnisliste, unverändert | 103 |
| `description` | nicht vorhanden → leer | 0 |
| `start_date` | Tag + Monatskürzel der Liste, Jahr aus dem Suchbeginn | 103 (15.10.–09.11.2026) |
| `start_time` | „HH:MM Uhr“ der Liste, sonst NULL | 56, 47 NULL |
| `end_date` / `end_time` | nicht vorhanden → NULL | 0 |
| `all_day` | nicht vorhanden → 0 | 0 |
| `venue_name` | „Ort: …“ der Liste (= Ort der Detailseite) | 103 |
| `venue_address` | `.events-address-str` der Detailseite (nur mit PLZ/Ort) | 102, 1 NULL (Innenstadt Kronach) |
| `city` | Ort nach der PLZ; Zuordnung „Innenstadt Kronach“ | 102 × Kronach, 1 × Nordhalben |
| `external_id` | Termin-ID | 103 eindeutig |
| `event_url` = `source_url` | `…/veranstaltungen/index.html?ev%5Bid%5D={ID}` (ohne Suchparameter) | 103 |

Nicht gespeichert: Kategorie, Veranstalter, Telefon, E-Mail, Langtext, Bild, Koordinaten.

### Identität und Matching (verifiziert)

- Getestet mit veränderten Kopien der echten Seiten: Titel, Uhrzeit (20:00 → 20:30), **Datum (29.10. → 28.10.)**, Ort
  + Adresse, Adresse, Stadt (→ Hof) → **jeweils derselbe Datensatz** (ID unverändert). Filterbegriff im Titel →
  gefiltert, vorhandener Datensatz bleibt unverändert. Neue ID → neuer Datensatz (Quelle hat den Termin neu angelegt).
- Quelldublette: „Gesundheit trifft Informatik …“ 27.10. 17:30 hat **zwei IDs** (16609, 16610) → zwei Datensätze; der
  Export fasst die identischen Zeilen zusammen (07-export.md Abschnitt 12.3).

### Filter / Status

- Kommunale Termine (Kategorie „Sitzungstermine“) im 2-Monats-Abruf: 4 Ausschusssitzungen und 2 Stadtratssitzungen,
  alle von bestehenden Regeln erfasst (`ausschusssitzung`, `stadtratssitzung`); im 28-Tage-Fenster 2 gefiltert.
  Bürgermeistersprechstunde, Rentensprechtag, Bürgerversammlung: im Abruf nicht vorhanden. 53 Titel geprüft,
  **0 Fehlalarme**.
- Status (abgesagt/ausverkauft/verschoben/entfällt): in Liste und Detailseiten nicht gefunden.

### Überschneidungen

- Keine: Keine andere Quelle hat Termine in Kronach oder Nordhalben. Gleichnamige Formate an anderen Orten sind
  verschiedene Veranstaltungen (Bauernmarkt Stadtsteinach, Martinimarkt Coburg, Heeresmusikkorps Veitshöchheim in Selb
  2027, Poetry Slam Coburg/Münchberg).

### Bekannte Einschränkungen

- Zeitraum 28 Tage (Laufzeit); spätere Termine kommen mit späteren Crawls.
- Keine Beschreibung, kein Ende.
- Termine ohne „Ort:“ werden übersprungen (Gedenktag Reichspogromnacht 09.11.).
- Monatskürzel für März noch nicht beobachtet; weicht es von „Mär“ ab, werden März-Termine als fehlerhaft gezählt.
- Laufzeit ~70 s; wächst mit der Zahl verschiedener Veranstaltungen im Fenster.

## 15. Nürnberg/Fürth

| Eigenschaft | Wert |
|---|---|
| Name | Nürnberg/Fürth |
| URL | **noch nicht bekannt**. Die Quelle „gibt es bzw. wird es geben“ und muss vom Auftraggeber benannt werden |
| Status | nicht analysiert (URL fehlt) |
| Parser vorhanden | nein |
| Detailseite vorhanden | noch zu prüfen |
| externe ID bekannt | noch zu prüfen |
| Bemerkungen | **Verbindliche Ortsregel:** Enthält die Quelle Veranstaltungen aus mehreren Städten (gemeinsame oder überregionale Quelle), werden **ausschließlich** Veranstaltungen übernommen, deren Veranstaltungsort tatsächlich **Nürnberg oder Fürth** ist. Andere Orte wie Erlangen oder Schwabach werden verworfen. Adapter-Einstellung `get_allowed_cities() = ['Nürnberg', 'Fürth']`. Details in [05-filter.md](05-filter.md) |

---

## DAS ZENTRUM vs. Bayreuth Tourismus (Analyse 2026-10-15; umgesetzt als Ortsausschluss A91, siehe Abschnitt 8)

Stichprobe: **alle 73** Termine von DAS ZENTRUM (API) gegen die Regional-API von Bayreuth Tourismus, gefiltert auf den
Ort „DAS ZENTRUM - Internationales Jugendkulturzentrum Bayreuth e.V.“ (`venueIds=e7cac5bf-…`, 1 Request, **50 Termine**).
Vergleich nach Datum + Titelanfang, Grenzfälle von Hand geprüft.

| Ergebnis | Anzahl |
|---|---|
| DAS-ZENTRUM-Termine, die auch bei Bayreuth Tourismus stehen (gleicher Tag, erkennbar gleiche Veranstaltung) | **40 von 73** (38 automatisch, 2 von Hand: „Rubey & Schwarz“/„Rubey und Schwarz“, Vortrag Jan-Uwe Rogge mit anderem Titel) |
| davon Titel nach Normalisierung identisch | 17 |
| davon andere Startzeit | 3 (Techno Classics 19:19 / 19:15, Ingmar Stadelmann 19:33 / 19:30, Wolfgang Krebs 20:00 / **18:00**) |
| widersprüchliches Datum | 1 („Die kleine Eule – feiert Weihnachten“: DAS ZENTRUM 23.11.2026, Bayreuth Tourismus **23.10.2026**) |
| nur bei DAS ZENTRUM | ca. 32, u. a. Mamaladnamala (3), Fest für die Sinne, Rudelsingen, fast alle Termine ab Februar 2027 |
| nur bei Bayreuth Tourismus | „Tanzhaus im ZENTRUM“ (6 ×, Seminarraum), European Outdoor Film Tour (2 ×) |

Feldvergleich:

| Merkmal | DAS ZENTRUM (API) | Bayreuth Tourismus (Regional-API) |
|---|---|---|
| Nähe zum Veranstalter | Betreiber des Hauses | Aggregator (Regionaldatenbank) |
| Umfang | 73, bis 12/2027 | 50 am Ort, bis 12/2027 |
| Titel | „Künstler – Programm“ | oft kürzer, nur Künstler („NightWash“, „Jan van Weyde“), Status im Titel („AUSVERKAUFT: Claus von Wagner …“) |
| Beginn | „Beginn“ laut Detailseite | meist gleich, 3 Abweichungen |
| Ende | nur 6 echte | bei allen, auffällig oft 22:00 / 22:30 / 23:45 (Herkunft nicht belegbar) |
| Ort / Adresse | Raum als Ort; Adresse nur Europasaal/Ganzes Haus | Ort + Raum + vollständige Adresse bei allen |
| Beschreibung | langer HTML-Text, kein Kurztext | kurzer Teaser |
| Status | `sold_out` nur im HTML-Theme-JSON | „AUSVERKAUFT:“ im Titel (1 Fall) |
| ID | TEC-Post-ID (Zahl) | `eventId` (UUID), Slug mit Zufallssuffix |
| Ticket | `website` | `ticketSystemLink` |

Bewertung (ohne Implementierung):
- **Duplikatrisiko hoch:** Wird Bayreuth Tourismus angebunden, stünden rund 40 Termine je Crawl doppelt im XML, und zwar
  mit **unterschiedlichem Titel** (nur 17 identisch) und teils anderer Uhrzeit. Die bestehende Gruppierung (A70)
  fasst sie nicht zusammen, weil sie strikte Gleichheit verlangt.
- **DAS ZENTRUM wirkt als Primärquelle stärker** für Termine im Haus: Betreiber, mehr Termine, längere Titel mit
  Programmnamen, stabile ID, Beginn belegt. Bayreuth Tourismus ist stärker bei Adresse, Raum und Kurzbeschreibung und
  deckt die ganze Region ab.
- Eine einfache Regel „gleicher Titel + gleiches Datum“ würde nur 17 von 40 Paaren finden; unscharfer Abgleich
  widerspricht A66. Mögliche spätere Strategie (Entscheidung beim Auftraggeber): bei der Anbindung von Bayreuth
  Tourismus Termine des Orts „DAS ZENTRUM“ dort per `venueIds`-Ausschluss bzw. Ortsfilter **nicht** übernehmen
  (Quelle mit Vorrang je Ort), statt Termine paarweise abzugleichen.

## Quellenübergreifende Duplikate (offener Punkt)

Das Matching (02-datenbank.md) arbeitet **innerhalb einer Quelle**. Erscheint dieselbe Veranstaltung in zwei Quellen,
z. B. DAS ZENTRUM und Bayreuth Tourismus, entstehen im MVP zwei Datensätze und damit zwei `<p>`-Zeilen im Export.
Ob das vorkommt, wird bei der Analyse der Quellen geprüft. Eine Lösung ist eine spätere Erweiterung.
