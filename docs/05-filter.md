# 05 – Filter

> **Stand 2026-09-26:** Die Textfilter (Stammtisch, politische Inhalte) sind **Filterregeln**, die in der
> WordPress-Option `eventcrawler_filter_rules` gespeichert sind und im Backend unter **Eventcrawler → Filterregeln**
> gepflegt werden (06-admin.md). Implementiert in `includes/class-eventcrawler-filter-rules.php`
> (`Eventcrawler_Filter_Rules`, Speicherung) und `includes/class-eventcrawler-filter.php` (`Eventcrawler_Filter`,
> Auswertung). Die Ortsregel (Abschnitt 5) ist davon getrennt und bleibt im Code. Abschnitt 7 enthält nur **geplante**
> Erweiterungen.

## 1. Position im Importprozess (implementiert)

```
Abruf -> Parser (Adapter) -> Normalisierung -> FILTER -> Matching -> INSERT/UPDATE
```

- Die Filter laufen **zentral im Crawler Core** (`Eventcrawler_Crawler::run()`), **nach der Normalisierung** und **vor
  dem Matching bzw. Speichern**. Sie arbeiten mit bereinigtem Klartext.
- **Adapter filtern nicht.** Sie liefern alle technisch gültigen Events. Die Regeln gelten **global für alle Quellen**;
  quellenspezifische Filterbegriffe gibt es nicht.
- Die aktiven Regeln werden **einmal je Crawl-Lauf** geladen (`Eventcrawler_Filter_Rules::get_active()` vor der
  Schleife über die Termine), nicht pro Termin. Eine Änderung im Backend wirkt ab dem nächsten Crawl einer Quelle.
- Ein gefiltertes Event wird **nicht gespeichert** und in der Statistik als „gefiltert“ gezählt.
- **Bereits gespeicherte Events** werden durch neue oder geänderte Regeln **nicht** gelöscht oder verändert. Trifft eine
  Regel beim nächsten Crawl auf ein schon gespeichertes Event, wird es als „gefiltert“ gezählt; der Datensatz bleibt
  unverändert, auch `last_seen_at` bleibt alt (getestet, offener Punkt in todo.md). Wer es nicht mehr exportieren
  will, löscht es in der Liste „Importierte Veranstaltungen“.

API:

- `Eventcrawler_Filter::get_exclusion_reason( $event, $allowed_cities = null, $rules = null )` liefert `null`
  (übernehmen), `'ort'` oder den **Kategorie-Schlüssel** der ersten passenden Regel (`'stammtisch'` bzw. `'politics'`).
  Ohne `$rules` werden die aktiven gespeicherten Regeln geladen (für Einzelprüfungen); der Crawler übergibt die einmal
  geladene Liste.
- `Eventcrawler_Filter::find_matching_rule( $text, $rules )` liefert die erste passende Regel oder `null`
  (wird auch vom Feld „Text prüfen“ im Backend benutzt).

Prüfreihenfolge:

1. Ortsregel (nur wenn der Adapter `get_allowed_cities()` setzt)
2. aktive Filterregeln in gespeicherter Reihenfolge; die erste Treffer-Regel bestimmt den Grund

## 2. Speicherung der Filterregeln (implementiert)

- Option **`eventcrawler_filter_rules`** (WordPress Options API). **Keine eigene Tabelle, keine Schemaänderung**,
  `EVENTCRAWLER_DB_VERSION` wurde dafür nicht geändert (die Version 3 vom 2026-10-02 betrifft nur `venue_address`).
- Wert: Liste von Regeln, jede mit

| Feld | Inhalt |
| --- | --- |
| `id` | zufällige, stabile Kennung, z. B. `r8ea4eda4a1da` (für Bearbeiten/Löschen) |
| `term` | Begriff, Klartext, max. 100 Zeichen |
| `category` | `stammtisch` oder `politics` |
| `match` | `contains` (Teilstring) oder `word` (ganzes Wort) |
| `enabled` | `true` = aktiv, `false` = deaktiviert (bleibt gespeichert, wird nicht angewendet) |

Kategorien (Anzeige im Backend deutsch):

| Schlüssel | Anzeige |
| --- | --- |
| `stammtisch` | Stammtisch / unerwünschte Treffen |
| `politics` | Politische Inhalte |

Hinweis: Bis zu dieser Version hieß der Grund für politische Inhalte intern `'politik'`, jetzt `'politics'`. Der Grund
wird nirgends gespeichert oder angezeigt, es gibt also keine Altdaten.

### Initialisierung der Defaults

- Die Default-Regeln stehen weiter im Code (`Eventcrawler_Filter::default_rules()`, Konstanten `STAMMTISCH_TERMS`,
  `POLITICAL_TERMS`, `PARTY_NAMES`). Sie werden **nur** für die Erstbelegung benutzt, nie zur Laufzeit.
- `Eventcrawler_Filter_Rules::maybe_initialize()` läuft bei der Aktivierung und bei `plugins_loaded`. Sie legt die Option
  **nur an, wenn sie noch nicht existiert** (`get_option() === false`), mit allen Defaults aktiv:
  36 Regeln = 1 × Stammtisch/`contains`, 21 × Politik/`contains` (seit 2026-10-02 mit `bürgerversammlung`),
  14 × Parteinamen Politik/`word`. Bis 2026-09-30 waren es 35.
- **Neue Defaults in bestehenden Installationen (seit 2026-10-02):** Die Option `eventcrawler_filter_rules_version`
  merkt sich, welcher Stand der Defaults angewendet wurde (fehlt sie: Stand 1). Ist er älter als
  `Eventcrawler_Filter_Rules::RULES_VERSION` (derzeit 2), werden nur die **danach neu hinzugekommenen** Defaults
  (`ADDED_DEFAULTS`, derzeit nur `bürgerversammlung`) **einmalig** ergänzt – nicht, wenn es den Begriff in der
  Kategorie schon gibt (auch deaktiviert oder als „ganzes Wort“). Danach wird der Stand gespeichert. Eine beschädigte
  Option wird nicht angefasst. Getestet: Ergänzung genau einmal, zweiter Aufruf ändert nichts, danach gelöscht →
  kommt nicht zurück, eigene deaktivierte Regel → keine Dublette, bleibt deaktiviert.
- Sonst passiert bei existierender Option nichts – auch wenn sie leer ist. Dadurch kommen **gelöschte oder deaktivierte
  Default-Regeln nie automatisch zurück** (getestet: Default „stadtrat“ gelöscht, „kreistag“ deaktiviert, danach Reload,
  `maybe_initialize()` und erneute Aktivierung → bleibt gelöscht bzw. deaktiviert).
- Eine Deinstallation löscht die Option **nicht** (wie die Tabelle, uninstall.php).

### Beschädigte Option

- Ist der Wert kein Array (z. B. manuell überschrieben), gibt es **keinen Fatal Error** und **keine automatische
  Rückkehr zu den Defaults**: Es gilt keine Textregel, die Seite „Filterregeln“ zeigt eine Fehlermeldung, und der
  Crawler schreibt bei `WP_DEBUG` „Filterregeln beschädigt … Crawl ohne Textfilter“ ins Protokoll. Neu angelegte Regeln
  ersetzen den beschädigten Wert.
- Einzelne ungültige Einträge (leerer Begriff, unbekannte Kategorie/Vergleichsart, kein Array) werden ignoriert, die
  gültigen gelten weiter (getestet).

## 3. Auswertung (implementiert)

- Geprüft werden **Titel und Beschreibung** (`title . ' ' . description`). Ort, Veranstalter und URLs werden nicht
  geprüft.
- **Groß- und Kleinschreibung egal**, auch bei Umlauten („ödp“ = „ÖDP“, „FREIE WÄHLER“ = „Freie Wähler“).
- `contains`: Teilstring, auch als Wortbestandteil, per `mb_stripos()`. Beispiel: `stammtisch` erfasst „Musikerstammtisch“.
- `word`: ganzes Wort bzw. ganze Wortfolge. Die Wortgrenze ist ein Unicode-Buchstabe oder eine Ziffer
  (`/(?<![\p{L}\p{N}])BEGRIFF(?![\p{L}\p{N}])/iu`). „SPD-Ortsverein“ und „Jusos-Treffen“ schlagen an, „SPDX“,
  „CSUfest“ und „Grünenfest“ nicht.
- Kein Umschreiben ä ↔ ae: „freie waehler“ trifft „Freie Wähler“ **nicht** (wie bisher).
- Das Matching-Verhalten ist gegenüber den früheren Konstanten **unverändert**: Ein Vergleich der alten, fest
  programmierten Filter mit den gespeicherten Default-Regeln auf 1.093 Fällen (1.048 echte Termine der drei Quellen +
  45 Grenzfälle) ergab identische Ergebnisse (je 38 gefiltert, keiner der echten Termine).
- Entwickler-Hook: `apply_filters( 'eventcrawler_filter_rules', $active_rules )` ändert die aktiven Regeln zur
  Laufzeit. Er ersetzt den früheren Hook `eventcrawler_exclude_terms`.

## 4. Default-Regeln (Erstbelegung)

**Stammtisch** (`stammtisch`, `contains`): `stammtisch`.
Erfasst z. B. „Stammtisch“, „Musikerstammtisch“, „MännerTrauerStammtisch“, „… unser monatlicher STAMMTISCH“ (getestet).

**Politische Begriffe** (`politics`, `contains`): Parteiveranstaltungen, Wahlkampf, Sitzungen politischer Gremien:

- `wahlkampf`, `wahlkundgebung`, `wahlveranstaltung`, `wahlforum`, `podiumsdiskussion zur wahl`
- `parteitag`, `parteiveranstaltung`, `ortsverband`, `kreisverband`, `ortsverein`, `mitgliederversammlung der`
- `stadtratssitzung`, `stadtrat`, `gemeinderatssitzung`, `gemeinderat`, `kreistagssitzung`, `kreistag`,
  `marktgemeinderat`, `bezirkstag`, `ausschusssitzung`
- `bürgerversammlung` (seit 2026-10-02, Kundenvorgabe; als Teilstring erfasst es auch „Bürgerversammlungen“)

**Parteinamen** (`politics`, `word`):

- `CSU`, `SPD`, `FDP`, `AfD`, `ÖDP`, `BSW`, `Die Linke`, `Bündnis 90`, `Die Grünen`, `Freie Wähler`,
  `Junge Union`, `Jusos`, `Junge Liberale`, `Grüne Jugend`

Getestet: „Wahlkampfveranstaltung“, „Stadtratssitzung“, „Sommerfest der CSU“, „SPD-Ortsverein“,
„Bündnis 90/Die Grünen“, „Freie Wähler“ werden ausgeschlossen. „Grüner Markt“, „SPDX-Workshop“ und
„Die Linkenbacher Blaskapelle“ werden **nicht** ausgeschlossen. Auf den 124 echten Events von Burg Rabenstein gab es
**keinen** Fehlalarm.

**Bad Steben (2026-09-24):** 560 echte Termine geprüft, 0 gefiltert. Es gibt dort weder Stammtische noch politische
Veranstaltungen. Grenzfall ohne Fehlalarm: „Geführte Wanderung … entlang des **Grünen** Bandes“ wird nicht gefiltert,
weil nur „Die Grünen“ als Wortfolge zählt. Über den echten Datenweg (Adapter → Crawler → Filter) wurde mit Testdaten
geprüft, dass ein Bad-Steben-Termin mit dem Titel „Musikerstammtisch am Feuerwehrhaus“ zentral gefiltert wird. Der
Adapter enthält keine Filterlogik.

**Coburg (2026-09-24):** 365 echte Termine des kommunalen Veranstaltungsportals geprüft, 0 gefiltert. Es gab keine
Stammtische, Stadtratssitzungen, Ausschüsse, Bürgerversammlungen oder Parteiveranstaltungen. Über den echten Datenweg
wurde mit Testdaten geprüft, dass ein Coburg-Termin mit dem Titel „Öffentliche Stadtratssitzung“ zentral gefiltert
wird und der bestehende Datensatz unverändert bleibt. Der Adapter enthält keine Filterlogik.

**Regression gespeicherte Regeln (2026-09-26):** Crawl aller drei Quellen aus dem lokalen HTML-Cache über den
Backend-Weg: Burg Rabenstein 124, Bad Steben 560 (3 bekannte „fehlerhaft“), Coburg 365 gefunden, jeweils 0 gefiltert,
alles „unverändert“, XML-Export vorher/nachher byte-identisch.

Hinweise und Risiken:

- Die Liste ist ein Startvorschlag und wird mit jeder neuen Quelle an echten Daten geprüft. Administratoren können sie
  jetzt selbst anpassen.
- Fehlalarme sind möglich, z. B. bei „Gemeinderat“ in einem nicht-politischen Zusammenhang. Im Zweifel gilt die Vorgabe:
  politische Inhalte werden **nicht** übernommen. Mit „Text prüfen“ im Backend lässt sich ein Titel vorab testen.
- **Bürgerversammlungen** werden seit 2026-10-02 gefiltert (Kundenvorgabe). Offen bleibt, ob weitere
  **Informationsveranstaltungen der Stadtverwaltung** als politisch gelten; ohne konkrete Vorgabe gibt es dafür keine Regel.
- Eine KI-Klassifikation ist ausdrücklich **nicht** Teil des MVP.

## 5. Sonderregel Nürnberg/Fürth: nur Nürnberg und Fürth (implementiert, noch ohne Quelle)

Diese Ortsregel ist **keine** Filterregel im Backend. Sie bleibt im Code und wird vor den Textregeln geprüft.

Enthält eine Quelle Veranstaltungen aus mehreren Städten und ist sie für Nürnberg/Fürth vorgesehen, werden
**ausschließlich** Veranstaltungen übernommen, deren Veranstaltungsort tatsächlich **Nürnberg** oder **Fürth** ist.
Andere Orte, z. B. **Erlangen** oder **Schwabach**, werden **nicht** übernommen.

Implementiert:

- Der Adapter deklariert `get_allowed_cities()`, z. B. `array( 'Nürnberg', 'Fürth' )`. Mit `null` gibt es keine
  Ortsbeschränkung; das gilt für Burg Rabenstein, Bad Steben und Coburg. Coburg liefert auch Umlandorte (Bad Rodach,
  Seßlach, Rödental, Neustadt bei Coburg, …); gespeichert wird die echte Stadt. Bad Steben liefert auch Nachbarorte (Hof,
  Lichtenberg, Hirschberg, Münchberg, Berg). Sollen nur Termine aus Bad Steben übernommen werden, reicht
  `get_allowed_cities() = array( 'Bad Steben' )` im Adapter. Das ist nicht gesetzt, solange der Auftraggeber nicht
  entschieden hat (todo.md).
- Verglichen wird das **normalisierte Feld `city`** (Kleinschreibung, ä/ö/ü/ß ↔ ae/oe/ue/ss). Stadtteilzusätze mit
  Bindestrich oder Leerzeichen werden akzeptiert („Nürnberg-Langwasser“, „Nuernberg“).
- Leere Stadt → ausgeschlossen (nicht geraten).
- Der Text wird **nicht** ausgewertet: „Bus ab Nürnberg“ mit Ort Erlangen wird ausgeschlossen (getestet).

Noch Aufgabe des jeweiligen Adapters, also erst mit der Nürnberg/Fürth-Quelle umzusetzen: die Stadt **bevorzugt aus
strukturierten Daten** bestimmen, in der Reihenfolge Feld Stadt/Ort der Quelle → Adresse (ggf. PLZ, Bereiche vorher
verifizieren) → Venue-Name → nur wenn nötig Veranstaltungstext. Ist die Stadt nicht sicher bestimmbar, bleibt `city`
leer, und das Event wird verworfen.

## 6. Sicherheit und Tests (2026-09-26)

Sicherheit der Verwaltung (Details 06-admin.md): nur `manage_options`, Änderungen nur per POST mit Nonce je Aktion,
Begriff mit `sanitize_text_field()` bereinigt (HTML wird entfernt), Ausgabe escaped.

| Test | Erwartung | Ergebnis |
| --- | --- | --- |
| Defaults vs. alte Konstanten, 1.093 Fälle | identisch | identisch, je 38 gefiltert |
| Regel `EVENTCRAWLER-TESTFILTER` hinzufügen, Crawl | Test-Event gefiltert, vorhandener Datensatz unverändert | gefiltert 1, Datensatz inkl. `last_seen_at` unverändert |
| Regel deaktivieren, Crawl | nicht gefiltert | 0 gefiltert |
| Regel aktivieren, Crawl | gefiltert | 1 gefiltert |
| Regel auf `EVENTCRAWLER-NEUFILTER` / Politik ändern, Crawl | alter Begriff nicht, neuer gefiltert | wie erwartet |
| Regel löschen, Crawl | nicht gefiltert | 0 gefiltert |
| Stammtisch / Politik / Parteiname | Kategorie `stammtisch` / `politics` / `politics` | wie erwartet |
| Groß-/Kleinschreibung, Umlaute | „STAMMTISCH“, „ödp infostand“, „FREIE WÄHLER“ gefiltert | wie erwartet |
| Ganzes Wort | „CSUfest“, „Grünenfest“ nicht; „Jusos-Treffen“ gefiltert | wie erwartet |
| Nur Titel und Beschreibung | Parteiname nur im Ortsfeld → nicht gefiltert | wie erwartet |
| `<script>alert(1)</script>` als Begriff | abgelehnt, nichts gespeichert | Fehlermeldung, nichts gespeichert |
| Duplikat „STAMMTISCH“ in gleicher Kategorie | abgelehnt | abgelehnt |
| Default gelöscht/deaktiviert + Reload/Aktivierung | bleibt gelöscht/deaktiviert | wie erwartet |
| Option beschädigt | kein Fatal, Warnung, keine Defaults | wie erwartet |
| Ungültige Nonce / GET / Abonnent | 403 / 405 / 403 | wie erwartet |

## 7. Geplante Erweiterungen (nicht implementiert)

- Ausnahmen/Whitelist für erwünschte Events trotz Filterwort
- Ignore-Liste für manuell gelöschte Events (06-admin.md)
- KI-gestützte Klassifikation
- Protokoll, welches Event warum gefiltert wurde. Der Grund wird schon ermittelt, aber noch nicht angezeigt
- Reihenfolge der Regeln im Backend ändern (heute: neue Regeln werden angehängt; relevant ist die Reihenfolge nur für
  den ermittelten Grund, nicht dafür, ob gefiltert wird)

## 8. Kundenfeedback „politische / interne Termine“ (2026-10-02)

Kundenbeispiele: SPD-Ortsverein, Bürgerversammlung, Stadtratssitzung. Geprüft mit den **aktiven gespeicherten
Regeln** (`tools/test-export-rules.php`); in den 1048 gespeicherten Terminen der drei Quellen kommt keiner dieser
Begriffe vor (0 Treffer für SPD, CSU, Stadtrat, Gemeinderat, Ausschuss, Kreistag, Bürgerversammlung, Partei).

| Begriff / Beispiel | Regel | Ergebnis |
| --- | --- | --- |
| „SPD-Ortsverein Hof“, „SPD Ortsverein lädt ein“ | `SPD` (Wort), `ortsverein` (enthält) | gefiltert |
| „Bürgerversammlung der Stadt Kulmbach“, „Bürgerversammlungen 2026“ | **neu** `bürgerversammlung` (enthält) | gefiltert |
| „Öffentliche Stadtratssitzung“ | `stadtratssitzung` | gefiltert |
| „Sommerfest der CSU“, „Infoabend der AfD“, „ÖDP-Infostand“, „Freie Wähler …“, „Jusos-Treffen“ | Parteinamen (Wort) | gefiltert |
| „Bündnis 90/Die Grünen …“, „Die Grünen am Marktplatz“ | `Die Grünen`, `Bündnis 90` (Wort) | gefiltert |
| „Gemeinderatssitzung“, „Sitzung des Kreistags“, „Ausschusssitzung Bau“ | bestehende Regeln | gefiltert |
| „CSUfest am See“, „Grünenfest im Park“, „Grüner Markt“, „… des Grünen Bandes“, „SPDX-Workshop“ | Wortgrenze | **nicht** gefiltert |

Interne Termine: Gemeinderatssitzung, Ausschusssitzung und Kreistag waren bereits Default-Regeln. Weitere Begriffe
wurden **nicht** ergänzt, weil es dafür weder eine Kundenvorgabe noch reale Fälle gibt.

**Grenzen des Wortfilters (bewusst so):**

- Es gibt **keine** Regel für „Grüne“ allein. Groß-/Kleinschreibung wird ignoriert; „Grüne“ als ganzes Wort träfe auch
  „Grüne Soße“, „grüne Woche“, „Grüne Tage“. Deshalb wird z. B. „Infostand der Grünen“ **nicht** erkannt (getestet).
  Wer das will, legt im Backend gezielt eine Regel an (z. B. „der Grünen“, Vergleichsart „ganzes Wort“).
- Der Filter versteht keinen Zusammenhang: „Vortrag über die Geschichte der SPD“ würde gefiltert, eine Parteiveranstaltung
  ohne einen der Begriffe nicht. Keine KI/NLP (A66).
- Bereits gespeicherte Termine werden durch die neue Regel nicht gelöscht (Abschnitt 1).

## 9. Realer Fehlalarm bei Theater Selb (2026-10-15)

| Termin | Regel | Treffer im Text | Einordnung |
|---|---|---|---|
| „Stars von Morgen Ein Konzert der Hofer Symphoniker mit Landes- und Bundespreisträgern von „Jugend musiziert““ (08.11.2026) | `bezirkstag` (Politik, enthält) | Untertitel „unter der Schirmherrschaft des **Bezirkstag**spräsidenten Henry Schramm, MdL a.D.“ | Jugendkonzert, **keine** politische Veranstaltung → Fehlalarm |

- Der Fehlalarm wurde **nicht** umgangen: keine Ausnahme für die Quelle, Regel unverändert (Vorgabe).
- Mögliche Lösungen zur Entscheidung durch den Auftraggeber: Regel `bezirkstag` auf „ganzes Wort“ umstellen (trifft
  dann „Bezirkstag“, nicht mehr „Bezirkstagspräsident“) oder durch `bezirkstagssitzung` ersetzen; beides ist ohne Code
  im Backend unter „Filterregeln“ möglich.
- Allgemein: Theatertitel können politische Wörter enthalten. Konstruierte Beispiele (keine echten Termine) zeigen das
  Risiko: „Kabarett: Der Stadtrat tagt“ (`stadtrat`) und „Die Grünen Witwen – Komödie“ (`Die Grünen`) würden gefiltert.
  „Minister im Quadrat“ (echter Termin) wird nicht gefiltert.

## 10. DAS ZENTRUM Bayreuth: Kabarett und Langtexte (2026-10-15)

- In den gespeicherten Daten (nur Titel; die Quelle hat keinen Kurztext) **kein Treffer**, auch kein `bezirkstag`-Fall.
- Kabaretttitel mit politischen Begriffen werden korrekt **nicht** gefiltert: „Sarah Bosetti – Make Democracy Great
  Again“, „Ingmar Stadelmann – liest Höcke“.
- Die nicht gespeicherten Langtexte würden drei Fehlalarme auslösen: `AfD` (ganzes Wort) bei Bosetti („AfD-Wähler“) und
  Stadelmann („AfD-Chefs“), `stammtisch` bei Wolfgang Krebs („… die Lufthoheit über die Stammtische …“). Das bestätigt,
  dass längere Beschreibungstexte die Fehlalarmrate erhöhen (vgl. Theater Selb, Abschnitt 9). Keine Regel geändert.

## 11. Kronach: kommunale Sitzungstermine (2026-10-15)

- Der Kalender der Stadt enthält die Kategorie „Sitzungstermine“. Im 2-Monats-Abruf: „4./5./6. Bau-, Stadtentwicklungs-
  und Wirtschaftsausschusssitzung“, „3. Werkausschusssitzung für den Eigenbetrieb Stadtwerke“, „2. Tourismus- und
  Stadtmarketingausschusssitzung“, „8. Stadtratssitzung“, „9. Stadtratssitzung (Jahresschlusssitzung)“ – **alle** von
  bestehenden Regeln erfasst (`ausschusssitzung`, `stadtratssitzung`, „enthält“). Im Live-Crawl (28 Tage) 2 gefiltert.
- Alle 53 verschiedenen Titel des Abrufs gegen die aktiven Regeln geprüft: **0 Fehlalarme**.
- Bürgermeistersprechstunde, Rentensprechtag, Bürgerversammlung kamen nicht vor. Keine Regel geändert.
