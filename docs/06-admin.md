# 06 – WordPress-Administration

> **Stand 2026-09-24: implementiert und getestet:**
> - Menü und Liste
> - Einzel- und Bulk-Löschen
> - Crawler für **alle** oder **eine** Quelle
> - Listenfilter nach Quelle, Monat, Jahr und Freitextsuche
> - XML-Export mit Filter nach Quelle, Monat und Jahr
> - **Stand 2026-09-26:** Untermenü „Filterregeln“ (Abschnitt 9)
> - **Stand 2026-10-06:** Untermenü „Link-Bezeichnungen“ (Abschnitt 10) und read-only Detail-Modal „Details“ in der
>   Liste (Abschnitt 11)
>
> Code: `wp-plugin/includes/admin/class-eventcrawler-admin.php`, `class-eventcrawler-list-table.php`,
> `class-eventcrawler-filter-admin.php` und `class-eventcrawler-domain-admin.php`, Skript/CSS in `wp-plugin/assets/`,
> Filter-SQL in `Eventcrawler_Repository::where_clause()`

## 1. Menü

- Top-Level-Menüpunkt **„Eventcrawler“**, Seitentitel und Überschrift **„Importierte Veranstaltungen“**,
  Seiten-Slug `eventcrawler` (`admin.php?page=eventcrawler`), Icon `dashicons-calendar-alt`, Position 26
- **Warum nicht „Veranstaltungen“:** Auf dem Zielsystem gibt es bereits ein anderes Plugin für die **manuelle**
  Veranstaltungsverwaltung mit diesem Menüpunkt. Das andere Plugin und sein Menüpunkt werden **nicht** verändert
  (Entscheidung A22).
- Berechtigung: **`manage_options`** (`Eventcrawler_Admin::CAPABILITY`), also nur Administratoren. Andere Rollen
  bekommen HTTP 403 (getestet mit einem Abonnenten). Eigene Rollen und Rechte sind nicht Teil des MVP.
- Untermenü (seit 2026-09-26):
  - **„Importierte Veranstaltungen“** = die Hauptseite (`page=eventcrawler`); ohne diesen Eintrag würde WordPress den
    ersten Unterpunkt „Eventcrawler“ nennen
  - **„Filterregeln“** (`page=eventcrawler-filter`, Abschnitt 9), ebenfalls nur `manage_options`
  - **„Link-Bezeichnungen“** (`page=eventcrawler-domains`, Abschnitt 10, seit 2026-10-06), ebenfalls nur `manage_options`

## 2. Aufbau der Seite

Nur WordPress-Admin-Stile, keine eigene Gestaltung. Von oben nach unten:

```
Importierte Veranstaltungen
[Meldungen]
Crawler:     Quelle [Alle Quellen ▼]                                       [Crawler starten]
XML-Export:  Quelle [Alle Quellen ▼] Monat [Alle Monate ▼] Jahr [Alle Jahre ▼]  [XML exportieren]
Liste filtern: Quelle [Alle Quellen ▼] Monat [Alle Monate ▼] Jahr [Alle Jahre ▼] Suche [______] [Filtern] Zurücksetzen
[Tabelle mit Bulk-Aktionen und Paginierung]
```

Vier getrennte Formulare mit eigenen Feldnamen und HTML-IDs. So kollidieren keine Nonce-Felder, und es entstehen
keine doppelten IDs (getestet: keine doppelte `id` auf der Seite):

| Formular | Methode / Ziel | Felder | Nonce |
|---|---|---|---|
| Crawler (kein Formular) | JavaScript: je Quelle POST `admin-ajax.php`, `action=eventcrawler_crawl_source`, `source` | Auswahl `#eventcrawler-crawl-source`, Button `#eventcrawler-crawl-start`, Fortschritt `#eventcrawler-crawl-progress` | `eventcrawler_crawl_source` als Feld `nonce`, übergeben per `window.eventcrawlerCrawl` (kein Nonce-Feld im HTML) |
| XML-Export | POST `admin-post.php`, `action=eventcrawler_export` | `eventcrawler_export_source`, `eventcrawler_export_month`, `eventcrawler_export_year` (`#eventcrawler-export-*`) | `eventcrawler_export` im Feld `eventcrawler_export_nonce` |
| Listenfilter | **GET** `admin.php?page=eventcrawler` | `source`, `month`, `year`, `s` (`#eventcrawler-filter-*`) | keiner (reine Ansicht) |
| Tabelle (Bulk-Löschen) | POST auf die aktuelle, gefilterte Listen-URL | `ids[]`, Bulk-Aktion | `bulk-events` im Feld `_wpnonce` (von `WP_List_Table`) |

### Quellen dynamisch aus dem Register

Alle drei Quellen-Dropdowns (Crawler, Export, Liste) kommen aus **einer** Stelle:
`Eventcrawler_Admin::source_options()` liest das Adapter-Register `Eventcrawler_Crawler::get_sources()` (Slug =>
Adapter; Label = `get_name()`). **Belegt mit Coburg (2026-09-24):** Nach der Registrierung erschien „Coburg“ ohne
Änderung am Admin-Code in Crawler-, Export- und Listen-Dropdown, in der Quellen-Spalte und in der Validierung
(Einzelcrawl, Filter, Export getestet). Allgemein erscheint ein neuer Adapter automatisch in allen
Auswahlfeldern, in der Validierung und in der Spalte „Quelle“. Quellennamen sind nirgends sonst hart codiert.
**Ebenso Theater Selb (2026-10-15):** ohne Änderung an Admin-Code oder JavaScript in Crawler-, Listen- und
Exportauswahl, in `window.eventcrawlerCrawl.sources` und in der Spalte „Quelle“; „Alle Quellen“ zeigt „Quelle 1 von 4“
bis „Quelle 4 von 4“. Die Suche findet Theater-Selb-Termine über Titel („Evviva“ 1), Beschreibung („Donizetti“ 1),
Ort („Rosenthal-Theater“ 29), Adresse („Hohenbergerstrasse“ 2) und Stadt („Selb“ 29); das Detail-Modal zeigt die
Werte wie gespeichert (NULL bei Ende, `""` bei leerer Beschreibung).
**Ebenso DAS ZENTRUM Bayreuth (2026-10-15):** ohne Admin-/JS-Änderung in allen drei Auswahlfeldern; „Alle Quellen“
zeigt „Quelle 1 von 5“ bis „Quelle 5 von 5“. Suche: Titel („Bosetti“ 1, „è bella“ 1), Ort („Kleinkunstbühne“ 19),
Adresse („Äußere Badstraße“ 54), Stadt („Bayreuth“ 73); Beschreibung ist bei dieser Quelle leer. Detail-Modal mit 5
echten Terminen geprüft (04-quellen.md Abschnitt 5).
**Ebenso Schützenhaus Münchberg (2026-10-15):** automatisch in allen Auswahlfeldern, „Quelle 1 von 6“ bis „Quelle 6 von
6“. Suche: Titel („Bausch“ 1), Beschreibung („Stadtrundgang“ 2, „Grüne“ 1), Ort („Schützenhaus Münchberg“ 46), Adresse
(„Hofer Straße“ 46), Stadt („Münchberg“ 46). Detail-Modal mit 6 echten Terminen geprüft.
**Ebenso Bayreuth Tourismus (2026-10-15):** „Quelle 1 von 10“ bis „Quelle 10 von 10“; Suche: Titel („Stuttgarter“ 1),
Beschreibung („Saint-Saëns“ 1), Ort („Friedrichsforum“ 13), Adresse („Hugenottenstra“ 1), Stadt („Bayreuth“ 538 der
Quelle, 612 gesamt mit DAS ZENTRUM). „gefunden“ zählt die Termine nach dem Ausschluss des Orts DAS ZENTRUM (A91).
Modal mit 5 Terminen geprüft.
**Ebenso Kronach (2026-10-15):** „Quelle 1 von 9“ bis „Quelle 9 von 9“; Suche: Titel („Ninotschka“ 2), Ort
(„Kreiskulturraum“ 3), Adresse („Siechenanger“ 13), Stadt („Nordhalben“ 1, „Kronach“ 103); Beschreibung ist bei dieser
Quelle leer. Modal mit 5 Terminen geprüft.
**Ebenso Helmbrechts (2026-10-15):** „Quelle 1 von 8“ bis „Quelle 8 von 8“; Suche: Titel („LaLeLu“ 1), Beschreibung
(„Kabarett“ 1), Ort („Textilmuseum“ 23), Adresse („Kirchspitz“ 1), Stadt („Helmbrechts“ 47); Modal mit 5 Terminen geprüft.
**Ebenso Stadtsteinach (2026-10-15):** „Quelle 1 von 7“ bis „Quelle 7 von 7“; Suche: Titel („Digital“ 4), Ort
(„Klinikums“ 14, „Steinachtalhalle“ 7), Stadt („Stadtsteinach“ 32); Beschreibung und Adresse sind bei dieser Quelle leer.
Detail-Modal mit 6 echten Terminen geprüft.

Die Monatsnamen kommen aus `Eventcrawler_Exporter::MONTHS`. Die Jahre ermittelt
`Eventcrawler_Repository::get_available_years()` (`DISTINCT YEAR(start_date)`), derzeit 2026 und 2027. Ohne Daten
gibt es nur „Alle Jahre“. Ein per URL gewähltes Jahr ohne Daten wird zusätzlich angezeigt, damit die Auswahl sichtbar bleibt.

## 3. Crawler starten

**Seit 2026-09-24: eine Quelle = ein HTTP-Request** (Entscheidung A55). Vorher lief „Alle Quellen“ in einem
einzigen `admin-post.php`-Request (drei Quellen ≈ 65 s von 120 s). Dieser Endpunkt (`action=eventcrawler_run`)
wurde entfernt.

**Ablauf:**

1. Die Seite lädt `assets/eventcrawler-admin.js` (nur auf dieser Admin-Seite, Vanilla JS, keine Bibliothek) und
   übergibt per `wp_add_inline_script` das Objekt `window.eventcrawlerCrawl` mit `ajaxUrl`, `nonce` und `sources`.
   `sources` stammt aus `Eventcrawler_Admin::source_options()`, also aus dem Adapter-Register. Im JavaScript ist
   keine Quelle hart codiert. Eine vierte registrierte Quelle wird automatisch Teil von „Alle Quellen“.
2. „Crawler starten“:
   - **eine Quelle** gewählt → ein Request für diese Quelle
   - **„Alle Quellen“** → je Quelle ein Request, **nacheinander**: Request starten, Antwort abwarten, anzeigen,
     nächste Quelle. Nicht parallel.
3. Jeder Request geht an `admin-ajax.php`, `action=eventcrawler_crawl_source`, `source={slug}`, `nonce`.
   `Eventcrawler_Admin::handle_crawl_source()` ruft denselben Crawler-Core auf wie bisher
   (`Eventcrawler_Crawler::run()`) und antwortet mit JSON:
   - Erfolg: `{"success":true,"data":{"source":"coburg","label":"Coburg","duration":16.2,"stats":{"found":365,"new":0,"updated":0,"unchanged":365,"filtered":0,"failed":0}}}`
   - Quellenfehler: `{"success":false,"data":{"source":"…","label":"…","duration":0.0,"message":"Quelle antwortet mit HTTP 500. Vorhandene Veranstaltungen dieser Quelle wurden nicht verändert.","stats":null}}`
4. **Fortschritt** (WordPress-Notice unter dem Button), aktualisiert nach jedem Request, z. B.:
   ```
   Crawler läuft – Quelle 2 von 3: Bad Steben
   ✓ Burg Rabenstein: 124 gefunden, 0 neu, 0 aktualisiert, 124 unverändert, 0 gefiltert, 0 fehlerhaft. (1,4 s)
   → Bad Steben wird verarbeitet …
   … Coburg wartet
   ```
5. **Während des Laufs** sind Button und Quellenauswahl gesperrt; ein zweiter Klick startet keinen zweiten Lauf
   (getestet: 3 Klicks → 3 Requests, je Quelle einer). Nach Ende oder Fehler werden beide wieder freigegeben.
6. **Ergebnis:** Nach dem letzten Request berechnet das Skript die Summe der **erfolgreichen** Quellen und zeigt:
   - alle Quellen: „Crawler abgeschlossen: 3 von 3 Quellen erfolgreich. Summe der erfolgreichen Quellen: 1047 gefunden, 1 neu, 0 aktualisiert, 1043 unverändert, 0 gefiltert, 3 fehlerhaft.“
     plus eine Zeile je Quelle mit Dauer. Fehlgeschlagene **Quellen** werden getrennt genannt („2 von 3 Quellen
     erfolgreich (1 fehlgeschlagen)“, Zeile „✗ Bad Steben: fehlgeschlagen – …“). „fehlerhaft“ zählt nur **einzelne
     Einträge** (z. B. ohne Stadt).
   - eine Quelle: „Crawler abgeschlossen (Coburg): 365 gefunden, …“ bzw. „Crawler fehlgeschlagen (Coburg).“
   - Farbe: rot bei einer fehlgeschlagenen Quelle, gelb bei fehlerhaften Einträgen, sonst grün.
7. **Aktualisierung der Liste:** Danach lädt die Seite **einmal neu**. Die Zusammenfassung wird vorher im
   `sessionStorage` des Browsers abgelegt und nach dem Reload einmalig wieder angezeigt (kein Server-Status, keine
   Tabelle). Ist `sessionStorage` nicht verfügbar, bleibt die Meldung ohne Reload stehen („Liste bitte neu laden“).

**Fehlerbehandlung im Skript** (je Quelle; die nächste Quelle startet trotzdem):
- Quellenfehler des Servers (`success:false`) → Meldung des Servers
- HTTP 403 / Antwort `-1` → „Sicherheitsprüfung fehlgeschlagen (Sitzung abgelaufen? Seite neu laden).“
- keine gültige JSON-Antwort (z. B. PHP-Fehlerseite) → „Ungültige Antwort vom Server (HTTP …).“
- Netzwerkabbruch → „Netzwerkfehler: …“
- Zeitüberschreitung nach 150 s (etwas über dem PHP-Limit von 120 s) → „Zeitüberschreitung …“ (nicht künstlich getestet)

**Browser geschlossen oder Seite verlassen** während „Alle Quellen“: Bereits abgeschlossene Quellen bleiben
gespeichert. Eine laufende Quelle wird serverseitig in der Regel zu Ende verarbeitet, weil PHP bei einem
Verbindungsabbruch nicht zwingend abbricht. Noch nicht gestartete Quellen werden **nicht** verarbeitet. Es gibt
keinen persistenten Job (für den MVP akzeptiert).

**Ohne JavaScript** bleibt der Button deaktiviert, mit dem Hinweis „Der Crawler benötigt JavaScript.“

**Getestet** (2026-09-24, im echten Browser: Microsoft Edge headless, per DevTools-Protokoll gesteuert):
„Alle Quellen“ live → genau 3 Crawl-Requests nacheinander ohne Überlappung. Einzelquellen → je 1 Request, nur diese
Quelle abgerufen (Abrufzähler). Bad Steben HTTP 500 bzw. Coburg Netzwerkfehler → die anderen Quellen laufen weiter.
Ergänzend mit eingeschleusten Antworten: abgelaufene Sitzung, PHP-Fehlerseite, Netzwerkabbruch.
Details und Laufzeiten: 03-crawler.md Abschnitt 14.

## 4. Veranstaltungsliste

Umgesetzt mit `WP_List_Table`, offiziell keine öffentliche API, bewusst minimal genutzt.

| Spalte | Feld | Anzeige |
|---|---|---|
| Checkbox | `id` | `ids[]` für die Mehrfachauswahl |
| Datum | `start_date` | `d.m.Y` |
| Uhrzeit | `start_time`, `end_time`, `end_date`, `all_day` | „18:00–22:00“, „18:00“, „Ganztägig“ oder „—“; liegt `end_date` nach `start_date`, zusätzlich „(bis TT.MM.JJJJ)“ |
| Titel | `title` | fett; darunter die Beschreibung als gekürzter Klartext (20 Wörter) |
| Veranstaltungsort | `venue_name` | darunter klein die Adresse `venue_address`, falls vorhanden (seit 2026-10-02, escaped) |
| Stadt | `city` | |
| Quelle | `source` | Anzeigename aus dem Register („Burg Rabenstein“, „Bad Steben“); unbekannte Slugs unverändert |
| zuletzt geprüft | `last_seen_at` | `d.m.Y H:i` |
| Aktionen | – | „Details“ (read-only Detail-Modal, Abschnitt 11, seit 2026-10-06), „Löschen“ (JS-Bestätigung) und „Quelle ansehen“ (`source_url`, neuer Tab, `rel="noopener noreferrer"`) |

- Standardsortierung `start_date ASC, start_time ASC, id`. Sortierbar: Datum, Titel, Stadt, Quelle, zuletzt geprüft
  (Whitelist im Repository; Sortierung nach Quelle nutzt den Slug).
- 50 Einträge pro Seite. Vergangene Veranstaltungen werden angezeigt; sie lassen sich über Monat/Jahr eingrenzen.
- Alle Ausgaben werden escaped (getestet: `<script>` in Beschreibung und im Suchfeld wird nicht ausgeführt).

### Listenfilter und Suche

| Filter | GET-Parameter | Werte | SQL (in `where_clause()`, immer über `prepare()`) |
|---|---|---|---|
| Quelle | `source` | Slug aus dem Register | `source = %s` |
| Monat | `month` | 1–12 | ohne Jahr: `MONTH(start_date) = %d` (dieser Monat in **allen** Jahren) |
| Jahr | `year` | 2000–2100 | `start_date >= 'JJJJ-01-01' AND start_date < 'JJJJ+1-01-01'` |
| Monat + Jahr | beide | | `start_date >= 'JJJJ-MM-01' AND start_date < erster Tag des Folgemonats` |
| Suche | `s` | Text, max. 100 Zeichen | `(title LIKE %s OR description LIKE %s OR venue_name LIKE %s OR venue_address LIKE %s OR city LIKE %s)` mit `%…%` (Adresse seit 2026-10-02; getestet: „Herrngasse“ Liste 59 = SQL 59) |

- Alle Filter sind **kombinierbar** (UND-Verknüpfung).
- Monat/Jahr filtern auf dem **Datumsfeld `start_date`**, nie per Textvergleich formatierter Datumswerte.
- Suche: `sanitize_text_field()`, `$wpdb->esc_like()`. `%` und `_` werden als normale Zeichen gesucht (getestet).
  Groß- und Kleinschreibung spielen keine Rolle (Collation `utf8mb4_unicode_520_ci`). Diese Collation behandelt
  Umlaute wie den Grundbuchstaben („Gesange“ findet auch „Gesänge“). `source` wird nicht durchsucht, dafür gibt es den
  Quellenfilter.
- **Ungültige Werte** in der URL (unbekannte Quelle, Monat 13, Jahr „abc“) werden **ignoriert**, also wie „alle“
  behandelt. Die Liste ist eine reine Ansicht; es gibt keine Fehlermeldung.
- **URL-Zustand:** Die Filter stehen in der URL (z. B. `…&source=bad-steben&month=10&year=2026&s=Therme`). Die Ansicht
  lässt sich neu laden, per Lesezeichen speichern und mit „Zurück“ erreichen. Paginierungs- und Sortierlinks von
  `WP_List_Table` übernehmen die aktuelle URL, sodass Filter **und** Suche erhalten bleiben (getestet: Link zu Seite 2
  und Sortierlink enthalten alle Parameter; Filterfelder bleiben vorbelegt).
- **„Zurücksetzen“** ist ein Link auf `admin.php?page=eventcrawler` und zeigt wieder alle Events.
- **Löschen** in einer gefilterten Ansicht: Lösch-Link, Bulk-Formular und der Redirect danach behalten Filter,
  Suche und Sortierung. Die Seitennummer entfällt, weil die Seite nach dem Löschen leer sein könnte (getestet).

Getestete Kombinationen (681 Events; Trefferzahl der Liste = unabhängige SQL-Zählung):

| Filter | Treffer |
|---|---|
| ohne Filter | 681 |
| Quelle Burg Rabenstein / Bad Steben | 124 / 557 |
| Monat Oktober (alle Jahre) / März (alle Jahre) | 228 / 15 |
| Jahr 2027 | 65 |
| Monat + Jahr 12/2026 | 154 |
| Bad Steben + 10/2026 / Burg Rabenstein + 10/2026 | 214 / 14 |
| Suche „Whisky“ / „therme“ / „Ahorntal“ / „Lichtenberg“ / „Gesänge“ | 4 / 317 / 124 / 8 / 1 |
| Bad Steben + Suche „Konzert“ | 201 |
| Bad Steben + 9/2026 + Suche „Therme“ | 21 |
| Bad Steben + 10/2026 + Suche „Therme“ (3 Seiten, Paginierung und Sortierung geprüft) | 109 |
| Suche „%“ / „_“ / ohne Treffer / SQL-Versuch `' OR 1=1 --` | 0 / 1 / 0 / 0 |
| ungültige Quelle / Monat 13 / Jahr „abc“ (ignoriert) | 681 |

Nach Anbindung von Coburg (1047 Events; Liste = SQL in allen Fällen, ohne Codeänderung):

| Filter | Treffer |
|---|---|
| Coburg / Coburg + Oktober / Coburg + 2026 / Coburg + 11/2026 | 365 / 264 / 365 / 38 |
| Coburg + Suche „Theater“ / Coburg + 10/2026 + „Landestheater“ | 22 / 14 |
| Suche „Bad Rodach“ (Stadt) / „Rödental“ (Stadt, Umlaut) / „Stadtführung“ (Beschreibung) / „Kongresshaus“ (Ort) / „Pippi“ (Titel) | 52 / 6 / 75 / 16 / 2 |
| Coburg + Oktober: 6 Seiten; Seite 2 und Sortierung nach Stadt behalten den Filter | geprüft |

## 5. XML-Export mit Filter

- Eigenes POST-Formular mit **eigenen Feldern** (übernimmt keine Werte aus Liste oder Crawler): Quelle, Monat, Jahr.
  **Keine** Freitextsuche im Export.
- Dieselbe Validierung wie bei der Liste (`Eventcrawler_Admin::read_filters()`), aber **strikt**: Ein gesetzter,
  ungültiger Wert bricht ab („Export abgebrochen: Unbekannte Quelle. / Ungültiger Monat. / Ungültiges Jahr.“), und es
  gibt keinen Download.
- Die Auswahl der Events erfolgt im Repository (`get_events_for_export( $filters )`), nicht nachträglich im XML.
- Keine Treffer → kein Download, gelbe Meldung „Keine Veranstaltungen für den gewählten Exportfilter vorhanden.“
  (ohne Filter wie bisher „Keine Veranstaltungen für den Export vorhanden.“).
- Format, Dateiname und Kombinationsregeln: 07-export.md.

## 6. Sicherheit

| Aktion | Absicherung | getestet |
|---|---|---|
| Crawler (AJAX, eine Quelle je Request) | nur angemeldet (`wp_ajax_` ohne `nopriv`; nicht angemeldet → 400 „0“), nur POST (GET → 405), `manage_options` (sonst 403), Nonce `eventcrawler_crawl_source` (`check_ajax_referer`; falscher oder Export-Nonce → 403 „-1“), Quelle per `sanitize_key` gegen das Register (unbekannt, leer, Klassenname → 400 „Unbekannte Quelle.“); keine Klassen/URLs aus dem Request | ja; bei jeder Ablehnung **kein** Abruf einer Quelle |
| XML exportieren | nur POST (GET → 405), `manage_options` (sonst 403), Nonce `eventcrawler_export`/`eventcrawler_export_nonce` (falscher oder Crawler-Nonce → 403), nicht angemeldet → 400, Quelle/Monat/Jahr validiert | ja; bei jeder Ablehnung **kein** XML |
| Löschen einzeln | `manage_options`, Nonce `eventcrawler_delete_{id}`, `absint()` | ja |
| Bulk-Löschen | `manage_options`, Nonce `bulk-events`, IDs per `absint()`, `DELETE … IN (%d,…)` | ja |
| Filterregeln anlegen/ändern/aktivieren/deaktivieren/löschen | nur POST an `admin-post.php` (GET → 405), `manage_options` (sonst 403), Nonce je Aktion `eventcrawler_filter_{add,update,toggle,delete}` im Feld `eventcrawler_filter_nonce` (falsch/fehlend → 403), Begriff per `sanitize_text_field` (HTML entfernt), Kategorie/Vergleich gegen Whitelist, Regel-ID gegen gespeicherte IDs; Ausgabe mit `esc_html`/`esc_attr` | ja; `<script>alert(1)</script>` wird abgelehnt, nichts gespeichert |
| Link-Bezeichnungen anlegen/ändern/aktivieren/deaktivieren/löschen | nur POST an `admin-post.php` (GET → 405), `manage_options` (sonst 403), Nonce je Aktion `eventcrawler_domain_{add,update,toggle,delete}` im Feld `eventcrawler_domain_nonce` (falsch, fehlend, Nonce einer anderen Aktion oder der Filterregeln → 403), Domain auf den Hostnamen normalisiert und geprüft, Ausgabe per `sanitize_text_field` (HTML entfernt), max. 100 Zeichen, ID gegen gespeicherte IDs; Ausgabe mit `esc_html`/`esc_attr`; Löschen mit Rückfrage | ja (Abschnitt 10) |
| Detaildaten (AJAX `eventcrawler_get_event_details`) | nur angemeldet (`wp_ajax_` ohne `nopriv`; sonst 400 „0“), nur POST (GET → 405), `manage_options` (Abonnent → 403), Nonce `eventcrawler_event_details` im Feld `nonce` (falsch, fehlend oder Crawler-Nonce → 403), `event_id` nur Ziffern > 0 (fehlend/ungültig → 400), nicht vorhanden → 404; Abfrage über `Repository::get_by_id()` (`prepare( … %d )`); nur lesend | ja (Abschnitt 11) |
| Listenfilter/Suche | Seite nur mit `manage_options`; Werte validiert (`sanitize_text_field`, Whitelist, Integer-Bereiche); SQL nur über `prepare()` und `esc_like()`; Ausgaben escaped | ja |

Prüfung der admin-post-Aktion (Export): `Eventcrawler_Admin::check_post_request()`. Der Crawler prüft im
AJAX-Endpunkt `handle_crawl_source()` selbst (POST, Capability, `check_ajax_referer`).
Rückmeldungen: Transient `eventcrawler_notices_{user_id}` (5 Minuten, wird beim Anzeigen gelöscht). Nach dem Löschen
steht der Parameter `deleted` in der URL; er gehört zu den `wp_removable_query_args`.

## 7. Löschen – Verhalten und spätere Erweiterung

- **MVP:** Normales Löschen (`DELETE`), ohne Papierkorb und ohne Ignore-Liste. Findet der Crawler die Veranstaltung
  wieder in der Quelle, wird sie **erneut importiert** (getestet). Das ist bekannt und für den MVP akzeptiert.
- **Spätere Erweiterung – Ignore-/Blacklist:** gelöschte Veranstaltungen merken (`source` + `external_id` /
  `source_url` / `event_key`) und nicht wieder importieren; mit Ansicht zum Aufheben. **Noch nicht implementiert.**

## 8. Nicht Teil des MVP

- Bearbeiten von Veranstaltungen im Backend (das Detail-Modal, Abschnitt 11, ist bewusst nur lesend)
- Einstellungsseite (Quellen, Zeitplan); Filterbegriffe siehe Abschnitt 9
- Dashboard-Widgets, Statistiken, Charts, Trefferzahlen in den Dropdowns (z. B. „September (132)“)
- Log-Ansicht, Screen Options
- Filter nach Stadt (derzeit über die Suche möglich)

## 9. Filterregeln (implementiert 2026-09-26)

Seite **Eventcrawler → Filterregeln** (`admin.php?page=eventcrawler-filter`), Klasse `Eventcrawler_Filter_Admin`.
Fachliche Regeln, Speicherung und Matching: [05-filter.md](05-filter.md).

Aufbau von oben nach unten:

1. Kurze Erklärung: Regeln gelten global für alle Quellen, geprüft werden Titel und Beschreibung, bereits
   gespeicherte Veranstaltungen werden nicht verändert.
2. Formular **„Begriff hinzufügen“** bzw. **„Filterregel bearbeiten“** (über den Link „Bearbeiten“, `&edit={id}`):
   Begriff (max. 100 Zeichen), Kategorie (Stammtisch / unerwünschte Treffen, Politische Inhalte), Vergleich
   (enthält (auch als Wortteil) / ganzes Wort), beim Bearbeiten zusätzlich „aktiv“.
3. **„Text prüfen“** (GET-Formular, speichert nichts): zeigt für einen eingegebenen Titel
   „Würde gefiltert: {Kategorie} (Regel „{Begriff}“)“ oder „Würde importiert (keine aktive Regel trifft zu).“
4. Tabelle **„Regeln (n)“** mit den Spalten Begriff, Kategorie, Vergleich, Status (Aktiv/Inaktiv) und Aktionen
   (Bearbeiten, Deaktivieren/Aktivieren, Löschen mit Rückfrage).

Rückmeldungen (Transient `eventcrawler_filter_notice_{user_id}`, nach Redirect):

| Aktion | Meldung |
| --- | --- |
| Anlegen | „Filterregel hinzugefügt.“ |
| Bearbeiten | „Filterregel aktualisiert.“ |
| Aktivieren / Deaktivieren | „Filterregel aktiviert.“ / „Filterregel deaktiviert.“ |
| Löschen | „Filterregel gelöscht.“ |
| leerer Begriff (auch nach Entfernen von HTML) | „Der Begriff darf nicht leer sein (HTML wird entfernt).“ |
| gleicher Begriff in derselben Kategorie (Groß-/Kleinschreibung egal) | „Diesen Begriff gibt es in dieser Kategorie bereits.“ |
| zu lang, unbekannte Kategorie/Vergleichsart | entsprechende Fehlermeldung, nichts gespeichert |

- Ist die Option beschädigt, zeigt die Seite eine Fehlermeldung („… Derzeit ist keine Regel aktiv …“) statt eines
   Fatal Errors.
- Die Nonce-Felder haben kein `id`-Attribut, damit die vielen Zeilenformulare keine doppelten IDs erzeugen (geprüft).
- Eine Änderung wirkt ab dem nächsten Crawl; bereits gespeicherte Veranstaltungen bleiben unverändert.

## 10. Link-Bezeichnungen (implementiert 2026-10-06)

Seite **Eventcrawler → Link-Bezeichnungen** (`admin.php?page=eventcrawler-domains`), Klasse
`Eventcrawler_Domain_Admin`, Speicherung in `Eventcrawler_Domain_Mappings`. Wirkung im Export: [07-export.md](07-export.md)
Abschnitt 13.

Im XML steht nach „Info:“ nur die Domain des Veranstaltungslinks (z. B. `pages.et4.de`). Hier legt der Administrator
für eine Domain einen verständlichen, **frei wählbaren** Text fest (z. B. „Bad Steben“, „Tourismus Bad Steben“ oder
„www.bad-steben.de“). Die Zuordnung gilt **global für alle Quellen** mit dieser Domain und **nur** für die Ausgabe im
XML; gespeicherte URLs, Matching und Crawler bleiben unverändert.

Aufbau von oben nach unten:

1. Kurze Erklärung (global, nur XML, Domain als Rückfall)
2. Formular **„Link-Bezeichnung hinzufügen“** bzw. **„Link-Bezeichnung bearbeiten“** (Link „Bearbeiten“, `&edit={id}`):
   - **Domain:** Domain oder ganzer Link. Gespeichert wird nur der Hostname in Kleinbuchstaben: `pages.et4.de`,
     `https://pages.et4.de`, `http://pages.et4.de/foo`, `PAGES.ET4.DE` → `pages.et4.de`; `www.example.de/path` →
     `www.example.de`. Protokoll, Port, Pfad, Query und Fragment entfallen, `www.` bleibt (wie im Export). Abgelehnt:
     leer, Leerzeichen/Sonderzeichen im Host, `@` (E-Mail-Adresse, Zugangsdaten), andere Schemes als http/https
     (`mailto:`, `ftp://`, `javascript:`)
   - **Ausgabe im XML:** freier Text, keine URL-Prüfung; HTML wird entfernt (`sanitize_text_field`), Whitespace
     zusammengefasst, höchstens 100 Zeichen; leer (auch nach Entfernen von HTML) wird abgelehnt
   - **aktiv** (Häkchen, beim Anlegen vorbelegt)
   - **Speichern**
3. Tabelle **„Link-Bezeichnungen (n)“**: Domain | Ausgabe im XML | Status (Aktiv / Inaktiv) | Aktionen (Bearbeiten,
   Deaktivieren/Aktivieren, Löschen mit Rückfrage)

| Aktion | Meldung |
| --- | --- |
| Anlegen | „Link-Bezeichnung gespeichert.“ |
| Bearbeiten | „Link-Bezeichnung aktualisiert.“ |
| Deaktivieren / Aktivieren | „Link-Bezeichnung deaktiviert. Im XML steht wieder die Domain.“ / „Link-Bezeichnung aktiviert.“ |
| Löschen | „Link-Bezeichnung gelöscht. Im XML steht wieder die Domain.“ |
| Domain schon vorhanden (Groß-/Kleinschreibung egal, auch beim Ändern der Domain) | „Für die Domain „pages.et4.de“ gibt es bereits eine Link-Bezeichnung.“ |
| leere bzw. ungültige Domain | „Die Domain darf nicht leer sein.“ / „Ungültige Domain. …“ |
| leere Ausgabe (auch nach Entfernen von HTML), zu lang | entsprechende Meldung, nichts gespeichert |

- Bewusst **keine Default-Einträge** (Entscheidung A73): Die Liste startet leer.
- Beschädigte Option → Fehlermeldung auf der Seite, im XML überall die Domain; ein neuer Eintrag ersetzt den Wert.
- Eine Domain ist keine Quelle: Die Bezeichnung ist nicht an einen Adapter gebunden. Liefert ein künftiger Adapter
  Links derselben Domain (z. B. eine weitere destination.one-Quelle auf `pages.et4.de`), gilt dieselbe Bezeichnung.
- Nach einem Validierungsfehler zeigt das Formular wieder den gespeicherten Stand (Eingaben werden nicht
  zurückgespielt, wie bei den Filterregeln).
- Nonce-Felder ohne `id`-Attribut (keine doppelten IDs), Rückmeldung über den Transient
  `eventcrawler_domain_notice_{user_id}`.

Getestet (2026-10-06, Testinstallation, `tools/test-domain-mappings.php` und HTTP-Tests): Anlegen per Domain und per
URL mit Pfad, Dubletten (`PAGES.ET4.DE`, URL-Form, beim Ändern), leere Domain/Ausgabe, `<script>alert(1)</script>`
(abgelehnt), Attribut-Ausbruch `x" onmouseover="…"` (gespeichert, escaped angezeigt), XSS als Domain (abgelehnt),
inaktiv anlegen, Bearbeiten, Deaktivieren/Aktivieren, Löschen; GET → 405, falscher/fehlender/fremder Nonce → 403,
Abonnent (Seite und Aktionen) → 403, nicht angemeldet → Login bzw. keine Wirkung.

## 11. Detail-Modal „Details“ (implementiert 2026-10-06)

Reines **Test- und Debug-Werkzeug**: zeigt die **tatsächlich gespeicherten** Werte eines Events aus
`{prefix}eventcrawler_events`, damit sich Quelle → Speicherung → XML-Ausgabe unterscheiden lassen (z. B.: Wurde
`venue_address` gespeichert? Ist `end_time` NULL? Welche `external_id`, welcher `event_key`, welche URL? Wann zuletzt
gesehen? Hat sich `content_hash` geändert?). **Nur lesend**: keine Felder, kein Speichern, keine DB-Änderung.

- **Erreichbar:** Liste „Importierte Veranstaltungen“, Spalte „Aktionen“, erster Eintrag **„Details“** (Button mit
  `data-event-id`, Entscheidung A76). In der Liste steht nur die ID, keine Beschreibung oder URL zusätzlich im HTML.
- **Ablauf:** Klick → Modal öffnet sofort mit „Lade Daten…“ → POST an `admin-ajax.php`
  (`action=eventcrawler_get_event_details`, `nonce`, `event_id`; Nonce per `window.eventcrawlerDetails`) →
  `Eventcrawler_Admin::handle_event_details()` → `Eventcrawler_Repository::get_by_id()` (`SELECT *`, `prepare`) →
  JSON `{"success":true,"data":{"id":1641,"fields":[["id","1641"],["source","coburg"],…,["end_time",null]]}}`.
  Kein SQL und keine Feldliste im JavaScript.
- **Felder:** alle Spalten der Tabelle in ihrer physischen Reihenfolge, auch künftige (derzeit 20: `id`, `source`,
  `external_id`, `event_key`, `title`, `description`, `start_date`, `start_time`, `venue_name`, `city`, `source_url`,
  `content_hash`, `created_at`, `updated_at`, `last_seen_at`, `end_date`, `end_time`, `all_day`, `event_url`,
  `venue_address`; die nach Version 1 ergänzten Spalten stehen in migrierten Tabellen hinten, 02-datenbank.md).
- **Werte:** genau wie gespeichert, keine Exportformatierung (`18:00:00`, nicht „18:00 Uhr“; `2026-09-24`).
  - `NULL` erscheint als **„NULL“** (kursiv, grau), ein leerer String als `""`
  - `all_day` als DB-Wert mit Klartext: „0 (Nein)“ / „1 (Ja)“
  - `description`, `external_id`, `event_key`, `content_hash` vollständig, nichts gekürzt; lange Werte brechen um,
    der Inhaltsbereich scrollt; technische Felder in Monospace
  - `event_url` / `source_url`: voller Wert als Link (`target="_blank"`, `rel="noopener noreferrer"`), **nur** bei
    `http://`/`https://`; andere Werte (z. B. `javascript:…`) nur als Text
- **Bedienung:** Schließen per X, Button „Schließen“, Escape und Klick auf den abgedunkelten Hintergrund; Fokus bleibt
  im Dialog und springt danach zurück auf „Details“. **„Alle Werte kopieren“** kopiert `name: wert` je Zeile (NULL als
  `NULL`).
- **Fehler:** „Event konnte nicht geladen werden.“ plus kurzer Grund (Event existiert nicht (mehr) / keine Berechtigung
  oder Sitzung abgelaufen / ungültige Antwort / Netzwerkfehler); keine PHP-Meldungen im UI.
- **XSS:** Werte werden nur per `textContent` bzw. als geprüfter Link in das DOM geschrieben, nie als HTML.
- Code: `assets/eventcrawler-admin.js` (zweiter Abschnitt, Vanilla JS) und `assets/eventcrawler-admin.css` (nur
  Layout), beide nur auf der Seite „Importierte Veranstaltungen“.

Getestet (2026-10-06): Endpunkt per HTTP (Admin 200 mit allen Spalten = DB-Werte, nicht angemeldet 400, Abonnent 403,
falscher/fehlender/Crawler-Nonce 403, GET 405, fehlende/ungültige ID inkl. `0`, `-5`, `12abc`, `1.5`, Array,
`1 OR 1=1`, `…; DROP TABLE …`, `UNION SELECT …` → 400, unbekannte ID → 404); im echten Browser (Edge headless) mit
einem Testdatensatz voller `<script>`, `<img onerror>`, `<svg onload>`, `javascript:`-URL und 6 017 Zeichen
Beschreibung: nichts ausgeführt, kein Tag im Modal-DOM, Beschreibung vollständig, NULL sichtbar, Link nur für
`https://`, Schließen auf allen vier Wegen, Kopieren, Fehlermeldungen 404/403, Crawler-Steuerung unverändert.
