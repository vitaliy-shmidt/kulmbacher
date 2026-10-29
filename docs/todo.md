# TODO – priorisierte Aufgabenliste

Stand: 2026-10-29 (WPEM-Ersatz: Frontend, manuelle Termine, Übernahme; Gestaltung überarbeitet). Erledigte Punkte abhaken, neue offene Punkte ergänzen.

## WPEM-Ersatz (2026-10-28) ← NÄCHSTER SCHRITT

Details, Funktionsmatrix, Entscheidungen E1–E10 und Abschaltplan: [10-frontend.md](10-frontend.md).

- [x] WP Kulmbacher Land vollständig analysiert (Code, echte Exportdatei, URLs/Sitemap/Canonical in der Testinstallation)
- [x] Manuelle Termine (`source = manual`): anlegen, bearbeiten, duplizieren, löschen; keine Schemaänderung
- [x] Frontend `[eventcrawler_events]` (Liste/Tagesansicht, Suche, Stadt, Datum, Pagination, Details) und `[eventcrawler_calendar]`
- [x] Aliase `[events_list]`/`[events_calendar]` ohne aktives WPEM
- [x] WPEM-Übernahme mit Probelauf, idempotent, exakte Dublettenprüfung
- [x] Tests `tools/test-frontend.php` (117), HTTP-Sicherheit, Paralleltest, Responsive 1280/768/375, Regression grün, XML bytegleich
- [x] Gestaltung überarbeitet (2026-10-29): Karten, Kalender + mobile Agenda, Filter, Accessibility; 156 Tests, 1440/768/375 geprüft
- [ ] **Kunde:** Darstellung im echten Betheme prüfen; rote Balken „Event-Calendar“/„Event-List“ im Seiteninhalt entfernen (Shortcodes bringen eigene Überschriften mit)
- [ ] **Kunde (E1):** Welche Seiten nutzen `[events_list]`/`[events_calendar]` (Zugang zur Kundeninstallation/Staging)?
- [ ] **Kunde (E2):** Sind `/veranstaltung/…`/`/veranstaltungsort/…` indexiert/verlinkt? Falls ja: 301-Redirects bauen (10-frontend.md Abschnitt 11)
- [ ] **Kunde (E3/E4):** Wird der CSV-/XML-Import bzw. CSV-Export von WPEM genutzt? Falls ja: Ersatz vor der Abschaltung
- [ ] **Kunde (E5):** Öffentlich alle Quellen oder nur manuelle Termine/Auswahl (`sources`)?
- [ ] **Kunde (E6–E10):** PLZ, vergangene Termine, XML-Zeilenformat manueller Termine, Beschreibungen gecrawlter Termine, Uhrzeiten im Titel
- [ ] Auf Staging: Probelauf der Übernahme ansehen, Problemfälle in WPEM korrigieren, übernehmen, Paralleltest
- [ ] Abschaltung nach Plan (10-frontend.md Abschnitt 14), danach Permalinks speichern


## Phase 1 – Dokumentation

- [x] Konzept dokumentieren (01-konzept.md)
- [x] Datenbankstruktur definieren (02-datenbank.md)
- [x] Crawler-Ablauf und Source-Adapter-Konzept dokumentieren (03-crawler.md)
- [x] Quellen dokumentieren (04-quellen.md)
- [x] Filterregeln dokumentieren (05-filter.md)
- [x] Admin-Oberfläche dokumentieren (06-admin.md)
- [x] Exportformat nach Spezifikation dokumentieren (07-export.md)
- [x] event_key-Konzept korrigiert: Hilfsschlüssel, nicht unique, wiederkehrende Termine berücksichtigt (02-datenbank.md)
- [ ] Referenzdatei `veranstaltungen-export-2026-09-30.xml` beschaffen, unter `docs/referenz/` ablegen und
      07-export.md Abschnitt 8 dagegen prüfen (liegt weiterhin nicht vor)

## Phase 2 – Plugin-Grundstruktur

- [x] Plugin-Bootstrap `wp-plugin/eventcrawler.php` (Header, Konstanten, Includes)
- [x] Aktivierungs-Hook: Tabelle `{prefix}eventcrawler_events` per `dbDelta()`, Option `eventcrawler_db_version`
- [x] Schema-Upgrade ohne erneute Aktivierung (`maybe_upgrade` bei `plugins_loaded`)
- [x] Repository-Klasse (`get_by_id`, `find_by_external_id`, `find_by_source_url`, `get_events`, `count_events`,
      `insert`, `update`, `delete`, `delete_many`)
- [x] Admin-Menü (`manage_options`); seit Phase 3 „Eventcrawler“ / „Importierte Veranstaltungen“ statt „Veranstaltungen“
- [x] Veranstaltungsliste (`WP_List_Table`, Sortierung, Paginierung)
- [x] Einzel-Löschen (Nonce, Capability, Redirect, Notice)
- [x] Bulk-Löschen (Nonce, Capability, Redirect, Notice)
- [x] Platzhalter-Buttons „Crawler starten“ / „XML exportieren“ (deaktiviert)
- [x] `uninstall.php` ohne Datenlöschung
- [x] Entwickler-Testdaten-Skript `tools/seed-testdata.php`
- [x] In temporärer WordPress-Installation getestet (WP 7.1.2, PHP 8.3.12, MariaDB 10.4.32, Präfix `ectest_`):
      Erkennung, Aktivierung, Tabelle/Indizes, Liste, Sortierung, Escaping, Löschen einzeln/mehrfach,
      falscher Nonce → 403, Abonnent → 403, Deaktivieren/Uninstall ohne Datenverlust, Upgrade-Pfad, keine PHP-Warnings
- [ ] **Dauerhafte** lokale WordPress-Testinstallation festlegen (z. B. eigene XAMPP-Installation, Plugin per Kopie
      oder Junction einbinden; Hinweis zur Junction in README.md beachten)

## Phase 3 – erster Crawler (Proof of Concept Burg Rabenstein)

- [x] erste Quelle analysiert: Burg Rabenstein (robots.txt, HTTP, Encoding, Struktur, keine Paginierung, kein JSON-LD,
      keine externe ID, eindeutige Detail-URLs) → 04-quellen.md
- [x] Adapter-Basisklasse `Eventcrawler_Source` (HTTP-Abruf, DOM/XPath, Textbereinigung, URL-Normalisierung)
- [x] erster Source Adapter `Eventcrawler_Source_Burg_Rabenstein`
- [x] HTTP-Abruf mit `wp_remote_get()` (Timeout, Status, Body geprüft)
- [x] Parsing mit `DOMDocument`/`DOMXPath`
- [x] Normalisierung und Pflichtfeldprüfung; `event_key` und `content_hash` zentral im Crawler
- [x] Filterlogik MVP zentral (Stammtisch, Politik, Parteinamen, Ortsregel)
- [x] Matching nach Priorität (external_id → URL → Fallback event_key)
- [x] INSERT
- [x] UPDATE bzw. nur `last_seen_at`
- [x] manueller Crawl über den Button „Crawler starten“ (POST, Nonce, `manage_options`)
- [x] Admin-Ergebnis (gefunden / neu / aktualisiert / unverändert / gefiltert / fehlerhaft)
- [x] Fehlerbehandlung (Netzwerk, HTTP-Status, leere Antwort, Strukturänderung, einzelne kaputte Einträge), `error_log` bei `WP_DEBUG`
- [x] Tests: Live-Crawl zweimal ohne Duplikate; UPDATE mit Testdaten; Fehlerfälle; Sicherheit (siehe 03-crawler.md Abschnitt 10)
- [x] event_key-Formel an echten Daten geprüft, keine Änderung nötig
- [ ] Fallback-Matching (Priorität 3) mit einer **realen** Quelle ohne ID/URL testen, sobald es eine gibt
- [ ] Beobachten, ob Burg Rabenstein bei Terminverschiebungen die URL ändert (offener Grenzfall, 04-quellen.md)

## Phase 4 – Administration

- [x] Eventliste (`WP_List_Table`) mit den Spalten aus 06-admin.md
- [x] Löschen einzeln
- [x] Mehrfachauswahl und Bulk-Löschen
- [x] Button „Crawler starten“ funktionsfähig
- [x] Menü umbenannt: „Eventcrawler“, Seitentitel „Importierte Veranstaltungen“
- [x] Anzeigename der Quelle statt Slug („Burg Rabenstein“)
- [x] Crawler für alle Quellen oder eine ausgewählte Quelle (Dropdown aus dem Adapter-Register, serverseitig validiert)
- [x] Listenfilter Quelle, Monat, Jahr (Jahre dynamisch) und Freitextsuche; kombinierbar; per GET in der URL
- [x] Filter bleiben bei Paginierung, Sortierung und Löschen erhalten; „Zurücksetzen“
- [x] Tests: Crawler-Auswahl mit Abrufzählern, 23 Listenfilter-Fälle gegen SQL, Sicherheit, Regression
- [ ] Listenfilter nach Stadt (derzeit über die Suche) – nur bei Bedarf
- [ ] Standardansicht „ab heute“ statt aller Events – nur bei Bedarf

## Phase 5 – XML (umgesetzt als „Phase 4“ des Auftrags)

- [x] XML-Erzeugung im Minimalformat mit XMLWriter (`Eventcrawler_Exporter`)
- [x] Gruppierung Datum → Ort, Sortierung Datum/Stadt/Uhrzeit/Titel, deutsches Datumsformat (locale-unabhängig)
- [x] `<p>`-Text aus strukturierten Daten inkl. „Info: URL“
- [x] Button „XML exportieren“ aktiv, Download `veranstaltungen-export-JJJJ-MM-TT.xml` (WordPress-Zeitzone)
- [x] Sicherheit (POST, Nonce, `manage_options`), leere DB, Fehler ohne kaputten Download
- [x] Tests mit 124 echten Events: wohlgeformt, 124 `<p>`, keine Begrenzung auf 50, Daten unverändert
- [ ] Ergebnis mit der Referenzdatei vergleichen (Datei fehlt weiterhin; offene Punkte in 07-export.md Abschnitt 9)
- [ ] Entscheidung Auftraggeber: Kurzbeschreibung im `<p>` behalten? (Teaser der Quelle oft mitten im Satz abgeschnitten)
- [x] Exportfilter Quelle, Monat, Jahr, kombinierbar, Auswahl in SQL; ohne Treffer kein Download (2026-09-24)
- [ ] Export nach frei wählbarem Datumsbereich, Stadt oder Checkbox-Auswahl (später)
- [ ] Optional: Filter im Dateinamen (z. B. `veranstaltungen-export-bad-steben-2026-10.xml`)

## Datenmodell Version 2 (Start/Ende/Ganztag/Ort/Link)

- [x] Schema-Version 2: `start_date`, `start_time`, `end_date`, `end_time`, `all_day`, `venue_name`, `city`, `event_url`, `source_url`
- [x] Migration 1 → 2 ohne Datenverlust, auch bei aktivem Plugin (124 von 124 Events Feld für Feld geprüft)
- [x] Migration idempotent, Neuinstallation und Reaktivierung getestet
- [x] Crawler-Normalisierung: NULL-Regeln, Endzeit → gleicher Tag, Ende ≤ Beginn → verworfen, Ganztag nur von der Quelle
- [x] `content_hash` um Ende, Ganztag und `event_url` erweitert; Hashes bei der Migration neu berechnet
- [x] Burg Rabenstein speichert Endzeiten (105 von 124), `event_url` = Detailseite
- [x] Matching unverändert (external_id → source_url → event_key); UPDATE-Tests für alle geänderten Felder
- [x] Admin-Liste: „18:00–22:00“, „Ganztägig“, „—“, „(bis TT.MM.JJJJ)“
- [x] XML-Export auf neue Felder umgestellt, Format unverändert (byte-identisch), Endzeit nicht exportiert
- [x] Fehler in `Repository::formats()` gefunden und behoben (Regressionstest)
- [x] docs/08-kompatibilitaet.md (nur fachliche Kompatibilität)
- [x] ~~Technische Integration in das vorhandene manuelle Veranstaltungs-Plugin~~ – ersetzt durch den WPEM-Ersatz
      (2026-10-28, 10-frontend.md): keine Integration/Synchronisation, sondern einmalige Übernahme

## Zweite Quelle: Bad Steben

- [x] Technische Analyse (bad-steben.de bettet eT4/destination.one ein; Pfadsegmente `/Offset:n/Limit:n`; Serien-ID
      `data-guid`; `data-start`/`data-end`; Platzhalter-Ende 23:59; Nachbarorte) → 04-quellen.md
- [x] Adapter `Eventcrawler_Source_Bad_Steben` (Seitenabruf, Parsing, Stadtnormalisierung, external_id je Termin)
- [x] Registrierung, Anzeigename „Bad Steben“
- [x] Live-Crawl: 560 gefunden, 557 gespeichert, 3 fehlerhaft (ohne Stadt); zweiter Crawl ohne Duplikate
- [x] UPDATE-Tests (Uhrzeit, Endzeit, Ort, Link), Datumsverschiebung dokumentiert, Filter über den echten Datenweg
- [x] Mehrquellen-Crawl mit Gesamt- und Quellenstatistik, Fehlerisolation getestet
- [x] event_key-Fallback erstmals mit echten Daten getestet (Testskript, 92 doppelte Schlüssel)
- [x] XML-Export mit beiden Quellen validiert (681 `<p>`)
- [ ] Entscheidung Auftraggeber: Nachbarorte (Hof, Lichtenberg, Hirschberg, Münchberg, Berg) übernehmen oder nur
      Bad Steben (`get_allowed_cities()`)?
- [ ] Optional: Beschreibung von den Detailseiten holen (ca. 88 zusätzliche Requests je Crawl) – nur auf Wunsch
- [ ] Optional: Erkennung verschobener Termine (Grenzfall C, Vorschlag in 04-quellen.md)

## Dritte Quelle: Coburg

- [x] Technische Analyse (TYPO3/events2, Tagesseiten per `a.nextDay` mit cHash, Termin-/Serien-ID, Ganztag,
      Umlandorte, keine Endzeit in der Liste) → 04-quellen.md
- [x] Adapter `Eventcrawler_Source_Coburg`, Registrierung; Datumshelfer `resolve_german_date()` in der Basisklasse
- [x] Coburg automatisch in Crawler-, Export- und Listen-Dropdown (keine Admin-Änderung)
- [x] Live: 365 gefunden/neu, zweiter Crawl 365 unverändert; Einzelcrawl ruft andere Quellen nicht ab
- [x] UPDATE-Tests (Titel, Start, Ende, Ort, Stadt, Termin-ID), Datumsverschiebung, Filter, Identitätsfall
- [x] Fehlerisolation mit drei Quellen, Live-Crawl aller drei Quellen (~65 s)
- [x] Listenfilter, Suche, Exportfilter und Gesamtexport mit Coburg validiert
- [ ] Entscheidung Auftraggeber: Umlandorte (Bad Rodach, Seßlach, …) übernehmen? Regionen als „Ort“ („Landkreis Coburg“)?
- [x] Laufzeit gelöst: Gesamtcrawl entkoppelt, eine Quelle = ein Request (siehe unten)

## Entkopplung des Gesamtcrawls (2026-09-24)

- [x] AJAX-Endpunkt `eventcrawler_crawl_source` für genau eine Quelle (angemeldet, POST, `manage_options`, Nonce, Register)
- [x] Alter Sammel-Endpunkt `admin-post.php?action=eventcrawler_run` entfernt; ein Codepfad für Einzel- und Gesamtcrawl
- [x] `assets/eventcrawler-admin.js`: Quellen aus dem Register, sequentiell, Fortschritt, Ergebnis je Quelle, Summe,
      Button/Auswahl gesperrt, Fehlerbehandlung, Reload mit Zusammenfassung (`sessionStorage`)
- [x] Tests im echten Browser (Edge headless): 3 getrennte Requests ohne Überlappung, Einzelquellen, Fehlerisolation,
      Doppelklick, eingeschleuste Fehler; Endpunkt-Sicherheit; Regression Liste/Filter/Export/Löschen
- [ ] Beobachten: längste Quelle (Coburg ~46 s) gegen das Limit von 120 s je Request; bei einer sehr großen neuen
      Quelle ggf. Aufteilung innerhalb der Quelle
- [ ] Später (nicht MVP): persistenter Hintergrundjob (WP-Cron), damit ein geschlossener Browser den Gesamtcrawl nicht abbricht
- [ ] Optional: Endzeiten von Detailseiten (365 zusätzliche Abrufe je Crawl) – nur auf Wunsch

## Filterregeln im Backend (2026-09-26)

- [x] Option `eventcrawler_filter_rules` (keine Tabelle, keine Schemaänderung); Erstbelegung mit den bisherigen
      35 Begriffen nur, wenn die Option fehlt
- [x] Untermenü „Eventcrawler → Filterregeln“: anlegen, bearbeiten, aktivieren/deaktivieren, löschen, „Text prüfen“
- [x] Crawler lädt die aktiven Regeln einmal je Lauf; Matching-Verhalten unverändert (1.093 Vergleichsfälle identisch)
- [x] Tests: Ablauf mit `EVENTCRAWLER-TESTFILTER`, Kategorien, Groß-/Kleinschreibung, Umlaute, ganzes Wort, XSS,
      Nonce, Abonnent, GET, gelöschte Defaults bleiben gelöscht, beschädigte Option, Regression der drei Quellen
- [ ] Mit dem Auftraggeber die Begriffsliste durchgehen (Fehlalarme); Bürgerversammlungen seit 2026-10-02 gefiltert

## Kundenfeedback XML: Encoding, URL, Textumfang (2026-09-30)

- [x] Encoding-Kette Quelle → HTTP → Parser → DB → Repository → XMLWriter → Download geprüft: durchgehend korrektes
      UTF-8; „Caf√©“ = UTF-8-Datei als Mac Roman geöffnet (07-export.md 10.1). 0 fehlerhafte Datensätze, keine Korrektur
- [x] Quellen live geprüft (Header, meta charset, Body, DOMDocument) → 04-quellen.md
- [x] `Eventcrawler_Source::to_utf8()`: Absicherung für künftige Nicht-UTF-8-Quellen (03-crawler.md)
- [x] Sichtbare URL ohne `http://`/`https://`, gespeicherte URL vollständig; Filter `eventcrawler_export_display_url`
- [x] WPEM-Importer-Kompatibilität getestet (mit Protokoll 1048/1048, ohne 0, mit Filter 1048) → 09-wpem-integration.md
- [x] Textanalyse aller Quellen (Längen, Muster, Redundanzen) → 07-export.md 10.4
- [x] Sichere Textregeln: unsichtbare Zeichen, Ort im Titel, Titel am Beschreibungsanfang (07-export.md 10.3)
- [x] Regression: Anzahl, Sortierung, Gruppierung, UTF-8, Wohlgeformtheit, DB unverändert, Live-Crawl; 61 Einzeltests
- [x] WPEM-Export als technische InDesign-Referenz: Bytevergleich mit der echten Datei, WPEM-Exportcode analysiert,
      Format angeglichen (keine Einrückung, kein LF am Ende), kein BOM, 75 Tests grün (07-export.md Abschnitt 11)
- [ ] **Katharina:** neuen Eventcrawler-Export im bestehenden InDesign-Workflow gegen den WPEM-Export testen; dabei
      klären, welche Datei vorher „Caf√©“ zeigte und wie sie geöffnet wurde (XML importieren / platzieren)
- [ ] Die Eventcrawler-Datei, die Katharina beim ersten Test bekommen hat, bytegenau prüfen (liegt nicht vor)
- [ ] **Kunde:** Wird unser XML in WP Kulmbacher Land importiert? Falls ja: volle URL per Filter oder Importer anpassen
- [x] **Kunde:** Was genau ist „zu viel“? Beantwortet am 2026-10-02 (lange Links, doppelte Ortsangaben, mehrere
      Uhrzeiten) → Abschnitt „Zweites Kundenfeedback“
- [ ] Optional nach Kundenentscheidung: Beschreibung kürzen (Vorschlag ≤ 100 Zeichen an Wortgrenze mit „…“)
- [ ] Optional: Zero-Width-Spaces schon beim Crawl entfernen (ändert content_hash → einmalige UPDATEs)

## Zweites Kundenfeedback XML: Links, Ort/Adresse, Uhrzeiten, Politik (2026-10-02)

- [x] Grundsatz dokumentiert: nur strukturierte Daten und exakte Regeln, keine semantischen Vermutungen (A66)
- [x] Sichtbarer Link nur Hostname (`pages.et4.de`), nur bei http/https; gespeicherte URLs vollständig (07-export.md 12.1)
- [x] Datenlage je Quelle (venue, Adresse, Stadt, Veranstalter, Treffpunkt, Telefon) → 04-quellen.md
- [x] Schema-Version 3: `venue_address`; Migration getestet (Daten identisch, Hashes neu, idempotent) → 02-datenbank.md 11
- [x] Coburg: Straße aus dem Block „Veranstaltungsort“ (Cache 341/365, live 321/335)
- [x] Export: Ort/Adresse nicht doppelt (Titel, Beschreibung, Stadt), Adresse nach dem Ort (07-export.md 12.2)
- [x] Export: mehrere Uhrzeiten identischer Termine in einer Zeile, DB getrennt (104 Zeilen / 210 Termine; 07-export.md 12.3)
- [x] Filterregel `bürgerversammlung` + einmalige Ergänzung neuer Defaults (`eventcrawler_filter_rules_version`) → 05-filter.md
- [x] Treffpunkt/Telefon/Anmeldung/Kontakt werden nicht entfernt (getestet); kein Parsing
- [x] `tools/test-export-rules.php` (97 Tests), Regression Export/HTTP/AJAX/Filterseite/Live-Crawl Coburg
- [ ] **Katharina:** neuen Testexport (nur sichere Änderungen) prüfen lassen und konkrete Beispiele für weitere
      Wünsche sammeln (welche Zeilen sind ihr noch zu lang, was genau soll weg)
- [ ] **Kunde:** Label „Info:“ vor der Domain behalten oder weglassen (Kundenbeispiele ohne „Info:“)? (07-export.md 12.6)
- [ ] **Kunde:** Burg Rabenstein feste Venue-Adresse „Rabenstein 33“ im Adapter? (nur Footer, nicht je Termin; 04-quellen.md)
- [ ] **Kunde:** Bad Steben Adressen über Detailseiten (ca. 88 Requests je Crawl, JSON-LD `location.address`)?
- [ ] **Kunde:** Burg Rabenstein Langbeschreibung statt Teaser (enthält Treffpunkt/Telefon, ist aber viel länger)?
- [ ] **Kunde:** weitere politische Begriffe nur mit konkreten Beispielen (z. B. „der Grünen“ als eigene Regel)
- [ ] Nicht automatisierbar (nur redaktionell): Marketingtext, Kürzen von Beschreibungen, sinngemäße Ortsangaben

## Link-Bezeichnungen und DB-Detail-Modal (2026-10-06)

- [x] Option `eventcrawler_export_domain_mappings` (id, domain, label, enabled), keine Tabelle, keine Schemaänderung
- [x] Domain-Normalisierung auf den Hostnamen, Dubletten ohne Groß-/Kleinschreibung, freier Ausgabetext (ohne HTML, ≤ 100 Zeichen)
- [x] Untermenü „Eventcrawler → Link-Bezeichnungen“: anlegen, bearbeiten, aktivieren/deaktivieren, löschen (POST, Nonce je Aktion)
- [x] Export: Lookup einmal je Export, exakter Host, Fallback Domain, Filter `eventcrawler_export_display_url` behält das letzte Wort
- [x] Bewusst keine Default-Einträge (A73)
- [x] Liste: „Details“ → read-only Modal mit allen Spalten (AJAX `eventcrawler_get_event_details`, NULL sichtbar, volle URLs)
- [x] Tests: `tools/test-domain-mappings.php` (90), HTTP (85), Browser/XSS (48 + 8 Crawler „Alle Quellen“), Regression (50),
      `tools/test-export-rules.php` (97); Export ohne Einträge bytegleich; DB-Prüfsumme unverändert
- [ ] **Kunde/Redaktion:** Link-Bezeichnungen festlegen und anlegen (Vorschlag: `pages.et4.de` → „Bad Steben“,
      `veranstaltungen.coburg.de` → „Coburg“; `www.burg-rabenstein.de` braucht keinen Eintrag)
- [ ] Beobachten: Kommt eine weitere Quelle über `pages.et4.de` (destination.one) dazu, passt eine Bezeichnung
      „Bad Steben“ nicht mehr für alle Termine dieser Domain. Dann neutralen Text wählen oder klären, ob eine
      quellenbezogene Bezeichnung nötig ist (bewusst nicht gebaut, A72)
- [ ] Optional: auf der Seite „Link-Bezeichnungen“ die in gespeicherten Events vorkommenden Domains ohne Eintrag anzeigen

## Vierte Quelle: Theater Selb (2026-10-15)

- [x] Technische Analyse (WordPress, `/spielplan/` vollständig laut REST-Zählung, keine Paginierung, REST ohne
      Termindaten, Detailseite mit „Adresse“, Post-ID je Aufführung, Ausstellungen nur Fließtext) → 04-quellen.md
- [x] Adapter `Eventcrawler_Source_Theater_Selb`, Registrierung; keine Änderung an Core, Admin, JS, Filter, Export, DB
- [x] `external_id` = Post-ID: Datumsverschiebung = UPDATE desselben Datensatzes (getestet)
- [x] Live: 30 gefunden, 29 neu, 1 gefiltert; zweiter Crawl 29 unverändert; „Alle Quellen“ mit 4 Quellen (~104 s)
- [x] UPDATE-, Fehler- und Filtertests, Detail-Modal, Suche, XML, Link-Bezeichnung (Test, wieder entfernt)
- [x] `tools/test-theater-selb.php` (38 Tests); Regression `test-export-rules.php` (97), `test-domain-mappings.php` (90)
- [ ] **Kunde:** Abgesagte Termine (Badge „ABGESAGT“, z. B. „Plan B!: Capones Vermächtnis“ am 28.11.2026) erscheinen
      im XML wie normale Termine. Soll das Badge ausgewertet werden (z. B. Termin überspringen oder Zusatz im Titel)?
      Bräuchte eine bewusste Regel; derzeit nicht umgesetzt. **Betrifft seit 2026-10-15 auch Concertbüro Franken:
      10 gespeicherte Termine mit Badge „ABGESAGT“** (dazu AUSVERKAUFT, LOCATION VERLEGT, TERMIN WIRD VERSCHOBEN)
- [ ] **Kunde:** Filterregel `bezirkstag` filtert das Jugendkonzert „Stars von Morgen“ (Schirmherr „Bezirkstagspräsident“).
      Regel auf „ganzes Wort“ umstellen oder belassen? (05-filter.md Abschnitt 9)
- [ ] **Redaktion:** Link-Bezeichnung `www.theater-selb.de` → z. B. „Theater Selb“ anlegen?
- [ ] Beobachten: Laufzeit Theater Selb (~46 s bei 30 Terminen, 1 Detailseite je Termin); ab ca. 80 Terminen nahe am
      Limit von 120 s je Request
- [ ] Optional: Ausstellungen (nur Fließtext auf „Spielplan & Ausstellungen“) – nicht strukturiert, derzeit nicht übernommen

## Fünfte Quelle: DAS ZENTRUM Bayreuth (2026-10-15)

- [x] Analyse: Ausgangs-URL 404, WordPress + The Events Calendar, REST-API mit allen Feldern, 73 Termine, 2 Requests,
      Beginn = API (Einlass nur Detailseite), Ende = Beginn als „kein Ende“, Kleinkunstbühne ohne Adresse → 04-quellen.md
- [x] Adapter `Eventcrawler_Source_Das_Zentrum_Bayreuth` (JSON), Registrierung; keine Änderung an Core, Admin, JS, Filter, Export, DB
- [x] Live: 73 neu, zweiter Crawl 73 unverändert; „Alle Quellen“ mit 5 Quellen (~113 s, längste Quelle Theater Selb ~46 s)
- [x] UPDATE-, Fehler- und Filtertests, Detail-Modal (5 Termine), Suche, XML, Link-Bezeichnung (Test, wieder entfernt)
- [x] `tools/test-das-zentrum-bayreuth.php` (45 Tests); Regression 97 / 90 / 38
- [x] Überschneidung mit Bayreuth Tourismus analysiert (ca. 40 von 73), damals nichts implementiert (seit 2026-10-15 umgesetzt als A91) → 04-quellen.md
- [ ] **Kunde:** Kleinkunstbühne bekommt Stadt „Bayreuth“ über die Ort-ID (A82), aber keine Straße. Soll die Hausadresse
      „Äußere Badstraße 7a“ auch für die Kleinkunstbühne gesetzt werden?
- [ ] **Kunde:** „Ausverkauft“ (3 Termine) steht nur im HTML der Website, nicht in der API – übernehmen? (bräuchte
      zusätzlichen Abruf und ein Feld bzw. eine Regel)
- [ ] **Redaktion:** Link-Bezeichnung `www.das-zentrum.de` → z. B. „DAS ZENTRUM Bayreuth“ anlegen?
- [x] Strategie gegen Doppelungen mit Bayreuth Tourismus: Ort DAS ZENTRUM dort nicht übernehmen (A91, 2026-10-15)
- [x] TLS `api.region-bayreuth.de` mit `wp_remote_get()` geprüft: verifiziert (2026-10-15)
- [x] **Kundenmeldung „liefert keine Ergebnisse“ (2026-10-29):** einmaliger Abbruch beim Verbindungsaufbau
      (cURL 28 nach 10 s = `connect_timeout` von Requests); nicht reproduzierbar (14/14 Abrufe ~1,1 s), API unverändert
      (69 Termine, 2 Seiten, Ort-ID 60 weiter ohne Stadt). Reparatur A102: genau ein Retry nur bei Zeitüberschreitung;
      65 Tests, 3 Einzelcrawls, „Alle Quellen“ 11/11 → 04-quellen.md Abschnitt 5 „Zuverlässigkeit“, 03-crawler.md Abschnitt 24
- [ ] Beobachten: Tritt die Zeitüberschreitung trotz Retry erneut auf (Debug-Log „Versuch 2/2“), Erreichbarkeit beim
      Betreiber klären; Bayreuth Tourismus erst nach ausdrücklicher Entscheidung als Ersatz (A91)

## Sechste aktive Quelle: Schützenhaus Münchberg (2026-10-15)

- [x] Zugriff mit Plugin-User-Agent: 200 (Liste, Startseite, robots.txt)
- [x] Analyse: WordPress/Elementor, eine Programmseite mit allen Terminen, REST ohne Datum, Detailseiten ohne Mehrwert → 04-quellen.md
- [x] Adapter (1 Request, fester Ort A86, `external_id` = Post-ID-n), Registrierung; keine Core-/Admin-/Export-Änderung
- [x] Live: 46 neu, zweiter Crawl 46 unverändert; Updates, Fehlerfälle (403/429/503/500 …), Modal, Suche, XML, Link-Bezeichnung
- [x] `tools/test-schuetzenhaus-muenchberg.php` (35 Tests); Regression 97 / 90 / 38 / 45
- [ ] **Redaktion:** Link-Bezeichnung `schuetzenhaus-muenchberg.de` → z. B. „Schützenhaus Münchberg“ anlegen?
- [ ] Quelle: Huebnotix mit Datum „Sa, 27.02.07“ wird übersprungen, bis das Schützenhaus das Jahr korrigiert

## Elfte aktive Quelle: Concertbüro Franken (2026-10-15)

- [x] Zugriff über `wp_remote_get()` 200; Contao-Monatsseiten (19 Abrufe), Liste reicht (Stichprobe 9 Detailseiten) → 04-quellen.md Abschnitt 15
- [x] Adapter (A93): nur exakt Nürnberg/Fürth, 80 andere Städte ausgeschlossen; external_id = Alias; keine Core-Änderung
- [x] Live: 324 neu, zweiter Crawl 324 unverändert; nur Nürnberg/Fürth in der DB und im XML; Updates, Fehlerfälle, Modal, Suche, Link-Bezeichnung
- [x] `tools/test-concertbuero-franken.php` (35 Tests); Regression aller Suites grün; 0 Dubletten mit anderen Quellen
- [ ] **Kunde:** abgesagte Termine (10) – siehe Frage zu Badges bei Theater Selb
- [ ] **Redaktion:** Link-Bezeichnung `www.concertbuero-franken.de` → „Concertbüro Franken“ anlegen?
- [ ] Beobachten: Verschiebungen legt die Quelle als neuen Termin an (neuer Alias) – alter Datensatz bleibt mit Badge

## Zehnte aktive Quelle: Bayreuth Tourismus (2026-10-15)

- [x] Zugriff 200, TLS mit WordPress verifiziert; Regional-API, 2 Abrufe (Suche + Ortsfilter DAS ZENTRUM) → 04-quellen.md Abschnitt 8
- [x] Adapter (A91): Ort DAS ZENTRUM über Venue-ID ausgeschlossen; external_id eventId_Datum_n; keine Core-Änderung
- [x] Live: 538 neu, zweiter Crawl 538 unverändert; DAS ZENTRUM unverändert; Updates, Fehlerfälle (inkl. TLS, Pagination), Modal, Suche, XML, Link-Bezeichnung
- [x] `tools/test-bayreuth-tourismus.php` (45 Tests); Regression aller Suites grün; 0 Restdubletten mit DAS ZENTRUM
- [ ] **Kunde:** rund 540 Termine je 28 Tage aus Bayreuth – Umfang im XML gewünscht? (ggf. später Kategorien/Zeitraum begrenzen)
- [ ] **Kunde:** „Kreativstammtisch“ wird über `stammtisch` gefiltert – so gewollt?
- [ ] **Redaktion:** Link-Bezeichnung `region-bayreuth.de` → z. B. „Bayreuth Tourismus“ anlegen?
- [ ] Beobachten: Datumsverschiebung erzeugt neuen Datensatz (keine Termin-ID in der Quelle); Status nur im Titel
- [ ] Beobachten: Stadtteil-IDs der Widget-Konfiguration; Ortsfilter DAS ZENTRUM (Venue-ID) bei Änderungen der Datenbank

## Neunte aktive Quelle: Kronach (2026-10-15)

- [x] Zugriff 200 (reproduzierbar); CMS recon; Datumssuche als Terminquelle, Termin-ID je Termin → 04-quellen.md Abschnitt 14
- [x] Adapter (A90): 28 Tage, Detailseiten nur je Veranstaltung über „Weitere Termine“; Zuordnung „Innenstadt Kronach“ → Kronach
- [x] Live: 103 neu, zweiter Crawl 103 unverändert; Updates (inkl. Datum), Fehlerfälle, Detail-404, Modal, Suche, XML, Link-Bezeichnung
- [x] `tools/test-kronach.php` (46 Tests); Regression aller Suites grün; keine Überschneidung mit aktiven Quellen
- [ ] **Redaktion:** Link-Bezeichnung `www.kronach.de` → „Kronach“ anlegen?
- [ ] Beobachten: Monatskürzel für März (Annahme „Mär“); bei Fehlern im Februar/März Kürzel aus der Quelle ergänzen
- [ ] Beobachten: Laufzeit ~70 s (45 Abrufe); Gesamtlauf „Alle Quellen“ jetzt ~5:40–5:50 min → Hintergrundjob (WP-Cron) einplanen
- [ ] Offen: Frankenwald Tourismus / Landkreis Kronach als regionale Quellen (nicht analysiert, nur Startseiten 200)

## Achte aktive Quelle: Helmbrechts (2026-10-15)

- [x] Zugriff 200 (reproduzierbar); TYPO3 news + calendarize; News-Liste unvollständig → Monatsansichten + Detailseiten → 04-quellen.md
- [x] Adapter (Slug_Datum_n, 00:00 = NULL, Ende nur am Detail-Datum, A89); keine Core-/Admin-/Export-Änderung
- [x] Live: 47 neu, zweiter Crawl 47 unverändert; Updates, Fehlerfälle, Detail-404, Modal, Suche, XML, Link-Bezeichnung
- [x] `tools/test-helmbrechts.php` (41 Tests); Regression aller Suites grün; keine Überschneidung mit aktiven Quellen
- [ ] **Kunde:** Verwaltungstermine (Bürgermeistersprechstunde, Rentensprechtag, Blutspende) werden übernommen – gewünscht?
      Falls nicht: als Filterregel im Backend anlegen (keine Codeänderung nötig)
- [ ] **Redaktion:** Link-Bezeichnung `stadt-helmbrechts.de` → „Helmbrechts“ anlegen?
- [ ] Beobachten: Laufzeit ~78 s (44 Abrufe); Gesamtlauf „Alle Quellen“ jetzt ~4:35 min → Hintergrundjob (WP-Cron) einplanen

## Siebte aktive Quelle: Stadtsteinach (2026-10-15)

- [x] Zugriff mit Plugin-User-Agent: 200 (reproduzierbar); TYPO3/calendarize, 5 Listenseiten + Detailseiten → 04-quellen.md
- [x] Adapter (Index-ID, Stadt nur bei „Stadtsteinach“ im Ortstext, A87), Registrierung; keine Core-/Admin-/Export-Änderung
- [x] Live: 32 neu, zweiter Crawl 32 unverändert; Updates, Fehlerfälle, Detail-404, Modal, Suche, XML, Link-Bezeichnung
- [x] `tools/test-stadtsteinach.php` (36 Tests); Regression 97 / 90 / 38 / 45 / 35
- [x] Ort→Stadt-Zuordnung für 5 extern belegte Orte (A88): 9 Termine zusätzlich gespeichert (41 statt 32), Herbstball →
      Kulmbach; 53 Tests
- [ ] Verbleibend: 6 Termine ohne Veranstaltungsort (Walberla-Wanderung, Schafkopfrennen, Martinsumzug,
      Krippenausstellung, Adventskonzert 2 ×) – nur lösbar, wenn die Stadt den Ort im Kalender einträgt
- [ ] Bei neuen Ortstexten ohne „Stadtsteinach“ (im Debug-Log „Stadt nicht belegt“) Ort prüfen und ggf. `VENUE_CITIES` ergänzen
- [ ] **Redaktion:** Link-Bezeichnung `www.stadtsteinach.de` → „Stadtsteinach“ anlegen?
- [ ] Beobachten: Laufzeit ~74 s (52 Abrufe); ab ca. 70 Terminen nahe am Limit von 120 s je Quelle

## Zurückgestellte / schwer erreichbare Quellen (seit 2026-10-15)

Übersicht mit Grund, Prüfung und nächsten Schritten: [04-quellen.md](04-quellen.md), Abschnitt „Zurückgestellte /
schwer erreichbare Quellen“. Grundsatz A85: Sperren werden nicht umgangen.

- [ ] Neustadt bei Coburg – HTTP 503 für „Eventcrawler“ → Stadt kontaktieren / API, iCal, RSS anfragen / verwerfen
- [ ] Kulmbach – HTTP 403 für „Eventcrawler“ → Stadt kontaktieren / Landkreis Kulmbach prüfen / verwerfen
- [ ] **Kunde:** Grundsatzfrage: Sollen gesperrte Betreiber angeschrieben werden (wer, mit welcher Kennung/Kontakt-URL)?
- [ ] Vor jeder neuen Quelle zuerst den Zugriff mit dem Plugin-User-Agent **über `wp_remote_get()`** prüfen (TLS-Maßstab
      ist WordPress, A92). Stand 2026-10-15: Hof 503 (Sperre), Thurnau TLS (zurückgestellt), Stadtsteinach/Helmbrechts/Kronach 200

## Phase 6 – weitere Quellen

Bei jeder neuen Quelle die Datenqualität über das Detail-Modal („Details“) prüfen: Adresse, Originaltext der
Beschreibung, gespeicherte URLs, `external_id`/`event_key`, Uhrzeiten/NULL, `all_day`; danach die Domain der Quelle ggf.
unter „Link-Bezeichnungen“ eintragen.

Quellen danach schrittweise integrieren: jeweils analysieren → Adapter → testen → Status in 04-quellen.md setzen.

- [x] Bad Steben (2026-09-24)
- [x] Coburg (2026-09-24)
- [x] Burg Rabenstein / Ahorntal (Proof of Concept, Phase 3)
- [x] Theater Selb (2026-10-15, siehe Abschnitt „Vierte Quelle: Theater Selb“)
- [x] DAS ZENTRUM Bayreuth (2026-10-15, siehe Abschnitt „Fünfte Quelle: DAS ZENTRUM Bayreuth“)
- [ ] Neustadt bei Coburg – **zurückgestellt** (2026-10-15): Server antwortet auf den User-Agent „Eventcrawler“ mit
      HTTP 503, sonst 200 (04-quellen.md Abschnitt 6). **Kunde:** Weg festlegen – (1) Freigabe/Schnittstelle bei der
      Stadt anfragen, (2) auf die Quelle verzichten (Coburg liefert Neustädter Termine mit), (3) nur nach Freigabe:
      User-Agent des Plugins ändern (betrifft alle Quellen). Erst danach Analyse, Adapter und Vergleich mit Coburg
- [ ] Kulmbach – **zurückgestellt** (2026-10-15): HTTP 403 für den Plugin-User-Agent, 200 für neutrale Kennung
      (04-quellen.md „Zurückgestellte / schwer erreichbare Quellen“). **Kunde:** Freigabe/API bei der Stadt anfragen
      oder Landkreis Kulmbach als Alternative prüfen lassen (TLS-Kette vorher klären)
- [x] Bayreuth Tourismus (2026-10-15, siehe Abschnitt „Zehnte aktive Quelle: Bayreuth Tourismus“)
- [x] Schützenhaus Münchberg (2026-10-15, siehe Abschnitt „Sechste aktive Quelle: Schützenhaus Münchberg“)
- [ ] Neustadt bei Coburg – Recheck 2026-10-15 20:17 UTC: weiterhin 503 nur für die Eventcrawler-Kennung (12/12, `Retry-After: 43200`), neutral 200 → zurückgestellt
- [ ] Hof – zurückgestellt (2026-10-15: HTTP 503 nur für die Eventcrawler-Kennung; Freigabe bei der Stadt anfragen)
- [x] Stadtsteinach (2026-10-15, siehe Abschnitt „Siebte aktive Quelle: Stadtsteinach“)
- [x] Helmbrechts (2026-10-15, siehe Abschnitt „Achte aktive Quelle: Helmbrechts“)
- [ ] Thurnau – zurückgestellt (2026-10-15: TLS, Root „ISRG Root YR“ fehlt im CA-Bundle von WordPress; nach
      WordPress-Update erneut prüfen oder Markt Thurnau ansprechen)
- [x] Kronach (2026-10-15, siehe Abschnitt „Neunte aktive Quelle: Kronach“)
- [x] Nürnberg/Fürth = Concertbüro Franken (2026-10-15, siehe Abschnitt „Elfte aktive Quelle: Concertbüro Franken“)

**Ursprüngliche Quellenliste vollständig technisch geprüft (2026-10-15): 15 Quellen – 11 aktiv, 4 zurückgestellt
(Neustadt bei Coburg, Kulmbach, Hof, Thurnau), 0 ungeprüft.**

## Offene Fragen an den Auftraggeber

- [ ] XML-Referenzdatei bereitstellen
- [ ] Bad Steben: auch Nachbarorte übernehmen? Veranstalter als „Ort“ akzeptabel (z. B. „Bayerisches Staatsbad Bad Steben GmbH“)?
- [x] URL der Nürnberg/Fürth-Quelle: Concertbüro Franken (2026-10-15)
- [x] Zählen Bürgerversammlungen als „politisch“? Ja (2026-10-02), Regel `bürgerversammlung`
- [ ] Zählen sonstige Informationsveranstaltungen der Verwaltung als „politisch“?
- [ ] Mehrtägige Veranstaltungen und Ausstellungen: an jedem Tag, nur am ersten Tag oder gar nicht exportieren?
- [ ] `<h2>` für Burg Rabenstein = „Ahorntal“ bestätigen. Allgemein: `<h2>` = Gemeinde oder Veranstaltungsort?
      (Derzeit gespeichert: city „Ahorntal“, venue_name „Burg Rabenstein“)
- [ ] Soll die Endzeit im XML erscheinen („18:00–22:00 Uhr“)? Gespeichert wird sie seit Schema-Version 2
- [ ] Soll der Preis gespeichert werden? Burg Rabenstein liefert ihn; das Schema speichert ihn nicht
- [x] Code des manuellen Veranstaltungs-Plugins liegt vor und ist analysiert (2026-10-28); Zugang zur
      **Kundeninstallation** fehlt weiterhin (E1/E2 in 10-frontend.md)
- [ ] Rechtliche Freigabe zur Übernahme von Texten der Quellen (Urheberrecht, Nutzungsbedingungen)
- [ ] Soll ein gespeichertes Event gelöscht werden, wenn es nach einer Änderung in der Quelle unter einen Filter fällt?

## Spätere Erweiterungen (nicht MVP)

- [ ] Automatischer Crawl per WP-Cron
- [ ] Ignore-/Blacklist für manuell gelöschte Events (derzeit kann ein gelöschtes Event erneut importiert werden)
- [ ] Option „Daten bei Deinstallation löschen“
- [ ] Bereinigung vergangener bzw. nicht mehr gefundener Events
- [ ] Erkennung quellenübergreifender Duplikate
- [ ] Manuell gesperrte Felder bzw. Bearbeiten **gecrawlter** Termine (manuelle Termine sind seit 2026-10-28 bearbeitbar)
- [ ] Änderungshistorie
- [x] Filterbegriffe im Backend pflegen (2026-09-26, siehe Abschnitt „Filterregeln im Backend“)
- [ ] Filterregeln: Reihenfolge ändern, Import/Export der Regelliste, Protokoll „warum gefiltert“
- [ ] Multisite / Netzwerkaktivierung (derzeit nicht berücksichtigt: bei Netzwerkaktivierung wird die Tabelle nur
      für die aktuelle Site angelegt; `maybe_upgrade` legt sie beim ersten Admin-Aufruf einer Site nach)
- [ ] weitere Exportformate (CSV, Excel, JSON), REST-API, Statistiken, Logs, KI-Klassifikation, Übersetzungen
