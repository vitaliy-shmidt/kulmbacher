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
- Zum vorhandenen manuellen Veranstaltungs-Plugin besteht nur **fachliche** Kompatibilität (`docs/08-kompatibilitaet.md`).
  Keine Annahmen über dessen CPT, Meta-Keys, Tabellen oder API treffen.
- Schema-Änderungen: `EVENTCRAWLER_DB_VERSION` erhöhen; Umbenennungen per Migration **vor** `dbDelta()` (docs/02-datenbank.md Abschnitt 10).
- Beim Entwickeln von Adaptern: Quelle höflich behandeln (HTML lokal zwischenspeichern, Parser offline testen, nur lesende GETs).
