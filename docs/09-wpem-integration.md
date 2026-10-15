# 09 – WP Kulmbacher Land (Kundenplugin) und unser XML

> **Stand 2026-09-30.** Erstellt beim Kundenfeedback zum XML (07-export.md Abschnitt 10). Das Kundenplugin wurde
> **nur gelesen und nicht verändert**. Der Eventcrawler verwendet weiterhin **keinen** Code, keine Meta-Keys, keinen
> CPT und keine Tabellen des Kundenplugins (08-kompatibilitaet.md gilt weiter).

## 1. Was vorliegt

| | |
|---|---|
| Plugin | „WP Kulmbacher Land“, Version 1.3.1, Text-Domain `wp-kulmbacher-land` |
| Ordner (nur Referenz) | `C:\xampp\htdocs\kulmbacher\wp-events-manager` (Dateien `includes/class-wpem-*.php`) |
| Relevant hier | XML-Export (`class-wpem-export.php`, `export_xml()`) und XML-Import (`class-wpem-import.php`, `import_xml()`, `parse_event_line()`) |
| Nicht untersucht | Frontend, AJAX, Metaboxen, Venue-Verwaltung im Detail (für diese Phase nicht nötig) |

Das „bestehende XML-Minimalformat“ (`<Root>/<Textabschnitt>/<h1>/<h2>/<p>`) ist das Exportformat dieses Plugins.

## 2. XML-Export des Kundenplugins (Vergleich)

| Punkt | WP Kulmbacher Land | Eventcrawler |
|---|---|---|
| Deklaration | `<?xml version="1.0" encoding="UTF-8"?>`, Header `application/xml; charset=utf-8` | gleich |
| BOM | XML ohne BOM (CSV-Export **mit** BOM „für Excel“) | ohne BOM |
| Erzeugung | String-Ausgabe mit `esc_html()` (u. a. `'` → `&#039;`), ohne Output-Buffer-Bereinigung | `XMLWriter`, Ausgaben vorher verworfen |
| Einrückung | keine, eine Zeile je Element | seit 2026-09-30 ebenfalls keine (vorher 4 Leerzeichen) |
| `<h2>` | Stadt des Veranstaltungsorts, sonst Name des Orts, sonst „Ohne Ort“ | `city` (ohne Stadt kein Export) |
| `<p>` | `[HH:MM Uhr ]Titel[: Beschreibung][ Info: Website]` | `[HH:MM Uhr[ & HH:MM Uhr] ]Titel[, Beschreibung][, Ort][, Adresse][. Info: Domain]` (seit 2026-10-02, 07-export.md 12) |
| Website | gespeicherter Wert, in der Regel mit `https://` | seit 2026-10-02 nur Hostname (07-export.md 12.1), vorher ab 2026-09-30 ohne Protokoll |

Hinweis: Werden Exporte beider Plugins nebeneinander verwendet, stehen Links dort mit, bei uns ohne `https://`.

## 3. XML-Import des Kundenplugins

`import_xml()` liest mit `simplexml_load_string()` und geht die Kinder von `<Textabschnitt>` durch:

- `<h1>`: Tag und deutscher Monatsname; **Jahr** aus dem Importformular oder das aktuelle Jahr
- `<h2>`: wird als Veranstaltungsort (Taxonomie) gesucht bzw. angelegt, mit dem Wert auch als Stadt
- `<p>` (`parse_event_line()`):
  1. `^(\d{1,2}:\d{2})\s*Uhr\s+` → Uhrzeit, sonst ganztägig
  2. `\s+Info:\s+(https?:\/\/\S+)\s*$` → Website (**nur mit `http://` oder `https://`**)
  3. Rest am ersten `": "` in Titel und Beschreibung geteilt

### Test mit unserem Gesamtexport (1048 `<p>`, 2026-09-30, nur Parser, keine Posts angelegt)

| Export | Uhrzeit erkannt | Website erkannt | Ergebnis |
|---|---|---|---|
| bis 2026-09-29 (`Info: https://…`) | 1045 | **1048** | Website korrekt; Titel = ganze Zeile bis vor „Info“ (unser Trenner ist `, `, nicht `: `) |
| ab 2026-09-30 (`Info: example.de/…`) | 1045 | **0** | „… Info“ landet im Titel, die URL in der Beschreibung |
| ab 2026-09-30 mit Filter `eventcrawler_export_display_url` (volle URL) | 1045 | **1048** | wie bis 2026-09-29 |

UTF-8 (z. B. „Café“) liest der Importer korrekt; ein BOM würde `simplexml` ebenfalls nicht stören.

**Folge:** Unser XML ist auch vor dieser Änderung nur eingeschränkt für den Import geeignet (Titel, Beschreibung und
Ort verschmelzen im Titel; das Jahr kommt aus dem Formular). Ein Import in WP Kulmbacher Land ist nirgends als
Anforderung dokumentiert.

## 4. Entscheidung (A62) und Wege für einen späteren Import

- Standard: sichtbare URL ohne Protokoll (Kundenwunsch, XML für Druck/Redaktion); seit 2026-10-02 nur der Hostname.
- Seit 2026-10-02 können mehrere Uhrzeiten in einer Zeile stehen („18:30 Uhr & 20:00 Uhr …“). Der Importer liest nur
  die erste Uhrzeit, der Rest landet im Titel. Für einen Import wäre also zusätzlich die Gruppierung abzuschalten
  (derzeit ohne Schalter; erst bei echtem Bedarf).
- Soll unser XML in WP Kulmbacher Land importiert werden:
  - **A:** volle URL per Filter (siehe 07-export.md 10.2), ohne Änderung am Kundenplugin, sofort möglich
  - **B:** Importer des Kundenplugins später anpassen (Website auch ohne Protokoll erkennen) – nicht in dieser Phase
- Für eine echte Übernahme gecrawlter Events wäre ein direkter Weg ohne Umweg über den Drucktext sinnvoller
  (strukturierte Felder statt `<p>`-Zeile); das bleibt eine spätere Integrationsphase (08-kompatibilitaet.md).

## 5. Rolle als technische Referenz für InDesign (2026-09-30)

Katharina verwendet den XML-Export von WP Kulmbacher Land erfolgreich in Adobe InDesign. Er ist deshalb die
**technische Referenz für die äußere Form** unseres XML (A65). Echte Referenzdatei:
`C:\xampp\htdocs\kulmbacher\veranstaltungen-export-2026-09-30.xml`.

Ablauf in `export_xml()` (gelesen, nicht geändert):

1. Header `Content-Type: application/xml; charset=utf-8`, `Content-Disposition: attachment; filename="veranstaltungen-export-{date('Y-m-d')}.xml"`, `Pragma: no-cache`, `Expires: 0`
2. Events nach Startdatum (Meta `_wpem_sort_date`) und dann nach Ort gruppiert, Orte je Tag per `ksort`
3. `echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"`, dann `<Root>\n`, `<Textabschnitt>\n`
4. je Tag `<h1>` („01. Juli Freitag“), je Ort `<h2>`, je Event `<p>` – jeweils `esc_html()` und `"\n"`
5. `</Textabschnitt>\n</Root>` **ohne** abschließenden Zeilenumbruch, danach `exit`; kein BOM, keine Einrückung

Befunde der echten Datei: gültiges UTF-8, kein BOM, LF, 146 × NBSP (U+00A0) als Zeichen, einmal die HTML-Entity
`&nbsp;` (in XML undefiniert → die Datei ist formal nicht wohlgeformt), `&quot;` und `&#039;` durch `esc_html()`.
Vergleich im Detail: 07-export.md Abschnitt 11.
