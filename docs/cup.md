# Mannschaftscup

Meldung der Cupmannschaften, Auslosung, Tableau, Rundenfristen und die Aufstellungsregel bei der Erfassung (Art. 18 bis 23). Die Erfassung einer Cup-Begegnung selbst steht in `docs/erfassung.md`. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Aufbau

Eine Konkurrenz vom Typ `CUP` je Kategorie. `competition.format_json` enthält `points_limit` (höchste Summe der Klassierungen, Art. 19.2; fehlt der Wert, gibt es keine Grenze) und `registration_deadline`. Meldungen stehen in `cup_entry`, das Tableau in `cup_tie`, die Begegnungen in `fixture`.

## Meldung

Der TK-Chef meldet in «Mein Club» Cupmannschaften mit zwei Spielern, bis `registration_deadline`. Danach nur die TK.

- Beide Spieler sind für den Club gemeldet (`player_season`, Status `active`).
- `points_total` ist die Summe ihrer Klassierungen und darf `points_limit` der Kategorie nicht übersteigen.
- Ein Spieler steht in höchstens einer Cupmannschaft der Saison, über alle Kategorien (Art. 20.2).
- Nummer und Name («Seefeld 2») vergibt das System fortlaufend je Club.
- Zurückziehen vor der Auslosung löscht die Meldung, danach setzt es `cup_entry.status = withdrawn`; der Gegner kommt kampflos weiter.

Der Cup findet statt, wenn sich mindestens acht Mannschaften melden (Art. 18.2). Darunter zeigt das Backend einen Hinweis; die TK entscheidet.

## Auslosung

«Spielbetrieb → Cup → Auslosen» erstellt für eine Kategorie den Vorschlag der ersten Runde; die TK kann Paarungen und Heimrecht ändern und gibt frei (Art. 21.1).

- Tableaugrösse ist die nächste Zweierpotenz. Setzung nach `points_total` absteigend, bei Gleichstand nach Meldezeitpunkt; die TK kann `seed` ändern.
- Gesetzte stehen so im Tableau, dass sich die Nummern 1 und 2 erst im Final treffen können. Freilose gehen an die höchsten Gesetzten.
- Die übrigen Plätze werden ausgelost. Die Auslosung ist mit ihrem Startwert reproduzierbar; der Startwert steht im `audit_log`.
- Die erstgenannte Mannschaft hat Heimrecht (Art. 21.1). Mannschaften desselben Clubs treffen in der ersten Runde nicht aufeinander, solange es anders geht.
- Reine Funktion in `Domain\Cup\Draw`.

Freigeben schreibt für jede Paarung `cup_tie` und eine `fixture` ohne Termin, setzt die Frist der Runde (`cup_tie.deadline`) und verschickt `cup_round` an die Spieler und TK-Chefs beider Mannschaften.

## Runden

- **Termin:** Das Heimteam schlägt vor, der Gegner stimmt zu ([verschiebung.md](verschiebung.md), Terminvorschlag).
- **Frist:** Sieben Tage vor `deadline` ohne Termin oder Resultat geht `cup_deadline` an beide Mannschaften. Nach der Frist erscheint die Begegnung in der Übersicht der TK; sie verlängert die Frist oder wertet forfait.
- **Nachrücken:** Sobald eine Begegnung `confirmed`, `final` oder `forfeit` ist, steht der Sieger im nächsten `cup_tie`. Sind beide Plätze einer Paarung besetzt, entsteht deren `fixture`; die TK setzt die Frist der Runde. Heimrecht in späteren Runden hat der obere Platz der Paarung, ausser die TK ändert es.
- Wird ein bestätigtes Resultat korrigiert und der Sieger wechselt, rückt das Tableau nur neu nach, wenn die nächste Begegnung noch kein Resultat hat. Sonst meldet es das der TK als Hinweis.
- **Halbfinal und Final** setzt die TK an (Ort und Termin, Art. 23.1); das Heimrecht entfällt.

## Aufstellungsregel bei der Erfassung

Zusätzlich zu `docs/erfassung.md` prüft die Erfassung einer Cup-Begegnung (Art. 20.1):

- Mindestens einer der beiden eingesetzten Spieler gehört zur ursprünglichen Meldung der Mannschaft.
- Die Summe der Klassierungen der eingesetzten Spieler ist nicht höher als `points_total` der Meldung.
- Ein eingesetzter Ersatzspieler ist für denselben Club gemeldet und hat in dieser Saison für keine andere Cupmannschaft gespielt oder gemeldet (Art. 20.2).

Verstösse sind Fehler am Feld; die TK kann im Korrekturmodus übersteuern, dann als Hinweis.

## Darstellung

Die Cup-Seite zeigt je Kategorie das Tableau (Runden als Spalten, auf dem Handy untereinander), Fristen, Resultate mit Link zum Matchreport und die Meldeliste. Ohne Auslosung zeigt sie wie bisher die Meldeliste.

## Dienste und Rechte

`Cup\Entries` (melden, zurückziehen), `Cup\DrawService` (vorschlagen, freigeben), `Cup\Bracket` (nachrücken, Fristen). Meldung mit `ttl_manage_club`, alles Weitere `ttl_tk`.

## Benachrichtigungen

`cup_round` (Auslosung, neue Runde), `cup_deadline` (Frist läuft ab).

## Tests

- Auslosung: 5 bis 16 Mannschaften, Freilose an die Gesetzten, Platz der Gesetzten, gleicher Club, Reproduzierbarkeit.
- Meldung gegen MariaDB: Limite an der Grenze, Spieler in zweiter Cupmannschaft, nicht gemeldeter Spieler, Rückzug vor und nach der Auslosung.
- Nachrücken: Sieg, Forfait, Freilos, Korrektur mit Siegerwechsel.
- Aufstellungsregel: kein ursprünglicher Spieler, Summe zu hoch, Ersatz aus anderer Cupmannschaft, Übersteuern durch die TK.
