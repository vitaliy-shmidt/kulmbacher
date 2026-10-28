# Hinweise für KI-Assistenten (Claude / Codex)

Projekt: WordPress-Plugin „Eventcrawler“. Es crawlt Veranstaltungen externer Websites in eine eigene DB-Tabelle und
exportiert sie im bestehenden XML-Minimalformat.

- Plugin-Code: `wp-plugin/`
- Dokumentation (verbindlich): `docs/`. **Vor jeder größeren Änderung `docs/README.md`, `docs/01-konzept.md` und
  `docs/todo.md` lesen.**

Die verbindlichen Entwicklungsregeln stehen in `docs/README.md`. Die wichtigsten:

- Ändern sich DB-Felder, Crawlerlogik, Filter oder Exportformat, wird die Doku im selben Schritt angepasst.
- MVP, keine Überarchitektur, keine externen Bibliotheken, wenn WP/PHP es selbst kann.
- `$wpdb` mit `$wpdb->prefix`, nie `wp_` fest. Capabilities, Nonces, Sanitizing, Escaping.
- Unklare Daten überspringen statt falsch speichern. Keine Quelle als funktionierend markieren, bevor sie getestet ist.
- XML-Format (`docs/07-export.md`) nie ohne ausdrückliche Anweisung ändern.
- Admin-Menü heißt „Eventcrawler“, nicht „Veranstaltungen“; das Menü eines anderen Plugins nie verändern.
- Das Kundenplugin „WP Kulmbacher Land“ (`wp-events-manager`, WPEM) wird durch den Eventcrawler ersetzt
  (`docs/10-frontend.md`). `{prefix}eventcrawler_events` ist die einzige Datenquelle: keine Synchronisation nach WPEM,
  keine Kopie in `wp_posts`. WPEM-Daten nur lesend und nur für die einmalige Übernahme verwenden; das Plugin selbst nie
  ändern. Manuelle Termine haben `source = manual`; gecrawlte Termine werden im Admin nicht bearbeitet.
- Schema-Änderungen: `EVENTCRAWLER_DB_VERSION` erhöhen; Umbenennungen per Migration **vor** `dbDelta()` (docs/02-datenbank.md Abschnitt 10).
- Beim Entwickeln von Adaptern: Quelle höflich behandeln (HTML lokal zwischenspeichern, Parser offline testen, nur lesende GETs).
