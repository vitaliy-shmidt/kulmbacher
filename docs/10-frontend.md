# 10 – WPEM-Ersatz: Frontend und manuelle Veranstaltungen

> **Stand 2026-10-28.** Ziel: Der Eventcrawler ersetzt das Kundenplugin „WP Kulmbacher Land“ (Ordner
> `wp-events-manager`, intern „WPEM“) schrittweise, damit es später deaktiviert werden kann. Die Tabelle
> `{prefix}eventcrawler_events` bleibt die **einzige** Datenquelle. Es gibt **keine** Synchronisation
> Eventcrawler → WPEM und keine doppelte Datenhaltung in `wp_posts`/`wp_postmeta`.
>
> Abschnitte 1–7 sind die Analyse (Istzustand WPEM), ab Abschnitt 8 Zielarchitektur, Umsetzung und Abschaltplan.

## 1. Grundlage der Analyse

| Quelle | Inhalt | Aussagekraft |
|---|---|---|
| Code `C:\xampp\htdocs\kulmbacher\wp-events-manager` (Version 1.3.1, 8 Klassen, 4127 Zeilen) | vollständig gelesen | technisch vollständig |
| Echte Exportdatei des Kunden `veranstaltungen-export-2026-09-30.xml` (Projektordner, nicht im Git) | WPEM-XML-Export, Zeitraum 01.–30.09.2026 | einziger Beleg für die **Daten** beim Kunden |
| Lokale Testinstallation (WP 7.1.4, Datenbank `eventcrawler_wptest`, Präfix `ectest_`) | WPEM mit 5 Testterminen aktiviert, URLs/Sitemap/Canonical gemessen | Verhalten des Codes, **nicht** die Kundenseite |

**Nicht verfügbar (lokal nicht belegbar):** die Kunden-WordPress-Installation (Seiten, Theme, Widgets, Menüs,
Datenbank). Unter `C:\xampp\htdocs` gibt es keine Kundeninstallation; die Testdatenbank enthält keine WPEM-Daten des
Kunden. Welche Seite welchen Shortcode nutzt, ist deshalb **nicht** geprüft (Entscheidungspunkt E1, Abschnitt 13).

**Befund Assets:** Das Plugin lädt `assets/css/frontend.css`, `assets/js/frontend.js`, `assets/js/admin.js`, den
Timepicker und `js.cookie` – der Referenzordner enthält **keinen** `assets/`-Ordner (in der Testinstallation 404/301).
Tagesnavigation, Accordion, Kalender-Modal, Live-Suche und „Mehr laden“ funktionieren ohne `frontend.js` nicht. Das
Verhalten wurde deshalb aus PHP-Code und HTML-Struktur abgeleitet, nicht aus einem laufenden Frontend.

## 2. Inventar WPEM

| Funktion | Datei/Klasse | Hook/Shortcode | Frontend/Admin | vermutlich genutzt | Bemerkung |
|---|---|---|---|---|---|
| CPT `wpem_event` | `class-wpem-post-type.php` / `WPEM_Post_Type` | `init` → `register_post_type` | beides | **ja** (Datenhaltung) | public, `has_archive`, Slug `veranstaltung`, `show_in_rest`, supports title+editor, Menü „Veranstaltungen“ |
| Taxonomie `wpem_venue` | `class-wpem-venues.php` / `WPEM_Venues` | `init` → `register_taxonomy` | beides | **ja** (Ort/Stadt) | public, nicht hierarchisch, Slug `veranstaltungsort`, `show_in_rest` |
| Venue-Term-Meta | `WPEM_Venues` | `created_/edited_wpem_venue` | Admin | ja | `wpem_venue_address` (Textarea), `wpem_venue_city`, `wpem_venue_zip`; Spalte „Stadt“ |
| Event-Meta / Metabox „Veranstaltungsdetails“ | `class-wpem-meta-boxes.php` / `WPEM_Meta_Boxes` | `add_meta_boxes`, `save_post_wpem_event` | Admin | **ja** | Felder Abschnitt 3; Zeitvalidierung (Ende vor Beginn → Entwurf + Fehlermeldung) |
| Ort inline anlegen | `WPEM_Meta_Boxes::ajax_add_venue` | AJAX `wpem_add_venue` | Admin | vermutlich | Name, Stadt, PLZ, Adresse |
| Admin-Spalten, Sortierung | `class-wpem-admin.php` / `WPEM_Admin` | `manage_wpem_event_posts_columns`, `pre_get_posts` | Admin | ja | Beginn, Ende, Ort, Ganztägig; Standard-Sortierung `_wpem_sort_date` |
| Admin-Filter Datum von/bis, Titel, Ort | `WPEM_Admin` | `restrict_manage_posts`, `parse_query` | Admin | wahrscheinlich | Cookie-Speicherung über fehlendes `admin.js` |
| Duplizieren | `WPEM_Admin` | `post_row_actions`, `admin_action_wpem_duplicate_event` | Admin | wahrscheinlich | Kopie als Entwurf, Meta + Ort |
| Seite „Ex-Im-Port“ | `class-wpem-export.php` / `WPEM_Export` | `admin_menu`, `admin_init` | Admin | **ja** (XML für InDesign belegt) | CSV-/XML-Export mit Filtern; Massenlöschung nach Monat/Jahr |
| XML-Export | `WPEM_Export::export_xml` | POST `wpem_export` | Admin | **ja** | Format-Referenz, docs/09 und 07-export.md Abschnitt 11 |
| CSV-Export | `WPEM_Export::export_csv` | POST `wpem_export` | Admin | unbekannt | Semikolon, BOM |
| CSV-/XML-Import | `class-wpem-import.php` / `WPEM_Import` | `admin_init` | Admin | **wahrscheinlich** (Abschnitt 4) | Monat/Jahr-Felder; legt Orte nach Stadtnamen an |
| Shortcode `[events_list]` | `class-wpem-frontend.php` / `WPEM_Frontend` | `add_shortcode` | Frontend | unbekannt (E1) | Tagesansicht, Abschnitt 5 |
| Shortcode `[events_calendar]` | `WPEM_Frontend` | `add_shortcode` | Frontend | unbekannt (E1) | Monatsraster |
| Kalender-Modal | `WPEM_Frontend::render_calendar_modal_html` | `wp_footer` | Frontend | unbekannt | wird auf **jeder** Seite ausgegeben (Script immer eingebunden) |
| Frontend-AJAX | `class-wpem-ajax.php` / `WPEM_Ajax` | `wp_ajax(_nopriv)_wpem_get_event_details`, `…_get_calendar`, `…_get_day_events`, `…_get_events_list`, `…_search_events`, `…_load_more_events` | Frontend | nur mit `frontend.js` | Nonce `wpem_nonce` |
| Single-Event-Seite | WordPress-Core (kein Template im Plugin) | Rewrite `veranstaltung/{slug}` | Frontend | **unbekannt** | zeigt nur Titel + Inhalt, **kein** Datum/Ort/Link (gemessen) |
| Event-Archiv | Core | `veranstaltung/` | Frontend | unbekannt | Theme-Archiv |
| Venue-Archiv | Core | `veranstaltungsort/{slug}` | Frontend | unbekannt | Theme-Archiv |
| REST | Core | `/wp-json/wp/v2/wpem_event`, `…/wpem_venue` | – | unbekannt | Meta nicht registriert, also ohne Termindaten |
| Feed | Core | `/veranstaltung/feed/` | – | unbekannt | |
| Assets | Hauptdatei | `wp_enqueue_scripts`, `admin_enqueue_scripts` | beides | – | **fehlen im Referenzordner**; Admin lädt jQuery-UI-CSS von code.jquery.com |
| Update-Sperre, Hinweis | Hauptdatei | `site_transient_update_plugins`, `auto_update_plugin`, `admin_notices` | Admin | – | nicht fachlich |
| Widgets, Blöcke, Templates, eigene Rewrite-Regeln, `post_type_link` | – | – | – | – | **nicht vorhanden** |

## 3. Datenmodell WPEM

| Element | Schlüssel | Format | Bemerkung |
|---|---|---|---|
| Titel | `post_title` | Text | |
| Beschreibung | `post_content` | HTML (Editor) | Export: `wp_strip_all_tags()` |
| Status | `post_status` | publish/draft/… | Frontend und Export nur `publish` |
| Beginn – Datum | `_wpem_start_date` | **`TT.MM.JJJJ`** (Text) | Pflichtfeld im Formular |
| Beginn – Uhrzeit | `_wpem_start_time` | `HH:MM` oder leer | |
| Ende – Datum | `_wpem_end_date` | `TT.MM.JJJJ` oder leer | |
| Ende – Uhrzeit | `_wpem_end_time` | `HH:MM` oder leer | |
| Ganztägig | `_wpem_all_day` | `'1'`/`'0'` | Import setzt `'1'`, wenn die Zeile nicht mit `HH:MM Uhr` beginnt |
| Webseiten-Link | `_wpem_website_url` | URL (`esc_url_raw`) | |
| Sortierdatum | `_wpem_sort_date` | `JJJJ-MM-TT[ HH:MM:00]` | abgeleitet, Grundlage aller Abfragen |
| Ort | Taxonomie `wpem_venue` (erster Term) | Termname | |
| Ort – Adresse | Term-Meta `wpem_venue_address` | mehrzeiliger Text | |
| Ort – Stadt | Term-Meta `wpem_venue_city` | Text | XML `<h2>` = Stadt, sonst Ortsname, sonst „Ohne Ort“ |
| Ort – PLZ | Term-Meta `wpem_venue_zip` | Text | Eventcrawler hat kein PLZ-Feld |

Die bisher bekannte Liste (CPT, Taxonomie, 7 Meta-Keys, 3 Term-Meta) ist damit **vollständig**; weitere Meta-Keys
gibt es im Code nicht. Zusätzlich nur `wpem_manual_update_notice_dismissed` (User-Meta, nicht fachlich).

## 4. Datenlage beim Kunden (aus der echten Exportdatei)

Die Datei enthält nur veröffentlichte Termine (`publish`) im exportierten Zeitraum. Mengen außerhalb des Zeitraums,
Entwürfe, Orte ohne Termine und Termine ohne Ort mit Stadt sind daraus **nicht** ableitbar.

| Kennzahl (01.–30.09.2026) | Wert |
|---|---|
| Tage (`<h1>`) | 30 |
| Ort-Gruppen (`<h2>`) | 380 (rund 60 verschiedene Städte, u. a. Kulmbach 25, Lichtenfels 23, Bad Staffelstein 23, Bad Steben 22, Bayreuth 19) |
| Termine (`<p>`) | **596** |
| Uhrzeit aus dem Zeitfeld (`HH:MM Uhr …`) | 369 |
| Uhrzeit nur im Titeltext (`14.30 Uhr …`, also ganztägig/ohne Zeitfeld gespeichert) | 155 |
| ohne Uhrzeit | 69 (plus 3 mit „bis“-Zeitspannen im Text) |
| Website im Feld (`Info: https://…`) | 18 |
| Website nur im Text (`Info: www…`) | 31 |
| Ort „Online“ | 4 |

**Bewertung:** Die Termine sind **redaktionell gepflegte Inhalte** (eigene Formulierungen, gemischte Zeitformate,
Telefonnummern, Treffpunkte) aus rund 60 Orten, viele davon ohne Crawler-Quelle (Lichtenfels, Bad Staffelstein,
Limmersdorf, Wirsberg …). Ob sie einzeln im Editor angelegt oder über den XML-/CSV-Import eingespielt wurden, ist nicht
sicher belegbar; die 155 Titel mit Uhrzeit im Text und `Info: www…` im Text passen zum XML-Import (der nur `HH:MM Uhr`
und `https?://` erkennt). **Ergebnis: Manuelle Veranstaltungen werden weiterhin gebraucht** – der Eventcrawler muss sie
selbst verwalten können. Teilweise überschneiden sie sich mit Crawler-Quellen (z. B. Concertbüro-Konzerte mit
derselben Detail-URL).

## 5. Frontend-Nutzung WPEM

Ohne Kundeninstallation ist nur belegt, **was** das Plugin anbieten würde, nicht **wo** es eingebunden ist (E1).

| Funktion | URL / Einbindung | HTML-Struktur / CSS-Klassen | Daten |
|---|---|---|---|
| Tagesliste | Seite mit `[events_list]`, Tag per `?date=JJJJ-MM-TT` | `.wpem-events-container > .wpem-day-navigation` (Vor/Zurück, Datum, Suchfeld, Kalender-Button) + `.wpem-events-list > .wpem-event-item` | nur Termine **eines** Tages (`_wpem_sort_date`), sortiert nach Sortierdatum; erste 10, Rest per „Mehr laden“ (AJAX) |
| Event-Zeile + Accordion | in der Tagesliste | `.wpem-event-header` (Datum, Uhrzeit/„Ganztägig“, Titel `h3`, Ort + Stadt), `.wpem-event-details` (Beschreibung, Ort/Adresse/PLZ Stadt, „Zur Webseite“), „Bearbeiten“ für Redakteure | alle Felder |
| Suche | Suchfeld in der Tagesnavigation, AJAX `wpem_search_events` | `.wpem-search-results` | WordPress-Suche (Titel/Inhalt) + Ortsname, max. 20, **alle** Zeiträume |
| Kalender | `[events_calendar]` oder Modal im Footer | `.wpem-calendar` (`-header`, `-weekdays`, `-days`, `.wpem-calendar-day.wpem-has-events`), max. 2 Titel je Tag + „+n weitere“ | Monat per AJAX; Klick auf Tag → Tagesliste |
| Detailseite | `/veranstaltung/{slug}/` | Theme-Template | **nur Titel + Beschreibung** (gemessen) |
| Archive | `/veranstaltung/`, `/veranstaltungsort/{slug}/` | Theme-Archiv | Titel/Auszug |
| Filter Monat/Stadt/Kategorie | – | – | **nicht vorhanden** (nur Shortcode-Attribut `venue`) |
| Vergangene Termine | über die Tagesnavigation erreichbar; Attribut `show_past` wird im Code **nicht** ausgewertet | | |
| Mobile | nur über das fehlende `frontend.css` | | nicht prüfbar |

### Shortcodes

| Shortcode | Parameter | Ausgabe | Datenquelle | im Projekt verwendet |
|---|---|---|---|---|
| `[events_list]` | `limit` (20, Termine je Tag), `show_past` (ohne Wirkung), `venue` (Venue-Slug), `date` (`JJJJ-MM-TT`, sonst `?date=`, sonst heute) | Tagesansicht | `wpem_event` + Meta + `wpem_venue` | **unbekannt** (E1) |
| `[events_calendar]` | `month`, `year` | Monatskalender | `wpem_event` | **unbekannt** (E1) |

## 6. URLs und SEO (gemessen in der Testinstallation, Permalinks `/%postname%/`)

| URL | Status | Sitemap (`wp-sitemap.xml`) | Canonical / robots |
|---|---|---|---|
| `/veranstaltung/{slug}/` (Detail) | 200 | **ja** (`wp-sitemap-posts-wpem_event-1.xml`) | Canonical auf sich selbst, kein `noindex` |
| `/veranstaltung/` (Archiv) | 200 | – | |
| `/veranstaltungsort/{slug}/` (Ort) | 200 | **ja** (`wp-sitemap-taxonomies-wpem_venue-1.xml`) | kein `noindex` |
| Seite mit `[events_list]`, `?date=…` | 200 | als Seite | |
| `/wp-json/wp/v2/wpem_event` | 200 | – | |

- Detailseiten und Ortsseiten sind also **öffentlich und indexierbar**, sobald die Website Suchmaschinen zulässt.
  Inhaltlich sind sie dünn (kein Datum, kein Ort). Ob sie beim Kunden indexiert oder verlinkt sind, ist lokal nicht
  belegbar (Search Console, E2).
- Nach dem Deaktivieren von WPEM liefern `/veranstaltung/…` und `/veranstaltungsort/…` **404**; die Posts bleiben in
  `wp_posts` (Deaktivieren löscht nichts).
- Die Tagesliste nutzt `?date=JJJJ-MM-TT`. Diesen Parameter übernimmt der Eventcrawler unverändert, damit Links auf
  Tagesansichten weiter funktionieren.

## 7. Funktionsmatrix

| WPEM-Funktion | aktuell genutzt | muss ersetzt werden | Eventcrawler vorhanden | neu zu bauen |
|---|---|---|---|---|
| Event-Liste (Tagesansicht) | unbekannt (E1) | ja, falls genutzt | nein | **ja** – `[eventcrawler_events]`, Alias `[events_list]` |
| Kalender | unbekannt (E1) | ja, falls genutzt | nein | **ja** – `[eventcrawler_calendar]`, Alias `[events_calendar]` |
| Event-Detail (Accordion) | unbekannt | ja | Admin-Modal (nur Admin) | **ja** – aufklappbarer Eintrag |
| Event-Detailseite (eigene URL) | unbekannt (E2) | nur als Redirect | nein | nein (Abschnitt 11) |
| Venue | ja (Daten) | Daten ja, Taxonomie nein | `venue_name`, `venue_address`, `city` | nein |
| Datum / Uhrzeit / Ende / All-Day | ja | ja | ja (Schema 2) | nein |
| Website-Link | ja | ja | `event_url` | nein |
| Suche | unbekannt | ja, falls Liste genutzt | Admin-Suche | **ja** (serverseitig) |
| Filter (Stadt, Datum) | nicht vorhanden | nein | Admin-Filter | **ja**, einfach (Verbesserung) |
| Pagination („Mehr laden“) | unbekannt | ja | Admin | **ja** (serverseitig) |
| Archiv / Venue-Archiv | unbekannt (E2) | nur als Redirect | nein | Redirect in Phase F |
| Manuelle Events anlegen/bearbeiten/löschen | **ja** | **ja** | Löschen ja | **ja** – `source = manual` |
| Duplizieren | wahrscheinlich | ja (klein) | nein | **ja** |
| Admin-Liste mit Filtern | ja | ja | ja | nur Quelle „Manuell“ |
| XML-Export | **ja** | ja | ja (Format unverändert) | nein |
| CSV-Export | unbekannt (E4) | offen | nein | nein |
| CSV-/XML-Import | wahrscheinlich (E3) | offen | nein | nein |
| Massenlöschung nach Monat | unbekannt | nein | Filter + Bulk-Löschen | nein |
| öffentliche URL / SEO / Rewrite | unbekannt (E2) | Redirects | nein | Phase F |
| mobile Ansicht | – | ja | – | **ja** (CSS) |
| REST, Feed | unbekannt | nein | nein | nein |

## 8. Zielarchitektur

```
Externe Quellen ──> Crawler ──┐
Redaktion (Admin-Formular) ───┼──> {prefix}eventcrawler_events ──> Frontend (Shortcodes)
WPEM-Bestand (einmalig) ──────┘                                 ├─> XML-Export (unverändert)
                                                                └─> Admin-Liste
```

- Manuelle Termine sind normale Zeilen der Tabelle mit `source = 'manual'` – **keine Schemaänderung**,
  `EVENTCRAWLER_DB_VERSION` bleibt 3. Sie durchlaufen dieselben Normalisierungsregeln (02-datenbank.md Abschnitt 2:
  NULL statt Ersatzwerten, nie künstlich 00:00, Endzeit ohne Enddatum = gleicher Tag), dieselbe `event_key`-/
  `content_hash`-Berechnung, denselben Export und dasselbe Frontend.
- Der Crawler fasst `manual` nie an (kein Adapter mit diesem Slug; Matching nur innerhalb der eigenen Quelle).
- WPEM wird **nicht** synchronisiert. Der Bestand wird einmalig und idempotent übernommen (Abschnitt 10).

## 9. Umsetzungsplan (Phasen)

| Phase | Inhalt | Stand |
|---|---|---|
| A | Frontend-Liste `[eventcrawler_events]` (Liste ab Datum und Tagesansicht), Suche, Stadtfilter, Datum, Pagination, aufklappbare Details | siehe Abschnitt 12 |
| B | Kalender `[eventcrawler_calendar]` | siehe Abschnitt 12 |
| C | Alias-Shortcodes `[events_list]`/`[events_calendar]` (nur wenn WPEM inaktiv ist) | siehe Abschnitt 12 |
| D | Manuelle Termine im Admin (anlegen, bearbeiten, duplizieren, löschen) | siehe Abschnitt 12 |
| E | Einmalige WPEM-Übernahme mit Probelauf (idempotent, ohne Dubletten zu Crawler-Terminen) | siehe Abschnitt 12 |
| F | Redirects alter WPEM-URLs, Paralleltest auf dem Kundensystem, Deaktivierung | offen (Abschnitt 11, 14) |

## 10. Datenmigration WPEM → Eventcrawler

**Nötig: ja** – der WPEM-Bestand enthält redaktionelle Termine, die keine Crawler-Quelle liefert (Abschnitt 4).
Gelöscht wird in WPEM nichts.

| WPEM | → Eventcrawler | Regel |
|---|---|---|
| Post-ID | `external_id = 'wpem-{ID}'`, `source = 'manual'` | UNIQUE `(source, external_id)` → **Idempotenz**: bereits übernommene IDs werden übersprungen |
| `post_status` | – | nur `publish` (wie Frontend und Export von WPEM); andere Status werden gezählt, nicht übernommen |
| `post_title` | `title` | Entities dekodiert, Klartext, max. 255 |
| `post_content` | `description` | `wp_strip_all_tags()` wie im WPEM-Export, Entities dekodiert; leer → NULL |
| `_wpem_start_date` `TT.MM.JJJJ` | `start_date` `JJJJ-MM-TT` | gültiges Kalenderdatum, sonst **übersprungen** (im Probelauf gemeldet) |
| `_wpem_start_time` `HH:MM` | `start_time` | leer → NULL; ungültig → übersprungen |
| `_wpem_end_date`, `_wpem_end_time` | `end_date`, `end_time` | wie Crawler-Normalisierung; Ende nicht nach Beginn → Ende verworfen (Hinweis) |
| `_wpem_all_day` `'1'` | `all_day = 1`, Uhrzeiten NULL | unverändert übernommen, auch wenn die Uhrzeit im Titel steht (keine Textauswertung, A66) |
| `_wpem_website_url` | `event_url` | nur http/https, sonst NULL (Hinweis) |
| erster `wpem_venue`-Term (nach Name, wie WPEM) | `venue_name` | NULL, wenn identisch mit der Stadt (XML-Import von WPEM legt Orte mit Stadtnamen an) |
| `wpem_venue_address` | `venue_address` | Zeilenumbrüche → „, “ |
| `wpem_venue_city`, sonst Termname | `city` | dieselbe Regel wie das `<h2>` im WPEM-Export; **ohne Ort → übersprungen** (WPEM-Export: „Ohne Ort“) |
| `wpem_venue_zip` | – | kein Feld im Schema (Entscheidungspunkt E6) |
| `_wpem_sort_date` | – | wird nicht gebraucht |
| – | `source_url = NULL` | sonst erschiene die alte WPEM-URL im XML als „Info:“ (Exporter-Fallback) |

**Dubletten zu Crawler-Terminen** (nur deterministisch, nie unscharf): Ein WPEM-Termin wird **nicht** übernommen, wenn
es in einer Crawler-Quelle einen Termin am selben `start_date` gibt mit

1. derselben `event_url` (exakter Vergleich nach Kleinschreibung von Schema/Host, ohne `#Fragment`), **oder**
2. demselben Titel **und** derselben Stadt (Kleinschreibung, Leerzeichen zusammengefasst).

Der Probelauf listet diese Termine mit der gefundenen Crawler-ID. Ähnliche, aber nicht identische Titel werden bewusst
nicht zusammengeführt (A66, A84).

## 11. URL-Strategie

- **Keine eigene Detail-URL** je Eventcrawler-Termin in dieser Phase. WPEM hat funktional ebenfalls keine
  Detailseite (Accordion; die CPT-Seite zeigt weder Datum noch Ort). Eigene, indexierbare Seiten für gecrawlte
  Fremdtexte wären außerdem rechtlich ungeklärt (todo.md: Freigabe der Texte) und Duplicate Content der Quellen.
  Details werden direkt in der Liste aufgeklappt; jeder Eintrag hat einen Anker `#eventcrawler-event-{id}`.
- **`?date=JJJJ-MM-TT`** bleibt wie bei WPEM der Parameter für einen Tag; Links auf Tagesansichten bleiben gültig, wenn
  die Seite denselben Pfad behält.
- **Alte WPEM-URLs (Phase F, beim Deaktivieren):** 301-Weiterleitungen, nur wenn WPEM inaktiv ist:
  - `/veranstaltung/{slug}/` → Listenseite `?date={start_date}#eventcrawler-event-{id}` (Zuordnung über `wp_posts.post_name`
    → Post-ID → `external_id = 'wpem-{ID}'`; die WPEM-Posts bleiben nach dem Deaktivieren in der Datenbank)
  - `/veranstaltung/` → Listenseite; `/veranstaltungsort/{slug}/` → Listenseite `?ec_city={Stadt}`
  - Ob das nötig ist, hängt von E2 ab (sind die URLs indexiert/verlinkt?).

## 13. Offene Entscheidungen

| # | Frage | Warum relevant |
|---|---|---|
| E1 | Auf welchen Seiten der Kundenwebsite stehen `[events_list]` / `[events_calendar]` (oder werden sie im Theme per `do_shortcode` genutzt)? | Ohne Zugriff auf die Kundeninstallation nicht prüfbar. Die Alias-Shortcodes decken beide Fälle ab, der Paralleltest muss auf dem Kundensystem laufen |
| E2 | Sind `/veranstaltung/{slug}/` und `/veranstaltungsort/{slug}/` indexiert oder extern verlinkt (Search Console, Backlinks)? | entscheidet, ob die Redirects aus Abschnitt 11 gebaut werden |
| E3 | Nutzt die Redaktion den CSV-/XML-**Import** von WPEM? Woher kommen die Dateien? | Falls ja, braucht der Eventcrawler vor der Abschaltung eine Importfunktion (nicht gebaut) |
| E4 | Wird der CSV-Export genutzt? | nicht gebaut |
| E5 | Sollen öffentlich **alle** Quellen erscheinen oder nur manuelle Termine (bisheriger WPEM-Inhalt) bzw. eine Auswahl? | Shortcode-Attribut `sources` ist vorhanden; Standard: alle |
| E6 | Wird die PLZ der Orte gebraucht? | kein Feld im Schema; bräuchte Schema-Version 4 |
| E7 | Sollen auch vergangene WPEM-Termine übernommen werden? | Standard der Übernahme: alle veröffentlichten |
| E8 | Manuelle Termine erscheinen im XML im Eventcrawler-Zeilenformat (`Titel, Beschreibung. Info: domain`), nicht im WPEM-Format (`Titel: Beschreibung Info: https://…`). Für Katharina akzeptabel? | XML-Format wird nicht eigenmächtig geändert (A8) |
| E9 | Beschreibungen gecrawlter Termine im öffentlichen Frontend zeigen? | Standard: nur bei manuellen Terminen (Freigabe der Fremdtexte offen) |

## 14. Abschaltplan WPEM

WPEM bleibt aktiv, bis alle Punkte erledigt sind:

1. Eventcrawler-Version mit Frontend, manuellen Terminen und Übernahme auf das Kundensystem (Staging) bringen.
2. E1 klären: Seiten mit WPEM-Shortcodes auflisten; dort parallel eine Testseite mit `[eventcrawler_events]` anlegen.
3. Probelauf der Übernahme ansehen (Eventcrawler → WPEM-Übernahme), Problemfälle in WPEM korrigieren, dann übernehmen.
4. Paralleltest: gleiche Tage in alter und neuer Ansicht vergleichen (Termine, Datum, Uhrzeit, Ort, Adresse, Link,
   Smartphone).
5. Redaktion legt neue Termine nur noch im Eventcrawler an (Stichtag festlegen); WPEM nur noch lesen.
6. E2/E3 entschieden, ggf. Redirects (Abschnitt 11) bzw. Import gebaut.
7. WPEM deaktivieren (nicht löschen): Seiten mit `[events_list]`/`[events_calendar]` laufen über die Aliase weiter.
   URLs prüfen, XML-Export prüfen.
8. Nach einer Beobachtungszeit: WPEM-Daten sichern, Plugin löschen (die WPEM-Posts bleiben für Redirects in der
   Datenbank, solange Redirects gebraucht werden).
