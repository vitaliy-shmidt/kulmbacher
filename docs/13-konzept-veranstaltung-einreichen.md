# Erweiterungskonzept: Veranstaltungen einfach einreichen

**Projekt:** Kulmbacher Land / Eventcrawler  
**Stand:** 11. November 2026  
**Status:** Diskussionsentwurf für die Redaktion und den Verlag – **noch nicht umgesetzt und nicht freigegeben**

> **Idee in einem Satz:** Jeder kann auf kulmbacher.land ohne Registrierung eine Veranstaltung melden. Die Redaktion prüft und entscheidet über die Veröffentlichung. Wer seine Veranstaltung im gedruckten Kulmbacher Land hervorheben oder zusätzlich Werbung schalten möchte, kann unverbindlich Interesse anmelden.

## 1. Warum diese Erweiterung?

Kulmbacher Land ist nicht nur ein Online-Veranstaltungskalender: Der Verlag produziert und verteilt auch ein gedrucktes Heft mit Veranstaltungen, Artikeln und Anzeigen. Die Printwerbung ist daher ein wichtiger Teil des Geschäftsmodells.

Bisher müssen viele Termine manuell übernommen und geprüft werden. Das neue Formular soll diesen Aufwand reduzieren und zugleich neue Kontakte für Printanzeigen ermöglichen – **ohne die kostenlose Meldung einer Veranstaltung an eine Werbebuchung zu koppeln**.

Die bewährten Funktionen des Eventcrawlers (automatischer Abruf externer Quellen, Kalender, manuelle Termine, Import und XML-Export für InDesign) bleiben bestehen.

## 2. So erlebt es ein Veranstalter

1. Auf der Website steht ein gut sichtbarer Link bzw. Button **„Veranstaltung eintragen“**.
2. Ein einfaches Formular öffnet sich. **Keine Registrierung und kein Login.**
3. Der Veranstalter trägt die wichtigsten Angaben und eine Kontaktperson ein.
4. Nach einer Spam-Prüfung wird die Einreichung gespeichert. Es erscheint eine verständliche Bestätigung: **„Vielen Dank! Die Redaktion prüft Ihre Veranstaltung.“**
5. Die Veranstaltung ist **noch nicht öffentlich sichtbar**.
6. Optional kann der Veranstalter Interesse an einer hervorgehobenen Darstellung oder an einer Printanzeige signalisieren. Das ist **eine unverbindliche Anfrage, keine Buchung und keine Zahlung**.

### Geplante Formularfelder

| Bereich | Angaben |
|---|---|
| Veranstaltung | Titel (Pflicht), Art/Kategorie (Auswahlliste), kurze Beschreibung |
| Zeit | Datum (Pflicht), Beginn (falls bekannt), Ende (optional), ganztägig nur bei ausdrücklicher Auswahl |
| Ort | Stadt/Ort und Veranstaltungsstätte aus vordefinierten Listen; für fehlende Einträge eine kontrollierte Option „Anderer Ort“ |
| Informationen | Link zur Veranstaltungsseite (optional) |
| Kontaktperson | Name und E-Mail-Adresse (Pflicht), Telefonnummer (optional), ggf. Verein/Organisation |
| Werbung | Keine Anfrage (Standard) / Interesse an bezahlter Hervorhebung / Interesse an zusätzlicher Printanzeige |
| Datenschutz | Verständlicher Datenschutzhinweis zur Einreichung und Kontaktaufnahme |

**Wichtig:** Die endgültigen Kategorien und Ortslisten soll die Redaktion festlegen. Echte fehlende Uhrzeiten bleiben leer, statt automatisch „00:00 Uhr“ einzutragen.

Die erste Version sollte bewusst kurz und mobil gut bedienbar sein. Flyer-Uploads und zusätzliche Formularfelder können wir bei Bedarf später ergänzen.

## 3. So arbeitet die Redaktion

Im WordPress-Backend kommt der Bereich **Eventcrawler → Einreichungen** hinzu.

Bei einer neuen Einreichung sieht die Redaktion eine **Benachrichtigung im Backend** („Neue Veranstaltung“) und auf Wunsch zusätzlich eine **E-Mail** an eine konfigurierbare Redaktionsadresse.

Die Übersicht zeigt beispielsweise Titel, Datum, Ort, Kategorie, Kontaktperson, Eingangsdatum und Bearbeitungsstatus.

Für jede Einreichung stehen folgende Aktionen zur Verfügung:

- **Prüfen / Bearbeiten:** Angaben ansehen und bei Bedarf korrigieren.
- **Freigeben:** Veranstaltung in den vorhandenen Eventcrawler übernehmen und für die dafür vorgesehenen Ausgaben verfügbar machen.
- **Ablehnen / Löschen:** Nicht passende oder unerwünschte Einreichung verwerfen, mit angemessener Lösch- und Aufbewahrungsregel.
- **Kontakt sperren:** Bei wiederholtem Spam künftige Einreichungen dieser Kontaktadresse blockieren; Sperren lassen sich wieder aufheben.
- **Rückfrage (Ausbaustufe):** Kontaktperson per E-Mail um fehlende Angaben bitten.

**Keine Einreichung wird automatisch veröffentlicht.** Die Redaktion behält die Entscheidung.

Ein einfacher Statusablauf reicht zunächst: **Neu → In Prüfung → Freigegeben oder Abgelehnt**. Für Rückfragen kann später ein eigener Status hinzukommen.

## 4. Kostenloser Termin und bezahlte Hervorhebung

Für den Verlag sind diese drei Fälle zu unterscheiden:

| Angebot | Bedeutung |
|---|---|
| **Regulärer Veranstaltungseintrag** | Kostenlose Meldung; Veröffentlichung nach redaktioneller Auswahl/Prüfung |
| **Bezahlte Hervorhebung** | Eine Veranstaltung kann im gedruckten Heft hervorgehoben werden; konkrete Darstellung und Preis bestimmt der Verlag |
| **Zusätzliche Printanzeige** | Eigenständiges Werbeangebot des Verlags, unabhängig von der reinen Termineinreichung |

Das Formular kann am Schluss dezent fragen: **„Möchten Sie Informationen zu einer hervorgehobenen Darstellung Ihrer Veranstaltung im gedruckten Kulmbacher Land erhalten?“**

Die Antwort ist freiwillig; voreingestellt ist **„Nein, nur Veranstaltung einreichen“**. Die kostenlose Einreichung wird dadurch nicht benachteiligt.

Im Backend kann die Redaktion bzw. Anzeigenabteilung das Werbeinteresse sehen und den Veranstalter kontaktieren. Die **Werbeanfrage** und die **redaktionelle Freigabe** bleiben getrennte Entscheidungen.

**Nicht Teil des ersten Schritts:** feste Anzeigenpreise im Formular, Online-Zahlung, automatische Rechnungen oder ein umfangreiches Anzeigen-CRM.

### Online- oder Print-Hervorhebung?

**Bisher besprochen ist die bezahlte Hervorhebung im Printheft.** Ob der Verlag auch hervorgehobene Online-Einträge anbieten möchte, bleibt ausdrücklich offen.

## 5. Online-Kalender und gedrucktes Heft sind nicht dasselbe

Die Redaktion soll getrennt entscheiden können:

1. **Ist die Veranstaltung freigegeben?**
2. **Soll sie online erscheinen?**
3. **Soll sie in einer bestimmten Druckausgabe erscheinen?**
4. **Ist eine bezahlte Hervorhebung bzw. Anzeige vereinbart?**

Eine Freigabe für den Online-Kalender bedeutet **nicht automatisch**, dass der Termin im nächsten Heft abgedruckt wird. Ein kostenpflichtiger Werbewunsch bedeutet **nicht automatisch** eine redaktionelle Freigabe.

Für eine spätere Ausbaustufe sinnvoll:

- Druckausgaben mit Zeitraum und Redaktionsschluss verwalten;
- Termine einer Ausgabe zuordnen;
- normale und hervorgehobene Printtermine unterscheiden;
- die bestehende InDesign-XML-Ausgabe für die Redaktion gezielt weiterentwickeln.

**Der bestehende XML-Export darf bis zu einer ausdrücklich abgestimmten Erweiterung nicht verändert werden.**

## 6. Sicherheit und Datenschutz

Ein öffentliches Formular braucht Schutz, gerade weil kein Konto erforderlich ist:

- unsichtbares Honeypot-Feld und einfache Zeit-/Plausibilitätsprüfungen;
- Rate-Limiting gegen massenhafte Einreichungen;
- bei Bedarf zusätzliche Bot-Prüfung/CAPTCHA;
- serverseitige Feld- und URL-Prüfung;
- kein ungeprüftes HTML und keine ausführbaren Uploads;
- Sperrliste für missbräuchlich verwendete Kontaktadressen (mit Entsperren);
- Schutz der Kontaktdaten: **Kontaktperson, E-Mail und Telefon erscheinen nicht automatisch öffentlich oder im XML-Export**;
- klare Datenschutzhinweise, angemessene Aufbewahrung und Löschmöglichkeit;
- alle Backend-Aktionen nur mit den passenden WordPress-Berechtigungen und Sicherheitsprüfungen.

Eine IP-Adresse allein sollte nicht als dauerhafte Sperridentität verwendet werden. Für redaktionelle Rückfragen kann eine optionale E-Mail-Bestätigung später ergänzt werden.

## 7. Technische Umsetzung – bewusst einfach

Dies ist **keine zweite Event-Plattform** und kein Portal mit Benutzerkonten.

- Das öffentliche Eingabeformular wird in Eventcrawler integriert, etwa über einen eigenen Shortcode wie `[eventcrawler_submit]` (Arbeitstitel).
- **Ungeprüfte Einreichungen werden getrennt** von den veröffentlichten Veranstaltungen gespeichert, damit sie niemals versehentlich im Kalender, in SEO-Seiten oder im XML-Export erscheinen.
- **Nach Freigabe** wird der Termin kontrolliert in die bestehende zentrale Tabelle `{prefix}eventcrawler_events` übernommen; die genaue Verknüpfung und Behandlung späterer Änderungen wird vor der Umsetzung festgelegt.
- Die bestehenden Crawler, Filter, deterministischen Matching-Regeln, NULL-Zeitregeln, SEO-URLs sowie CSV/XML-Importe bleiben unangetastet, soweit nicht ausdrücklich abgestimmt.
- Das bisherige Plugin `wp-events-manager` bleibt während des aktuellen Parallelbetriebs aktiv; keine automatische Shortcode-Übernahme oder Abschaltung.
- Benachrichtigungen, Sperren und der redaktionelle Verlauf werden nur so aufwendig umgesetzt, wie es für einen sicheren Betrieb notwendig ist.

## 8. Sinnvolle Umsetzung in Etappen

**Etappe 1 – Öffentliche Einreichung**

- Formular und vordefinierte Kategorien/Orte
- Kontaktperson und Spam-Schutz
- gesicherte Ablage mit Status „Neu“
- verständliche Eingangsbestätigung

**Etappe 2 – Redaktion**

- Übersicht neuer Einreichungen im Backend
- optionale E-Mail-Benachrichtigung
- Prüfen, Bearbeiten, Freigeben, Ablehnen/Löschen
- Kontakt sperren und entsperren
- sichere Übernahme freigegebener Termine in Eventcrawler

**Etappe 3 – Printwerbung**

- freiwilliges Interesse an Hervorhebung oder Printanzeige
- Kennzeichnung und Bearbeitung der Werbeanfrage im Backend
- später: Druckausgaben, Auswahl für das Heft und hervorgehobene Printtermine

Jede technische Etappe braucht Tests, Sicherheitsprüfungen und aktualisierte Dokumentation. **Aktuell ist dies nur ein Vorschlag, kein Entwicklungsauftrag.**

## 9. Fragen für die Redaktion und den Verlag

Diese Punkte sollten wir vor der Umsetzung gemeinsam klären:

1. **Welche Veranstaltungsarten und Orte** sollen im Formular auswählbar sein? Was passiert, wenn ein Ort fehlt?
2. **Welche Angaben sind unbedingt nötig?** Genügt eine kurze Beschreibung, oder braucht die Redaktion zusätzliche Informationen?
3. Soll die Redaktion **nur im WordPress-Backend** informiert werden oder auch per E-Mail? An welche Rolle/Funktionsadresse?
4. Soll eine **Freigabe grundsätzlich den Online-Kalender** betreffen und die Aufnahme ins gedruckte Heft separat entschieden werden?
5. **Wie wird eine bezahlte Hervorhebung im Heft heute gehandhabt?** Wer entscheidet, wie wird sie gekennzeichnet, und welche Informationen benötigt die Anzeigenabteilung?
6. Möchte der Verlag **Werbeinteresse gleich im Einreichungsformular** abfragen oder erst später Kontakt aufnehmen?
7. Wie soll mit **unpassenden, doppelten oder offensichtlich werblichen** Einreichungen umgegangen werden?
8. Welche **Druckausgaben und Redaktionsschluss-Termine** sollen perspektivisch berücksichtigt werden?

---

**Vorschlag für die Besprechung:** Zuerst gemeinsam das kurze Formular und den Freigabeablauf abstimmen. Danach entscheiden wir, wie die Werbeanfragen zur tatsächlichen Arbeitsweise des Verlags passen. So entsteht kein unnötig kompliziertes System.
