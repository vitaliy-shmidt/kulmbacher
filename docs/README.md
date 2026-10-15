# Veranstaltungscrawler – Projektdokumentation

## Projektziel

Ein WordPress-Plugin crawlt Veranstaltungen von mehreren externen Websites (Oberfranken, Coburg, Nürnberg/Fürth),
filtert unerwünschte Einträge (Stammtische, politische Veranstaltungen), speichert die Veranstaltungen in einer
**eigenen Datenbanktabelle** (nicht als WordPress-Posts), erkennt sie bei späteren Crawls wieder, zeigt sie im
Backend an und exportiert sie im **bestehenden XML-Minimalformat** (`<Root>/<Textabschnitt>/<h1>/<h2>/<p>`).

Erste Ausbaustufe ist ein bewusst kleiner **MVP / Kundendemo**.

## Speicherorte

| Was | Pfad |
|---|---|
| WordPress-Plugin (Quellcode) | `C:\xampp\htdocs\kulmbacher\wp-plugin` |
| Dokumentation | `C:\xampp\htdocs\kulmbacher\docs` |
| Regeln für KI-Assistenten | `C:\xampp\htdocs\kulmbacher\AGENTS.md` (+ `CLAUDE.md` verweist darauf) |
| XML-Referenzdatei | `docs/referenz/veranstaltungen-export-2026-09-30.xml` – **liegt noch nicht vor** (siehe [07-export.md](07-export.md)) |
| Entwickler-Werkzeuge (nicht Teil des Plugins) | `C:\xampp\htdocs\kulmbacher\tools` |
| Kundenplugin „WP Kulmbacher Land“ (nur Referenz, nicht ändern) | `C:\xampp\htdocs\kulmbacher\wp-events-manager`, siehe [09-wpem-integration.md](09-wpem-integration.md) |

## Aktueller Entwicklungsstand (Stand: 2026-10-15)

- **Phase 1 – Dokumentation:** erledigt.
- **Phase 2 – Plugin-Grundgerüst:** erledigt und in einer WordPress-Testinstallation getestet:
  - Plugin-Bootstrap
  - Aktivierung mit Tabellenanlage per `dbDelta()`
  - Repository
  - Admin-Menü (heute „Eventcrawler“, siehe unten)
  - Veranstaltungsliste
  - Einzel- und Bulk-Löschen
- **Phase 3 – erster Crawler (Proof of Concept Burg Rabenstein):** erledigt und getestet:
  - Source-Adapter-Basis und Adapter Burg Rabenstein
  - Crawler-Service mit Normalisierung, zentralem Filter (Stammtisch, Politik, Ortsregel), Matching, INSERT/UPDATE
    und Statistik
  - Button „Crawler starten“ im Admin mit Ergebnismeldung
  - Live-Crawl: 124 Veranstaltungen, zweiter Crawl ohne Duplikate
- **Phase 4 – XML-Export:** erledigt und getestet:
  - Button „XML exportieren“ lädt `veranstaltungen-export-JJJJ-MM-TT.xml` mit **allen** gespeicherten Veranstaltungen
    herunter, im Minimalformat `<Root>/<Textabschnitt>/<h1>/<h2>/<p>` (siehe [07-export.md](07-export.md))
  - Test mit 124 echten Events: 109 `<h1>`, 109 `<h2>`, 124 `<p>`, wohlgeformt, Daten unverändert
  - Die Admin-Spalte „Quelle“ zeigt den Anzeigenamen („Burg Rabenstein“) statt des Slugs
- **Datenmodell Version 2:** erledigt und getestet:
  - neutrales Veranstaltungsmodell mit `start_date`/`start_time`, `end_date`/`end_time`, `all_day`, `venue_name`,
    `city`, `event_url` und `source_url`
  - Migration von Version 1 ohne Datenverlust (124 von 124 Events, Feld für Feld geprüft), auch bei aktivem Plugin
  - Burg Rabenstein speichert jetzt Endzeiten (105 von 124)
  - Die Admin-Liste zeigt „18:00–22:00“. Der XML-Export ist unverändert (keine Endzeit im XML)
  - Fachliche (nicht technische!) Kompatibilität mit dem vorhandenen manuellen Veranstaltungs-Plugin:
    [08-kompatibilitaet.md](08-kompatibilitaet.md)
- **Zweite Quelle Bad Steben:** erledigt und getestet:
  - Die Events kommen von eT4/destination.one (`pages.et4.de`), nicht direkt von bad-steben.de
  - 560 Termine live gefunden, 557 gespeichert, 3 übersprungen (ohne Stadtangabe)
  - Matching über `external_id` = Serien-ID + Datum + laufende Nummer (siehe [04-quellen.md](04-quellen.md))
  - „Crawler starten“ crawlt beide Quellen nacheinander, mit Gesamt- und Quellenstatistik; der Ausfall einer Quelle
    blockiert die andere nicht
  - Der event_key-Fallback wurde erstmals mit echten Daten getestet
  - Der XML-Export enthält beide Quellen unverändert im Kundenformat
- **Admin-Menü:** „Eventcrawler“, Seitentitel „Importierte Veranstaltungen“. Umbenannt, weil ein anderes Plugin für
  manuelle Veranstaltungen bereits „Veranstaltungen“ belegt (siehe [06-admin.md](06-admin.md)).
- **Backend-Erweiterung:** erledigt und getestet (siehe [06-admin.md](06-admin.md)):
  - Crawler für alle Quellen oder eine ausgewählte Quelle
  - Listenfilter nach Quelle, Monat und Jahr, Freitextsuche; kombinierbar, bleiben bei Paginierung, Sortierung und
    Löschen erhalten
  - XML-Export filterbar nach Quelle, Monat und Jahr ([07-export.md](07-export.md)); XML-Format unverändert
  - Alle Quellen-Dropdowns kommen automatisch aus dem Adapter-Register
- **Dritte Quelle Coburg:** erledigt und getestet (siehe [04-quellen.md](04-quellen.md)):
  - TYPO3/events2, verlinkte Tagesseiten, Zeitfenster 42 Tage (15 Abrufe)
  - 365 Termine live, alle gespeichert; Matching über Serien-Slug + Datum + laufende Nummer
  - Erste echte Ganztagstermine (3)
  - Integration ohne Änderung an Admin, Repository, Filter und Export: nur Adapter, Registrierung und ein neuer
    Datumshelfer in der Basisklasse
  - Crawl aller drei Quellen: ca. 65 s (damals noch in einem Request)
- **Entkopplung des Gesamtcrawls:** erledigt und getestet (siehe [03-crawler.md](03-crawler.md) Abschnitt 14):
  - **eine Quelle = ein eigener Request** (`admin-ajax.php`, `action=eventcrawler_crawl_source`)
  - „Alle Quellen“ arbeitet der Browser nacheinander ab, mit Fortschrittsanzeige, Ergebnis je Quelle und Summe
  - Die Quellenliste kommt aus dem Adapter-Register; eine vierte Quelle braucht keine Admin- oder JS-Änderung
  - Längster Einzelrequest: Coburg ~46 s (Limit 120 s je Request)
  - Einschränkung: kein persistenter Job; wird der Browser geschlossen, entfallen noch nicht gestartete Quellen
- **Filterregeln im Backend:** erledigt und getestet (siehe [05-filter.md](05-filter.md), [06-admin.md](06-admin.md)):
  - Untermenü „Eventcrawler → Filterregeln“: Begriffe anlegen, bearbeiten, aktivieren/deaktivieren, löschen; Feld
    „Text prüfen“
  - Speicherung in der Option `eventcrawler_filter_rules`, keine neue Tabelle, keine Schemaänderung
  - Die bisherigen Begriffe werden einmalig als Default-Regeln angelegt (35 Regeln); gelöschte oder deaktivierte
    Defaults kommen nicht zurück
  - Filterverhalten unverändert (1.093 Vergleichsfälle identisch); bereits gespeicherte Events werden durch neue
    Regeln nicht verändert
- **Kundenfeedback zum XML (2026-09-30):** erledigt und getestet (siehe [07-export.md](07-export.md) Abschnitt 10):
  - „Caf√©“: Quelle, DB und XML sind korrektes UTF-8; das Programm beim Kunden öffnet die Datei als Mac Roman.
    Keine Datenkorrektur, kein BOM (erst nach Rückmeldung des Kunden)
  - sichtbare URL im XML ohne `http://`/`https://`, gespeichert bleibt die volle URL
  - kleine Textregeln im Export (unsichtbare Zeichen, Ort nicht doppelt zum Titel); Analyse „zu viele Infos“ mit
    offenen Fragen an den Kunden
  - Kundenplugin „WP Kulmbacher Land“ als Referenz gelesen, nicht verändert ([09-wpem-integration.md](09-wpem-integration.md))
  - Der in InDesign bewährte WPEM-Export ist die technische Format-Referenz: Bytevergleich mit der echten Datei
    (`veranstaltungen-export-2026-09-30.xml` im Projektordner), Sonderzeichen bytegleich; unser XML jetzt ebenfalls ohne
    Einrückung und ohne LF am Ende, weiterhin ohne BOM ([07-export.md](07-export.md) Abschnitt 11)
- **Zweites Kundenfeedback zum XML (2026-10-02):** erledigt und getestet (siehe [07-export.md](07-export.md) Abschnitt 12):
  - Grundsatz: keine semantischen Vermutungen, nur strukturierte Daten und exakte Regeln (A66)
  - sichtbarer Link nur noch als Hostname (`pages.et4.de`), gespeichert bleibt die volle URL
  - Schema-Version 3: Feld `venue_address`; Coburg liefert die Straße strukturiert (341 von 365 Terminen)
  - Ort/Adresse nicht doppelt (Titel, Beschreibung, Stadt); mehrere Uhrzeiten identischer Termine in einer Zeile,
    nur im Export (1048 Termine → 942 Zeilen)
  - Filterregel `bürgerversammlung`, einmalig auch in bestehenden Installationen ergänzt
  - Datenlage je Quelle (Adresse, Veranstalter, Treffpunkt, Telefon) in [04-quellen.md](04-quellen.md); nicht
    automatisierbare Wünsche (Marketingtext, Kürzen) dokumentiert
  - Tests: `tools/test-export-rules.php` (97 Tests)
- **Link-Bezeichnungen und DB-Detail-Modal (2026-10-06):** erledigt und getestet (siehe [06-admin.md](06-admin.md)
  Abschnitte 10/11, [07-export.md](07-export.md) Abschnitt 13):
  - Untermenü „Eventcrawler → Link-Bezeichnungen“: je Domain ein frei wählbarer Text für „Info:“ im XML
    (`pages.et4.de` → „Bad Steben“); global nach Domain, nicht je Quelle; Fallback auf die Domain; gespeicherte URLs
    unverändert; Option `eventcrawler_export_domain_mappings`, keine Schemaänderung, keine Default-Einträge
  - Ohne Einträge ist der Export bytegleich mit dem Stand vom 2026-10-02
  - Liste: „Details“ öffnet ein read-only Modal mit allen gespeicherten Spalten eines Events (AJAX
    `eventcrawler_get_event_details`, NULL als „NULL“, volle URLs) – Werkzeug für die Datenqualität neuer Quellen
  - Tests: `tools/test-domain-mappings.php` (90 Tests), HTTP-Sicherheitstests, Browsertest (XSS), Regression
- **Vierte Quelle Theater Selb (2026-10-15):** erledigt und getestet (siehe [04-quellen.md](04-quellen.md) Abschnitt 4):
  - WordPress-Website des Rosenthal-Theaters; Liste `/spielplan/` + eine Detailseite je Termin (31 Abrufe, ~46 s)
  - Matching über die WordPress-Post-ID je Aufführung: Verschiebungen werden als UPDATE erkannt
  - Ort/Stadt nur über die Adresse des Termins belegt („Rosenthal-Theater Selb“, „Selb“), Adresse als `venue_address`
  - Live 30 Termine, 29 gespeichert, 1 gefiltert (realer Fehlalarm `bezirkstag`, [05-filter.md](05-filter.md) Abschnitt 9)
  - Integration ohne Änderung an Core, Admin, JS, Filter, Export und DB; „Alle Quellen“ mit 4 Quellen ~104 s
  - Tests: `tools/test-theater-selb.php` (38 Tests)
- **Fünfte Quelle DAS ZENTRUM Bayreuth (2026-10-15):** erledigt und getestet (siehe [04-quellen.md](04-quellen.md) Abschnitt 5):
  - WordPress mit The Events Calendar; gelesen wird die öffentliche REST-API (JSON, 2 Abrufe, ~4 s), keine Detailseiten
  - Matching über die TEC-Post-ID je Termin: Verschiebungen werden als UPDATE erkannt
  - 73 Termine live gespeichert; Ort und Adresse aus der API, Kleinkunstbühne mit Stadt über die Ort-ID (A82)
  - Überschneidung mit Bayreuth Tourismus analysiert (ca. 40 von 73 Terminen), **keine** quellenübergreifende Deduplizierung
  - Integration ohne Änderung an Core, Admin, JS, Filter, Export und DB; „Alle Quellen“ mit 5 Quellen ~113 s
  - Tests: `tools/test-das-zentrum-bayreuth.php` (45 Tests)
- **Sechste aktive Quelle Schützenhaus Münchberg (2026-10-15):** erledigt und getestet (siehe [04-quellen.md](04-quellen.md) Abschnitt 9):
  - Zugriff mit dem Plugin-User-Agent geprüft (200); WordPress/Elementor, eine Programmseite (1 Abruf, ~6 s)
  - Matching über Post-ID + laufende Nummer; Verschiebungen werden als UPDATE erkannt
  - 46 Termine gespeichert; fester Ort „Schützenhaus Münchberg, Hofer Straße 69“ (A86); Einlass ≠ Beginn
  - Integration ohne Änderung an Core, Admin, JS, Filter, Export und DB; „Alle Quellen“ mit 6 Quellen ~118 s
  - Tests: `tools/test-schuetzenhaus-muenchberg.php` (35 Tests)
- **Siebte aktive Quelle Stadtsteinach (2026-10-15):** erledigt und getestet (siehe [04-quellen.md](04-quellen.md) Abschnitt 11):
  - TYPO3/calendarize; 5 Listenseiten + eine Detailseite je Termin (52 Abrufe, ~74 s, längste Quelle)
  - Matching über die calendarize-Index-ID
  - Stadt nur belegt, wenn der Ortstext „Stadtsteinach“ enthält (A87) oder der Ort in einer extern belegten
    Ort→Stadt-Zuordnung steht (A88, 5 Orte, z. B. Bauernmarktscheune → Stadtsteinach, Pfarrzentrum St. Hedwig Kulmbach →
    Kulmbach): 41 von 47 Terminen gespeichert, 6 ohne Ort übersprungen
  - Integration ohne Änderung an Core, Admin, JS, Filter, Export und DB; „Alle Quellen“ mit 7 Quellen ~195 s
  - Tests: `tools/test-stadtsteinach.php` (53 Tests)
- **Achte aktive Quelle Helmbrechts (2026-10-15):** erledigt und getestet (siehe [04-quellen.md](04-quellen.md) Abschnitt 12):
  - TYPO3 news + calendarize; Termine aus 2 Monatsansichten, Ort/Adresse/Stadt/Teaser von der Detailseite (44 Abrufe, ~78 s)
  - Matching über Slug + Datum + Nummer; „00:00“ der Monatsansicht = keine Uhrzeit
  - 47 von 49 Terminen gespeichert (2 ohne Ort); keine Überschneidung mit den aktiven Quellen
  - Integration ohne Änderung an Core, Admin, JS, Filter, Export und DB; „Alle Quellen“ mit 8 Quellen ~275 s
  - Tests: `tools/test-helmbrechts.php` (41 Tests)
- **Neunte aktive Quelle Kronach (2026-10-15):** erledigt und getestet (siehe [04-quellen.md](04-quellen.md) Abschnitt 14):
  - CMS recon; Termine aus der Datumssuche (28 Tage, 9 Seiten), Adresse/Stadt von der Detailseite je Veranstaltung (45 Abrufe, ~70 s)
  - Matching über die Termin-ID der Quelle (Datumsverschiebung = Update desselben Datensatzes)
  - 103 von 106 Terminen gespeichert (2 Sitzungen gefiltert, 1 ohne Ort); Fremdort Nordhalben korrekt
  - Integration ohne Änderung an Core, Admin, JS, Filter, Export und DB; „Alle Quellen“ mit 9 Quellen ~340 s
  - Tests: `tools/test-kronach.php` (46 Tests)
- **Zehnte aktive Quelle Bayreuth Tourismus (2026-10-15):** erledigt und getestet (siehe [04-quellen.md](04-quellen.md) Abschnitt 8):
  - Regional-API (region-bayreuth.de), 2 Abrufe in ~4 s; TLS mit WordPress verifiziert
  - Termine am Ort DAS ZENTRUM werden nicht übernommen (Venue-ID, A91) – keine Doppelungen mit der Quelle DAS ZENTRUM
  - Matching über eventId + Datum; 538 Termine gespeichert (28 Tage), 3 gefiltert, 7 ohne Ort
  - Integration ohne Änderung an Core, Admin, JS, Filter, Export und DB; „Alle Quellen“ mit 10 Quellen ~342 s
  - Tests: `tools/test-bayreuth-tourismus.php` (45 Tests)
- **Noch nicht implementiert:**
  - Export nach frei wählbarem Datumsbereich, Stadt oder Checkbox-Auswahl
  - Listenfilter nach Stadt (derzeit über die Suche)
  - weitere Quellen ab der elften
  - automatischer Crawl (Cron), bewusst nicht im MVP
- **Quellen:** Burg Rabenstein, Bad Steben, Coburg, Theater Selb, DAS ZENTRUM Bayreuth, Schützenhaus Münchberg und
  Stadtsteinach, Helmbrechts, Kronach und Bayreuth Tourismus sind analysiert und angebunden (10 aktive Quellen).
  **Zurückgestellt** (Server sperren den User-Agent „Eventcrawler“, keine Umgehung,
  A85): Neustadt bei Coburg (HTTP 503) und Kulmbach (HTTP 403) – siehe [04-quellen.md](04-quellen.md) „Zurückgestellte /
  schwer erreichbare Quellen“. Die übrigen Quellen und die geplante Nürnberg/Fürth-Quelle sind dokumentiert, aber
  **nicht technisch analysiert**.
- **XML-Referenzdatei:** fehlt weiterhin. Der Export ist nach dem dokumentierten Minimalstandard implementiert; ein
  Abgleich mit der Referenz steht aus (offene Punkte in [07-export.md](07-export.md) Abschnitt 9).

## Plugin-Eckdaten

| | |
|---|---|
| Plugin-Name / Slug | Eventcrawler / `eventcrawler` |
| Hauptdatei | `wp-plugin/eventcrawler.php` (in WordPress: `wp-content/plugins/eventcrawler/eventcrawler.php`) |
| Version | 0.1.0, Schema-Version 3 (`EVENTCRAWLER_DB_VERSION`) |
| Tabelle | `{$wpdb->prefix}eventcrawler_events` |
| Optionen | `eventcrawler_db_version`, `eventcrawler_filter_rules` (Filterregeln, 05-filter.md), `eventcrawler_filter_rules_version` (Stand der Default-Regeln), `eventcrawler_export_domain_mappings` (Link-Bezeichnungen, 07-export.md Abschnitt 13) |
| Capability | `manage_options` |
| Admin-Menü / Seitentitel | „Eventcrawler“ / „Importierte Veranstaltungen“ |
| Admin-Seiten | `wp-admin/admin.php?page=eventcrawler` (Importierte Veranstaltungen, mit Detail-Modal), `wp-admin/admin.php?page=eventcrawler-filter` (Filterregeln), `wp-admin/admin.php?page=eventcrawler-domains` (Link-Bezeichnungen) |
| AJAX-Aktionen | `eventcrawler_crawl_source` (eine Quelle crawlen), `eventcrawler_get_event_details` (gespeicherte Werte eines Events, nur lesend) |
| Quellen (Slug) | `burg-rabenstein`, `bad-steben`, `coburg`, `theater-selb`, `das-zentrum-bayreuth`, `schuetzenhaus-muenchberg`, `stadtsteinach`, `helmbrechts`, `kronach`, `bayreuth-tourismus` |
| Hooks | `eventcrawler_sources` (Adapter registrieren), `eventcrawler_filter_rules` (aktive Filterregeln zur Laufzeit; ersetzt `eventcrawler_exclude_terms`), `eventcrawler_export_display_url` (sichtbare URL im XML nach Link-Bezeichnung, 07-export.md 10.2/13) |
| Anforderungen | WordPress ≥ 6.0, PHP ≥ 7.4 (getestet mit WordPress 7.1.2, PHP 8.3.12, MariaDB 10.4.32) |

## Lokale Umgebung und Test

- `C:\xampp\htdocs\kulmbacher` enthält **keine WordPress-Installation**, nur `docs/`, `wp-plugin/`, `tools/`, `wp-events-manager/` (Referenz) und
  Projektdateien. Auch unter `C:\xampp\htdocs` wurde keine WordPress-Installation gefunden.
- `wp-plugin/` liegt **nicht** unter `wp-content/plugins/` und wurde bewusst nicht verschoben. Zum Testen wird der
  Ordner als `wp-content/plugins/eventcrawler` in eine WordPress-Installation eingebunden, als Kopie oder als
  Verzeichnis-Junction (`mklink /J`).
- **Achtung bei einer Junction bzw. einem Symlink:** Wird das Plugin in WordPress über „Löschen“ entfernt, löscht
  WordPress die Dateien im Projektordner. Vorher die Junction entfernen.
- Lokal vorhanden: XAMPP mit PHP 8.3.12 (`C:\xampp\php\php.exe`) und MariaDB 10.4.32.
- Die Tests der Phasen 2 bis 4 und der Migration auf Schema-Version 2 liefen in einer **temporären** WordPress-7.1.2-Installation außerhalb des Projekts
  (Session-Scratchpad, PHP-Built-in-Server, Datenbank `eventcrawler_wptest`, Tabellenpräfix `ectest_`). In Phase 3 kam
  dort ein Test-MU-Plugin hinzu, das HTTP-Antworten für Fehler- und Update-Tests ersetzt. Beides ist nicht Teil des
  Projekts. Eine dauerhafte Testinstallation ist noch festzulegen (siehe [todo.md](todo.md)).
- Die Zeitzone einer frischen WordPress-Installation ist UTC. `last_seen_at` usw. folgen der Zeitzone der Installation.
  Auf dem Zielsystem sollte „Europe/Berlin“ eingestellt sein.
- Testdaten: ausschließlich über das Entwickler-Skript `tools/seed-testdata.php`, nie automatisch durch das Plugin:
  ```
  C:\xampp\php\php.exe tools\seed-testdata.php <pfad\zu\wp-load.php>           # 7 Demo-Events (source = dev-test)
  C:\xampp\php\php.exe tools\seed-testdata.php <pfad\zu\wp-load.php> --delete  # alle dev-test-Events entfernen
  ```
- Tests der Export- und Filterregeln (Testdaten nur in einer Transaktion mit Rollback, Filterregeln werden danach
  wiederhergestellt):
  ```
  C:\xampp\php\php.exe tools\test-export-rules.php <pfad\zu\wp-load.php>
  ```
- Tests der Link-Bezeichnungen und der Detaildaten (Option wird danach wiederhergestellt, Tabelle nur gelesen):
  ```
  C:\xampp\php\php.exe tools\test-domain-mappings.php <pfad\zu\wp-load.php>
  ```
- Parsertests Theater Selb (eingebettete HTML-Ausschnitte, keine HTTP-Abrufe, keine DB-Änderung):
  ```
  C:\xampp\php\php.exe tools\test-theater-selb.php <pfad\zu\wp-load.php>
  ```
- Parsertests DAS ZENTRUM Bayreuth (eingebettete API-Antworten, keine HTTP-Abrufe, keine DB-Änderung):
  ```
  C:\xampp\php\php.exe tools\test-das-zentrum-bayreuth.php <pfad\zu\wp-load.php>
  ```
- Parsertests Schützenhaus Münchberg (eingebettete HTML-Ausschnitte, keine HTTP-Abrufe, keine DB-Änderung):
  ```
  C:\xampp\php\php.exe tools\test-schuetzenhaus-muenchberg.php <pfad\zu\wp-load.php>
  ```
- Parsertests Stadtsteinach (eingebettete HTML-Ausschnitte, keine HTTP-Abrufe, keine DB-Änderung):
  ```
  C:\xampp\php\php.exe tools\test-stadtsteinach.php <pfad\zu\wp-load.php>
  ```
- Parsertests Helmbrechts (eingebettete HTML-Ausschnitte, keine HTTP-Abrufe, keine DB-Änderung):
  ```
  C:\xampp\php\php.exe tools\test-helmbrechts.php <pfad\zu\wp-load.php>
  ```
- Parsertests Kronach (eingebettete HTML-Ausschnitte, keine HTTP-Abrufe, keine DB-Änderung):
  ```
  C:\xampp\php\php.exe tools\test-kronach.php <pfad\zu\wp-load.php>
  ```
- Parsertests Bayreuth Tourismus (eingebettete JSON-Antworten, keine HTTP-Abrufe, keine DB-Änderung):
  ```
  C:\xampp\php\php.exe tools\test-bayreuth-tourismus.php <pfad\zu\wp-load.php>
  ```

## Dokumente

| Datei | Inhalt |
|---|---|
| [01-konzept.md](01-konzept.md) | Ziel, MVP-Umfang, Datenfluss, Komponenten, Nicht-Ziele, Architekturentscheidungen |
| [02-datenbank.md](02-datenbank.md) | Tabelle `{prefix}eventcrawler_events`, Felder, Indizes, event_key, content_hash, Matching- und Update-Strategie |
| [03-crawler.md](03-crawler.md) | Crawl-Ablauf, Source-Adapter-Konzept, internes Event-Format, Normalisierung, Fehlerverhalten |
| [04-quellen.md](04-quellen.md) | Alle Veranstaltungsquellen mit Analysestatus |
| [05-filter.md](05-filter.md) | Ausschlussregeln (Stammtisch, Politik) und Ortsregel Nürnberg/Fürth |
| [06-admin.md](06-admin.md) | Minimale WordPress-Admin-Oberfläche |
| [07-export.md](07-export.md) | XML-Exportformat (verbindlich) |
| [08-kompatibilitaet.md](08-kompatibilitaet.md) | Fachliches Mapping zum vorhandenen manuellen Veranstaltungs-Plugin (keine technische Integration) |
| [09-wpem-integration.md](09-wpem-integration.md) | Kundenplugin „WP Kulmbacher Land“: XML-Export/-Import im Vergleich, Import-Kompatibilität unseres XML |
| [todo.md](todo.md) | Priorisierte Aufgabenliste / Phasenplan |

## Wie die Dokumentation verwendet wird

1. **Vor jeder größeren Änderung** die relevanten Dokumente lesen, mindestens `01-konzept.md` und `todo.md`.
2. Die Dokumentation ist die **verbindliche Quelle für Anforderungen**. Ein neuer Claude-/Codex-Lauf muss das Projekt
   allein anhand dieses Ordners fortsetzen können, ohne den ursprünglichen Chat zu kennen.
3. Ändern sich Datenbankfelder, Crawlerlogik, Filter oder Exportformat, wird **im selben Arbeitsschritt** die
   zugehörige Doku angepasst.
4. Neue Architekturentscheidungen werden in `01-konzept.md` im Abschnitt „Architekturentscheidungen“ eingetragen.
5. Den Status einer Quelle erst in `04-quellen.md` ändern, wenn sie tatsächlich geprüft wurde.
6. Erledigte Aufgaben in `todo.md` abhaken. Neue offene Punkte dort ergänzen.

## Entwicklungsregeln (verbindlich)

1. Vor größeren Änderungen zuerst die bestehende Dokumentation lesen.
2. Architekturentscheidungen in `docs/` dokumentieren.
3. Ändern sich Datenbankfelder, Crawlerlogik, Filter oder Exportformat, wird die zugehörige Dokumentation mit geändert.
4. Keine unnötige Überarchitektur.
5. Der erste Stand ist eine Kundendemo / ein MVP.
6. Bestehende Anforderungen nicht eigenmächtig verändern.
7. Keine externe Bibliothek einführen, wenn WordPress/PHP die Aufgabe selbst sinnvoll lösen kann.
8. WordPress Coding Standards beachten, soweit sinnvoll.
9. Datenbankzugriffe über `$wpdb`.
10. Tabellenpräfix nie fest als `wp_` voraussetzen, immer `$wpdb->prefix` verwenden.
11. Eingaben und Admin-Aktionen absichern: Capabilities, Nonces, Sanitizing, Escaping.
12. Fremde HTML-Inhalte niemals ungeprüft ausgeben.
13. Bei Crawling-Problemen niemals stillschweigend falsche Daten erzeugen.
14. Kann eine Quelle Daten nicht eindeutig liefern: dokumentieren und den Eintrag lieber überspringen als falsch speichern.
15. Keine Quelle als funktionierend markieren, bevor sie tatsächlich geprüft wurde.
16. Die vorhandene XML-Referenz niemals ohne ausdrückliche Anweisung durch ein neues Schema ersetzen.
