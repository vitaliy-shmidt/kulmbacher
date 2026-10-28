# 08 – Kompatibilität mit dem vorhandenen Veranstaltungs-Plugin

> **Stand 2026-09-24.** Dies ist derzeit **nur fachliche Kompatibilität**.
>
> **Nachtrag 2026-09-30:** Der Code des Kundenplugins („WP Kulmbacher Land“ 1.3.1) liegt inzwischen als Referenz unter
> `C:\xampp\htdocs\kulmbacher\wp-events-manager` vor. Gelesen wurden bisher nur XML-Export und XML-Import
> (09-wpem-integration.md). Der Eventcrawler nutzt weiterhin nichts davon technisch; die Aussagen unten zu fehlendem
> Zugriff beziehen sich auf den Stand vom 2026-09-24.
>
> **Nachtrag 2026-10-28:** Das Kundenplugin ist jetzt vollständig analysiert (CPT `wpem_event`, Taxonomie `wpem_venue`,
> Meta-Keys, Shortcodes, URLs) und der Eventcrawler soll es ersetzen: [10-frontend.md](10-frontend.md). Technisch
> genutzt wird es nur **lesend und einmalig** für die WPEM-Übernahme (Mapping der Felder dort in Abschnitt 10). Eine
> Synchronisation oder ein Schreiben in WPEM gibt es weiterhin nicht. Die Abschnitte unten beschreiben den Stand
> vor dieser Analyse.

## Ausgangslage

- Beim Kunden existiert ein **anderes WordPress-Plugin für manuell gepflegte Veranstaltungen**. Es belegt auch den
  Admin-Menüpunkt „Veranstaltungen“, deshalb heißt unser Menü „Eventcrawler“ (06-admin.md, Entscheidung A22).
- In der Entwicklungsumgebung besteht **kein technischer Zugriff** auf dieses Plugin. Es gibt:
  - keinen Plugin-Code und keine Plugin-Dateien
  - keine technische Dokumentation und keine API-Dokumentation
  - keine Datenbankstruktur und keine Meta-Keys
- Bekannt sind **nur die sichtbaren Felder** des Veranstaltungseditors aus einem Screenshot bzw. Präsentationsvideo.
- Name, Hersteller und Version des Plugins sind in diesem Projekt nicht dokumentiert.

## Sichtbare fachliche Felder (laut Präsentationsvideo)

- Beginn – Datum
- Beginn – Uhrzeit
- Ende – Datum
- Ende – Uhrzeit
- Ganztägige Veranstaltung
- Veranstaltungsort
- Webseiten-Link

## Mapping (fachlich)

| Sichtbares Feld Fremdplugin | Eventcrawler | Typ im Eventcrawler | Stand |
|---|---|---|---|
| Beginn – Datum | `start_date` | `DATE` | implementiert (Schema-Version 2) |
| Beginn – Uhrzeit | `start_time` | `TIME`, NULL = unbekannt/ganztägig | implementiert |
| Ende – Datum | `end_date` | `DATE`, NULL = keine Endinformation | implementiert |
| Ende – Uhrzeit | `end_time` | `TIME`, NULL = unbekannt/ganztägig | implementiert |
| Ganztägige Veranstaltung | `all_day` | `TINYINT(1)`, 0/1 | implementiert |
| Veranstaltungsort | `venue_name` | `VARCHAR(255)` | implementiert |
| Webseiten-Link | `event_url` | `VARCHAR(2048)` | implementiert |

Zusätzlich nur intern im Eventcrawler (ohne sichtbares Gegenstück im Fremdplugin):

| Feld | Zweck |
|---|---|
| `city` | Gemeinde/Stadt; `<h2>` im XML-Export. Ob das Fremdplugin die Stadt getrennt vom Veranstaltungsort führt, ist **unbekannt** |
| `source_url` | technische Herkunft (Quellseite des Crawlers), Matching-Grundlage |
| `source` | Quellen-Slug, z. B. `burg-rabenstein` |
| `external_id` | ID der Quelle, falls vorhanden |
| `event_key` | Hilfsschlüssel für das Fallback-Matching |
| `content_hash` | Änderungserkennung |

Die Regeln für NULL, Ende und Ganztag stehen in [02-datenbank.md](02-datenbank.md), Abschnitt 2.

## Wichtig: Was diese Kompatibilität bedeutet und was nicht

**Dies ist derzeit nur fachliche Kompatibilität.** Der Eventcrawler kann dieselben **Informationen** speichern, die
im Editor des Fremdplugins sichtbar sind.

Es wird **ausdrücklich nicht** behauptet, dass der Eventcrawler

- technisch kompatibel mit dem Fremdplugin ist,
- Daten direkt in das Fremdplugin importieren kann,
- dieselben Meta-Keys, Custom Post Types, Taxonomien oder Tabellen verwendet,
- API-kompatibel (REST, PHP-Klassen, Hooks) ist.

Es wurden **keine Annahmen** über CPT, Meta-Keys, Tabellen, Taxonomien, interne IDs (z. B. Venue-IDs), REST-API oder
PHP-Klassen des Fremdplugins getroffen und nichts davon im Code verwendet.

### Technisch unbekannt (mangels Zugriff)

- Speicherort der Veranstaltungen (Custom Post Type? eigene Tabelle?) und Meta-Keys
- Datums- und Zeitformat der gespeicherten Werte, Zeitzonenbehandlung
- ob der Veranstaltungsort Freitext ist oder ein verknüpftes Objekt mit eigener ID (Venue-Verwaltung)
- ob es ein Feld für Stadt oder Adresse gibt
- wie Ganztag, fehlende Endzeit und mehrtägige Veranstaltungen intern abgebildet werden
- Pflichtfelder, Validierung, Beschreibung/Inhalt, Bilder, Kategorien
- ob es eine Import-Schnittstelle, REST-Endpunkte oder Hooks gibt

## Voraussetzungen für eine spätere echte Integration

Eine technische Integration (z. B. Übernahme gecrawlter Events in das Fremdplugin) erfordert Zugriff auf:

- den **Plugin-Code**
- eine **Installation** (mindestens Testsystem) mit Beispieldaten
- die **technische Dokumentation**
- die **Datenbankstruktur** (CPT/Meta-Keys bzw. Tabellen)

Erst danach kann ein technisches Mapping dokumentiert und umgesetzt werden, z. B. als eigener, getrennter
Integrationsbaustein. Nicht implementiert und ausdrücklich nicht Teil der aktuellen Phase sind: Synchronisierung,
fremde Meta-Keys, fremder CPT, fremde Venue-IDs, REST-Integration und ein Import-Button.
