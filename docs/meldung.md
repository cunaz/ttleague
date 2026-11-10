# Online-Meldung der Clubs

Der TK-Chef meldet in «Mein Bereich → Mein Club», was der Club der TK heute auf Formularen und per E-Mail schickt: Spieler, Mannschaften mit Stammspielern, Captains, Lokale und Hallensperren. Die TK prüft und gibt frei. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Ablauf

1. Die TK legt die geplante Saison an und setzt das Meldefenster (`season.registration_opens`, `registration_closes`). Für jeden teilnehmenden Club entsteht eine Zeile in `club_season` mit Status `open`; die TK-Chefs sind vorgetragen ([saison.md](saison.md)).
2. Der TK-Chef pflegt die Meldung. Jede Änderung wird sofort gespeichert und geprüft; Verstösse stehen am Feld.
3. Der TK-Chef reicht ein: `club_season.status = submitted`. Das geht nur ohne offene Verstösse. Danach kann er nichts mehr ändern, bis die TK die Meldung zurückgibt.
4. Die TK prüft im Backend («Spielbetrieb → Meldungen»): freigeben (`reviewed`) oder mit Bemerkung zurückgeben (`open`). Der TK-Chef erhält `registration_decided`.
5. Nach Ablauf des Meldefensters ändert nur noch die TK. Sieben und zwei Tage vorher geht `registration_reminder` an TK-Chefs, deren Meldung noch `open` ist.

**Nachmeldungen** in der aktiven Saison: Spieler bis `season.late_registration_until` (Art. 27.3, Standard 31. Januar), Captains, Lokaldaten und Hallensperren jederzeit. Mannschaften und Stammspieler ändert in der aktiven Saison nur die TK.

## Was gemeldet wird

| Bereich | Inhalt | Regeln |
|---|---|---|
| Spieler des Vorjahrs | Bestätigen oder abmelden. Bestätigte erhalten `player_season` in der geplanten Saison mit der bestätigten Klassierung, Status `provisional` bis zur Freigabe der Meldung. | Ein Spieler ist pro Saison nur für einen Club gemeldet (Art. 27.2). Wechselt er den Club, meldet ihn der neue Club; die TK sieht den Wechsel in der Prüfung. |
| Neue Spieler | Name, E-Mail (freiwillig), Klassierung des Clubs, STT-Lizenz falls vorhanden. Entsteht als `player_request`. | Klassierung gilt als provisorisch; eine STT-Klassierung wird übernommen (Art. 28.2). Spielberechtigt erst nach Freigabe mit Lizenznummer (Art. 26.2, 29.1). |
| Mannschaften | Anzahl und Nummern, je drei Stammspieler (`team_roster.kind = stamm`), Heimlokal, Anspielzeit, Captain (`club_role`). | Genau drei Stammspieler. Niemand ist Stammspieler in zwei Mannschaften (Art. 12.2). Stammspieler müssen gemeldet sein. Der Captain braucht eine E-Mail-Adresse. |
| Lokale | Name, Adresse, Telefon, Spieltag, Öffnungszeit, Anzahl Tische. | Spieltag Montag bis Freitag. Anzahl Tische mindestens 1. |
| Hallensperren | Lokal, von, bis, Grund (`venue_closure`). | `bis` nicht vor `von`. |

Kategorie und Gruppe einer Mannschaft legt die TK fest, nicht der Club.

## Prüfung durch die TK

Seite «Meldungen» mit dem Stand je Club (offen, eingereicht, geprüft), Anzahl Spieler und Mannschaften, offene Neumeldungen.

- **Neue Spieler freigeben:** Die TK trägt die Lizenznummer ein und kann die Klassierung ändern (Art. 28.3). Das System schlägt eine bestehende Person vor, wenn Name oder E-Mail passen, damit keine Doppel entstehen. Freigabe legt `person`, `player` und `player_season` (Status `active`) an und setzt `player_request.status = approved`. Ablehnen verlangt eine Bemerkung.
- **Meldung freigeben:** setzt alle `player_season` des Clubs in dieser Saison auf `active`.
- Die TK darf jede Meldung selbst bearbeiten, auch nach Ablauf des Fensters.

## Dienste und Rechte

- `Registration\ClubRegistration` (Spieler, Mannschaften, Captains, Einreichen), `Registration\Venues` (Lokale, Sperren), `Registration\Review` (TK). Reine Regeln in `Domain\Registration\RegistrationRules`.
- Rechte: `ttl_manage_club` mit der Club-ID; im Dienst zusätzlich Saisonstatus, Meldefenster und `club_season.status`. Die TK immer.
- Formulare wie bisher in «Mein Club»: serverseitig, `admin-post.php`, Nonce pro Aktion, Rückmeldung über `Flash`.

## Benachrichtigungen

`registration_reminder` und `registration_decided`, siehe [saisonbetrieb.md](saisonbetrieb.md). Zur Kenntnis der TK erscheint eine Liste «Meldungen zu prüfen» in der Übersicht.

## Tests

- Regeln: drei Stammspieler, doppelter Stammspieler, nicht gemeldeter Stammspieler, Spieler in zwei Clubs, Frist der Nachmeldung an der Grenze, Einreichen mit Verstoss.
- Dienste gegen MariaDB: Vorjahresspieler bestätigen und abmelden, Neumeldung bis zur Freigabe (kein `player` vorher), Freigabe mit bestehender Person, Rückgabe, Sperre nach dem Einreichen, Rechte der geplanten und der aktiven Saison.
- WordPress: Formulare in «Mein Club» mit Nonce und Rechten, Seite «Meldungen».
