# 02 – Datenbank

> **Stand 2026-10-02: Schema-Version 3, implementiert und getestet** (neues Feld `venue_address`, Abschnitt 11).
> Version 2 vom 2026-09-24.
> - Schema und Migration: `wp-plugin/includes/class-eventcrawler-installer.php`
> - Zugriffe: `wp-plugin/includes/class-eventcrawler-repository.php`
> - Matching und Update (Abschnitte 4–7): `wp-plugin/includes/class-eventcrawler-crawler.php`
>
> Getestet mit MariaDB 10.4 und dem Tabellenpräfix `ectest_`. Alle drei Matching-Prioritäten sind mit echten Daten
> geprüft: Priorität 2 (URL) mit Burg Rabenstein, Priorität 1 (external_id) mit Bad Steben live, Priorität 3
> (event_key) mit Bad-Steben-Daten in einem Testskript (03-crawler.md Abschnitt 12).
>
> Version 2 bildet die **fachlichen** Felder des vorhandenen manuellen Veranstaltungs-Plugins beim Kunden ab
> (Start, Ende, Ganztag, Veranstaltungsort, Webseiten-Link). Eine technische Kopplung gibt es nicht, siehe
> [08-kompatibilitaet.md](08-kompatibilitaet.md).

## 1. Grundsatz

- Genau **eine** Tabelle: `{$wpdb->prefix}eventcrawler_events` (bei Standardpräfix `wp_eventcrawler_events`).
  **Das Präfix nie fest als `wp_` voraussetzen.** Der Tabellenname kommt aus `Eventcrawler_Installer::table_name()`.
- Anlage, Migration und Aktualisierung über `Eventcrawler_Installer::upgrade()`:
  - im Aktivierungs-Hook (`activate`)
  - bei `plugins_loaded`, wenn die Option `eventcrawler_db_version` ungleich `EVENTCRAWLER_DB_VERSION` ist
    (`maybe_upgrade`). Die Migration läuft also auch bei **bereits aktiviertem** Plugin (getestet).
  - **Bei jeder Schema-Änderung `EVENTCRAWLER_DB_VERSION` in `eventcrawler.php` erhöhen**, bei Umbenennungen eine
    Migration ergänzen (Abschnitt 10) und dieses Dokument anpassen.
  - Aktuelle Schema-Version: **3** (vorher 2, davor 1)
- Alle Zugriffe über `$wpdb` mit `prepare()`, `insert()`, `update()` und `delete()`, gekapselt in `Eventcrawler_Repository`.
- Zeichensatz über `$wpdb->get_charset_collate()`, in der Regel `utf8mb4`.
- Datum und Uhrzeit der Veranstaltung (`start_*`, `end_*`) sind **lokale Zeit der Veranstaltung** (Europe/Berlin),
  ohne Umrechnung. `created_at`, `updated_at` und `last_seen_at` werden mit `current_time( 'mysql' )` gesetzt, also
  in der Ortszeit der WordPress-Installation.
- **Fehlende Werte sind `NULL`.** Es werden keine Ersatzwerte gespeichert, insbesondere nie `00:00:00` als Uhrzeit.

## 2. Tabellenstruktur (Schema-Version 3)

| Feld | Typ | Null | Beschreibung |
|---|---|---|---|
| `id` | `BIGINT(20) UNSIGNED AUTO_INCREMENT` | nein | Primärschlüssel |
| `source` | `VARCHAR(50)` | nein | Slug des Source Adapters, z. B. `burg-rabenstein`. `dev-test` ist für Entwickler-Testdaten reserviert. **`manual`** = manuell gepflegter Termin (seit 2026-10-28, kein Adapter, wird nie gecrawlt, 10-frontend.md) |
| `external_id` | `VARCHAR(191)` | ja | stabile Event-ID der Quelle, falls vorhanden |
| `event_key` | `CHAR(40)` | nein | technischer Hilfsschlüssel (SHA-1) für das Fallback-Matching, Abschnitt 4. **Nicht eindeutig** |
| `title` | `VARCHAR(255)` | nein | Titel (Klartext) |
| `description` | `TEXT` | ja | Kurzbeschreibung (Klartext) |
| `start_date` | `DATE` | nein | **Beginn – Datum** (bis Version 1: `event_date`) |
| `start_time` | `TIME` | ja | **Beginn – Uhrzeit**; `NULL`, wenn unbekannt oder ganztägig |
| `end_date` | `DATE` | ja | **Ende – Datum**; `NULL`, wenn die Quelle keine Endinformation liefert |
| `end_time` | `TIME` | ja | **Ende – Uhrzeit**; `NULL`, wenn unbekannt oder ganztägig |
| `all_day` | `TINYINT(1) UNSIGNED` | nein, Standard 0 | **Ganztägige Veranstaltung**: 0 = normal, 1 = ganztägig |
| `venue_name` | `VARCHAR(255)` | ja | **Veranstaltungsort**, der konkrete Ort, z. B. „Burg Rabenstein“, „Hirsch“, „Löwensaal“ (bis Version 1: `venue`) |
| `venue_address` | `VARCHAR(255)` | ja | **Adresse des Veranstaltungsorts** (Straße/Hausnummer, ohne PLZ und Stadt), seit Version 3. **Nur** aus einem strukturierten Adressfeld der Quelle, nie aus Fließtext; `NULL`, wenn die Quelle keine liefert. Derzeit nur Coburg (04-quellen.md) |
| `city` | `VARCHAR(100)` | nein | Gemeinde/Stadt, z. B. „Ahorntal“, „Nürnberg“. Im XML-Export das `<h2>` |
| `event_url` | `VARCHAR(2048)` | ja | **Webseiten-Link**: öffentlicher Link zur Veranstaltung, z. B. Detail- oder Ticketseite |
| `source_url` | `VARCHAR(2048)` | ja | technische Herkunft: die Quellseite, über die der Crawler das Event erkannt hat. Grundlage von Matching-Priorität 2 |
| `content_hash` | `CHAR(40)` | nein | SHA-1 über die Inhaltsfelder, Abschnitt 5 |
| `created_at` | `DATETIME` | nein | erster Import |
| `updated_at` | `DATETIME` | nein | letzte inhaltliche Änderung durch den Crawler |
| `last_seen_at` | `DATETIME` | nein | letzter Crawl, in dem das Event gefunden wurde („zuletzt geprüft“) |

### Regeln für Start, Ende und Ganztag (implementiert in `Eventcrawler_Crawler::normalize()`)

| Fall | start_date | start_time | end_date | end_time | all_day |
|---|---|---|---|---|---|
| Beginn und Ende, z. B. 18:30–21:45 | 2026-10-15 | 18:30:00 | 2026-10-15 | 21:45:00 | 0 |
| keine Endinformation | 2026-10-15 | 18:30:00 | NULL | NULL | 0 |
| keine Uhrzeit bekannt | 2026-10-15 | NULL | NULL | NULL | 0 |
| ganztägig (nur wenn die Quelle es eindeutig sagt) | 2026-10-15 | NULL | NULL oder echtes Enddatum | NULL | 1 |

- **Endzeit ohne Enddatum** → `end_date = start_date`.
- **Ende nicht nach dem Beginn**, z. B. „22:00–01:00“ ohne Folgedatum → **Ende wird verworfen**
  (`end_date` und `end_time` = NULL, Protokoll bei `WP_DEBUG`). Es wird nicht geraten, dass das Ende am Folgetag liegt.
  Ein Ende am Folgetag muss der **Adapter** ausdrücklich als `end_date` liefern, wenn die Quelle es eindeutig erkennen
  lässt (dann wird es übernommen, getestet).
- `end_date` vor `start_date` → Ende wird verworfen.
- `all_day = 1` → `start_time` und `end_time` werden auf NULL gesetzt. **Aus einer fehlenden Uhrzeit wird nie auf
  Ganztag geschlossen**, das setzt nur der Adapter.
- Ungültige Werte (z. B. Uhrzeit `25:00`, Datum `2026-02-30`) → Eintrag wird übersprungen („fehlerhaft“).

### Indizes

| Name | Spalten | Art | Zweck |
|---|---|---|---|
| `PRIMARY` | `id` | Primary Key | |
| `source_external` | `source, external_id` | **UNIQUE** | Matching Priorität 1. Mehrere `NULL` sind erlaubt. Deckt auch Abfragen nur nach `source` ab |
| `source_url` | `source, source_url(191)` | INDEX (Präfix, **nicht** unique) | Matching Priorität 2 |
| `source_event_key` | `source, event_key` | INDEX (**nicht** unique) | Matching Priorität 3 |
| `start_date` | `start_date, start_time` | INDEX | Liste, Export, später Zeitraumfilter (bis Version 1: `event_date`) |
| `city` | `city` | INDEX | Filter nach Ort |
| `last_seen_at` | `last_seen_at` | INDEX | „zuletzt geprüft“ |

**Kein UNIQUE-Index auf `event_key` oder `source_url`:** Wiederkehrende Termine dürfen denselben Hilfsschlüssel
haben, und eine URL kann bei manchen Quellen mehrere Termine bezeichnen. Getestet: Zwei Datensätze mit gleichem
`event_key` werden beide gespeichert.

Länge der Indizes: `source` (50 × 4 Byte) + `source_url(191)` bzw. `external_id` (191 × 4 Byte) = 964 Byte. Das liegt
unter dem InnoDB-Limit von 3072 Byte.

### SQL (wie in `Eventcrawler_Installer::create_table()`)

```sql
CREATE TABLE {prefix}eventcrawler_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(50) NOT NULL,
  external_id varchar(191) DEFAULT NULL,
  event_key char(40) NOT NULL,
  title varchar(255) NOT NULL,
  description text,
  start_date date NOT NULL,
  start_time time DEFAULT NULL,
  end_date date DEFAULT NULL,
  end_time time DEFAULT NULL,
  all_day tinyint(1) unsigned NOT NULL DEFAULT 0,
  venue_name varchar(255) DEFAULT NULL,
  venue_address varchar(255) DEFAULT NULL,
  city varchar(100) NOT NULL,
  event_url varchar(2048) DEFAULT NULL,
  source_url varchar(2048) DEFAULT NULL,
  content_hash char(40) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_external (source,external_id),
  KEY source_url (source,source_url(191)),
  KEY source_event_key (source,event_key),
  KEY start_date (start_date,start_time),
  KEY city (city),
  KEY last_seen_at (last_seen_at)
) {charset_collate};
```

In einer migrierten Tabelle stehen die neuen Spalten physisch am Ende. Das hat keine fachliche Bedeutung.

Bewusst **nicht** enthalten, als spätere Felder denkbar: `organizer`, `meeting_point`, `phone`, `price`,
`image_url`, `status` (z. B. abgesagt), `locked_fields`, `ignored_at`.

## 3. external_id

- **Manuelle Termine** (`source = manual`): `NULL` bei Anlage im Formular; `wpem-{Post-ID}` bei der Übernahme aus
  WP Kulmbacher Land (Idempotenz über den UNIQUE-Index, 10-frontend.md Abschnitt 10). Beim Bearbeiten bleibt sie
  unverändert. `source_url` ist bei manuellen Terminen immer `NULL`.

- Wird vom Adapter gesetzt, wenn die Quelle eine **eindeutige, stabile** ID pro Termin liefert.
- Burg Rabenstein hat keine (04-quellen.md).
- Eine ID, die nur eine **Serie** bezeichnet, ist allein ungeeignet. Der UNIQUE-Index würde den zweiten Termin ablehnen.
- **Bad Steben** (seit 2026-09-24) hat eine Serien-ID (`e_…`) und kann dieselbe Serie mehrmals am Tag haben. Der
  Adapter bildet deshalb `external_id = {e_ID}_{JJJJ-MM-TT}_{n}` (n = laufende Nummer an diesem Tag nach Beginn).
  Eine geänderte Uhrzeit am selben Tag bleibt derselbe Datensatz. Eine Datumsverschiebung ergibt einen neuen
  Datensatz (04-quellen.md). Das Schema ist dafür unverändert (`VARCHAR(191)`).

## 4. event_key (technischer Hilfsschlüssel)

Der event_key ist **nur für das Fallback-Matching** gedacht (Quellen ohne stabile ID und ohne stabile Detail-URL). Er
ist **keine Garantie**, dass zwei Veranstaltungen identisch sind, und deshalb nicht unique. Das Feld wird für jedes
Event berechnet.

Implementierte Definition (`Eventcrawler_Crawler::event_key()`), in Version 2 inhaltlich unverändert:

```
event_key = sha1( source . '|' . normalize(title) . '|' . normalize(city) . '|' . start_date )
```

- `normalize()`: `mb_strtolower`, Whitespace zusammenfassen, trimmen.
- Das **Datum ist enthalten**, damit wiederkehrende Termine mit gleichem Titel nicht zusammenfallen. Die **Uhrzeit ist
  nicht enthalten**, eine geänderte Uhrzeit am selben Tag aktualisiert den Datensatz.
- Folge: Bei Events **ohne** stabile ID/URL erzeugt eine **Datumsänderung einen neuen Datensatz** (für den MVP akzeptiert).
- Die Umbenennung `event_date` → `start_date` ändert die Werte nicht. Nach der Migration waren alle event_keys
  unverändert (getestet).
- Burg Rabenstein: 124 verschiedene event_keys, kein Anlass für eine Änderung.
- Bad Steben: **92 doppelte event_keys** (dieselbe Serie mehrmals am Tag). Der Fallback wurde damit am 2026-09-24
  erstmals mit echten Daten getestet (03-crawler.md Abschnitt 12): keine Duplikate, Uhrzeitänderung am selben Tag
  erkannt, Datumsverschiebung ergibt einen neuen Datensatz. Die Formel bleibt unverändert. Bad Steben selbst matcht
  über `external_id`.

## 5. content_hash (Version 3)

Erkennt inhaltliche Änderungen (`Eventcrawler_Crawler::content_hash()`). Die Funktion arbeitet mit normalisierten Events
**und** mit Datenbankzeilen, damit Hashes bei der Migration neu berechnet werden können.

```
content_hash = sha1( implode( '|', [
    normalize(title),
    normalize(description),
    start_date,          // Y-m-d
    start_time,          // H:i oder ''
    end_date,            // Y-m-d oder ''
    end_time,            // H:i oder ''
    all_day,             // 0|1
    normalize(venue_name),
    normalize(city),
    event_url,
    source_url,
    normalize(venue_address),   // seit Version 3, am Ende angehängt
] ) )
```

- `source_url` bleibt enthalten wie in Version 1. Sie ist bei URL-Matching ohnehin stabil.
- **Nicht** enthalten: `id`, `source`, `external_id`, `event_key`, `created_at`, `updated_at`, `last_seen_at`, und
  Werte, die nicht gespeichert werden (Preis, Bild).
- Getestet: Eine Änderung von title, description, start_date, start_time, end_date, end_time, all_day, venue_name,
  city oder event_url ändert den Hash; gleiche Daten ergeben denselben Hash.
- Bei der Migration auf Version 2 wurden alle gespeicherten Hashes mit der neuen Formel **neu berechnet**. So führen
  nur echte Inhaltsänderungen (hier: die neu gelesenen Endzeiten) zu einem UPDATE, nicht die geänderte Formel.

## 6. Matching-Strategie – implementiert, unverändert seit Version 1

Gesucht wird innerhalb derselben `source`. Die erste anwendbare Priorität entscheidet:

| Priorität | Bedingung | Suche | Repository-Methode |
|---|---|---|---|
| **1** | Adapter liefert eine stabile `external_id` | `source + external_id` | `find_by_external_id()` |
| **2** | eindeutige Detail-URL je Termin (`has_unique_detail_urls() = true`) | `source + source_url` | `find_by_source_url()` |
| **3** | weder ID noch stabile URL | `source + event_key` | `find_fallback_candidates()` |

- Greift Priorität 1 oder 2 ohne Treffer, wird **neu angelegt**. Es wird nicht auf eine niedrigere Priorität ausgewichen.
- **Bei stabiler ID oder URL dürfen sich start_date, start_time, end_date, end_time, venue_name und event_url ändern,
  ohne dass ein Duplikat entsteht.** Getestet in Version 2: Jede dieser Änderungen aktualisierte denselben Datensatz.
- Matching über `source_url`, **nicht** über `event_url`. Der öffentliche Link darf sich ändern (z. B. neue
  Ticketseite), ohne die Identität zu berühren.
- Fallback: Kandidaten mit gleichem `source + event_key`, die im laufenden Crawl noch nicht zugeordnet wurden
  (`last_seen_at < Startzeit des Laufs`). Gleiche `start_time` hat Vorrang, sonst die kleinste `id`.
- Keine Fuzzy-Matching-Engine im MVP.
- URL-Normalisierung (`Eventcrawler_Source::absolute_url()`): absolut, Schema und Host klein, Query und Fragment
  entfernt.

## 7. Update-Strategie – implementiert und getestet

| Situation | Aktion |
|---|---|
| kein bestehendes Event | **INSERT**; `insert()` setzt `created_at`, `updated_at`, `last_seen_at` |
| gefunden, gleicher `content_hash` | nur `last_seen_at = jetzt` |
| gefunden, anderer `content_hash` | **UPDATE** aller Inhaltsfelder inkl. `event_key`, `content_hash`, `updated_at`, `last_seen_at`. Eine gespeicherte `external_id` wird nie mit `NULL` überschrieben. Felder, die jetzt fehlen, werden auf `NULL` gesetzt (z. B. entfernte Endzeit) |
| gefunden, aber das neue Event wird gefiltert | keine Änderung |

`Repository::update()` setzt keine Zeitstempel automatisch. Keine Änderungshistorie; der alte Stand wird überschrieben.
Events, die in der Quelle nicht mehr auftauchen, werden **nicht** gelöscht.

## 8. Löschen, Deaktivierung, Deinstallation

| Vorgang | Verhalten |
|---|---|
| Löschen im Backend | echtes `DELETE`, ohne Papierkorb |
| erneuter Crawl nach dem Löschen | das Event wird **erneut importiert** (keine Ignore-Liste, getestet) |
| Plugin deaktivieren | Tabelle und Daten bleiben (getestet) |
| Plugin deinstallieren | `uninstall.php` löscht **nichts** (getestet) |

Später: Ignore-/Blacklist (`ignored_at` oder kleine Tabelle), Option „Daten bei Deinstallation löschen“.

## 9. Repository-API (`Eventcrawler_Repository`)

| Methode | Rückgabe | Hinweis |
|---|---|---|
| `get_by_id( $id )` | `array\|null` | `SELECT *`; auch für das read-only Detail-Modal der Admin-Liste (06-admin.md Abschnitt 11), das alle Spalten ungefiltert zeigt |
| `find_by_external_id( $source, $external_id )` | `array\|null` | Priorität 1 |
| `find_by_source_url( $source, $url )` | `array\|null` | Priorität 2; erwartet eine normalisierte URL |
| `find_fallback_candidates( $source, $event_key, $run_started )` | `array[]` | Priorität 3 |
| `get_events( $args )` | `array[]` | `orderby` (Whitelist: `start_date`, `title`, `city`, `source`, `last_seen_at`), `order`, `per_page`, `page`, `filters`; innerhalb eines Tages nach `start_time`, dann `id` |
| `get_events_for_export( $filters )` | `array[]` | alle bzw. gefilterte Datensätze ohne Paginierung; `ORDER BY start_date, city, start_time IS NULL, start_time, title, id` |
| `count_events( $filters )` | `int` | Anzahl, optional gefiltert |
| `get_available_years()` | `int[]` | `DISTINCT YEAR(start_date)` aufsteigend, für die Jahr-Auswahl |
| `get_public_events( $filters, $per_page, $page )` | `array[]` | Frontend (seit 2026-10-28): nur `PUBLIC_COLUMNS`, `ORDER BY start_date, start_time IS NOT NULL, start_time, title, id` (ohne Uhrzeit zuerst), `LIMIT/OFFSET` |
| `get_calendar_events( $filters )` | `array[]` | Kalender: `id, title, start_date, start_time, all_day` eines Zeitraums |
| `get_cities( $filters )` | `string[]` | `DISTINCT city` für die Stadtauswahl im Frontend |
| `get_distinct_values( $column )` | `string[]` | Vorschläge `venue_name`/`city` aus manuellen Terminen (Formular), max. 500 |
| `where_clause( $filters )` (privat) | `array` | **eine** gemeinsame WHERE-Bedingung für Liste, Zählung, Export und Frontend: `source`, `month` (ohne Jahr: `MONTH(start_date)`), `year` bzw. Monat + Jahr (Datumsbereich auf `start_date`), `search` (`LIKE` auf title/description/venue_name/venue_address/city mit `esc_like`); seit 2026-10-28 zusätzlich `sources` (`IN`), `date_from`/`date_to` (`start_date >=`/`<=`), `city` (exakt). Erwartet validierte Werte (`Eventcrawler_Admin::read_filters()` bzw. `Eventcrawler_Frontend`) |
| `insert( $data )` | `int\|false` | nur erlaubte Spalten (`COLUMNS`); Zeitstempel automatisch, falls sie fehlen |
| `update( $id, $data )` | `bool` | nur erlaubte Spalten; `NULL` schreibt NULL |
| `delete( $id )` | `bool` | |
| `delete_many( $ids )` | `int` | ungültige IDs werden ignoriert |

Die wpdb-Formate (`%s`/`%d`) werden **in der Reihenfolge der übergebenen Daten** gebildet. Das ist eine Korrektur in
Version 2: Vorher richtete sich die Reihenfolge nach der Spaltenliste. Das fiel erst mit der ersten `%d`-Spalte
(`all_day`) auf, als ein Test `venue_name = 0` ergab. Ein Regressionstest mit absichtlich vertauschter Spaltenreihenfolge
ist bestanden.

## 10. Migration Version 1 → 2 (implementiert und getestet)

`dbDelta()` kann Spalten nicht umbenennen. Es würde neue, leere Spalten neben den alten anlegen. Deshalb läuft in
`Eventcrawler_Installer::upgrade()` zuerst `migrate_to_2()` und erst danach `dbDelta()`:

1. `migrate_to_2()` (idempotent, prüft Spalten und Indizes vorher):
   - `ALTER TABLE … CHANGE event_date start_date date NOT NULL` (Daten bleiben erhalten)
   - `ALTER TABLE … CHANGE venue venue_name varchar(255) DEFAULT NULL`
   - `ALTER TABLE … DROP INDEX event_date` (dbDelta legt danach `start_date` an)
   - Bei einem DB-Fehler: Abbruch, `error_log`, Version bleibt 1, **kein** dbDelta
2. `create_table()`: `dbDelta()` ergänzt `end_date`, `end_time`, `all_day` (Standard 0), `event_url` und den Index `start_date`.
3. `backfill_2()` (idempotent):
   - `event_url = source_url`, wo `event_url` NULL ist. Bis Version 1 war `source_url` der einzige, öffentliche Link.
   - `content_hash` aller Zeilen mit der neuen Formel neu berechnen
4. Option `eventcrawler_db_version = 2`.

Neue Felder bekommen bei der Migration **keine erfundenen Werte**: `end_date`/`end_time` = NULL, `all_day` = 0. Die
echten Endzeiten liefert erst der nächste Crawl.

Tests (2026-09-24, echte Burg-Rabenstein-Daten):

| Test | Ergebnis |
|---|---|
| Migration bei aktiviertem Plugin, nur durch Aufruf einer Admin-Seite | Version 1 → 2, keine Fehler |
| Anzahl Events vor/nach | 124 / 124 |
| alle 124 Zeilen, Feld für Feld (id, source, external_id, event_key, title, description, start_date = vorher event_date, start_time, venue_name = vorher venue, city, source_url, created_at, updated_at, last_seen_at) | identisch |
| neue Felder nach Migration | end_date/end_time 124 × NULL, all_day 124 × 0, event_url = source_url 124 × |
| gespeicherte Hashes = neu berechnete Hashes | 124 von 124 |
| erneuter Lauf mit Version 1 in der Option auf fertiger Version-2-Tabelle (Idempotenz) | keine Änderung (Prüfsumme gleich) |
| Neuinstallation ohne Tabelle | Tabelle wird direkt in Version 2 angelegt |
| Deaktivieren/Reaktivieren | keine Änderung |

## 11. Migration Version 2 → 3 (implementiert und getestet, 2026-10-02)

Anlass: Kundenwunsch „Adresse statt doppelter Ortsangaben“ (07-export.md Abschnitt 12). Coburg liefert die Straße
strukturiert (04-quellen.md); in `venue_name` gemischt wäre sie nicht sauber von Ort und Stadt zu trennen.

- Nur eine **neue** Spalte, keine Umbenennung → keine Migration vor `dbDelta()` nötig. `upgrade()`:
  `migrate_to_2()` (idempotent, ändert bei Version 2 nichts) → `create_table()` (dbDelta ergänzt `venue_address`)
  → `backfill()` (vorher `backfill_2()`, jetzt bei jedem Upgrade: `event_url`-Ergänzung + Neuberechnung aller
  `content_hash` mit der aktuellen Formel) → Option `eventcrawler_db_version = 3`.
- Altdaten bekommen **keine** erfundene Adresse (`venue_address = NULL`). Die Coburg-Adressen kommen mit dem nächsten
  Crawl (einmalig „aktualisiert“).
- `Eventcrawler_Repository::COLUMNS` und die Suche (`LIKE` auch auf `venue_address`) sind erweitert. Die Admin-Liste
  zeigt die Adresse klein unter dem Veranstaltungsort.

Tests (Testinstallation, 1048 Events, Sicherung vorher per `mysqldump`):

| Test | Ergebnis |
|---|---|
| Migration durch Laden von WordPress (Plugin aktiv) | Version 2 → 3, Spalte `venue_address varchar(255) NULL` angelegt |
| alle Altdaten Feld für Feld (MD5 über alle Spalten außer `content_hash`) | identisch |
| gespeicherte Hashes = neu berechnete Hashes | 1048 von 1048 |
| erneuter Aufruf (Idempotenz) | keine Änderung |
| Crawl aus dem HTML-Cache danach | Burg Rabenstein 124 unverändert, Bad Steben 557 unverändert (+3 bekannte ohne Stadt), Coburg **341 aktualisiert** (Adresse ergänzt), 24 unverändert; zweiter Lauf 365 unverändert, keine Duplikate |
| Live-Crawl Coburg (AJAX) | 335 gefunden, 42 neu, 293 unverändert, 0 fehlerhaft; 321 mit Adresse |
