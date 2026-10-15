# 01 – Konzept

## 1. Ziel des Projekts

Das WordPress-Plugin „Eventcrawler“ (Arbeitsname) ruft Veranstaltungen von mehreren externen Websites ab und stellt
sie redaktionell aufbereitet als XML bereit. Das XML folgt einem **bestehenden Minimalformat** und wird vermutlich für
einen Veranstaltungskalender in Druck oder Redaktion weiterverwendet.

Die Veranstaltungen werden **nicht als WordPress-Posts** gespeichert, sondern in einer **eigenen Datenbanktabelle**
(`{$wpdb->prefix}eventcrawler_events`, siehe [02-datenbank.md](02-datenbank.md)).

## 2. MVP – Umfang der ersten Version

Der MVP dient als **Kundendemo**. Er soll klein, verständlich und nachvollziehbar bleiben.

Grundfunktionen:

1. Veranstaltungsquellen crawlen
2. Veranstaltungen erkennen (Liste und ggf. Detailseiten auslesen)
3. neue Veranstaltungen speichern
4. vorhandene Veranstaltungen beim nächsten Crawl wiedererkennen
5. Änderungen an vorhandenen Veranstaltungen erkennen (`content_hash`)
6. vorhandene Datensätze aktualisieren, **ohne Duplikate** anzulegen
7. unerwünschte Veranstaltungen filtern (Stammtische, Politik, Ortsregel Nürnberg/Fürth)
8. Veranstaltungen im WordPress-Backend anzeigen
9. Veranstaltungen im Backend löschen (einzeln und mehrere)
10. Veranstaltungen als XML exportieren (bestehendes Format)

Für die Demo genügt es, wenn **zunächst eine einzige Quelle** als Proof of Concept funktioniert. Weitere Quellen
kommen schrittweise dazu (siehe [todo.md](todo.md)).

## 3. Datenfluss

```
Quelle (Website)
  -> Abruf (wp_remote_get)
  -> Parser (Source Adapter der Quelle)
  -> Normalisierung (gemeinsames internes Event-Format)
  -> Filter (Ortsregel im Code; Stammtisch/Politik als gespeicherte Filterregeln)
  -> Event Matching (external_id -> source_url -> event_key)
  -> INSERT oder UPDATE oder nur last_seen_at aktualisieren
  -> Datenbank ({prefix}eventcrawler_events)

Datenbank
  -> Admin-Liste (anzeigen, filtern, löschen; Detail-Modal zeigt die gespeicherten Rohwerte, nur lesend)
  -> XML-Export (Datum -> Ort -> Veranstaltungen; sichtbarer Link = Link-Bezeichnung der Domain, sonst die Domain)
```

## 4. Hauptkomponenten

| Komponente | Aufgabe | Doku |
|---|---|---|
| Plugin-Bootstrap | Hauptdatei, Konstanten, Laden der Klassen, Aktivierungs-Hook | – |
| Installer / DB | Tabelle per `dbDelta()` anlegen, Datenzugriff über `$wpdb` | [02-datenbank.md](02-datenbank.md) |
| Crawler Core | Adapter aufrufen, normalisieren, filtern, matchen, speichern, Ergebnis zählen | [03-crawler.md](03-crawler.md) |
| Source Adapter (je Quelle einer) | Website abrufen und parsen, Events im internen Format liefern | [03-crawler.md](03-crawler.md), [04-quellen.md](04-quellen.md) |
| Filter | zentrale, einfache Ausschlusslogik | [05-filter.md](05-filter.md) |
| Admin | Menüseite, Liste (`WP_List_Table`) mit read-only Detail-Modal, Buttons „Crawler starten“ / „XML exportieren“, Löschen, Unterseiten „Filterregeln“ und „Link-Bezeichnungen“ | [06-admin.md](06-admin.md) |
| Exporter | XML im bestehenden Minimalformat erzeugen und als Download ausliefern | [07-export.md](07-export.md) |

### Dateistruktur des Plugins

Mit ✅ markierte Dateien existieren (Stand 2026-10-15). Die übrigen sind für spätere Phasen geplant.

```
wp-plugin/                                  (Plugin-Root, in WP als wp-content/plugins/eventcrawler)
  eventcrawler.php                       ✅ Plugin-Header, Konstanten, Includes, Aktivierungs-Hook, Admin-Init
  uninstall.php                          ✅ löscht bewusst KEINE Daten (siehe 02-datenbank.md Abschnitt 8)
  includes/
    class-eventcrawler-installer.php     ✅ Tabelle anlegen / aktualisieren (dbDelta), Tabellenname
    class-eventcrawler-repository.php    ✅ $wpdb-Zugriffe: finden, auflisten, zählen, einfügen, aktualisieren, löschen
    class-eventcrawler-crawler.php       ✅ Crawler Core: Quellenregister, Ablauf, Normalisierung, Hashes, Matching, Statistik
    class-eventcrawler-filter.php        ✅ zentrale Filterlogik (Stammtisch, Politik, Ortsregel)
    class-eventcrawler-filter-rules.php  ✅ gespeicherte Filterregeln (Option eventcrawler_filter_rules)
    class-eventcrawler-domain-mappings.php ✅ Link-Bezeichnungen Domain -> Ausgabe im XML (Option eventcrawler_export_domain_mappings)
    class-eventcrawler-exporter.php      ✅ XML-Export: Gruppierung, deutsches Datum, <p>-Text (Textregeln, sichtbare URL/Link-Bezeichnung), XMLWriter, Prüfung
    admin/
      class-eventcrawler-admin.php       ✅ Menü „Eventcrawler“, Seite, AJAX-Crawl je Quelle, AJAX-Detaildaten, Export, Löschaktionen, Notices
      class-eventcrawler-list-table.php  ✅ WP_List_Table für die Eventliste (Aktionen: Details, Löschen, Quelle ansehen)
      class-eventcrawler-filter-admin.php ✅ Untermenü „Filterregeln“
      class-eventcrawler-domain-admin.php ✅ Untermenü „Link-Bezeichnungen“
    sources/
      abstract-eventcrawler-source.php   ✅ Basis der Adapter (HTTP-Abruf, UTF-8-Absicherung, DOM/XPath, Textbereinigung, URLs)
      class-eventcrawler-source-burg-rabenstein.php ✅ Adapter Burg Rabenstein
      class-eventcrawler-source-bad-steben.php      ✅ Adapter Bad Steben (eT4/destination.one)
      class-eventcrawler-source-coburg.php          ✅ Adapter Coburg (TYPO3/events2)
      class-eventcrawler-source-theater-selb.php    ✅ Adapter Theater Selb (WordPress, Liste + Detailseiten)
      class-eventcrawler-source-das-zentrum-bayreuth.php ✅ Adapter DAS ZENTRUM Bayreuth (The Events Calendar, REST-API)
      class-eventcrawler-source-schuetzenhaus-muenchberg.php ✅ Adapter Schützenhaus Münchberg (Elementor-Liste)
      class-eventcrawler-source-stadtsteinach.php   ✅ Adapter Stadtsteinach (TYPO3/calendarize, Liste + Detailseiten)
      class-eventcrawler-source-helmbrechts.php     ✅ Adapter Helmbrechts (TYPO3 news + calendarize, Monatsansichten + Detailseiten)
      class-eventcrawler-source-kronach.php         ✅ Adapter Kronach (CMS recon, Datumssuche + Detailseiten je Veranstaltung)
      class-eventcrawler-source-bayreuth-tourismus.php ✅ Adapter Bayreuth Tourismus (Regional-API, ohne Ort DAS ZENTRUM)
      class-eventcrawler-source-<slug>.php  weitere Adapter – ab Phase 6
  assets/
    eventcrawler-admin.js                ✅ Crawler-Steuerung im Browser (Quellen nacheinander, Fortschritt) und Detail-Modal der Liste
    eventcrawler-admin.css               ✅ kleines Layout-CSS für das Detail-Modal

tools/                                      (außerhalb des Plugins, wird nicht ausgeliefert)
  seed-testdata.php                      ✅ Entwickler-Skript: Demo-Events einfügen/entfernen (CLI)
  test-export-rules.php                  ✅ Entwickler-Tests: Export-/Filterregeln aus dem Kundenfeedback (CLI, Rollback)
  test-domain-mappings.php               ✅ Entwickler-Tests: Link-Bezeichnungen und Export, Detaildaten (CLI, Option wird wiederhergestellt)
  test-theater-selb.php                  ✅ Entwickler-Tests: Parser Theater Selb mit eingebetteten HTML-Ausschnitten (CLI, keine DB-Änderung)
  test-das-zentrum-bayreuth.php          ✅ Entwickler-Tests: Parser DAS ZENTRUM mit eingebetteten API-Antworten (CLI, keine DB-Änderung)
  test-schuetzenhaus-muenchberg.php      ✅ Entwickler-Tests: Parser Schützenhaus Münchberg mit eingebetteten HTML-Ausschnitten (CLI)
  test-stadtsteinach.php                 ✅ Entwickler-Tests: Parser Stadtsteinach mit eingebetteten HTML-Ausschnitten (CLI)
  test-helmbrechts.php                   ✅ Entwickler-Tests: Parser Helmbrechts mit eingebetteten HTML-Ausschnitten (CLI)
  test-kronach.php                       ✅ Entwickler-Tests: Parser Kronach mit eingebetteten HTML-Ausschnitten (CLI)
  test-bayreuth-tourismus.php            ✅ Entwickler-Tests: Parser Bayreuth Tourismus mit eingebetteten JSON-Antworten (CLI)
```

- Keine Namespaces, Interfaces, Factories oder Service Container. Klassen mit dem Präfix `Eventcrawler_` genügen für
  den MVP.
- Präfix für Funktionen, Klassen, Optionen und Hooks: `eventcrawler_` / `Eventcrawler_`.
- Konstanten: `EVENTCRAWLER_VERSION`, `EVENTCRAWLER_DB_VERSION`, `EVENTCRAWLER_PATH`.
- Der Admin-Code wird nur bei `is_admin()` geladen, die `WP_List_Table`-Klasse erst beim Aufruf der Admin-Seite.

## 5. Ausdrücklich NICHT Bestandteil des MVP

Diese Punkte werden jetzt nicht gebaut. Sie sind nur als mögliche spätere Erweiterungen notiert:

- umfangreiche Änderungshistorie
- manuell gesperrte Felder (Redaktion überschreibt Werte, Crawler soll sie nicht zurücksetzen)
- Ignore-/Blacklist, damit manuell gelöschte Events nicht erneut importiert werden (siehe [06-admin.md](06-admin.md))
- CSV-, Excel- oder JSON-Export
- Statistiken, Dashboard-Charts
- komplexe Benutzerrechte (MVP: nur `manage_options`)
- automatische KI-Klassifikation (z. B. für politische Inhalte)
- komplizierte, im Backend konfigurierbare Filter (umgesetzt ist nur eine einfache Begriffsliste, A58)
- komplexe Logs (MVP: nur eine Ergebnis-Zusammenfassung nach dem Crawl)
- automatische Übersetzungen
- REST-API
- automatischer Crawl per WP-Cron (sinnvoller nächster Schritt nach dem MVP, siehe [todo.md](todo.md))
- zusätzliche Tabellen (Quellen, Crawl-Läufe, Logs)

## 6. Architekturentscheidungen

| # | Datum | Entscheidung | Begründung |
|---|---|---|---|
| A1 | 2026-09-24 | Speicherung in eigener Tabelle statt Custom Post Type | Vorgabe des Auftraggebers; strukturierte Felder, einfaches Matching |
| A2 | 2026-09-24 | Ein Source Adapter pro Quelle, kein universeller Selektor | Websites sind unterschiedlich aufgebaut; Vorgabe des Auftraggebers |
| A3 | 2026-09-24 | Alle Adapter liefern dasselbe interne Event-Format | Filter, Matching und Speicherung laufen einmal zentral |
| A4 | 2026-09-24 | Identifikation: `source + external_id` → `source + normalisierte source_url` → Fallback über `event_key` | Vorgabe. **Korrigiert in Phase 2:** Der event_key ist nur ein technischer Hilfsschlüssel, enthält vorläufig das Datum und ist **nicht unique**, damit wiederkehrende Termine nicht zusammengelegt werden (siehe 02-datenbank.md Abschnitt 4/6) |
| A5 | 2026-09-24 | Änderungserkennung über `content_hash` (SHA-1) | einfache Update-Entscheidung ohne Feldvergleich |
| A6 | 2026-09-24 | HTTP-Abruf mit `wp_remote_get`, HTML-Parsing mit PHP `DOMDocument`/`DOMXPath` | keine externe Bibliothek nötig (Regel 7) |
| A7 | 2026-09-24 | Nur eine einzige Tabelle im MVP, keine Log-/Quellen-Tabellen | keine Überarchitektur |
| A8 | 2026-09-24 | XML-Export exakt im bestehenden Minimalformat | Vorgabe; Schema darf nicht eigenmächtig geändert werden |
| A9 | 2026-09-24 | Quellen werden im Code registriert (Adapterliste), nicht im Backend konfiguriert | MVP; keine Konfigurationsoberfläche nötig |
| A10 | 2026-09-24 | Crawl im MVP manuell und synchron per Button (optional je Quelle) | einfach zu demonstrieren; Cron später |
| A11 | 2026-09-24 | Plugin-Slug `eventcrawler`, Hauptdatei `eventcrawler.php`, Tabelle `{prefix}eventcrawler_events` | Struktur aus der Doku übernommen, nicht die abweichenden Beispielnamen aus dem Phase-2-Auftrag (`event-crawler.php`, `class-database.php`) |
| A12 | 2026-09-24 | Capability `manage_options` für den gesamten Admin-Bereich | MVP: nur Administratoren |
| A13 | 2026-09-24 | UNIQUE nur auf `(source, external_id)`; `event_key` und `source_url` nur einfache Indizes | Ein UNIQUE-Index auf event_key/URL könnte legitime wiederkehrende Termine blockieren |
| A14 | 2026-09-24 | Schema-Upgrade über Option `eventcrawler_db_version` + `plugins_loaded` | Tabelle wird auch ohne erneute Aktivierung aktualisiert |
| A15 | 2026-09-24 | Löschaktionen im Hook `load-{page_hook}` statt über `admin-post.php` | Einzel- und Bulk-Löschen der `WP_List_Table` laufen so über denselben Einstieg; danach Post/Redirect/Get |
| A16 | 2026-09-24 | Deaktivierung und Deinstallation löschen **keine** Daten | kein überraschender Datenverlust; eine Option „Daten bei Deinstallation löschen“ kommt später |
| A17 | 2026-09-24 | Buttons „Crawler starten“ und „XML exportieren“ vorerst deaktiviert mit Hinweis | einfachste saubere Lösung, keine Fake-Erfolgsmeldung |
| A18 | 2026-09-24 | Testdaten nur über das CLI-Skript `tools/seed-testdata.php` (source `dev-test`), kein Seeding im Plugin | Keine Testdaten auf Kundensystemen |
| A19 | 2026-09-24 | Spalte „Aktionen“ statt der Hover-Zeilenaktionen von WordPress | Löschen ist für die Demo sofort sichtbar |
| A20 | 2026-09-24 | `source`, `event_key` und `content_hash` setzt der Crawler zentral, nicht der Adapter | Eine einzige Formel für alle Quellen. Abweichung vom Wortlaut des Phase-3-Auftrags („Adapter liefert event_key“), das Ergebnis ist identisch |
| A21 | 2026-09-24 | Burg Rabenstein: nur die Übersichtsseite wird abgerufen (1 Request), keine Detailseiten; Matching über die Detail-URL (Priorität 2), da es keine externe ID gibt | Die Übersicht enthält alle benötigten Felder strukturiert; höfliches Crawling |
| A22 | 2026-09-24 | Admin-Menü heißt „Eventcrawler“, Seitentitel „Importierte Veranstaltungen“ (vorher „Veranstaltungen“). Slug `eventcrawler`, Capability und Datenbank unverändert | Ein anderes Plugin für manuelle Veranstaltungen belegt „Veranstaltungen“; dessen Menü wird nicht verändert |
| A23 | 2026-09-24 | Endzeit, Preis, Bild, Ticket-URL werden trotz Verfügbarkeit **nicht** gespeichert, das Schema wird nicht erweitert | Proof of Concept ohne Schema-Änderung; Erweiterung erst bei Bedarf (z. B. für den Export) |
| A24 | 2026-09-24 | Crawl-Nonce im eigenen Feld `eventcrawler_run_nonce` | Vermeidet die Kollision mit `_wpnonce` der Listentabelle (doppelte HTML-ID) |
| A25 | 2026-09-24 | Ein vorhandenes Event, das nach einer Änderung in der Quelle gefiltert würde, bleibt unverändert stehen | Keine automatische Löschung im MVP; Entscheidung über Löschen ist offen (todo.md) |
| A26 | 2026-09-24 | XML-Deklaration `<?xml version="1.0" encoding="UTF-8"?>`, ein einziges `<Textabschnitt>`, Einrückung 4 Leerzeichen (**seit 2026-09-30 ohne Einrückung, A65**) | Die Referenzdatei fehlt; Vorgabe des Auftraggebers. Gegen die Referenz zu prüfen (07-export.md Abschnitt 9) |
| A27 | 2026-09-24 | `<p>` = `[HH:MM Uhr ]Titel[, Beschreibung][, Ort][. Info: URL]`; nach `.`/`!`/`?` nur ein Leerzeichen statt Komma | Vorgabe des Auftraggebers für den MVP, solange die Referenz fehlt; vermeidet „!,“ |
| A28 | 2026-09-24 | Gesamtexport aller gespeicherten Events (auch vergangene), Sortierung Datum → Stadt → Uhrzeit (ohne Uhrzeit zuletzt) → Titel → id | Einfachster MVP; Zeitraum-/Stadtfilter später |
| A29 | 2026-09-24 | XML nur mit `XMLWriter`; vor dem Download mit `DOMDocument` erneut eingelesen; Ausgaben per Output-Buffer verworfen | Kein kaputter Download, keine PHP-Hinweise in der Datei |
| A30 | 2026-09-24 | Export in eigener Klasse `Eventcrawler_Exporter`; Admin nur Request, Rechte, Nonce und Download; SQL nur im Repository (`get_events_for_export()`) | Trennung der Zuständigkeiten |
| A31 | 2026-09-24 | Events ohne Stadt werden nicht exportiert (nur `error_log` bei `WP_DEBUG`) | Nie unter einem erfundenen Ort einsortieren |
| A32 | 2026-09-24 | Anzeigename der Quelle zentral aus dem Adapter-Register (`get_source_name()`); der Slug in der DB bleibt | Eine Zuordnung für alle Quellen, automatisch erweiterbar |
| A33 | 2026-09-24 | **Der Eventcrawler verwendet ein neutrales internes Veranstaltungsmodell mit Start, Ende, Ganztag, Veranstaltungsort und öffentlichem Event-Link. Dieses Modell orientiert sich fachlich an den im Präsentationsvideo sichtbaren Feldern des vorhandenen manuellen Veranstaltungs-Plugins, ist aber technisch nicht von diesem Plugin abhängig.** Schema-Version 2: `start_date`, `start_time`, `end_date`, `end_time`, `all_day`, `venue_name`, `city`, `event_url`, `source_url` | Kein technischer Zugriff auf das Fremdplugin; spätere Integration bleibt möglich, ohne das Modell umzubauen (08-kompatibilitaet.md) |
| A34 | 2026-09-24 | Migration 1 → 2 per `ALTER TABLE … CHANGE` **vor** `dbDelta()` (`event_date` → `start_date`, `venue` → `venue_name`), idempotent, Abbruch ohne Versionserhöhung bei Fehler; danach `event_url = source_url` für Altdaten und Neuberechnung aller `content_hash` | `dbDelta()` kann nicht umbenennen; kein Datenverlust; nur echte Inhaltsänderungen lösen danach UPDATEs aus |
| A35 | 2026-09-24 | Fehlende Werte sind `NULL`; Endzeit ohne Enddatum → gleicher Tag; Ende ≤ Beginn → Ende verwerfen statt Folgetag raten; `all_day` nur, wenn die Quelle es eindeutig liefert | Keine erfundenen Daten (Regeln 13/14) |
| A36 | 2026-09-24 | `event_url` (öffentlicher Link) und `source_url` (Herkunft, Matching) getrennt; Matching nur über `source_url`; Export „Info:“ bevorzugt `event_url` | Links können sich unabhängig von der Identität ändern |
| A37 | 2026-09-24 | Endzeit, Enddatum und Ganztag werden gespeichert, aber **nicht** im XML ausgegeben | Kundenformat nicht eigenmächtig ändern |
| A38 | 2026-09-24 | `Repository::formats()` bildet wpdb-Formate in der Reihenfolge der übergebenen Daten | Behebt einen in Version 2 gefundenen Fehler (`venue_name = 0` bei der ersten `%d`-Spalte) |
| A39 | 2026-09-24 | Bad Steben wird über die eingebettete Plattform eT4/destination.one (`pages.et4.de`) gelesen, als serverseitiges HTML mit den Pfadsegmenten `/Offset:n/Limit:250`, nicht über bad-steben.de selbst und nicht per Browserautomation | Die Kalenderseite enthält keine Events; die Trefferliste liefert strukturierte `data-`-Attribute (ID, Beginn, Ende) ohne JavaScript |
| A40 | 2026-09-24 | Bad Steben: `external_id = {e_ID}_{Datum}_{n}` (Serien-ID + Datum + laufende Nummer am Tag) | Die Quelle hat nur eine Serien-ID, dieselbe Serie läuft mehrmals täglich; so bleibt eine Uhrzeitänderung am selben Tag derselbe Datensatz. Eine Datumsverschiebung ergibt einen neuen (dokumentierte Grenze) |
| A41 | 2026-09-24 | Endzeit nur, wenn die Quelle sie sichtbar anzeigt; der Platzhalter `data-end = 23:59` wird ignoriert | Keine erfundenen Endzeiten |
| A42 | 2026-09-24 | Stadt = echte Stadt der Quelle (auch Nachbarorte wie Hof, Lichtenberg), nicht pauschal „Bad Steben“; ohne Stadtangabe wird übersprungen (außer Postanschrift im Ort) | Echte Daten statt Annahmen; Beschränkung auf Bad Steben wäre per `get_allowed_cities()` möglich, Entscheidung beim Auftraggeber |
| A43 | 2026-09-24 | „Crawler starten“ crawlt alle Quellen nacheinander mit try/catch je Quelle; Meldungen: Gesamtzeile + eine Zeile je Quelle | Fehlerisolation; kleine Aufschlüsselung ohne neue UI |
| A44 | 2026-09-24 | `event_url`/`source_url` bei Bad Steben ohne `?From=…` | Der Parameter enthält teils die Abrufzeit und würde sonst bei jedem Crawl ein UPDATE auslösen |
| A45 | 2026-09-24 | Quellen-Auswahlfelder (Crawler, Liste, Export) und deren Validierung kommen ausschließlich aus dem Adapter-Register (`Eventcrawler_Crawler::get_sources()` → `Eventcrawler_Admin::source_options()`); keine zweite Quellenliste | Eine neue Quelle (z. B. Coburg) erscheint automatisch überall |
| A46 | 2026-09-24 | Crawler-Auswahl `all` oder genau ein Slug; ungültig → kein Crawl; Meldung bei einer Quelle „Crawler abgeschlossen (Name): …“ | Gezieltes Nachcrawlen einer Quelle; keine Klassen/URLs aus Request-Parametern |
| A47 | 2026-09-24 | Eine private Methode `Repository::where_clause()` für Liste, Zählung und Export; Jahr bzw. Monat + Jahr als Datumsbereich auf `start_date`, Monat allein als `MONTH(start_date)`, Suche per `LIKE` + `esc_like` | Keine doppelten WHERE-Bedingungen, indexfreundlich, kein Textvergleich formatierter Daten |
| A48 | 2026-09-24 | Listenfilter per GET (`source`, `month`, `year`, `s`) in der URL; ungültige Werte werden ignoriert. Exportfilter per POST mit eigenen Feldnamen; ungültige Werte brechen ab | Liste = Ansicht (Reload, Zurück, Paginierung); Export = Aktion (eindeutiges Ergebnis) |
| A49 | 2026-09-24 | Kein Freitextfilter im Export; Dateiname bleibt `veranstaltungen-export-JJJJ-MM-TT.xml` | Nachvollziehbarkeit und Stabilität |
| A50 | 2026-09-24 | Coburg: Folgeseiten über den verlinkten `a.nextDay` (mit cHash) statt selbst gebauter URLs; Zeitfenster 42 Tage, max. 25 Seiten | cHash kann nicht selbst erzeugt werden; begrenzte Laufzeit und Last (15 Abrufe) |
| A51 | 2026-09-24 | Coburg: `external_id = {Serien-Slug}_{Datum}_{n}` statt der Termin-ID aus der URL | Termin-IDs sind fortlaufend je Serie und werden vermutlich beim Speichern neu vergeben (nicht belegbar); so entstehen bei Bearbeitungen keine Duplikate, nur `event_url` ändert sich |
| A52 | 2026-09-24 | `Eventcrawler_Source::resolve_german_date()` in der Basisklasse: deutsche Monats-/Wochentagsnamen fest, Jahr aus Referenzdatum, Wochentagsprüfung | Erste Quelle mit ausgeschriebenen Monaten ohne Jahr; wiederverwendbar, locale-unabhängig, rät nicht |
| A53 | 2026-09-24 | Mehrtägige Veranstaltungen, die die Quelle je Tag als eigenen Termin listet, werden je Tag gespeichert, ohne künstliches `end_date` | Quelle beschreibt Einzeltermine mit eigener Uhrzeit und ID |
| A54 | 2026-09-24 | Stadtnormalisierung Coburg: Ortsteilzusätze `-OT …`/`-StT …` entfernen, „b.“ → „bei“ | Einheitliche `<h2>`-Gruppierung; keine Ableitung aus dem Quellennamen |
| A55 | 2026-09-24 | **Ein manueller Gesamtcrawl wird nicht mehr innerhalb eines einzigen HTTP-Requests ausgeführt. Jede registrierte Quelle wird in einem eigenen geschützten Request verarbeitet (`admin-ajax.php`, `action=eventcrawler_crawl_source`). Die Admin-Oberfläche orchestriert „Alle Quellen“ sequentiell. Dadurch hängt die maximale Laufzeit nicht mehr von der Summe aller Quellen ab.** Der alte Endpunkt `admin-post.php?action=eventcrawler_run` wurde entfernt | Drei Quellen brauchten 64–66 s in einem Request (Limit 120 s); jede weitere Quelle hätte das Risiko erhöht. Jetzt gilt das Limit je Quelle (längste: Coburg ~46 s) |
| A56 | 2026-09-24 | Orchestrierung im Browser (Vanilla JS, `assets/eventcrawler-admin.js`), sequentiell, ohne Cron, Queue, Job-Tabelle oder Server-Lock; Quellenliste und Nonce per `wp_add_inline_script` aus dem Register | Kleinste Lösung ohne neue Infrastruktur; nicht parallel, um fremde Websites zu schonen und Fehler klar zuzuordnen. Einschränkung: kein persistenter Job |
| A57 | 2026-09-24 | Nach „Crawler starten“ wird die Seite einmal neu geladen; die Zusammenfassung überlebt über `sessionStorage` im Browser | Einfachste stabile Aktualisierung der Liste ohne serverseitigen Status |
| A58 | 2026-09-26 | **Textbasierte Ausschlussregeln werden nicht mehr ausschließlich im PHP-Code gepflegt. Die beim ersten Einsatz initialisierten Default-Regeln werden anschließend als WordPress-Option gespeichert und können von Administratoren verwaltet werden. Die zentrale Filterklasse verwendet zur Laufzeit ausschließlich die aktiven gespeicherten Regeln.** | Redaktion soll Begriffe ohne Code-Änderung anpassen können; Matching-Verhalten bleibt gleich |
| A59 | 2026-09-26 | Speicherung als eine Option `eventcrawler_filter_rules` (Liste mit id, term, category, match, enabled), keine eigene Tabelle; Erstbelegung nur, wenn die Option fehlt; beschädigte Option → keine Textregel, Warnung, keine automatischen Defaults | Wenige Dutzend Regeln; gelöschte Defaults dürfen nie zurückkommen; kein stilles Zurücksetzen von Admin-Entscheidungen |
| A60 | 2026-09-26 | Regeln einmal je Crawl-Lauf laden; neue Regeln verändern gespeicherte Events nicht (werden als „gefiltert“ gezählt) | Kein Datenverlust durch eine versehentlich angelegte Regel; Löschen bleibt eine bewusste Aktion in der Liste |
| A61 | 2026-09-30 | **Encoding: Die gesamte Kette bleibt UTF-8 ohne Umkodierung; keine Zeichen-Mappings, keine Datenkorrektur, kein BOM.** „Caf√©“ beim Kunden entsteht durch Öffnen der korrekten UTF-8-Datei als Mac Roman (07-export.md 10.1). Ein BOM wird erst nach Freigabe/Test beim Kunden eingeführt | Quelle, DB und XML nachweislich korrekt (0 fehlerhafte Datensätze); eine Reparatur im Plugin würde korrekte Daten beschädigen |
| A62 | 2026-09-30 | Sichtbare URL im XML ohne `http://`/`https://` (`Eventcrawler_Exporter::display_url()`), gespeicherte URLs bleiben vollständig; Filter `eventcrawler_export_display_url` liefert bei Bedarf die volle URL | Kundenwunsch; XML dient laut Konzept Druck/Redaktion. Der XML-Importer von WP Kulmbacher Land erkennt Links nur mit Protokoll (09-wpem-integration.md) – dafür der Filter, ohne das Kundenplugin zu ändern |
| A63 | 2026-09-30 | Kleine deterministische Textregeln nur im Export: unsichtbare Zeichen entfernen, Ort entfällt bei Vorkommen im Titel (Wortgrenze), Titel am Beschreibungsanfang nicht wiederholen. Keine Maximallänge, keine KI/NLP, keine Admin-Konfiguration | Nur Regeln, die echte Daten belegen bzw. keine Information verlieren; Titel bleibt immer; DB unverändert |
| A64 | 2026-09-30 | `Eventcrawler_Source::to_utf8()` im HTTP-Abruf: gültiges UTF-8 unverändert; ungültiges nur mit deklariertem bekanntem Zeichensatz umwandeln, sonst Quellenfehler | `create_xpath()` liest immer als UTF-8; eine künftige Nicht-UTF-8-Quelle darf nicht stillschweigend falsche Zeichen speichern (Regeln 13/14); falscher Header bei echtem UTF-8 führt nicht zu Doppelkodierung |
| A65 | 2026-09-30 | **Der in InDesign bewährte XML-Export von WP Kulmbacher Land ist die technische Referenz für die äußere XML-Form, nicht für den Inhalt.** Angeglichen: keine Einrückung, ein Element je Zeile, LF, kein LF nach `</Root>`. Unverändert: XMLWriter, Deklaration, kein BOM, UTF-8, literal `'`, keine `&nbsp;`-Entity, Header, Dateiname | Katharina nutzt diese Datei erfolgreich; der Bytevergleich zeigt identische Zeichen und Deklaration, Unterschiede nur im Whitespace (07-export.md Abschnitt 11). Die Referenz ist selbst nicht wohlgeformt (`&nbsp;`) – das wird nicht übernommen |
| A66 | 2026-10-02 | **Der Eventcrawler stellt keine semantischen Vermutungen an.** Kundenwünsche werden nur umgesetzt, wenn sie mit strukturierten Quelldaten oder einer exakten, deterministischen Regel lösbar sind. Was ein Verständnis des Textes bräuchte (Marketingtext erkennen, „wichtige“ Infos auswählen, Personen als Ansprechpartner, Adressen aus Fließtext, Zusammenführen nach ähnlichen Titeln), wird dokumentiert und nicht automatisiert | Keine falschen Daten (Regeln 13/14); nachvollziehbares Verhalten für die Redaktion |
| A67 | 2026-10-02 | Sichtbare URL im XML = nur Hostname bei `http(s)://` (`pages.et4.de`); andere Schemes unverändert; gespeichert bleibt die volle URL; Filter `eventcrawler_export_display_url` wie bisher. Ersetzt die Darstellung aus A62 (nur Protokoll entfernt) | Kundenwunsch (lange Links); reine Darstellungsänderung |
| A68 | 2026-10-02 | **Schema-Version 3: neues Feld `venue_address`** (Straße/Hausnummer), nur aus einem strukturierten Adressfeld der Quelle, nie aus Fließtext. Befüllt derzeit nur Coburg (Adresszeile im Block „Veranstaltungsort“). Kein Hartcodieren von „Rabenstein 33“ | Coburg liefert die Adresse zuverlässig strukturiert; in `venue_name` gemischt wäre sie nicht sauber trennbar (Dopplungsregeln, Admin, spätere Integration) |
| A69 | 2026-10-02 | Exportregel „Ort/Adresse nicht doppelt“: Ort bzw. Adresse entfällt, wenn er nur die Stadt (`<h2>`) ist oder als ganze Wortfolge schon in Titel **oder Beschreibung** steht. Erweitert A63 (bisher nur Titel) wegen des Kundenbeispiels „Burg Rabenstein … Burg Rabenstein“ | Exakte Wiederholung desselben Textes in derselben Zeile; kein Informationsverlust, kein Fuzzy-Matching |
| A70 | 2026-10-02 | Mehrere Uhrzeiten nur **im Export** zu einer Zeile zusammenfassen („18:30 Uhr & 20:00 Uhr …“), nur bei exakter Identität von Quelle, Datum, Stadt, Titel, Beschreibung, Ort, Adresse und fertiger Zeile ohne Uhrzeit; nur Termine mit Uhrzeit. DB-Datensätze bleiben getrennt | Kundenwunsch; strikte Identität verhindert das Zusammenführen verschiedener Veranstaltungen |
| A71 | 2026-10-02 | Neue Default-Filterregel `bürgerversammlung` (Politik, enthält). Bestehende Installationen erhalten neue Defaults **einmalig** über die Option `eventcrawler_filter_rules_version`; gelöschte/deaktivierte Regeln kommen nie zurück. Keine bloße Regel „Grüne“ | Kundenvorgabe; „Grüne“ als ganzes Wort träfe auch „Grüne Soße“, „grüne Woche“ (Groß-/Kleinschreibung wird ignoriert) |
| A72 | 2026-10-06 | **Link-Bezeichnungen: Im XML-Export kann für eine Domain ein frei gewählter Ausgabetext stehen („pages.et4.de“ → „Bad Steben“). Die Zuordnung ist global nach Domain (nicht je Quelle), wird im Backend gepflegt und gilt ausschließlich für die sichtbare Angabe nach „Info:“.** Gespeichert als Option `eventcrawler_export_domain_mappings` (Liste mit id, domain, label, enabled), keine Tabelle, keine Schemaänderung. Ohne aktiven Eintrag steht die Domain im XML (A67). `event_url`, `source_url`, Matching, Crawler und Adapter bleiben unberührt | Kundenwunsch: technische Domains sind für Leser unverständlich. Domain und Quelle sind verschiedene Konzepte (eine Plattform-Domain kann künftig mehreren Quellen dienen, eine Quelle kann mehrere Domains verlinken). Kein Hartcodieren im Exporter |
| A73 | 2026-10-06 | **Keine Default-Link-Bezeichnungen.** Die Liste startet leer | Die Texte sind redaktionelle Entscheidungen („Coburg“ oder „Coburg Veranstaltungen“?); `www.burg-rabenstein.de` → `www.burg-rabenstein.de` hätte keine Wirkung; `pages.et4.de` ist die Plattform destination.one und kann künftig auch Termine anderer Orte liefern – ein voreingestelltes „Bad Steben“ wäre dann falsch. Drei Einträge sind im Backend schnell angelegt |
| A74 | 2026-10-06 | Export: Link-Bezeichnungen **einmal je Export** laden (`Eventcrawler_Domain_Mappings::get_lookup()` in `build_xml()`) und als Parameter an `group_times()`/`format_body()` übergeben; Reihenfolge `display_url()` (Host) → Link-Bezeichnung (exakter Host, nur bei http/https) → Filter `eventcrawler_export_display_url` | Kein Laden je Event, kein statischer Zustand; ohne Parameter verhalten sich die statischen Methoden wie bisher (Tests). Der Filter behält das letzte Wort (volle URL für einen WPEM-Import) |
| A75 | 2026-10-06 | **Read-only Detail-Modal in der Veranstaltungsliste** („Details“): zeigt alle Spalten eines Events genau wie gespeichert (`SELECT *` über `Repository::get_by_id()`, NULL als „NULL“). Daten erst beim Klick per AJAX (`eventcrawler_get_event_details`: POST, `manage_options`, eigener Nonce, Integer-ID), Ausgabe nur per `textContent` bzw. als geprüfter http(s)-Link. Keine Bearbeitung | Test-/Debug-Werkzeug für die Datenqualität neuer Quellen (Quelle → Speicherung → XML unterscheiden). Liste bleibt klein; neue Spalten erscheinen automatisch |
| A76 | 2026-10-06 | „Details“ steht als erster Eintrag in der Spalte „Aktionen“ (wie A19), nicht in Hover-Zeilenaktionen unter dem Titel. Modal-JS im vorhandenen `assets/eventcrawler-admin.js` (eigener Abschnitt), kleines eigenes `assets/eventcrawler-admin.css`, keine Bibliothek | Aktionen bleiben an einer, immer sichtbaren Stelle; das Skript wird ohnehin nur auf dieser Seite geladen |
| A77 | 2026-10-15 | **Theater Selb: Liste `/spielplan/` plus eine Detailseite je Termin** (31 Requests, 1 s Pause, ca. 46 s). `external_id` = WordPress-Post-ID des Termins | Die Liste enthält weder Ort noch Stadt noch Adresse; nur die Detailseite hat ein strukturiertes Feld „Adresse“. Jede Aufführung ist ein eigener Beitrag; die Post-ID bleibt bei Verschiebung und Umbenennung gleich (real belegt, Post 52794) → Datumsänderungen werden als UPDATE erkannt. REST-API ohne Termindaten |
| A78 | 2026-10-15 | Theater Selb: `venue_name` „Rosenthal-Theater Selb“ und `city` „Selb“ nur, wenn die „Adresse“ des Termins die Theateradresse aus dem Seitenfooter ist (exakter Vergleich nach Normalisierung von Leerzeichen, Punkt, „Straße/Strasse/Str.“). Andere Adresse mit „PLZ Stadt“ → diese Stadt ohne Ortsnamen; ohne Stadt → Termin übersprungen. `venue_address` in der Schreibweise der Quelle, angehängte „PLZ Stadt“ abgetrennt | Die Quelle hat kein Ortsfeld je Termin. Kein pauschales Hartcodieren (vgl. Burg Rabenstein), sondern ein Beleg je Termin; keine Vermutung bei fremden Adressen (A66) |
| A79 | 2026-10-15 | Theater Selb: `description` = Untertitel der Detailseite; Genre, Veranstalter, Badges („ABGESAGT“, „AUSVERKAUFT“), Ticket-Link und Preis werden nicht gespeichert, kein neues Feld | Untertitel ist ein klar definiertes kurzes Feld; das Modell hat kein Status- oder Ticketfeld. Absagen bleiben eine offene Frage an den Auftraggeber (todo.md) |
| A80 | 2026-10-15 | **DAS ZENTRUM Bayreuth über die öffentliche REST-API von The Events Calendar** (`/wp-json/tribe/events/v1/events`, 2 Requests), kein HTML, keine Detailseiten. `external_id` = TEC-Post-ID. Slug `das-zentrum-bayreuth` (Vorgabe) | Die API liefert alle Felder strukturiert (Beginn = „Beginn“ der Detailseite, 6/6 geprüft; Ort mit Adresse). Erste Quelle mit JSON; `fetch_html()` wird für den JSON-Text wiederverwendet (gleiche Fehlerbehandlung, `to_utf8()`) |
| A81 | 2026-10-15 | DAS ZENTRUM: Ende nur, wenn es nach dem Beginn liegt (Ende = Beginn ist die fehlende Endangabe, 67/73) | Wie der Platzhalter 23:59 bei Bad Steben (A41): kein erfundenes Ende, keine Log-Meldungen „Ende verworfen“ je Termin |
| A82 | 2026-10-15 | DAS ZENTRUM: Für die Ort-ID 60 („DAS ZENTRUM – Kleinkunstbühne“), die in der Quelle weder Adresse noch Stadt hat, wird `city` = „Bayreuth“ gesetzt, keine Straße. Jeder andere Ort ohne Stadt → Termin übersprungen | Ohne Stadt fielen 19 von 73 Terminen weg. Beleg: Raumseite „Die Kleinkunstbühne im ZENTRUM Bayreuth“, Regionaldatenbank (Raum des Orts „DAS ZENTRUM“, Äußere Badstraße 7a, 95448 Bayreuth). Bewusst an die Ort-ID gebunden, nicht an den Namen; die Straße wird nicht hartcodiert (vgl. A78) |
| A83 | 2026-10-15 | DAS ZENTRUM: keine Beschreibung (Langtext nicht übernommen, nicht gekürzt); „Ausverkauft“ aus dem HTML nicht übernommen | Kein abgegrenzter Kurztext in der Quelle; der Langtext würde zudem drei Fehlalarme (`AfD`, `stammtisch`) bei Kabarett auslösen. Status nur im HTML-Theme-JSON, kein Feld im Modell (wie A79) |
| A84 | 2026-10-15 | **Keine quellenübergreifende Deduplizierung.** Die Überschneidung DAS ZENTRUM / Bayreuth Tourismus (ca. 40 von 73 Terminen) ist nur dokumentiert (04-quellen.md) | Nur 17 der 40 Paare haben denselben Titel, 3 eine andere Uhrzeit, 1 ein anderes Datum; Zusammenführen bräuchte unscharfen Abgleich (A66). Entscheidung über eine Strategie (z. B. Vorrang je Ort) beim Auftraggeber |
| A85 | 2026-10-15 | **Zugriffssperren von Betreibern werden nicht umgangen.** Antwortet eine Quelle auf den ehrlichen User-Agent des Plugins gezielt mit 403/503 (neutrale Kennung: 200), wird sie als „zurückgestellt“ in 04-quellen.md geführt; kein Adapter, keine Browser-Kennung, keine Änderung des Plugin-User-Agents zur Umgehung. Prüfung des Zugriffs vor jeder Quellenanalyse | Neustadt bei Coburg (503) und Kulmbach (403) sperren Anfragen mit „Eventcrawler“. Eine Umgehung missachtet die Entscheidung des Betreibers; der User-Agent ist zentral und gälte für alle Quellen. Weg über Freigabe, offizielle Schnittstelle oder Verzicht (Auftraggeber) |
| A86 | 2026-10-15 | **Schützenhaus Münchberg:** nur die Programmseite (1 Request), fester Ort „Schützenhaus Münchberg, Hofer Straße 69, Münchberg“; `external_id` = `{Post-ID}-{n}` | Die Liste enthält alle Termine mit Datum, Beginn und Kurztext, aber kein Ortsfeld. Die Seite ist das Hausprogramm; das Detail-Template nennt für jeden Termin dieselbe Hausadresse als festen Text (kein Feld je Termin), also brächten Detailseiten keinen Beleg je Termin, nur Laufzeit über dem Limit (44 × ~2,7 s). Vergleichbar Burg Rabenstein (fester Ort). Die Nummer n trennt Mehrtagesbeiträge in Termine |
| A87 | 2026-10-15 | **Stadtsteinach:** Liste (5 Seiten) + Detailseite je Termin (Ort nur dort); `external_id` = calendarize-Index-ID; `city` = „Stadtsteinach“ **nur**, wenn der Ortstext das Wort „Stadtsteinach“ enthält, sonst Termin übersprungen; keine Beschreibung, keine Adresse | Die Quelle hat kein Stadt- und kein Adressfeld. Pauschal „Stadtsteinach“ wäre nachweislich falsch (Herbstball im „Pfarrzentrum St. Hedwig Kulmbach“). Der Name der eigenen Gemeinde im Ortstext ist ein exakter Beleg; andere Orte oder Ortsnamen werden nicht ausgewertet (A66). 15 von 47 Terminen fallen dadurch weg – eine Zuordnungsliste Ort → Stadt bleibt Entscheidung des Auftraggebers |
| A88 | 2026-10-15 | **Die Stadt kann bei einzelnen Orten einer Quelle über eine explizite, verifizierte Ort→Stadt-Zuordnung gesetzt werden, wenn die Quelle selbst keine Stadt liefert.** Umsetzung zuerst bei Stadtsteinach (`VENUE_CITIES` im Adapter, 5 Orte). Regeln: nur quellenspezifisch (im Adapter, nicht global, nicht im Exporter/Repository); nur Orte mit externem Beleg (offizielle Stadt-, Tourismus-, Vereins- bzw. Kirchenseite oder Amtsblatt); exakter Vergleich des normalisierten Ortstexts (Entities, Leerzeichen, Groß-/Kleinschreibung), keine Teilstring-, Fuzzy- oder semantische Erkennung; es wird nur `city` gesetzt – keine Adresse und kein geänderter Ortsname daraus abgeleitet; Termine ohne Ort bleiben ausgeschlossen; keine Laufzeit-Abfragen (kein Geocoding) | 9 Termine mit eindeutig belegbarem Ort fehlten sonst (u. a. der Bauernmarkt 5 ×). Eine Pauschalregel „Stadtsteinach“ ist ausgeschlossen, weil die Quelle Termine in Kulmbach enthält (Herbstball im „Pfarrzentrum St. Hedwig Kulmbach“ → Kulmbach). Wenige feste Orte: keine Admin-Verwaltung nötig |
| A89 | 2026-10-15 | **Helmbrechts:** Termine aus den calendarize-Monatsansichten (aktueller + nächster Monat, ab heute), Ort/Adresse/Stadt/Teaser/Ende von der Detailseite je Veranstaltung; `external_id` = `{Slug}_{Datum}_{n}`; „Beginn: 00:00 Uhr“ der Monatsansicht = keine Uhrzeit (NULL); Ende nur für den Termin mit dem Detail-Datum | Die News-Liste zeigt je Veranstaltung nur einen Termin (FGH-Theater 1 von 5) und lässt einzelne Veranstaltungen aus; nur die Monatsansicht enthält jeden Termin. Ort nur auf der Detailseite (strukturiert mit PLZ/Stadt). 00:00 ist belegt ein Platzhalter (Detail: 08:00 am ersten Tag). Zwei Monate halten die Laufzeit bei ~75 s |
| A90 | 2026-10-15 | **Kronach:** Termine aus der Datumssuche (heute + 27 Tage, alle Seiten), `external_id` = Termin-ID; Jahr aus dem Suchbeginn; Adresse/Stadt von der Detailseite, für die unter „Weitere Termine“ genannten Termine derselben Veranstaltung ohne eigenen Abruf (nur bei exakt gleichem Ortsnamen); Zuordnung „Innenstadt Kronach“ → Kronach (A88) | Die Liste hat je Termin eine eigene ID, aber weder Jahr noch Adresse; die Startansicht zeigt nur 9 Hervorhebungen. Eine Detailseite je Termin wären ~110 Abrufe; „Weitere Termine“ ist die quelleigene Zuordnung von Terminen zu einer Veranstaltung (36 Abrufe). Fremdorte (Nordhalben) belegen, dass die Stadt nicht pauschal Kronach ist. 28 Tage halten die Laufzeit bei ~70 s |
| A91 | 2026-10-15 | **Quelle mit Vorrang je Ort bei bekannter starker Überschneidung:** Eine source-spezifische Prioritätsregel ist zulässig, wenn sie auf einem stabilen, belegten Merkmal beruht. Konkret: **Bayreuth Tourismus übernimmt keine Termine am Ort DAS ZENTRUM** (Venue-ID `e7cac5bf-eefe-4b23-af39-d06aba69e0bd` der Regionaldatenbank, über den API-Ortsfilter), weil DAS ZENTRUM als Primärquelle separat angebunden ist. Ausdrücklich **keine** unscharfe Deduplizierung, keine globale Event-ID, keine Datenfusion zwischen Quellen, keine semantische Erkennung; andere Orte werden nicht ausgeschlossen. Ausschlüsse zählen nicht als „gefunden“ (keine eigene Ergebniskategorie, keine Core-Änderung) | Ohne Regel stünden ca. 40 Termine je Jahr doppelt im XML, meist mit anderem Titel (A66 verbietet unscharfen Abgleich). DAS ZENTRUM ist Betreiber des Hauses, hat mehr Termine, vollständigere Titel und eine Termin-ID. Die Venue-ID ist eindeutig (genau ein Ort in 633) und deckt sich mit dem exakten Ortsnamen (15/15) |
| A92 | 2026-10-15 | **TLS: Maßstab ist WordPress selbst.** Eine Quelle wird nur angebunden, wenn `wp_remote_get()` mit `sslverify=true` und dem CA-Bundle von WordPress verbindet. Scheitert die Verifikation, wird die Quelle zurückgestellt – kein `sslverify=false`, kein `CURLOPT_SSL_VERIFYPEER`, kein eigenes CA-Zertifikat im Plugin (Thurnau, 2026-10-15: Root „ISRG Root YR“ fehlt im Bundle) | Eine abgeschaltete Prüfung würde Daten ungeprüfter Herkunft speichern; eigenes CA-Handling wäre eine Sicherheitsentscheidung des Betreibers. Git-curl ist kein Maßstab (bei Bayreuth Tourismus falsch negativ) |
