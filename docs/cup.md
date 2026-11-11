# Mannschaftscup

Meldung der Cupmannschaften, Auslosung, Tableau, Rundenfristen und die Aufstellungsregel bei der Erfassung (Art. 18 bis 23). Die Erfassung einer Cup-Begegnung selbst steht in `docs/erfassung.md`. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Aufbau

Eine Konkurrenz vom Typ `CUP` je Kategorie. Meldungen stehen in `cup_entry`, das Tableau in `cup_tie`, die Begegnungen in `fixture`. `competition.format_json` enthält die Parameter der Kategorie:

| Schlüssel | Bedeutung |
|---|---|
| `points_limit` | höchste Summe der Klassierungen (Art. 19.2); fehlt der Wert, gibt es keine Grenze |
| `registration_deadline` | Meldeschluss (Datum); fehlt er, melden die TK-Chefs bis zur Auslosung |
| `min_entries` | ab so vielen Meldungen findet der Cup statt (Art. 18.2), Standard 8 |
| `draw` | Vorschlag der Auslosung bis zur Freigabe: Startwert und Plätze |

## Meldung

Der TK-Chef meldet in «Mein Bereich» (Abschnitt «Cup») Cupmannschaften mit zwei Spielern, bis und mit `registration_deadline`. Danach nur die TK.

- Es meldet der TK-Chef des Clubs in der Saison der Konkurrenz (aktiv oder geplant) oder die TK.
- Beide Spieler sind für den Club gemeldet (`player_season`, Status `active`).
- `points_total` ist die Summe ihrer Klassierungen und darf `points_limit` der Kategorie nicht übersteigen.
- Ein Spieler steht in höchstens einer Cupmannschaft der Saison, über alle Kategorien (Art. 20.2). Das gilt auch, wenn seine Mannschaft nach der Auslosung zurückgezogen wurde.
- Nummer und Name («Seefeld 2») vergibt das System fortlaufend je Club und Kategorie.
- Zurückziehen vor der Auslosung löscht die Meldung, danach setzt es `cup_entry.status = withdrawn`; der Gegner kommt kampflos weiter. Nach der Auslosung zieht nur die TK zurück.
- Nach der Freigabe der Auslosung gibt es keine neuen Meldungen mehr, auch nicht durch die TK.

Der Cup findet statt, wenn sich mindestens `min_entries` Mannschaften melden (acht, Art. 18.2). Darunter zeigt das Backend einen Hinweis; die TK entscheidet.

## Auslosung

«Spielbetrieb → Cup» erstellt für eine Kategorie den Vorschlag der ersten Runde; die TK kann Paarungen und Heimrecht ändern und gibt frei (Art. 21.1).

- Tableaugrösse ist die nächste Zweierpotenz. Setzung nach `points_total` absteigend, bei Gleichstand nach Meldezeitpunkt, dann nach ID; die TK kann `seed` ändern (eine vergebene Setznummer geht vor, ein bestehender Vorschlag verfällt).
- Gesetzt wird ein Viertel des Tableaus, mindestens zwei Mannschaften und mindestens so viele, wie es Freilose gibt. Gesetzte stehen auf den üblichen Setzplätzen: Die Nummern 1 und 2 treffen sich erst im Final, die Nummern 1 bis 4 erst im Halbfinal. Freilose gehen an die höchsten Gesetzten.
- Die übrigen Plätze werden ausgelost. Die Auslosung ist mit ihrem Startwert reproduzierbar (eigener Zufallsgenerator in `Domain\Cup\DrawRandom`, unabhängig von der PHP-Version); der Startwert steht im `audit_log` (`competition`, Aktionen `cup_draw_proposed` und `cup_draw_released`).
- Die erstgenannte Mannschaft hat Heimrecht (Art. 21.1); wer erstgenannt ist, wird gelost, auch bei Gesetzten. Mannschaften desselben Clubs treffen in der ersten Runde nicht aufeinander, solange es anders geht: Geloste Mannschaften werden getauscht, solange das die Zahl solcher Paarungen senkt.
- Reine Funktion in `Domain\Cup\Draw`.

Der Vorschlag liegt bis zur Freigabe in `format_json` (`draw`). Er gilt nur für die Meldungen, aus denen er entstand: Kommt eine dazu oder fällt eine weg, verlangt die Freigabe eine neue Auslosung. Geänderte Paarungen prüft der Dienst (jede Mannschaft genau einmal, keine Paarung aus zwei Freilosen).

Freigeben (nur in der aktiven Saison) schreibt `cup_tie` für alle Runden, die Setzung in `cup_entry.seed` und für jede Paarung der ersten Runde eine `fixture` ohne Termin, setzt die Frist der Runde (`cup_tie.deadline`, freiwillig) und merkt die Mitteilung `cup_round` an die Spieler und TK-Chefs beider Mannschaften vor. Freilose rücken sofort nach; treffen sich zwei Freilose in der zweiten Runde, entsteht deren Begegnung gleich mit. Danach ist die Auslosung fest.

## Runden

- **Termin:** Das Heimteam schlägt vor, der Gegner stimmt zu ([verschiebung.md](verschiebung.md), Terminvorschlag). Begegnungen ohne Termin stehen dort in «Mein Bereich» unter «Ohne Termin»; die Mitteilung zur Runde verlinkt die Erfassungsseite mit dem Formular.
- **Frist:** Sieben Tage vor `deadline` ohne Termin und ohne Resultat geht `cup_deadline` an beide Mannschaften, bis und mit dem Tag der Frist. Am Tag danach erscheint die Begegnung ohne Resultat in der Übersicht der TK («Cup: Frist abgelaufen»); sie verlängert die Frist oder wertet auf der Erfassungsseite forfait. Die TK setzt die Frist für eine ganze Runde oder eine einzelne Paarung, nie in die Vergangenheit.
- **Nachrücken:** Sobald eine Begegnung `confirmed`, `final` oder `forfeit` ist, steht der Sieger im nächsten `cup_tie`: der Sieger der oberen Paarung (ungerader Platz) oben, der andere unten. Sind beide Plätze einer Paarung besetzt, entsteht deren `fixture` (ohne Termin, `match_no` = 100 × Runde + Platz); die TK setzt die Frist der Runde. Heimrecht in späteren Runden hat der obere Platz der Paarung, ausser die TK tauscht es (nur solange die Begegnung kein Resultat hat). Der Tausch ändert die Begegnung, nicht die Plätze im Tableau.
- **Korrektur:** Wird ein bestätigtes Resultat korrigiert und der Sieger wechselt, oder wird es zurückgesetzt, rückt das Tableau nur neu nach, wenn die nächste Begegnung noch kein Resultat hat (auch kein provisorisches). Diese Begegnung wird dann annulliert (`cancelled`, sie behält ihren Verlauf) und mit der richtigen Mannschaft neu angelegt. Sonst bleibt das Tableau stehen, und die TK sieht den Hinweis «Cup: Tableau prüfen» in der Übersicht und auf der Seite «Cup»; setzt sie die nächste Begegnung zurück, rückt das Tableau nach.
- **Rückzug nach der Auslosung:** Die offene Begegnung der Mannschaft wird über die Erfassung forfait für den Gegner gewertet (mit Verlauf und Mitteilung «Entscheid der TK»). Wartet die Mannschaft in einer Paarung noch auf ihren Gegner, kommt dieser ohne Begegnung weiter, sobald er feststeht. Ziehen beide Mannschaften einer Paarung zurück, kommt niemand weiter und der Gegner der nächsten Runde hat ein Freilos. Ein Resultat bleibt gültig, auch wenn der Sieger später zurückzieht.
- **Halbfinal und Final** setzt die TK an (Ort und Termin, Art. 23.1): Termin in der Zukunft und in der Saison, Ort aus den aktiven Lokalen oder offen. Das Heimrecht entfällt; technisch bleibt die Mannschaft des oberen Platzes «Heim» und erfasst das Resultat.

`Cup\Bracket::sync()` gleicht das Tableau nach jeder dieser Änderungen ab und lässt sich beliebig oft wiederholen. In WordPress hängt es an den Aktionen `ttleague_result_confirmed` und `ttleague_result_decided`.

## Aufstellungsregel bei der Erfassung

Zusätzlich zu `docs/erfassung.md` prüft die Erfassung einer Cup-Begegnung (Art. 20.1, reine Regel in `Domain\Cup\LineupRule`):

- Mindestens einer der beiden eingesetzten Spieler gehört zur ursprünglichen Meldung der Mannschaft.
- Die Summe der Klassierungen der eingesetzten Spieler ist nicht höher als `points_total` der Meldung. Spielt die Mannschaft wie gemeldet, gilt die Meldung, auch wenn eine Klassierung seither gestiegen ist.
- Ein eingesetzter Ersatzspieler ist für denselben Club gemeldet und hat in dieser Saison für keine andere Cupmannschaft gespielt oder gemeldet (Art. 20.2), über alle Kategorien.

Verstösse sind Fehler am Feld (`lineup.home`, `lineup.away` für die Mannschaft, `lineup.home.B` für den Ersatzspieler); die TK kann im Korrekturmodus übersteuern, dann als Hinweis. Ersatzspieler aus einer anderen Cupmannschaft zeigt das Formular als nicht spielberechtigt. Fehlen der Meldung Spieler oder Punktesumme (importierte Saisons), entfällt die betroffene Prüfung.

## Darstellung

Die Cup-Seite zeigt je Kategorie das Tableau (Runden als Spalten, auf dem Handy untereinander) mit Fristen, Terminen, Resultaten, Link zum Matchreport und Heimrecht, darunter die Meldeliste. Zurückgezogene Mannschaften sind gekennzeichnet. Ohne Auslosung zeigt sie wie bisher Meldeliste und Resultate; Saisons ohne `cup_tie` (Importe) sehen unverändert aus.

- **Mein Bereich:** Abschnitt «Cup» pro Club für TK-Chefs der aktiven oder der geplanten Saison (nach «Mein Club»): eigene Mannschaften, Zurückziehen, Formular mit den gemeldeten Spielern (vergebene Spieler gesperrt), Punktelimite und Meldeschluss.
- **Backend «Spielbetrieb → Cup»** (`admin.php?page=ttleague-tk-cup`), ein Reiter je Kategorie: Einstellungen, Meldungen mit Setzung und Rückzug, Meldung durch die TK, Auslosung (vorschlagen mit freiwilligem Startwert, Paarungen ändern, freigeben mit Frist), danach das Tableau mit Frist je Runde und Paarung, Heimrecht tauschen, Halbfinal und Final ansetzen. Hinweis unter der Mindestzahl der Meldungen.
- **Übersicht der TK:** Listen «Cup: Frist abgelaufen» (`#ttl-cup-fristen`, mit «Frist verlängern») und, nur bei Bedarf, «Cup: Tableau prüfen» (`#ttl-cup-tableau`).
- **Export:** Liste `cup-meldungen` (Meldungen der aktiven Saison; Filter `saison=<Slug>` und `konkurrenz=<ID>`), im Backend und mit `wp ttleague export cup-meldungen`.

Alle Formulare gehen ohne JavaScript an `admin-post.php` (`Cup\Forms`: Nonce pro Aktion und Objekt, `ttl_manage_club` für Meldung und Rückzug, sonst `ttl_tk`), Rückmeldung über `Flash`.

## Dienste und Rechte

Meldung mit `ttl_manage_club`, alles Weitere `ttl_tk`; die Dienste prüfen zusätzlich selbst. Code in `src/Cup/`, Einstieg `Cup::register()`:

| Klasse | Aufgabe |
|---|---|
| `Domain\Cup\Draw`, `DrawRandom` | rein: Setzung, Tableau, Auslosung, Prüfung geänderter Paarungen |
| `Domain\Cup\BracketRules` | rein: Platz in der nächsten Runde, Heimrecht, Stand einer Paarung (Resultat, Freilos, Rückzug) |
| `Domain\Cup\LineupRule` | rein: Aufstellungsregel |
| `Entries` | melden, zurückziehen, Parameter der Kategorie |
| `DrawService` | Setzung, vorschlagen, ändern, freigeben |
| `Bracket` | nachrücken, Rückzug, Fristen, ansetzen, Heimrecht, Listen für die TK |
| `CupRepository` | Abfragen |
| `CupEvents`, `WordPressCupEvents` | Ereignis nach dem Speichern: Datenversion, Aktion `ttleague_cup_changed` (Konkurrenz, Art, Konto, Paarungen) |
| `CupNotifications`, `CupMessages` | Mitteilungen und Texte |
| `Forms`, `MyAreaSection`, `AdminPage`, `Export` | Formulare, HTML, CSV |

Jeder Schreibvorgang läuft in einer Transaktion unter der Sperre der Saison (wie die Erfassung) mit Eintrag im `audit_log` (`cup_entry`, `cup_tie`, `competition`, `fixture`). Fehler sind `Results\ResultException` mit den Codes der Erfassung. Eine REST-API hat das Modul nicht.

## Benachrichtigungen

| Art (`event_type`) | Einstellung | Empfänger | Anlass |
|---|---|---|---|
| `cup_round` | `cup_round` | beide Mannschaften | Auslosung, neue Begegnung nach dem Nachrücken, Halbfinal oder Final angesetzt, Heimrecht getauscht |
| `cup_bye` | `cup_round` | die Mannschaft | Freilos in der ersten Runde |
| `cup_deadline` | `cup_deadline` | beide Mannschaften | sieben Tage vor der Frist ohne Termin und ohne Resultat |

Empfänger einer Mannschaft sind ihre beiden Spieler und die TK-Chefs des Clubs. Bezug im Versandprotokoll ist die Paarung (`ref_type` `cup_tie`); der Zusatz hält Begegnung, Heimteam, Termin und Ort bzw. die Frist fest, damit nach einer Änderung eine neue Mitteilung folgt und sonst keine doppelt hinausgeht.

- Nach der Auslosung gehen die Mitteilungen nicht in der Anfrage der TK hinaus: Die Freigabe merkt ein einmaliges WP-Cron-Ereignis vor (`ttleague_cup_round_notices`), ein Schritt versendet an höchstens 25 Empfänger und plant den Rest ein; pro PHP-Prozess läuft ein Schritt. Lässt sich das Ereignis nicht einplanen, wird das gemeldet (`ttleague_notification_error`).
- Der stündliche Lauf der Fristen (`ttleague_notifications_hourly`) versendet dazu selbst nichts. Er plant den Versand für Konkurrenzen mit ausstehenden Mitteilungen neu ein, falls kein Schritt wartet (höchstens zehn pro Lauf), für Paarungen der letzten sieben Tage. Ausstehend ist eine Mitteilung, wenn eine aktive Person mit gültiger E-Mail-Adresse sie über keinen Kanal erhalten hat. Wer den Ereignistyp ganz abgewählt hat, gilt so lange als ausstehend: Für ihn plant der Lauf bis zu sieben Tage lang einen Schritt ein, der nichts versendet.
- Einzelne neue oder geänderte Paarungen (höchstens zwei auf einmal) gehen direkt an ihre Empfänger. Scheitert dieser Versand, holt ihn derselbe stündliche Lauf nach, solange die Begegnung höchstens sieben Tage alt ist.
- `cup_deadline` hängt am stündlichen Lauf, ohne eigenen Cron, und beachtet dessen Versandfenster. Die sieben Tage sind `CupNotifications::REMINDER_DAYS`.

## Grenzen

- Im Halbfinal und Final kann die technisch «Heim» genannte Mannschaft weiterhin einen Termin vorschlagen (Modul Verschiebung), solange die TK nichts angesetzt hat.
- Eine annullierte Begegnung bleibt in der Datenbank. Ein offener Terminvorschlag dazu lässt sich nur noch ablehnen oder zurückziehen. Setzt die TK eine annullierte Begegnung auf der Erfassungsseite zurück, gehört sie zu keiner Paarung mehr.
- Die Mannschaft, die nach einer Korrektur aus der nächsten Runde fällt, erhält keine eigene Mitteilung.
- Korrigiert die TK ein Resultat einer archivierten Saison, gleicht das Tableau wie in der aktiven Saison ab.

## Tests

- Auslosung (`tests/Unit/Domain/CupDrawTest.php`): 5 bis 16 Mannschaften, Freilose an die Gesetzten, Platz der Gesetzten, gleicher Club (auch wenn eine Paarung unvermeidlich ist), Reproduzierbarkeit mit festgehaltenen Werten, Prüfung geänderter Paarungen. Dazu `CupBracketRulesTest` und `CupLineupRuleTest`.
- Dienste gegen MariaDB (`tests/Integration/Cup/`): Meldung (Limite an der Grenze, Spieler in zweiter Cupmannschaft, nicht gemeldeter Spieler, Meldeschluss am Tag und am Tag danach, Rückzug vor und nach der Auslosung), Auslosung (Vorschlag, Setzung, Änderungen, Freigabe, Freilose, veralteter Vorschlag, Terminvorschlag über das Modul Verschiebung), Nachrücken (Sieg, Forfait, Freilos, Rückzug, Korrektur mit Siegerwechsel mit und ohne Resultat der nächsten Begegnung, Zurücksetzen), Fristen an ihren Grenzen, Halbfinal und Final, Heimrecht, Aufstellungsregel über den `ResultService` (kein ursprünglicher Spieler, Summe an der Grenze, Ersatz aus anderer Cupmannschaft gemeldet oder eingesetzt, Übersteuern durch die TK), Benachrichtigungen (Versand in Schritten, Freilos, neue Runde, Erinnerung sieben Tage vorher) und die Cup-Seite (Tableau; Saison ohne `cup_tie` unverändert).
- WordPress (`tests/WordPress/Cup/`): Formulare mit Nonce und Rechten, «Mein Bereich», Seite «Cup», Listen der Übersicht, Export, Cache der Cup-Seite nach der Freigabe, Versand über WP-Cron mit Auffangnetz.
