# 12 – SEO-Detailseiten

> **Stand 2026-11-03: implementiert und getestet** (`tools/test-seo.php`, 101 Tests inkl. echter HTTP-Aufrufe).
> Code: `wp-plugin/includes/class-eventcrawler-seo.php`, `class-eventcrawler-sitemap-provider.php`,
> `wp-plugin/templates/eventcrawler-event.php`; Slug: `Eventcrawler_Repository::assign_slug()`/`slug_base()`,
> Schema-Version 4 (02-datenbank.md Abschnitt 12). Entscheidungen A106–A110 in [01-konzept.md](01-konzept.md).
> **Standardmäßig ausgeschaltet** („Eventcrawler → Einstellungen → SEO“).

## 1. Ziel und Grenzen

Jeder Termin kann eine eigene, indexierbare Seite bekommen – **parallel** zu WP Kulmbacher Land (WPEM), ohne dessen
Seiten zu ersetzen. Ersetzt A101 („keine eigene Detail-URL“).

- Inhalt konservativ (Urheberrecht): H1-Titel, Datum, Uhrzeit (ggf. Ende), Ort, Adresse, Stadt, Original-Link
  „Zur Veranstaltungsseite“ (nur http/https, `target="_blank" rel="noopener noreferrer"`). Beschreibung nur, wenn
  sie öffentlich freigegeben ist – dieselbe Regel wie in der Liste (A100: Standard nur manuelle Termine, Filter
  `eventcrawler_frontend_show_description`). Keine Bilder/Logos, keine technischen Felder.
- Nur vorhandene Daten: keine erfundenen Texte, kein Status (ABGESAGT/AUSVERKAUFT wird nicht ausgewertet), keine
  Ticket-/Offer-Daten, keine 00:00 ohne Uhrzeit.
- Kein Cache (eine Abfrage je Seite über den Index `slug`).

## 2. URL und Parallelbetrieb (A106)

| Zustand | Detail-URL |
|---|---|
| Detailseiten aus (Standard) | keine; `/eventcrawler-veranstaltung/…` ist eine normale 404; Listen-Ausgabe unverändert |
| an, WPEM aktiv (Parallelbetrieb) | `/eventcrawler-veranstaltung/{slug}/` |
| an, WPEM deaktiviert | Basis frei wählbar, z. B. später `veranstaltung` |

- **WPEM belegt `/veranstaltung/{slug}/`** (CPT-Rewrite) und `/veranstaltungsort/…`. Solange WPEM aktiv ist, lehnt die
  Einstellung diese Basen ab; außerdem jede Basis, unter der schon eine Seite/ein Beitrag liegt
  (`Eventcrawler_Settings::base_conflict()`).
- Temporäre Basis: **`eventcrawler-veranstaltung`** (eindeutig, kollidiert mit keinem WordPress- oder WPEM-Pfad,
  sprechend). Rewrite-Regel `^{basis}/([a-z0-9-]+)/?$` → `index.php?eventcrawler_event=$1`, Query-Variable nur bei
  eingeschalteten Detailseiten.
- Rewrite-Regeln werden nur bei einer Änderung neu erzeugt: Option `eventcrawler_rewrite_state` (`off` bzw.
  `on:{basis}`) wird bei `init` mit dem Sollzustand verglichen; Speichern der Einstellungen löscht sie bei Änderung von
  Schalter/Basis. Deaktivieren des Plugins löscht `rewrite_rules` (WordPress erzeugt sie ohne Eventcrawler neu).
- **Keine** Weiterleitungen von WPEM-URLs, **keine** Canonicals auf WPEM-Seiten, keine Änderung an WPEM (getestet:
  WPEM-Detailseite 200, eigener Canonical, ohne Eventcrawler-Inhalte).

## 3. Slug (Schema-Version 4, A110)

**Prüfung des bestehenden Schemas (Schema 3):** kein Feld für einen dauerhaften Slug. Geprüfte Alternativen:

| Variante | Stabil bei Titeländerung | ID im sichtbaren Slug | Schemaänderung | Bewertung |
|---|---|---|---|---|
| Slug bei jedem Aufruf aus Titel + Datum berechnen | **nein** (alte URL → 404) | nein | nein | verworfen |
| `/{titel-datum}-{id}/`, Auflösung über die ID, Weiterleitung bei geändertem Titel | URL ändert sich (301) | **immer** | nein | möglich, aber jede URL trägt eine ID |
| Option/Tabelle Slug → ID | ja | nein | zusätzliche Datenhaltung | widerspricht A7 (eine Tabelle) |
| **Spalte `slug` (UNIQUE), beim Anlegen vergeben, nie geändert** | **ja** | nur bei Kollision | additive Spalte | **gewählt** |

Regeln:

- `slug_base` = Titel klein, `ä→ae, ö→oe, ü→ue, ß→ss`, Akzente entfernt, sonstige Zeichen → `-`, max. 80 Zeichen
  (an Wortgrenze), + `-JJJJ-MM-TT` des Beginns. Beispiel: „Ninotschka“, 24.10.2026 → `ninotschka-2026-10-24`.
  Unabhängig von der Sprache der Installation.
- Kollision (gleicher Titel am selben Tag, z. B. zwei Vorstellungen): der ältere Datensatz behält `…-2026-10-24`, der
  neue bekommt die **ID** als stabilen Zusatz (`…-2026-10-24-4711`). Testdaten: 194 von 3227 Terminen.
- Vergabe in `Eventcrawler_Repository::insert()` für **alle** Wege (Crawler, manuell, Import, WPEM-Übernahme), ohne
  Änderung am Crawler. Danach nie geändert: Titel- oder Datumsänderung durch Crawler/Import/Formular lässt die URL
  gleich. `slug` ist nicht Teil von `content_hash` und nicht im XML-Export.
- Gelöschter und neu angelegter Termin bekommt einen neuen Slug (wie eine neue ID).

## 4. Seite, Title, Description, Canonical, Robots

- **Vorlage:** Block-Themes (z. B. Twenty Twenty-Five): Kopf/Fuß als Template-Parts, vor `wp_head` gerendert;
  klassische Themes (Betheme): `get_header()`/`get_footer()`. Inhalt `.eventcrawler.eventcrawler-detail` (CSS in
  `eventcrawler-frontend.css`, geprüft 1280 px und 375 px ohne horizontales Scrollen).
- **Request:** Die Hauptabfrage lädt keine Beiträge (`posts_pre_query`), ist keine Startseite (`parse_query`), der
  Termin wird genau einmal per Slug geladen. Gefunden → 200 (`pre_handle_404`); nicht gefunden oder ungültige Zeichen →
  **echte WordPress-404** mit 404-Vorlage. `redirect_canonical` greift auf Detail-URLs nicht.
- **Vergangene Termine:** weiter 200, Hinweis „Diese Veranstaltung hat bereits stattgefunden.“, Robots unverändert.
- **Title:** Muster aus den Einstellungen, Standard `{title} am {date} in {city}` („Ninotschka am 24.10.2026 in
  Kronach“). Platzhalter `{title} {date} {time} {venue} {city} {site}`; fehlt ein Wert, entfällt der Platzhalter samt
  dem Wort davor („im {venue}“). Ausgabe escaped (`pre_get_document_title`/`wp_title` geben sonst roh aus).
- **Meta-Description:** „{Titel} am {Datum}[ um {Uhrzeit}] in {Stadt}.[ Ort: {Ort}[, {Adresse}].]“, max. 160 Zeichen
  (Wortgrenze, „…“). Nichts Erfundenes; fehlende Teile entfallen.
- **Canonical:** genau ein Self-Canonical `/{basis}/{slug}/`, nie mit Query-Parametern.
- **Robots (`wp_robots`):** `index, follow` nur wenn indexierbar: Titel, Datum, Stadt (Pflichtfelder) **und Ort oder
  Adresse**. Sonst `noindex, follow` (Seite bleibt erreichbar). Eine nicht öffentliche Website („Suchmaschinen davon
  abhalten“) bleibt `noindex`.
- **SEO-Plugins:** Für Yoast (`wpseo_canonical`, `wpseo_title`, `wpseo_metadesc`) und Rank Math (`rank_math/frontend/…`)
  liefern Filter dieselben Werte auf der Detailseite. Ob beim Kunden ein SEO-Plugin aktiv ist, ist nicht bekannt
  (todo.md); mit aktivem Plugin kann die Description doppelt erscheinen.

## 5. Structured Data (A107)

`<script type="application/ld+json">` mit `schema.org/Event`, nur bei Einstellung „Event Structured Data ausgeben“
(Standard an, wirkt nur mit Detailseiten) und nur für indexierbare Termine:

| Feld | Quelle | Regel |
|---|---|---|
| `@context`, `@type` | – | `https://schema.org`, `Event` |
| `name` | `title` | |
| `startDate` | `start_date` + `start_time` | mit Uhrzeit: ISO 8601 mit Zeitzone der Website (`2026-10-24T20:00:00+02:00`); ohne Uhrzeit oder ganztägig: nur `2026-10-24` – keine erfundene 00:00 |
| `endDate` | `end_date` + `end_time` | nur wenn vorhanden, Regel wie `startDate` |
| `location` | `Place` mit `name` = `venue_name` (wenn vorhanden), `address` = `PostalAddress` (`streetAddress` = `venue_address` wenn vorhanden, `addressLocality` = `city`, `addressCountry` = `DE`) | |
| `description` | Beschreibung | nur wenn öffentlich freigegeben (A100), max. 500 Zeichen |
| `url` | Detailseite | Self-URL |

**Nicht** ausgegeben: `eventStatus` (keine deterministische Quelle; Status im Titel wird nicht ausgewertet), `offers`
(keine verlässlichen Ticketdaten), `image`, `organizer`, `performer`. JSON mit `JSON_HEX_TAG|JSON_HEX_AMP`, damit ein
`</script>` im Titel die Seite nicht bricht (getestet).

## 6. Indexierbarkeit

`Eventcrawler_Seo::indexable()` = `Eventcrawler_Repository::INDEXABLE_SQL`: Slug, Titel, Datum, Stadt und (`venue_name`
oder `venue_address`). Gilt für Robots, JSON-LD und Sitemap. In den Testdaten erfüllen alle 3227 Termine die Regel
(alle haben einen Ort); bei WPEM-Übernahmen ohne Ortsnamen (Ort = Stadt) kann es Ausnahmen geben.

## 7. Sitemap (A108)

- Eigener Anbieter der WordPress-Kern-Sitemap: `wp-sitemap-eventcrawler-1.xml` (im Index `wp-sitemap.xml`), nur wenn
  Detailseiten **und** „Event-Seiten in WordPress-Sitemap aufnehmen“ (Standard an) eingeschaltet sind und WordPress
  Sitemaps ausliefert (öffentliche Website, kein SEO-Plugin, das sie abschaltet).
- Inhalt: indexierbare Termine mit `start_date` ≥ heute − **„Vergangene Veranstaltungen in Sitemap“** (Standard
  **60 Tage**, 0–3650), also alle zukünftigen und die der letzten 60 Tage; `lastmod` = `updated_at` mit Zeitzone.
- Abfrage nur `slug, updated_at` (kein `SELECT *`) über den Index `start_date`, seitenweise mit dem Kern-Limit
  (2000 je Datei).
- Keine Listen- oder Filter-URLs; die Seite mit `[eventcrawler_events]` steht wie jede Seite ohne Parameter in der
  Seiten-Sitemap des Kerns.

## 8. Filter-URLs der Liste (gilt immer)

Seiten mit `[eventcrawler_events]`, `[eventcrawler_calendar]` (bzw. den übernommenen Aliasen) bekommen bei
`?date=`, `?ec_search=`, `?ec_city=`, `?ec_page=` oder `?ec_month=` **`noindex, follow`**; der Canonical des Kerns zeigt
auf die Seite ohne Parameter (getestet). Die Seite ohne Parameter bleibt indexierbar. Das ist unabhängig vom Schalter
„Detailseiten“, weil es nur Seiten mit Eventcrawler-Shortcodes betrifft.

## 9. Einstellungen „Eventcrawler → Einstellungen → SEO“

| Einstellung | Schlüssel in `eventcrawler_settings` | Standard |
|---|---|---|
| Event-Detailseiten aktivieren | `seo_detail_pages` | **aus** |
| Event Structured Data ausgeben | `seo_structured_data` | an |
| Event-Seiten in WordPress-Sitemap aufnehmen | `seo_sitemap` | an |
| URL-Basis | `seo_base` | `eventcrawler-veranstaltung` |
| Vergangene Veranstaltungen in Sitemap (Tage) | `seo_sitemap_past_days` | 60 |
| Seitentitel-Muster | `seo_title_pattern` | `{title} am {date} in {city}` (muss `{title}` enthalten) |

Mit eingeschalteten Detailseiten verlinkt der Titel jeder Karte in `[eventcrawler_events]` auf die Detailseite;
ausgeschaltet bleibt die Ausgabe wie bisher. Im Admin: Link „Detailseite“ in der Liste, SEO-Block im Detail-Modal
und im Formular manueller Termine – nur sachliche Hinweise (✓ Detailseite aktiv, ✓ Titel/Datum/Uhrzeit/Ort vorhanden,
⚠ Adresse fehlt, freigegeben/nicht freigegeben), keine Punktzahl.

## 10. Spätere Migration von WPEM-URLs (A109, nur vorbereitet)

Nicht aktiv. Wenn WPEM abgeschaltet ist und E2 (10-frontend.md) ergibt, dass `/veranstaltung/…` indexiert/verlinkt ist:

1. WPEM deaktivieren, Permalinks speichern (10-frontend.md Abschnitt 14).
2. Zuordnung WPEM-URL → Eventcrawler-Termin: `wp_posts.post_name` (Typ `wpem_event`) → Post-ID →
   `external_id = 'wpem-{ID}'` (`source = manual`, aus der Übernahme) → `slug`.
3. 301 von `/veranstaltung/{post_name}/` auf `/{basis}/{slug}/`; nur für übernommene Termine, sonst auf die Listenseite.
4. Optional danach URL-Basis auf `veranstaltung` umstellen (erlaubt, sobald WPEM inaktiv ist); dann zeigen die neuen
   Detailseiten direkt unter dem alten Pfad. Die bisherige Basis `eventcrawler-veranstaltung` müsste dann ebenfalls per
   301 weitergeleitet werden.

Bis dahin werden **keine** Redirects und keine Canonicals auf WPEM-Seiten gesetzt.

## 11. Tests

`tools/test-seo.php` (101 Tests). Teil 1 im Prozess (Rollback): Schema 4 und Idempotenz der Migration, Slug-Regeln,
Kollision mit ID-Zusatz, Slug bleibt bei Titel-/Datumsänderung, Slug nicht im Hash, Slugs für manuelle Termine,
eindeutig für alle Zeilen; URL nur mit Einstellung; Kartentitel verlinkt nur mit Einstellung; Title-Muster,
Description (mit/ohne Uhrzeit, Ort, ganztägig, Länge); JSON-LD (startDate mit/ohne Zeit, Zeitzone, endDate,
ganztägig, fehlende Adresse, Beschreibung nur manuell, keine eventStatus/offers/image, `</script>` maskiert);
Indexierbarkeit; vergangene Termine; Admin-Hinweise; Seiteninhalt (Escaping, javascript:-Link, Original-Link,
keine technischen Felder); Sitemap-Auswahl. Teil 2 per HTTP (Testtermine `dev-test`, danach gelöscht): Detailseite
200, H1, `<title>` escaped, genau ein Self-Canonical, `index, follow`, Description, JSON-LD, vergangener Termin 200 mit
Hinweis, fehlende Adresse, ohne Ort `noindex` ohne JSON-LD, XSS im Titel, unbekannter/ungültiger Slug 404, Sitemap-Index
und -Datei (Zukunft, 20 Tage alt drin, 200 Tage alt und ohne Ort nicht, keine Query-URLs), Listenseite ohne Parameter
ohne noindex, mit `ec_city/date/ec_search/ec_page/ec_month` `noindex, follow` und Canonical ohne Parameter, WPEM-
Detailseite unverändert, Detailseiten aus → 404, keine Sitemap, keine Links.

Zusätzlich per HTTP über das Einstellungsformular: Einschalten → Detailseite 200, Basis „veranstaltung“ bei aktivem
WPEM abgelehnt, Ausschalten → 404. Migration auf Schema 4 in der Testinstallation: 3227 Zeilen, alle Altdaten feldgenau
identisch (MD5 aller Spalten außer `slug`), 3227 eindeutige Slugs, 194 mit ID-Zusatz, ca. 1,8 s.
