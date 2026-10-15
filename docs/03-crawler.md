# 03 – Crawler

> **Stand 2026-09-24: implementiert** für **eine** Quelle (Burg Rabenstein, Proof of Concept). Getestet live und mit
> kontrollierten Testdaten in einer WordPress-Testinstallation (siehe Abschnitt 9).
> Die Matching-Prioritäten, der event_key und der content_hash sind verbindlich in **02-datenbank.md, Abschnitte 4–7**
> beschrieben. Seit Schema-Version 2 liefert und speichert der Crawler zusätzlich Ende (Datum/Uhrzeit), Ganztag,
> `venue_name` und `event_url` (Abschnitt 11).

## 1. Ablauf eines Crawls (implementiert)

```
Button "Crawler starten" (Admin-Seite, assets/eventcrawler-admin.js)
  -> Auswahl: "Alle Quellen" = alle Quellen aus window.eventcrawlerCrawl.sources (serverseitig aus dem Register),
     sonst genau eine
  -> Browser: je Quelle NACHEINANDER ein eigener Request (Antwort abwarten, dann nächste Quelle)
       POST admin-ajax.php  action=eventcrawler_crawl_source  source={slug}  nonce
       -> Eventcrawler_Admin::handle_crawl_source()   angemeldet, POST, manage_options, Nonce, Slug aus dem Register
       -> in try/catch: Eventcrawler_Crawler::run( $source )
         1. Startzeit des Laufs merken
         2. $source->fetch_events()           Adapter: HTTP-Abruf + Parsing
            -> WP_Error?  => Abbruch, DB unverändert, Fehlermeldung
         3. je Event: normalize()             Pflichtfelder, Bereinigung, event_key, content_hash
            -> ungültig?  => "fehlerhaft", überspringen
         4. Eventcrawler_Filter               Ortsregel, dann aktive Filterregeln (vor der Schleife EINMAL je Lauf
                                              aus der Option eventcrawler_filter_rules geladen)
            -> ausgeschlossen? => "gefiltert", nicht speichern
         5. find_existing()                   Matching Prio 1/2/3
         6. INSERT | nur last_seen_at | UPDATE
       -> JSON: stats (bzw. message bei Quellenfehler) + duration
  -> Browser: Fortschritt je Quelle; am Ende Summe, Seite neu laden (Zusammenfassung via sessionStorage)
```

Ein Fehler einer Quelle (WP_Error oder Exception) beendet nur **diesen Request**; der Browser startet trotzdem die
nächste Quelle (Abschnitt 14). Das PHP-Zeitlimit (`set_time_limit( 120 )`) gilt damit **je Quelle**, nicht mehr für
die Summe aller Quellen.

Die Schritte 1–2 sind quellenspezifisch (Adapter), die Schritte 3–6 laufen zentral im Crawler.

## 2. Dateien

| Datei | Klasse | Aufgabe |
|---|---|---|
| `includes/sources/abstract-eventcrawler-source.php` | `Eventcrawler_Source` | Basisklasse der Adapter: HTTP-Abruf, DOM/XPath, Textbereinigung, URL-Normalisierung |
| `includes/sources/class-eventcrawler-source-burg-rabenstein.php` | `Eventcrawler_Source_Burg_Rabenstein` | Adapter Burg Rabenstein (Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-bad-steben.php` | `Eventcrawler_Source_Bad_Steben` | Adapter Bad Steben über eT4/destination.one (Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-coburg.php` | `Eventcrawler_Source_Coburg` | Adapter Coburg (TYPO3/events2, verlinkte Tagesseiten; Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-theater-selb.php` | `Eventcrawler_Source_Theater_Selb` | Adapter Theater Selb (WordPress, Liste + Detailseite je Termin; Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-bayreuth-tourismus.php` | `Eventcrawler_Source_Bayreuth_Tourismus` | Adapter Bayreuth Tourismus (Regional-API region-bayreuth.de, 2 Abrufe; Ort DAS ZENTRUM ausgeschlossen, A91; Details in 04-quellen.md Abschnitt 8) |
| `includes/sources/class-eventcrawler-source-kronach.php` | `Eventcrawler_Source_Kronach` | Adapter Kronach (CMS recon; Datumssuche + Detailseiten je Veranstaltung über „Weitere Termine“; Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-helmbrechts.php` | `Eventcrawler_Source_Helmbrechts` | Adapter Helmbrechts (TYPO3 news + calendarize; Monatsansichten + Detailseite je Veranstaltung; Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-stadtsteinach.php` | `Eventcrawler_Source_Stadtsteinach` | Adapter Stadtsteinach (TYPO3/calendarize, Listenseiten + Detailseite je Termin; Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-schuetzenhaus-muenchberg.php` | `Eventcrawler_Source_Schuetzenhaus_Muenchberg` | Adapter Schützenhaus Münchberg (WordPress/Elementor, eine Programmseite; Details in 04-quellen.md) |
| `includes/sources/class-eventcrawler-source-das-zentrum-bayreuth.php` | `Eventcrawler_Source_Das_Zentrum_Bayreuth` | Adapter DAS ZENTRUM Bayreuth (REST-API von The Events Calendar, JSON; Details in 04-quellen.md) |
| `includes/class-eventcrawler-crawler.php` | `Eventcrawler_Crawler` | Quellenregister, Ablauf, Normalisierung, Hashes, Matching, INSERT/UPDATE, Statistik |
| `includes/class-eventcrawler-filter.php` | `Eventcrawler_Filter` | zentrale Filterauswertung, Ortsregel, Code-Defaults der Filterregeln (05-filter.md) |
| `includes/class-eventcrawler-filter-rules.php` | `Eventcrawler_Filter_Rules` | Filterregeln in der Option `eventcrawler_filter_rules`: Erstbelegung, Laden, Anlegen, Ändern, Löschen (05-filter.md) |
| `includes/class-eventcrawler-repository.php` | `Eventcrawler_Repository` | Datenbankzugriffe inkl. `find_fallback_candidates()` |
| `includes/admin/class-eventcrawler-admin.php` | `Eventcrawler_Admin` | Button, `admin_post_eventcrawler_run`, Ergebnismeldungen |

## 3. Source Adapter

Ein Adapter liest **nur** seine Quelle. Er enthält **keine** Datenbank-, Filter-, Matching- oder Admin-Logik und liefert
alle technisch lesbaren Events. Ob ein Event übernommen wird, entscheidet der zentrale Filter.

Schnittstelle von `Eventcrawler_Source`:

| Methode | Zweck |
|---|---|
| `get_slug()` | Wert für die Spalte `source`. **Nie ändern**, sobald Daten existieren |
| `get_name()` | Anzeigename (Admin-Meldungen, Liste der Quellen) |
| `get_url()` | tatsächlich abgerufene URL |
| `fetch_events()` | `array[]` im internen Format oder `WP_Error`, wenn die Quelle nicht gelesen werden konnte |
| `get_allowed_cities()` | Ortsregel: `null` = keine Beschränkung, sonst z. B. `array( 'Nürnberg', 'Fürth' )` |
| `has_unique_detail_urls()` | `true` nur nach Prüfung, dass jede Detail-URL genau einen Termin bezeichnet (Matching Priorität 2) |

Hilfsfunktionen der Basisklasse:

- Zugriffssperren: Antwortet eine Quelle auf diesen User-Agent gezielt mit 403/503, wird sie nicht umgangen (A85,
  04-quellen.md „Zurückgestellte / schwer erreichbare Quellen“).
- `fetch_html( $url, $timeout = null )`: `wp_remote_get()` mit 20 s Timeout (je Adapter überschreibbar, Bad Steben 30 s), max. 3 Redirects, max. 5 MB, User-Agent
  `Eventcrawler/<version> (WordPress; <home_url>)`. Prüft `is_wp_error()`, HTTP-Status 200 und einen nicht leeren Body.
  Jeder Fehler wird als `WP_Error` zurückgegeben. Seit 2026-09-30 läuft der Body durch `to_utf8()`:
  - gültiges UTF-8 bleibt **unverändert**, auch wenn der Header einen anderen Zeichensatz nennt (sonst Doppelkodierung
    „CafÃ©“)
  - ungültiges UTF-8 wird nur umgewandelt, wenn der Content-Type einen bekannten Zeichensatz nennt (z. B. ISO-8859-1,
    Windows-1252; `mb_convert_encoding`)
  - sonst `WP_Error` „Quelle liefert kein gültiges UTF-8 …“ → Quellenfehler, Daten unverändert (Regeln 13/14)
  - Die drei angebundenen Quellen liefern UTF-8 (04-quellen.md), für sie ändert sich nichts (Live-Crawl geprüft)
- `create_xpath( $html )`: `DOMDocument::loadHTML()` mit erzwungenem UTF-8 (vorangestelltes `<?xml encoding="UTF-8">`).
  Ohne diesen Hinweis würde libxml bei fehlendem `<meta charset>` ISO-8859-1 annehmen und „CafÃ©“ erzeugen (geprüft
  2026-09-30, 07-export.md 10.1). libxml-Warnungen werden intern gesammelt und verworfen, sie erscheinen nie in der
  Oberfläche.
- `clean_text()`: dekodiert HTML-Entities, ersetzt geschützte Leerzeichen (U+00A0) durch normale, fasst Zeilenumbrüche
  und Whitespace zusammen, trimmt. Regex wird nur für diese Textnormalisierung verwendet, nie zum Parsen von HTML.
- `absolute_url()`: macht relative Links absolut und normalisiert sie (Schema und Host klein, Query und Fragment entfernt).
- `first()`: erster XPath-Treffer oder `null`.

Registrierung: `Eventcrawler_Crawler::get_sources()`, erweiterbar über den Filter-Hook `eventcrawler_sources`.
Registriert sind in dieser Reihenfolge `Eventcrawler_Source_Burg_Rabenstein`, `Eventcrawler_Source_Bad_Steben`,
`Eventcrawler_Source_Coburg`, `Eventcrawler_Source_Theater_Selb`, `Eventcrawler_Source_Das_Zentrum_Bayreuth` und
`Eventcrawler_Source_Schuetzenhaus_Muenchberg`, `Eventcrawler_Source_Stadtsteinach`, `Eventcrawler_Source_Helmbrechts`, `Eventcrawler_Source_Kronach`, `Eventcrawler_Source_Bayreuth_Tourismus`. Eine neue Quelle braucht: Adapterdatei, `require_once` in
`eventcrawler.php` und eine Zeile in `get_sources()`. Admin, Filter, Repository und Export ändern sich dafür nicht (bei
Coburg, Theater Selb, DAS ZENTRUM, Schützenhaus Münchberg, Stadtsteinach, Helmbrechts, Kronach und Bayreuth Tourismus so umgesetzt).
Weitere Adapter werden dort ergänzt. Der Anzeigename im Admin kommt automatisch aus `get_name()`.

## 4. Internes Event-Format

Ein Adapter liefert je Termin (Stand Schema-Version 2; fehlende Werte sind `null`, nie Ersatzwerte wie `00:00`):

```php
array(
    'title'       => 'Wildschwein-Grillbuffet',            // Pflicht
    'description' => 'Wildschwein vom Spieß …',            // Klartext, optional
    'start_date'  => '2026-09-24',                         // Pflicht, Y-m-d
    'start_time'  => '18:00',                              // H:i oder null
    'end_date'    => '2026-09-24',                         // Y-m-d oder null (keine Endinformation)
    'end_time'    => '20:30',                              // H:i oder null
    'all_day'     => false,                                // true nur, wenn die Quelle es eindeutig sagt
    'venue_name'  => 'Burg Rabenstein',                    // konkreter Veranstaltungsort
    'venue_address' => 'Herrngasse 4',                     // optional, seit Schema 3: nur aus strukturiertem Adressfeld
    'city'        => 'Ahorntal',                           // Pflicht, Gemeinde/Stadt
    'event_url'   => 'https://www.burg-rabenstein.de/…',   // öffentlicher Link zur Veranstaltung
    'source_url'  => 'https://www.burg-rabenstein.de/…',   // Herkunft, normalisierte Detail-URL (Matching)
    'external_id' => null,                                 // stabile ID der Quelle oder null
    '_error'      => '…',                                  // nur bei nicht lesbarem Eintrag
)
```

Ein Ende am **Folgetag** (z. B. 22:00–01:00) liefert der Adapter nur dann als `end_date`, wenn die Quelle es
eindeutig erkennen lässt. Sonst verwirft der Crawler das Ende (Abschnitt 5).

`source`, `event_key` und `content_hash` setzt der **Crawler** zentral, nicht der Adapter. So ist garantiert, dass alle
Quellen dieselben Formeln verwenden. Das weicht bewusst vom Wortlaut des Phase-3-Auftrags ab („Adapter liefert
event_key“): Das Ergebnis ist dasselbe, aber die Formel existiert nur an einer Stelle (Entscheidung A20).

Mehrtägige Veranstaltungen oder Serien liefert ein Adapter als **mehrere Einträge**, einen je Termin. Burg Rabenstein
listet ohnehin jeden Tag einzeln, z. B. den Mittelaltermarkt an vier Tagen.

## 5. Normalisierung und Validierung (Crawler)

| Feld | Behandlung |
|---|---|
| `title` | `sanitize_text_field`, max. 255 Zeichen. **Pflicht** |
| `description` | `sanitize_textarea_field` (Klartext) |
| `start_date` | muss exakt `Y-m-d` und ein gültiges Datum sein. **Pflicht** |
| `start_time`, `end_time` | `H:i` oder `H:i:s` → gespeichert als `HH:MM:00`; leer → `NULL`. Ungültig → fehlerhaft |
| `end_date` | `Y-m-d` oder `NULL`. Ungültig → fehlerhaft. Endzeit ohne Enddatum → `end_date = start_date` |
| Ende ≤ Beginn | Ende wird verworfen (`end_date`/`end_time` = NULL, Protokoll bei `WP_DEBUG`), nicht als Folgetag geraten |
| `all_day` | 0/1. Bei 1 werden `start_time`/`end_time` NULL. Nie aus fehlender Uhrzeit abgeleitet |
| `venue_name` | `sanitize_text_field`, max. 255 |
| `venue_address` | `sanitize_text_field`, max. 255, leer → `NULL` (seit Schema-Version 3). Der Adapter liefert sie **nur** aus einem strukturierten Adressfeld bzw. einem eindeutig definierten Quellbereich, nie per Regex aus Beschreibungen (A68) |
| `city` | `sanitize_text_field`, max. 100. **Pflicht** |
| `event_url` | `esc_url_raw` (nur http/https), optional |
| `source_url` | `esc_url_raw` (nur http/https). **Pflicht**, wenn die Quelle über URLs matcht |
| `external_id` | Text oder `NULL` |

Die Regeln sind mit Einzelfällen getestet: 18:30–21:45, nur Beginn, ohne Uhrzeit, ganztägig, ganztägig mehrtägig,
22:00–01:00 ohne und mit Folgedatum, Enddatum vor Beginn, ungültige Endzeit, ungültiges Enddatum, fehlendes Startdatum.

Ein Eintrag mit `_error` oder mit einem fehlenden bzw. ungültigen Pflichtfeld wird **übersprungen** und als
„fehlerhaft“ gezählt. Es werden keine Werte geraten (Regeln 13/14).

Datumsangaben werden **ohne Server-Locale** interpretiert (`DateTime::createFromFormat` mit festem Format). Burg
Rabenstein und Bad Steben liefern numerische bzw. ISO-Daten. Seit Coburg (ausgeschriebene Monate ohne Jahr) gibt es in
der Basisklasse `resolve_german_date( $text, $reference )`:
- feste Tabelle der deutschen Monats- und Wochentagsnamen (auch „März“/„Maerz“), unabhängig von der Server-Locale
- Jahr aus einem Referenzdatum: gleiches Jahr, oder das Folgejahr, wenn der Monat vor dem Referenzmonat liegt
- ein angegebener Wochentag muss zum errechneten Datum passen, sonst `null` (Termin „fehlerhaft“, nicht geraten)

Getestet: Jahreswechsel (Ref 30.12. → „Freitag, 01. Januar“ = Folgejahr), „März“, falscher Wochentag, 30. Februar,
unbekannter Monat → `null`.

## 6. Matching, INSERT und UPDATE

Siehe 02-datenbank.md Abschnitte 6 und 7. Kurz:

- Priorität 1 `source + external_id`, Priorität 2 `source + source_url` (nur bei `has_unique_detail_urls()`),
  Priorität 3 Fallback über `event_key` (Kandidaten, die im aktuellen Lauf noch nicht zugeordnet wurden;
  gleiche `start_time` hat Vorrang).
- Greift Priorität 1 oder 2 ohne Treffer, wird **neu angelegt**. Es wird nicht auf eine niedrigere Priorität ausgewichen.
- Kein Treffer → INSERT. Gleicher Hash → nur `last_seen_at`. Anderer Hash → UPDATE aller Inhaltsfelder,
  `updated_at` und `last_seen_at`. Eine gespeicherte `external_id` wird nie mit `NULL` überschrieben.
- Ist ein vorhandenes Event nach einer Änderung in der Quelle **gefiltert**, bleibt der bestehende Datensatz
  unverändert stehen. Er wird weder aktualisiert noch gelöscht (offener Punkt in todo.md).

## 7. Ergebnisstatistik

`run()` liefert je Quelle:

| Schlüssel | Anzeige | Bedeutung |
|---|---|---|
| `found` | gefunden | Einträge, die der Adapter geliefert hat |
| `new` | neu | eingefügt |
| `updated` | aktualisiert | Inhalt geändert |
| `unchanged` | unverändert | nur `last_seen_at` aktualisiert |
| `filtered` | gefiltert | durch den zentralen Filter ausgeschlossen (auch ein schon gespeichertes Event, das eine neue Regel trifft; der Datensatz bleibt unverändert) |
| `failed` | fehlerhaft | ungültige Einträge oder DB-Fehler |

Admin-Meldungen nach einem Lauf, zuerst die Summe, dann je Quelle eine Zeile (echtes Beispiel vom 2026-09-24):

```
Crawler abgeschlossen (2 von 2 Quellen erfolgreich): 684 gefunden, 557 neu, 0 aktualisiert, 124 unverändert, 0 gefiltert, 3 fehlerhaft.
Burg Rabenstein: 124 gefunden, 0 neu, 0 aktualisiert, 124 unverändert, 0 gefiltert, 0 fehlerhaft.
Bad Steben: 560 gefunden, 557 neu, 0 aktualisiert, 0 unverändert, 0 gefiltert, 3 fehlerhaft.
```

Farben: Gesamtzeile rot, wenn mindestens eine Quelle fehlschlug, sonst gelb bei fehlerhaften Einträgen, sonst grün.
Quellenzeile rot bei einem Quellenfehler („Bad Steben: fehlgeschlagen – … Vorhandene Veranstaltungen dieser Quelle
wurden nicht verändert.“). Übergabe an die Listenseite per Transient `eventcrawler_notices_{user_id}`
(5 Minuten, wird beim Anzeigen gelöscht).

## 8. Fehlerverhalten (implementiert und getestet)

| Situation | Verhalten |
|---|---|
| Netzwerkfehler / Timeout | `WP_Error` → rote Meldung „Crawler fehlgeschlagen (…): Quelle nicht erreichbar: … Vorhandene Veranstaltungen wurden nicht verändert.“ |
| HTTP-Status ≠ 200 | dito, „Quelle antwortet mit HTTP 500.“ |
| leerer Body | dito, „Quelle liefert eine leere Antwort.“ |
| keine Event-Container gefunden | dito, „Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.“ Das wird bewusst **nicht** als „0 Events“ gewertet |
| einzelner Eintrag unlesbar | wird übersprungen, als „fehlerhaft“ gezählt, Meldung gelb |
| Exception im Adapter | wird abgefangen (`Throwable`) und wie ein Quellenfehler behandelt, kein Fatal Error |
| DB-Fehler bei INSERT/UPDATE | Eintrag als „fehlerhaft“ gezählt |

In **keinem** Fall werden Events gelöscht, deaktiviert oder als abgesagt markiert.

Protokoll: `error_log( '[eventcrawler][<slug>] …' )`, nur wenn `WP_DEBUG` aktiv ist, also mit `WP_DEBUG_LOG` in
`wp-content/debug.log`. Protokolliert werden Abbrüche, übersprungene Einträge und DB-Fehler. Eine Log-Tabelle gibt es
nicht.

## 9. Auslösung, Laufzeit, Rücksicht auf die Quellen

- Nur manuell über den Button „Crawler starten“. **Kein WP-Cron und kein externer Cron** (getestet: kein
  Cron-Eintrag des Plugins).
- Synchron im Request, `set_time_limit( 120 )`. Burg Rabenstein braucht **einen** HTTP-Request pro Crawl
  (ca. 550 KB), weil keine Detailseiten abgerufen werden. Bad Steben braucht derzeit **3** Requests (Limit 250,
  1 s Pause dazwischen). Coburg braucht derzeit **15** Requests (42-Tage-Fenster, je Seite ca. 3 Tage, 1 s Pause),
  allein ca. 47 s. Ein Live-Crawl aller drei Quellen dauerte im Test **64–66 s** und liegt damit unter dem
  Zeitlimit von 120 s. Kommen weitere Quellen mit vielen Seiten hinzu, muss die Laufzeit neu bewertet werden
  (Zeitlimit oder Hintergrundverarbeitung; todo.md).
- Nur lesende GET-Anfragen, keine Formulare, keine Logins, keine Buchungen.
- Während der Entwicklung wird das HTML lokal zwischengespeichert. Parser-Tests laufen gegen diese Kopie, nicht live.

## 10. Tests Phase 3 (2026-09-24)

Umgebung: temporäre WordPress-7.1.2-Installation (PHP 8.3.12, MariaDB 10.4.32, Präfix `ectest_`, `WP_DEBUG` an).
Die Fehlerfälle und der UPDATE-Test liefen mit einem MU-Plugin **nur in der Testinstallation**, das die HTTP-Antwort
über `pre_http_request` ersetzt. Es ist nicht Teil des Projekts.

| Test | Ergebnis |
|---|---|
| Parser offline gegen gespeichertes HTML: 124 Events, Datum/Zeit/Titel/URL/Umlaute/Teaser korrekt, keine NBSP, kein „[...]“ | bestanden |
| Parser: leere Struktur → `WP_Error`; ungültiges Datum und Datum Text ≠ Attribut → `_error`; ohne Uhrzeit → `null` | bestanden |
| Filter: 21 Einzelfälle (Stammtisch, Politik, Parteinamen mit Wortgrenzen, Ortsregel Nürnberg/Fürth) | bestanden |
| Filter auf allen 124 echten Events: 0 fälschlich ausgeschlossen | bestanden |
| Live-Crawl 1 über den Admin-Button: 124 gefunden, 124 neu | bestanden |
| Live-Crawl 2: 0 neu, 124 unverändert, `last_seen_at` aktualisiert, `updated_at` unverändert, keine Duplikate | bestanden |
| UPDATE (Testdaten): Uhrzeit geändert → derselbe Datensatz aktualisiert; Datum bei gleicher URL geändert → derselbe Datensatz; Titel mit „Stammtisch“ → gefiltert, Bestand unverändert. Ergebnis: 2 aktualisiert, 121 unverändert, 1 gefiltert | bestanden |
| Netzwerkfehler, HTTP 500, leere Antwort, Strukturänderung → Fehlermeldung, DB unverändert, kein Fatal Error | bestanden |
| Ein kaputter Eintrag → 1 fehlerhaft, Rest verarbeitet | bestanden |
| Falscher Nonce, fehlender Nonce, GET statt POST, Abonnent, nicht angemeldet → 403/403/405/403/400, **kein** Abruf der Quelle | bestanden |
| Einzel- und Bulk-Löschen weiterhin; gelöschte Events werden beim nächsten Crawl wieder importiert (bekannt, keine Ignore-Liste) | bestanden |
| Keine PHP-Warnings/Notices in `debug.log` | bestanden |

## 11. Datenmodell Version 2 im Crawler (2026-09-24)

- Adapter und Crawler verwenden `start_date`, `start_time`, `end_date`, `end_time`, `all_day`, `venue_name`, `city`,
  `event_url`, `source_url` (Regeln: Abschnitt 5 und 02-datenbank.md Abschnitt 2).
- Matching unverändert: Priorität 1 `external_id`, 2 `source_url`, 3 `event_key`. Nicht über `event_url`.
- `content_hash` erweitert um Ende, Ganztag und `event_url` (02-datenbank.md Abschnitt 5).
- Die Endzeit wird gespeichert, aber **nicht** in das XML geschrieben (07-export.md).

Tests nach der Migration auf Version 2:

| Test | Ergebnis |
|---|---|
| Live-Crawl 1 nach Migration | 124 gefunden, 0 neu, **105 aktualisiert** (Endzeiten ergänzt), 19 unverändert, 0 gefiltert, 0 fehlerhaft |
| Live-Crawl 2 | 124 gefunden, 0 neu, 0 aktualisiert, 124 unverändert, keine Duplikate |
| Datenstand danach | start_time 124, end_time 105, end_date 105 (alle = start_date), all_day 0, venue_name „Burg Rabenstein“ 124, city „Ahorntal“ 124, event_url = source_url 124 |
| Burg Rabenstein mit verändertem HTML: Startzeit, Endzeit, Enddatum (data-endDate Folgetag) | 2 aktualisiert, dieselben Datensätze, kein Duplikat |
| Test-Adapter (nur im Testskript), stabile source_url: Änderung von start_time, end_time, end_date, venue_name, event_url, start_date | jeweils UPDATE desselben Datensatzes, danach „unverändert“, kein Duplikat |
| Konsistenz: gespeicherter Hash = neu berechneter Hash | 124 von 124 |
| Löschen einzeln und mehrfach, falscher Nonce → 403; gelöschte Events kommen beim nächsten Crawl zurück | bestanden |
| PHP-Warnings/Notices | keine |

Während dieser Tests wurde ein **Fehler im Repository** gefunden und behoben: Die wpdb-Formate wurden in der
Reihenfolge der Spaltenliste statt der übergebenen Daten gebildet. Mit der ersten `%d`-Spalte (`all_day`) landete
`%d` auf `venue_name`, Ergebnis `venue_name = 0`. Weil der Hash aus den korrekten Daten berechnet wurde, meldete ein
zweiter Crawl trotzdem „unverändert“. Nach der Korrektur wurde das Backup von vor der Migration zurückgespielt und
Migration und Crawls komplett wiederholt (Ergebnisse oben). Als Schutz gibt es einen Regressionstest und die
Prüfung „gespeicherter Hash = neu berechneter Hash“.

## 12. Mehrere Quellen (Stand 2026-09-24: Burg Rabenstein + Bad Steben)

> Stand seit 2026-09-24: Die Orchestrierung ist in den Browser gewandert (Abschnitt 14). Die folgenden Punkte gelten
> sinngemäß weiter; die damaligen Tests liefen noch mit einem gemeinsamen Request.

- „Crawler starten“ crawlt je nach Auswahl im Dropdown **alle** registrierten Quellen nacheinander oder **genau eine**
  Quelle. Es gibt einen einzigen Button. Die Quelle wird serverseitig gegen das Adapter-Register validiert; unbekannte
  Werte starten keinen Crawl. Die Statistik enthält nur tatsächlich ausgeführte Quellen.
- **Fehlerisolation:** Jede Quelle läuft in einem eigenen Request und dort in `try/catch`. Ein `WP_Error` oder eine
  Exception beendet nur diese Quelle. Deren Daten bleiben unverändert (keine Löschung, kein neues `last_seen_at`).
- Die Gesamtstatistik summiert nur die erfolgreichen Quellen und nennt „x von n Quellen erfolgreich“.
- Adapter dürfen `sleep()` zwischen eigenen Seitenabrufen nutzen (Bad Steben: 1 s).

Tests (2026-09-24):

| Test | Ergebnis |
|---|---|
| Live-Crawl 1 beider Quellen | Gesamt 684 gefunden, 557 neu, 124 unverändert, 3 fehlerhaft; Burg Rabenstein 124 unverändert; Bad Steben 560 gefunden, 557 neu, 3 fehlerhaft (ohne Stadt) |
| Live-Crawl 2 | 0 neu, 0 aktualisiert, 681 unverändert, 3 fehlerhaft, keine Duplikate |
| Bad Steben mit veränderten Testdaten (Uhrzeit, Endzeit, Ort, Link, Datum, Stammtisch-Titel) | 1 neu (verschobener Termin), 4 aktualisiert (dieselben Datensätze), 1 gefiltert, 551 unverändert; Wiederholung 0 Änderungen |
| Bad Steben fällt aus (Netzwerk, HTTP 500, kaputte Struktur), Burg Rabenstein ok | Burg Rabenstein verarbeitet, Bad Steben rot gemeldet, Bad-Steben-Daten unverändert |
| Burg Rabenstein fällt aus (Netzwerk, HTTP 500), Bad Steben ok | Bad Steben verarbeitet, Burg Rabenstein rot gemeldet, Daten unverändert |
| beide fallen aus | „0 von 2 Quellen erfolgreich“, keine Datenänderung |
| Crawl mit falschem Nonce / als Abonnent | 403, keine Quelle abgerufen |
| Hash-Konsistenz über beide Quellen | 681 von 681 |
| PHP-Warnings/Notices | keine |

### Erster Test des event_key-Fallbacks mit echten Daten

Bad Steben matcht über `external_id` (Priorität 1). Weil hier erstmals echte Daten mit **mehrfach gleichem event_key**
vorliegen (92 Fälle: dieselbe Serie mehrmals am Tag), wurde der Fallback (Priorität 3) zusätzlich geprüft. Dazu lief ein
Test-Adapter **nur im Testskript** mit denselben 560 Terminen, aber ohne `external_id`.

| Fall | Ergebnis |
|---|---|
| Lauf 1 / Lauf 2 identisch | 557 neu / 557 unverändert, keine Duplikate, trotz 92 doppelter event_keys |
| A: gleicher Titel, gleiche Stadt, anderes Datum | getrennte Datensätze |
| B: Uhrzeit geändert (zweiter Termin des Tages 16:00 → 17:00) | derselbe Datensatz; die Regel „noch nicht zugeordnete Kandidaten, gleiche Uhrzeit bevorzugt“ greift |
| C: Termin auf einen anderen Tag verschoben | neuer Datensatz, der alte bleibt (bekannte Grenze, 02-datenbank.md Abschnitt 4) |

Die Formel `source|title|city|start_date` wurde **nicht** geändert. Die echten Daten zeigten keinen Fehler, nur die
bekannte Grenze C. Ein Vorschlag dazu steht in 04-quellen.md (Bad Steben, „Vorschlag für Grenzfall C“).
Einschränkung von B: Hängt die Zuordnung von der Reihenfolge ab (Termine kommen chronologisch), kann bei gleichzeitiger
Änderung mehrerer Termine derselben Serie am selben Tag die Zuordnung Datensatz ↔ Termin wechseln. Duplikate entstehen
dabei nicht.

## 13. Dritte Quelle Coburg (2026-09-24)

Integration **ohne Änderungen** an Admin, Repository, Filter, Export oder Datenbank. Geändert bzw. neu:
- `class-eventcrawler-source-coburg.php` (neu)
- `eventcrawler.php` (`require_once`)
- `Eventcrawler_Crawler::get_sources()` (eine Zeile)
- `Eventcrawler_Source::resolve_german_date()` (neuer, wiederverwendbarer Datumshelfer; in Abschnitt 5 angekündigt)

Tests:

| Test | Ergebnis |
|---|---|
| Offline gegen 15 gespeicherte Seiten | 365 Termine, alle gültig, external_id eindeutig, keine Platzhalterzeiten, 3 × ganztägig, Stadtnormalisierung |
| Live-Crawl 1 nur Coburg | 365 gefunden, 365 neu, 0 aktualisiert, 0 unverändert, 0 gefiltert, 0 fehlerhaft; Burg Rabenstein und Bad Steben **nicht** abgerufen (Zähler 0) |
| Live-Crawl 2 nur Coburg | 365 gefunden, 0 neu, 0 aktualisiert, 365 unverändert |
| Veränderte Kopie (Titel, Start, Ende, Ort, Stadt, Termin-ID, Datum, Stadtratssitzung) | 1 neu (Datumsverschiebung), 6 aktualisiert (dieselben Datensätze), 1 gefiltert, 357 unverändert; Wiederholung 0 Änderungen |
| Alle Quellen (Kopien), Abrufzähler | Rabenstein 1, Bad Steben 2, Coburg 15 Abrufe |
| Coburg fällt aus (Netzwerk, HTTP 500, kaputte Struktur) | „2 von 3 Quellen erfolgreich“; Burg Rabenstein und Bad Steben verarbeitet; Coburg-Daten unverändert (365, altes `last_seen_at`) |
| Live-Crawl aller drei Quellen, 2 × | je 1048 gefunden, 0 neu, 0 aktualisiert, 1045 unverändert, 0 gefiltert, 3 fehlerhaft (Bad Steben); 64–66 s |
| Löschen Coburg einzeln + mehrfach (gefilterte Ansicht) | 1 bzw. 3 gelöscht, Filter bleibt, beim nächsten Crawl 4 neu |
| Hash-Konsistenz, PHP-Warnings, Duplikate | 0 Abweichungen, keine Warnings, keine Duplikate |

## 14. Eine Quelle = ein Request (Entkopplung des Gesamtcrawls, 2026-09-24)

**Vorher:** „Alle Quellen“ lief als **ein** `admin-post.php`-Request (`Eventcrawler_Admin::handle_run()`), der alle
Adapter nacheinander aufrief. Die Laufzeit war die **Summe** aller Quellen (drei Quellen: 64–66 s bei einem Limit von
120 s).

**Jetzt:** Der Browser orchestriert. Jede Quelle ist ein eigener, geschützter Request an `admin-ajax.php`
(`action=eventcrawler_crawl_source`, 06-admin.md Abschnitt 3). Die Requests laufen **nacheinander**, nie parallel:
fremde Websites werden nicht gleichzeitig belastet, und die Adapter-Pausen bleiben erhalten. Zwischen zwei Quellen gibt
es keine zusätzliche Pause, weil jede Quelle eine andere Domain anspricht. Die maximale Laufzeit eines Requests hängt
nur noch von der **langsamsten einzelnen Quelle** ab.

- Der Crawler-Core ist unverändert. Der Endpunkt wählt nur die Quelle, ruft `Eventcrawler_Crawler::run()` auf und gibt
  JSON zurück. Einzelquelle und „Alle Quellen“ nutzen **denselben** Codepfad.
- Quellenliste für „Alle Quellen“: serverseitig aus dem Adapter-Register (`window.eventcrawlerCrawl.sources`).
  Eine neue registrierte Quelle erzeugt automatisch einen weiteren Request, ohne Änderung am JavaScript oder Admin.
- Kein Cron, keine Queue, keine Job-Tabelle, keine serverseitige Sperre. Doppelklicks verhindert die Oberfläche
  (Button und Auswahl sind während des Laufs gesperrt).
- **Bekannte Einschränkung:** Der Crawl ist **kein persistenter Hintergrundjob**. Wird der Browser während
  „Alle Quellen“ geschlossen, bleiben bereits abgeschlossene Quellen gespeichert; noch nicht gestartete Quellen werden
  nicht verarbeitet. Für den MVP akzeptiert.

### Laufzeiten je Quelle (gemessen 2026-09-24, Testumgebung, PHP-Built-in-Server)

Live-Lauf „Alle Quellen“ im Browser (Edge headless):

| Quelle | Crawl-Requests | Seitenabrufe beim Anbieter | Dauer Request (Browser) | Dauer Crawl (Server) | Ergebnis |
|---|---|---|---|---|---|
| Burg Rabenstein | 1 | 1 | 2,2 s | 1,4 s | 124 gefunden, 124 unverändert |
| Bad Steben | 1 | 3 | 23,9 s | 21,7 s | 558 gefunden, 1 neu, 554 unverändert, 3 fehlerhaft |
| Coburg | 1 | 15 | 45,9 s | 45,2 s | 365 gefunden, 365 unverändert |
| **Gesamt** | **3** | 19 | Benutzersicht **75 s** (inkl. Anmeldung/Reload) | – | 1047 gefunden, 1 neu, 1043 unverändert, 3 fehlerhaft |

- Längster Einzelrequest: **Coburg ~46 s**, also etwa 38 % des Limits von 120 s je Request. Keine Quelle kommt dem
  Limit nahe. Eine Seiten-Queue innerhalb einer Quelle ist derzeit nicht nötig.
- Bad Steben live schwankt (Anbieter; 5–24 s beobachtet), aus der lokalen Kopie ~3–4 s.

### Tests

| Test | Ergebnis |
|---|---|
| „Alle Quellen“ live im Browser | genau **3** Crawl-Requests (burg-rabenstein → bad-steben → coburg), **ohne Überlappung** (jeder startet, wenn der vorige endet); Fortschritt sichtbar; Button/Auswahl gesperrt; Summe = Summe der Quellen; Reload, Zusammenfassung danach sichtbar |
| Einzelquelle Burg Rabenstein / Bad Steben / Coburg (Browser) | je **1** Request; nur diese Quelle abgerufen (Abrufzähler 1/0/0, 0/2/0, 0/0/15) |
| Bad Steben HTTP 500 | „2 von 3 Quellen erfolgreich (1 fehlgeschlagen)“; Coburg danach gestartet und verarbeitet; Bad-Steben-Daten unverändert |
| Coburg Netzwerkfehler | andere zwei verarbeitet; Coburg-Daten unverändert |
| eingeschleust: Sitzung abgelaufen (403 „-1“), PHP-Fehlerseite (500, kein JSON), Netzwerkabbruch | je Quelle verständliche Meldung, nächste Quelle startet trotzdem, Oberfläche wieder frei |
| Doppelklick (3 Klicks) | 3 Requests, jede Quelle einmal |
| Endpunkt direkt: unbekannte Quelle, leer, Klassenname / falscher Nonce, Export-Nonce / GET / Abonnent / nicht angemeldet | 400 / 403 / 405 / 403 / 400; kein Abruf |
| alter Endpunkt `admin-post.php?action=eventcrawler_run` | entfernt (400) |
| Regression: Duplikate, Endzeiten, Hash-Konsistenz, PHP-Warnings | keine Duplikate, Endzeiten unverändert, 0 Abweichungen, keine Warnings |

## 15. Vierte Quelle Theater Selb (2026-10-15)

Integration **ohne Änderungen** an Crawler-Core, Basisklasse, Admin, JavaScript, Repository, Filter, Export oder
Datenbank. Neu bzw. geändert:
- `class-eventcrawler-source-theater-selb.php` (neu)
- `eventcrawler.php` (`require_once`), `Eventcrawler_Crawler::get_sources()` (eine Zeile)
- `tools/test-theater-selb.php` (neu, 38 Parsertests mit eingebetteten HTML-Ausschnitten)

Der Adapter nutzt nur vorhandene Helfer (`fetch_html()` mit `to_utf8()`, `create_xpath()`, `clean_text()`,
`absolute_url()`, `resolve_german_date()`). Die Liste nennt das Jahr; `resolve_german_date()` bekommt dafür den
1. Januar dieses Jahres als Referenz und prüft den Wochentag. Erstmals ruft ein Adapter je Termin eine Detailseite ab
(Begründung: 04-quellen.md Abschnitt 4). Ein Fehler einer einzelnen Detailseite überspringt nur diesen Termin
(`_error`); ein Fehler der Liste bricht die Quelle ab.

Tests (Testinstallation WP 7.1.2, PHP 8.3.12, MariaDB 10.4.32; Mock nur dort, für Fehler- und Änderungsfälle):

| Test | Ergebnis |
|---|---|
| Parser offline gegen die lokal gespeicherte Liste + 30 Detailseiten | 30 Termine, 30 verschiedene Post-IDs, 0 fehlerhaft, 25 mit Beschreibung |
| `tools/test-theater-selb.php` (Wochentag, Jahr, Einlass ≠ Beginn, Liste ≠ Detail, Adressvarianten, fremde Adresse, fehlende Detailseite/ID, Dubletten, Struktur) | 38 bestanden |
| Live-Crawl 1 („Alle Quellen“ im Browser) | Theater Selb: 30 gefunden, 29 neu, 0 aktualisiert, 0 unverändert, 1 gefiltert, 0 fehlerhaft; **31 Abrufe** |
| Live-Crawl 2 | 30 gefunden, 0 neu, 0 aktualisiert, 29 unverändert, 1 gefiltert; keine Duplikate |
| Änderungen (Titel, Uhrzeit, Datum, Ort/Adresse, Beschreibung, URL, Filterbegriff) | 6 aktualisiert (dieselben Datensätze, auch bei Datumsänderung), 1 zusätzlich gefiltert; Wiederholung 0 Änderungen (04-quellen.md) |
| Liste HTTP 500 / Netzwerkfehler / kaputte Struktur | Quellenfehler, Daten unverändert |
| eine Detailseite HTTP 404 | nur dieser Termin „fehlerhaft“, sein Datensatz unverändert; Rest verarbeitet |
| „Alle Quellen“ mit Burg Rabenstein, Bad Steben, Coburg als Fehler (Mock) | „1 von 4 Quellen erfolgreich“, Theater Selb danach normal verarbeitet |
| Hash-Konsistenz alle Quellen, doppelte `external_id` | 0 Abweichungen (1385 Events), 0 Dubletten |
| PHP-Warnings/Notices (Plugin), JS-Fehler | keine |

### Laufzeiten „Alle Quellen“ mit 4 Quellen (live, 2026-10-15, Edge headless)

| Quelle | Lauf 1: Request / Crawl | Lauf 1: Ergebnis | Lauf 2: Request / Crawl | Lauf 2: Ergebnis |
|---|---|---|---|---|
| Burg Rabenstein | 1,7 s / 1,2 s | 151 gefunden, 35 neu, 3 aktualisiert, 113 unverändert | 1,8 s / 1,2 s | 151 unverändert |
| Bad Steben | 11,7 s / 11,3 s | 463 gefunden, 100 neu, 1 aktualisiert, 362 unverändert | 11,8 s / 11,2 s | 463 unverändert |
| Coburg | 44,2 s / 43,7 s | 354 gefunden, 131 neu, 3 aktualisiert, 219 unverändert, 1 fehlerhaft | 43,4 s / 42,7 s | 353 unverändert, 1 fehlerhaft |
| Theater Selb | 46,1 s / 45,6 s | 30 gefunden, 29 neu, 1 gefiltert | 46,3 s / 45,7 s | 29 unverändert, 1 gefiltert |
| **Summe** | ca. 104 s | 998 gefunden, 295 neu, 7 aktualisiert, 694 unverändert, 1 gefiltert, 1 fehlerhaft | ca. 103 s | 998 gefunden, 0 neu, 0 aktualisiert, 996 unverändert |

- Die vier Requests liefen strikt nacheinander (jeder startete, als der vorige endete), Fortschritt „Quelle 1 von 4“
  bis „Quelle 4 von 4“. Die Neuen bei den alten Quellen in Lauf 1 sind neue Termine der Quellen seit dem letzten Crawl
  (2026-10-01), nicht Folge dieser Änderung.
- Theater Selb ist jetzt die längste Quelle (~46 s, 38 % des Limits von 120 s je Request), fast nur Pausen
  (30 × 1 s). Keine Optimierung nötig; beobachten, falls der Spielplan deutlich wächst (todo.md).
- Coburg „1 fehlerhaft“: Termin „Weihnachtliches Puppentheater“ ohne Stadtangabe in der Quelle (bestehendes Verhalten).

## 16. Fünfte Quelle DAS ZENTRUM Bayreuth (2026-10-15)

Integration **ohne Änderungen** an Crawler-Core, Basisklasse, Admin, JavaScript, Repository, Filter, Export oder
Datenbank. Neu bzw. geändert:
- `class-eventcrawler-source-das-zentrum-bayreuth.php` (neu)
- `eventcrawler.php` (`require_once`), `Eventcrawler_Crawler::get_sources()` (eine Zeile)
- `tools/test-das-zentrum-bayreuth.php` (neu, 45 Tests mit eingebetteten API-Antworten)
- `tools/test-theater-selb.php`: erwartete Reihenfolge im Register um die fünfte Quelle ergänzt

Erste Quelle mit JSON statt HTML: `fetch_html()` liefert den Antworttext (Status-, Leer- und UTF-8-Prüfung wie bei
HTML), der Adapter dekodiert mit `json_decode()`. Nicht lesbares JSON oder fehlendes `events` → Quellenfehler
„Antwort der Veranstaltungs-API nicht lesbar“. Die Seitenzahl kommt aus `total_pages` der ersten Antwort.

| Test | Ergebnis |
|---|---|
| Parser offline gegen die gespeicherten 2 API-Seiten | 73 Termine, 73 IDs, 0 fehlerhaft, 6 mit Ende, 19 × Kleinkunstbühne mit Stadt, 54 mit Adresse |
| `tools/test-das-zentrum-bayreuth.php` | 45 bestanden |
| Live-Crawl 1 („Alle Quellen“, 5 Quellen) | DAS ZENTRUM: 73 gefunden, 73 neu; **2 Abrufe**; 3,4 s |
| Live-Crawl 2 | 73 gefunden, 0 neu, 0 aktualisiert, 73 unverändert |
| Änderungen (Titel, Beginn, Datum, Ort, Adresse, Stadt, URL, Langtext, Filterbegriff, fehlende Felder) | 7 aktualisiert (dieselben Datensätze, auch bei Datumsänderung), 1 gefiltert, 3 fehlerhaft; Wiederholung 0 Änderungen (04-quellen.md) |
| HTTP 500 / Netzwerkfehler / leere Antwort / kein JSON / Seite 2 HTTP 500 | Quellenfehler, Daten unverändert (Prüfsumme inkl. `last_seen_at` gleich) |
| „Alle Quellen“ mit Quellen 1–4 als Fehler (Mock) | „1 von 5 Quellen erfolgreich“, DAS ZENTRUM danach normal verarbeitet |
| Hash-Konsistenz, doppelte `external_id` | 0 Abweichungen (1459 Events), 0 Dubletten |
| PHP-Warnings/Notices, JS-Fehler | keine (nur `favicon.ico` 404 der Testinstallation) |

### Laufzeiten „Alle Quellen“ mit 5 Quellen (live, 2026-10-15, Edge headless)

| Quelle | Abrufe | Lauf 1: Request / Crawl | Lauf 1: Ergebnis | Lauf 2: Request / Crawl | Lauf 2: Ergebnis |
|---|---|---|---|---|---|
| Burg Rabenstein | 1 | 1,6 s / 1,1 s | 151 gefunden, 151 unverändert | 1,9 s / 1,4 s | 151 unverändert |
| Bad Steben | 2 (lt. Adapterlogik, 464 Treffer) | 18,0 s / 17,6 s | 464 gefunden, 1 neu, 463 unverändert | 13,6 s / 13,1 s | 464 unverändert |
| Coburg | ca. 15 (42-Tage-Fenster, nicht gezählt) | 43,3 s / 41,8 s | 354 gefunden, 353 unverändert, 1 fehlerhaft | 43,8 s / 43,3 s | 353 unverändert, 1 fehlerhaft |
| Theater Selb | 31 (gezählt) | 46,0 s / 44,6 s | 30 gefunden, 29 unverändert, 1 gefiltert | 46,0 s / 45,5 s | 29 unverändert, 1 gefiltert |
| DAS ZENTRUM Bayreuth | 2 (gezählt) | 3,8 s / 3,4 s | 73 gefunden, 73 neu | 4,3 s / 3,3 s | 73 unverändert |
| **Summe** | | ca. 113 s | 1072 gefunden, 74 neu, 996 unverändert, 1 gefiltert, 1 fehlerhaft | ca. 110 s | 1072 gefunden, 0 neu, 0 aktualisiert, 1070 unverändert |

- Strikt nacheinander, Fortschritt „Quelle 1 von 5“ bis „Quelle 5 von 5“. Längster Request weiterhin Theater Selb
  (~46 s, 38 % des Limits von 120 s je Request); DAS ZENTRUM ist mit ~4 s eine der schnellsten Quellen.
- Testwerkzeug-Hinweis: Ein headless Edge aus einem früheren Testlauf lief noch im Hintergrund und zeigte alte
  Verbindungsfehler; nach Beenden lief der Test in einem frischen Browser ohne JS-Fehler.

## 17. Sechste aktive Quelle Schützenhaus Münchberg (2026-10-15)

Zuerst Zugriff geprüft (A85): Programmseite, Startseite, robots.txt mit dem Plugin-User-Agent → 200. Integration
**ohne Änderungen** an Core, Basisklasse, Admin, JavaScript, Repository, Filter, Export oder Datenbank. Neu bzw.
geändert: Adapter, `require_once`, eine Zeile in `get_sources()`, `tools/test-schuetzenhaus-muenchberg.php` (35 Tests);
in `test-theater-selb.php` und `test-das-zentrum-bayreuth.php` nur die erwartete Reihenfolge im Register ergänzt.

| Test | Ergebnis |
|---|---|
| Parser offline (gespeicherte Seite) | 46 Einträge → 47 Termine, 46 gültig, 1 fehlerhaft (Quelle: „Sa, 27.02.07“) |
| `tools/test-schuetzenhaus-muenchberg.php` | 35 bestanden |
| Live-Crawl 1 (Alle Quellen) | 47 gefunden, 46 neu, 1 fehlerhaft; **1 Abruf**, 5,8 s |
| Live-Crawl 2 | 47 gefunden, 0 neu, 0 aktualisiert, 46 unverändert, 1 fehlerhaft |
| Änderungen | 5 aktualisiert (dieselben Datensätze, auch bei Datumsänderung), 1 gefiltert, 3 fehlerhaft; Wiederholung 0 |
| HTTP 403 / 429 / 503 / 500, Netzwerkfehler, leere Antwort, kaputte Struktur | Quellenfehler, Daten unverändert (inkl. `last_seen_at`) |
| „Alle Quellen“, Quellen 1–5 als Fehler | „1 von 6 Quellen erfolgreich“, Schützenhaus Münchberg danach normal |
| Hash-Konsistenz / doppelte `external_id` | 0 / 0 (1506 Events) |
| PHP-Warnings, JS-Fehler | keine |

Ein erster Live-Lauf scheiterte bei vier Quellen an einer kurzen **lokalen** Netzwerkstörung („Resolving timed out“,
„SSL connection timeout“); die Fehlerisolation griff (Burg Rabenstein und Bad Steben verarbeitet, Daten der anderen
unverändert). Wiederholung kurz danach ohne Fehler.

### Laufzeiten „Alle Quellen“ mit 6 aktiven Quellen (live, 2026-10-15)

| Quelle | Abrufe | Lauf 1: Request | Lauf 1: Ergebnis | Lauf 2: Request | Lauf 2: Ergebnis |
|---|---|---|---|---|---|
| Burg Rabenstein | 1 | 1,6 s | 151 unverändert | 2,0 s | 151 unverändert |
| Bad Steben | 2 | 16,7 s | 465 unverändert | 17,7 s | 465 unverändert |
| Coburg | ca. 15 | 43,7 s | 353 unverändert, 1 fehlerhaft | 43,6 s | 353 unverändert, 1 fehlerhaft |
| Theater Selb | 31 | 46,4 s | 29 unverändert, 1 gefiltert | 45,8 s | 29 unverändert, 1 gefiltert |
| DAS ZENTRUM Bayreuth | 2 | 3,7 s | 73 unverändert | 3,9 s | 73 unverändert |
| Schützenhaus Münchberg | 1 | 6,2 s | 47 gefunden, 46 neu, 1 fehlerhaft | 6,4 s | 46 unverändert, 1 fehlerhaft |
| **Summe** | | ca. 118 s | 1120 gefunden, 46 neu, 1071 unverändert | ca. 118 s | 1120 gefunden, 0 neu, 0 aktualisiert, 1117 unverändert |

Strikt nacheinander, „Quelle 1 von 6“ bis „Quelle 6 von 6“; längster Request weiter Theater Selb (~46 s).

## 18. Siebte aktive Quelle Stadtsteinach (2026-10-15)

Zugriff vorab geprüft (A85): Veranstaltungsseite (2 ×), Startseite, robots.txt → 200. Integration **ohne Änderungen**
an Core, Basisklasse, Admin, JavaScript, Repository, Filter, Export oder Datenbank: Adapter, `require_once`, eine Zeile
in `get_sources()`, `tools/test-stadtsteinach.php` (36 Tests); in den Tests von Theater Selb, DAS ZENTRUM und
Schützenhaus Münchberg nur die erwartete Reihenfolge ergänzt.

| Test | Ergebnis |
|---|---|
| Parser offline (5 Listenseiten, 47 Detailseiten) | 47 Termine, 32 gültig, 15 ohne belegte Stadt |
| Live-Crawl 1 / 2 | 47 gefunden, 32 neu, 15 fehlerhaft / 0 neu, 0 aktualisiert, 32 unverändert, 15 fehlerhaft; **52 Abrufe**, 73,7 s |
| Änderungen | Titel, Beginn, Datum, Ort → UPDATE; neue Index-ID → neuer Datensatz; Filterbegriff → gefiltert |
| HTTP 403/429/503/500, Netzwerkfehler, leere Antwort, kaputte Struktur | Quellenfehler, Daten unverändert |
| eine Detailseite 404 | nur dieser Termin „fehlerhaft“, sein Datensatz unverändert |
| „Alle Quellen“, Quellen 1–6 als Fehler | „1 von 7 Quellen erfolgreich“, Stadtsteinach danach normal |
| Hash / doppelte `external_id` | 0 / 0 (1538 Events); keine PHP-Warnings, keine JS-Fehler |

### Laufzeiten „Alle Quellen“ mit 7 aktiven Quellen (live, 2026-10-15)

| Quelle | Abrufe | Lauf 1 | Lauf 2 | Lauf 2: Ergebnis |
|---|---|---|---|---|
| Burg Rabenstein | 1 | 2,1 s | 1,9 s | 151 unverändert |
| Bad Steben | 2 | 20,3 s | 15,3 s | 465 unverändert |
| Coburg | ca. 15 | 45,4 s | 44,2 s | 353 unverändert, 1 fehlerhaft |
| Theater Selb | 31 | 46,4 s | 46,7 s | 29 unverändert, 1 gefiltert |
| DAS ZENTRUM Bayreuth | 2 | 3,9 s | 3,7 s | 73 unverändert |
| Schützenhaus Münchberg | 1 | 5,7 s | 6,2 s | 46 unverändert, 1 fehlerhaft |
| Stadtsteinach | 52 | 74,6 s | 74,3 s | 32 unverändert, 15 fehlerhaft |
| **Summe** | | ca. 198 s | ca. 192 s | 1167 gefunden, 0 neu, 0 aktualisiert, 1149 unverändert |

Stadtsteinach ist jetzt der längste Einzelrequest (~74 s, 62 % des Limits von 120 s je Request). Das Limit gilt je
Quelle; die Gesamtdauer von „Alle Quellen“ ist dafür unerheblich. Beobachten, falls die Terminzahl stark steigt (todo.md).

### Nachtrag: Ort→Stadt-Zuordnung Stadtsteinach (A88, 2026-10-15)

Adapteränderung ohne zusätzliche Requests: Live-Crawl 1 nach der Änderung (nur Stadtsteinach) 47 gefunden, **9 neu**,
0 aktualisiert, 32 unverändert, 0 gefiltert, 6 fehlerhaft; 52 Abrufe, 73,4 s. Die bestehenden 32 Datensätze blieben
unverändert (Hash und `updated_at` gleich). Live-Crawl 2 als „Alle Quellen“ (7 Quellen, ca. 190 s): Stadtsteinach
47 gefunden, 0 neu, 0 aktualisiert, 41 unverändert, 6 fehlerhaft (73,6 s); alle anderen Quellen 0 neu, 0 aktualisiert.
Tests: `tools/test-stadtsteinach.php` 53 (vorher 36), übrige Suites unverändert grün; 1547 Events, Hash-Konsistenz 0
Abweichungen, keine PHP-Warnings, keine JS-Fehler.

## 19. Achte aktive Quelle Helmbrechts (2026-10-15)

Zugriff vorab geprüft (A85): Veranstaltungsseite (2 ×), Startseite, robots.txt → 200, kein Bot-Schutz. Integration
**ohne Änderungen** an Core, Basisklasse, Admin, JavaScript, Repository, Filter, Export oder Datenbank: Adapter,
`require_once`, eine Zeile in `get_sources()`, `tools/test-helmbrechts.php` (41 Tests); in den Tests der übrigen
neueren Quellen nur die erwartete Reihenfolge ergänzt.

| Test | Ergebnis |
|---|---|
| Parser offline (2 Monatsansichten, 42 Detailseiten) | 49 Termine, 47 gültig, 2 ohne Ort |
| Live-Crawl 1 (Alle Quellen) / Live-Crawl 2 (nur Helmbrechts) | 49 gefunden, 47 neu, 2 fehlerhaft / 0 neu, 0 aktualisiert, 47 unverändert; **44 Abrufe**, 74–77 s |
| Änderungen | Titel, Beginn, Ort, Adresse, Stadt, Ende, Teaser → UPDATE; Datumsverschiebung und neuer Slug → neuer Datensatz; Filterbegriff → gefiltert; Wiederholung 0 |
| HTTP 403/429/503/500, Netzwerkfehler, leere Antwort, kaputte Struktur | Quellenfehler, Daten unverändert (inkl. `last_seen_at`) |
| eine Detailseite 404 | nur diese Veranstaltung „fehlerhaft“, ihr Datensatz unverändert |
| „Alle Quellen“, Quellen 1–7 als Fehler | „1 von 8 Quellen erfolgreich“, Helmbrechts danach normal |
| Hash / doppelte `external_id` | 0 / 0 (1596 Events); keine PHP-Warnings, keine JS-Fehler |

Nebenbefund im Live-Lauf: Schützenhaus Münchberg meldete 4 „aktualisiert“ – die Quelle hatte die vier „zamg’hockt“-Termine
umbenannt und neue Teaser gesetzt; dieselben Datensätze wurden über die Post-ID aktualisiert (realer Beleg für A86).

### Laufzeiten „Alle Quellen“ mit 8 aktiven Quellen (live, 2026-10-15, Edge headless)

| Quelle | Abrufe | Request | Ergebnis |
|---|---|---|---|
| Burg Rabenstein | 1 | 2,6 s | 151 unverändert |
| Bad Steben | 2 | 15,9 s | 465 gefunden, 2 neu, 463 unverändert |
| Coburg | ca. 15 | 46,3 s | 353 unverändert, 1 fehlerhaft |
| Theater Selb | 31 | 46,8 s | 29 unverändert, 1 gefiltert |
| DAS ZENTRUM Bayreuth | 2 | 3,9 s | 73 unverändert |
| Schützenhaus Münchberg | 1 | 7,3 s | 4 aktualisiert (Quelle geändert), 42 unverändert, 1 fehlerhaft |
| Stadtsteinach | 52 | 74,7 s | 41 unverändert, 6 fehlerhaft |
| Helmbrechts | 44 | 77,9 s | 49 gefunden, 47 neu, 2 fehlerhaft |
| **Summe** | ca. 148 | **ca. 275 s** (Browser-Lauf 4:50 min inkl. Anmeldung/Reload) | 1216 gefunden, 49 neu, 4 aktualisiert, 1152 unverändert, 1 gefiltert, 10 fehlerhaft |

- Strikt nacheinander, „Quelle 1 von 8“ bis „Quelle 8 von 8“; der Browser-AJAX-Lauf lief stabil durch.
- Längster Einzelrequest jetzt Helmbrechts (~78 s, 65 % des Limits von 120 s je Request), gefolgt von Stadtsteinach
  (~75 s). Die Gesamtdauer wächst mit jeder Quelle (inzwischen fast 5 Minuten im offenen Browser); ein Hintergrundjob
  (WP-Cron) wird damit wichtiger (todo.md), ist aber bewusst noch nicht gebaut.

## 20. Neunte aktive Quelle Kronach (2026-10-15)

Zugriff vorab geprüft (A85): Veranstaltungsseite, Startseite, robots.txt → 200, kein Bot-Schutz. Integration **ohne
Änderungen** an Core, Basisklasse, Admin, JavaScript, Repository, Filter, Export oder Datenbank: Adapter,
`require_once`, eine Zeile in `get_sources()`, `tools/test-kronach.php` (46 Tests); in den Tests der übrigen neueren
Quellen nur die erwartete Reihenfolge ergänzt. Der Adapter nutzt `resolve_german_date()` der Basisklasse für das Jahr
und baut die Detail-URL selbst (`absolute_url()` entfernt die Query, die hier die Termin-ID trägt).

| Test | Ergebnis |
|---|---|
| Parser offline (9 Listenseiten, 36 Detailseiten) | 106 Termine, 105 gültig, 1 ohne Ort |
| Live-Crawl 1 (Alle Quellen) / Live-Crawl 2 (nur Kronach) | 106 gefunden, 103 neu, 2 gefiltert, 1 fehlerhaft / 0 neu, 0 aktualisiert, 103 unverändert, Hashes gleich; **45 Abrufe**, 68–71 s |
| Änderungen (veränderte Kopien) | Titel, Uhrzeit, Datum, Ort, Adresse, Stadt → 6 × UPDATE desselben Datensatzes; Filterbegriff → gefiltert; zurück auf Original → 6 × UPDATE |
| HTTP 403/429/503/500, Netzwerkfehler, leere Antwort, kaputte Struktur | Quellenfehler, Daten unverändert (Prüfsumme inkl. `last_seen_at`) |
| eine Detailseite 404 (Nordwaldhalle) | nur dieser Termin „fehlerhaft“, sein Datensatz unverändert |
| „Alle Quellen“, Quellen 1–8 als Fehler | „1 von 9 Quellen erfolgreich“, Kronach danach normal |
| Hash / doppelte `external_id` | 0 / 0 (1699 Events); keine PHP-Warnings, keine JS-Fehler |

### Laufzeiten „Alle Quellen“ mit 9 aktiven Quellen (live, 2026-10-15, Edge headless)

| Quelle | Abrufe | Request | Ergebnis |
|---|---|---|---|
| Burg Rabenstein | 1 | 2,0 s | 151 unverändert |
| Bad Steben | 2 | 11,6 s | 465 unverändert |
| Coburg | ca. 15 | 47,0 s | 353 unverändert, 1 fehlerhaft |
| Theater Selb | 31 | 45,7 s | 29 unverändert, 1 gefiltert |
| DAS ZENTRUM Bayreuth | 2 | 4,5 s | 73 unverändert |
| Schützenhaus Münchberg | 1 | 5,8 s | 46 unverändert, 1 fehlerhaft |
| Stadtsteinach | 52 | 75,9 s | 41 unverändert, 6 fehlerhaft |
| Helmbrechts | 44 | 76,5 s | 47 unverändert, 2 fehlerhaft |
| Kronach | 45 | 70,8 s | 106 gefunden, 103 neu, 2 gefiltert, 1 fehlerhaft |
| **Summe** | ca. 193 | **ca. 340 s** (Browser-Lauf 5:52 min inkl. Anmeldung/Reload) | 1322 gefunden, 103 neu, 0 aktualisiert, 1205 unverändert, 3 gefiltert, 11 fehlerhaft |

- Strikt nacheinander, „Quelle 1 von 9“ bis „Quelle 9 von 9“; der Browser-AJAX-Lauf lief stabil durch.
- Kein Einzelrequest nahe 120 s (längster Helmbrechts/Stadtsteinach ~76 s, 64 %). Die Gesamtdauer liegt jetzt bei
  fast 6 Minuten im offenen Browser; ein Hintergrundjob (WP-Cron) bleibt offen (todo.md) und ist bewusst nicht gebaut.

## 21. Zehnte aktive Quelle Bayreuth Tourismus (2026-10-15)

Zugriff vorab geprüft (A85): Website, Kalender, robots.txt, API → 200; TLS der API mit dem CA-Bundle von WordPress und
`wp_remote_get()` (Standard `sslverify`) erfolgreich. Integration **ohne Änderungen** an Core, Basisklasse, Admin,
JavaScript, Repository, Filter, Export oder Datenbank: Adapter, `require_once`, eine Zeile in `get_sources()`,
`tools/test-bayreuth-tourismus.php` (45 Tests); in den Tests der übrigen neueren Quellen nur die Reihenfolge ergänzt.
Der Ausschluss des Orts DAS ZENTRUM (A91) liegt vollständig im Adapter.

| Test | Ergebnis |
|---|---|
| Parser offline (echte API-Antwort 28 Tage + Ortsfilter) | 563 Termine, 15 DAS ZENTRUM ausgeschlossen, 548 geliefert, 541 gültig, 7 ohne Ort |
| Live-Crawl 1 (Alle Quellen) / Live-Crawl 2 (nur Bayreuth Tourismus) | 548 gefunden, 538 neu, 3 gefiltert, 7 fehlerhaft / 0 neu, 0 aktualisiert, 538 unverändert, Hashes gleich; **2 Abrufe**, 3–4 s |
| DAS ZENTRUM nach beiden Läufen | 73 Datensätze, `content_hash` und `updated_at` unverändert; 0 Bayreuth-Tourismus-Datensätze am Ort DAS ZENTRUM |
| Änderungen (veränderte Kopie der API-Antwort) | Titel, Kurztext, Beginn, Ende, Ort, Adresse, Stadt, URL → 8 × UPDATE desselben Datensatzes; Datum +1 Tag → neuer Datensatz (dokumentierte Grenze); Filterbegriff → gefiltert |
| HTTP 403/429/503/500, Netzwerkfehler, **TLS-Fehler (cURL 60)**, leere Antwort, kaputtes JSON, leere Ergebnisliste, **Pagination bricht ab** (Seite 2: 500), **Ortsfilter-Abruf 500** | Quellenfehler, Daten unverändert (Prüfsumme inkl. `last_seen_at`) |
| „Alle Quellen“, Quellen 1–9 als Fehler | „1 von 10 Quellen erfolgreich“, Bayreuth Tourismus normal |
| Hash / doppelte `external_id` | 0 / 0 (2238 Events); keine PHP-Warnings, keine JS-Fehler |

### Laufzeiten „Alle Quellen“ mit 10 aktiven Quellen (live, 2026-10-15, Edge headless)

| Quelle | Abrufe | Request | Ergebnis |
|---|---|---|---|
| Burg Rabenstein | 1 | 1,7 s | 151 unverändert |
| Bad Steben | 2 | 12,5 s | 1 neu, 464 unverändert |
| Coburg | ca. 15 | 44,5 s | 353 unverändert, 1 fehlerhaft |
| Theater Selb | 31 | 45,7 s | 29 unverändert, 1 gefiltert |
| DAS ZENTRUM Bayreuth | 2 | 4,7 s | 73 unverändert |
| Schützenhaus Münchberg | 1 | 6,4 s | 46 unverändert, 1 fehlerhaft |
| Stadtsteinach | 52 | 76,5 s | 41 unverändert, 6 fehlerhaft |
| Helmbrechts | 44 | 75,9 s | 47 unverändert, 2 fehlerhaft |
| Kronach | 45 | 69,8 s | 103 unverändert, 2 gefiltert, 1 fehlerhaft |
| Bayreuth Tourismus | 2 | 3,8 s | 548 gefunden (15 DAS ZENTRUM vorher ausgeschlossen), 538 neu, 3 gefiltert, 7 fehlerhaft |
| **Summe** | ca. 195 | **ca. 342 s** (Browser-Lauf ca. 6 min inkl. Anmeldung/Reload) | 1870 gefunden, 539 neu, 0 aktualisiert, 1307 unverändert, 6 gefiltert, 18 fehlerhaft |

- Strikt nacheinander, „Quelle 1 von 10“ bis „Quelle 10 von 10“; Browser-AJAX stabil.
- Bayreuth Tourismus verlängert den Gesamtlauf kaum (API, eine Seite). Längster Einzelrequest weiter Stadtsteinach /
  Helmbrechts (~76 s, 64 % von 120 s).

## 22. Thurnau und Hof zurückgestellt (2026-10-15)

- **Thurnau:** TLS-Prüfung ausschließlich über WordPress (`wp_remote_get()`, `sslverify=true`, Plugin-User-Agent,
  WordPress 7.1.2, PHP 8.3.12, cURL 8.7.0 / OpenSSL 3.0.15): Startseite, Veranstaltungsseite, robots.txt und
  `thurnau.de` → jeweils `WP_Error` „cURL error 60: SSL certificate problem: self-signed certificate in certificate
  chain“. Ursache: Die Kette endet in „ISRG Root YR“, die im CA-Bundle von WordPress fehlt (04-quellen.md). Kein Adapter,
  keine Codeänderung (A92).
- **Hof:** über WordPress 2 × HTTP 503 (Veranstaltungsseite, robots.txt), neutraler curl-User-Agent für robots.txt: 200
  → Sperre der Kennung (A85), zurückgestellt.
- Aktive Quellen unverändert 10; kein Live-Crawl, keine Änderung an Plugin oder Tests in dieser Phase.
