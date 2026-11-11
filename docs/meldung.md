# Online-Meldung der Clubs

Der TK-Chef meldet in «Mein Bereich», was der Club der TK heute auf Formularen und per E-Mail schickt: Spieler, Mannschaften mit Stammspielern, Captains, Lokale und Hallensperren. Die TK prüft und gibt frei. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Ablauf

1. Die TK legt die geplante Saison an und setzt das Meldefenster (`season.registration_opens`, `registration_closes`). Für jeden teilnehmenden Club entsteht eine Zeile in `club_season` mit Status `open`; die TK-Chefs sind vorgetragen ([saison.md](saison.md)).
2. Der TK-Chef pflegt die Meldung. Jede Änderung wird sofort gespeichert und geprüft; Verstösse stehen als «offene Punkte» oben und bei der betroffenen Mannschaft.
3. Der TK-Chef reicht ein: `club_season.status = submitted`. Das geht nur ohne offene Verstösse. Danach kann er nichts mehr ändern, bis die TK die Meldung zurückgibt.
4. Die TK prüft im Backend («Spielbetrieb → Meldungen»): freigeben (`reviewed`) oder mit Bemerkung zurückgeben (`open`). Der TK-Chef erhält `registration_decided`.
5. Nach Ablauf des Meldefensters ändert an der Meldung der geplanten Saison nur noch die TK. Sieben und zwei Tage vorher geht `registration_reminder` an TK-Chefs, deren Meldung noch `open` ist.

Die Schritte 1 bis 5 und der Status in `club_season` betreffen die Meldung für die **geplante** Saison. Die Freigabe der Saison verlangt, dass alle Meldungen `reviewed` sind ([saison.md](saison.md)).

Das Meldefenster zählt in Kalendertagen der Website (`wp_timezone()`), erster und letzter Tag eingeschlossen. Ohne `registration_opens` gibt es kein Fenster; ohne `registration_closes` bleibt es offen, bis die TK einen letzten Tag setzt (dann gibt es auch keine Erinnerung). Gibt die TK eine Meldung nach Ablauf des Fensters zurück, kann der Club sie erst wieder ändern, wenn die TK auch das Fenster verlängert.

**Nachmeldungen in der aktiven Saison** sind davon unabhängig: Sie hängen weder am Meldefenster noch am Status in `club_season`.

| Was | Wer | Bis wann | Wirkung |
|---|---|---|---|
| Spieler nachmelden (bestehende Lizenz oder neu) | TK-Chef | `season.late_registration_until`, der Tag eingeschlossen (Art. 27.3; ohne Wert der 31. Januar im Jahr des Saisonendes), danach nur die TK | `player_season` mit Status `provisional` bzw. `player_request`; spielberechtigt nach Freigabe durch die TK |
| Captain ändern | TK-Chef | jederzeit | gilt sofort |
| Lokaldaten und Hallensperren | TK-Chef | jederzeit | gilt sofort; die TK sieht die Änderung 14 Tage lang in der Übersicht |
| Stammspieler, Heimlokal und Anspielzeit einer Mannschaft | nur die TK | – | gilt sofort; die Stammspieler müssen dabei stimmen (genau drei, niemand doppelt) |
| Mannschaften hinzufügen oder entfernen | niemand über die Meldung | – | – |

Eine archivierte Saison ändert niemand, auch die TK nicht.

## Was gemeldet wird

| Bereich | Inhalt | Regeln |
|---|---|---|
| Spieler des Vorjahrs | Bestätigen (einzeln oder alle) oder abmelden. Bestätigte erhalten `player_season` in der Saison der Meldung, Status `provisional` bis zur Freigabe. Wer nicht bestätigt wird, ist nicht gemeldet; dafür gibt es keinen eigenen Eintrag. | Ein Spieler ist pro Saison nur für einen Club gemeldet (Art. 27.2). Wechselt er den Club, meldet ihn der neue Club mit der Lizenznummer; der bisherige Club sieht ihn dann als «gemeldet für …», und die TK sieht den Wechsel in der Prüfung. |
| Spieler mit Lizenznummer | Bestehende Spieler, die im Vorjahr nicht für den Club gemeldet waren (Pause, Clubwechsel). | wie oben |
| Neue Spieler | Name, E-Mail (freiwillig), Klassierung des Clubs, STT-Lizenz falls vorhanden. Entsteht als `player_request`; Person und Spieler gibt es erst nach der Freigabe. | Klassierung gilt als provisorisch; die TK legt sie bei der Freigabe fest (Art. 28.2, 28.3). Spielberechtigt erst nach Freigabe mit Lizenznummer (Art. 26.2, 29.1). Ein neuer Spieler kann deshalb erst nach der Freigabe Stammspieler werden; die TK gibt Neumeldungen unabhängig vom Stand der Meldung frei. |
| Mannschaften | Anzahl (fortlaufende Nummern, Name «Kurzname Nummer»), je drei Stammspieler (`team_roster.kind = stamm`), Heimlokal, Anspielzeit, ein Captain (`club_role`). Entfernt wird immer die Mannschaft mit der höchsten Nummer. Zu Beginn lassen sich die Mannschaften des Vorjahrs übernehmen (siehe unten). | Genau drei Stammspieler. Niemand ist Stammspieler in zwei Mannschaften (Art. 12.2). Stammspieler müssen für den Club gemeldet sein. Heimlokal, Anspielzeit und Captain müssen gesetzt sein. Der Captain braucht eine E-Mail-Adresse und gehört zum Club (gemeldeter Spieler oder Person mit Funktion im Club). |
| Lokale | Name, Adresse, Telefon, Spieltag, Öffnungszeit, Anzahl Tische. Lokale gehören zum Club, nicht zu einer Saison; sie werden nicht gelöscht, sondern als «nicht mehr in Gebrauch» markiert. | Name, Spieltag Montag bis Freitag und Anzahl Tische (mindestens 1) sind Pflicht. Ein Lokal, in dem eine Mannschaft der aktiven oder geplanten Saison spielt, bleibt in Gebrauch. |
| Hallensperren | Lokal, von, bis, Grund (`venue_closure`). | `bis` nicht vor `von`; ein einzelner Tag ist erlaubt. |

Kategorie und Gruppe einer Mannschaft legt die TK fest, nicht der Club: Neue Mannschaften haben `competition_id` NULL. Hat die TK eine Mannschaft schon eingeteilt, entfernt sie nur noch die TK, und nur solange keine Begegnung angesetzt ist.

### Mannschaften des Vorjahrs übernehmen

Solange der Club in der geplanten Saison noch keine Mannschaft hat und in der aktiven mindestens eine, bietet die Meldung den Knopf «Mannschaften des Vorjahrs übernehmen (N)» (`ClubRegistration::copy_teams()`, Aktion `ttleague_reg_team_copy`). Es gelten dieselben Rechte, dasselbe Fenster und derselbe Meldestand wie für «Mannschaft hinzufügen».

- Übernommen werden die Mannschaften der aktiven Saison mit gleicher Nummer und gleichem Namen, ohne zurückgezogene (`team.status`). `competition_id` bleibt NULL.
- Heimlokal, sofern es noch ein Lokal des Clubs in Gebrauch ist, und Anspielzeit.
- Stammspieler, soweit sie in der geplanten Saison für den Club gemeldet sind (Status egal). Deshalb zuerst die Spieler bestätigen; Bindungen (`bound`) kommen nicht mit.
- Der Captain, wenn er die Regeln für Captains noch erfüllt (gehört zum Club, hat eine E-Mail-Adresse).
- Was danach fehlt, steht wie sonst als offener Punkt bei der Mannschaft. Jede Mannschaft steht mit ihrer Vorlage (`copied_from`) im `audit_log`.

Hat der Club schon eine Mannschaft gemeldet, lehnt der Dienst ab; es wird nichts zusammengeführt.

### Klassierung bestehender Spieler

Bestehende Spieler meldet das System mit der Klassierung aus der letzten früheren Saison, in der sie gemeldet waren: Ist deren Klassierung abgeschlossen (`season.classification_closed_at`), gilt der Entscheid der TK (`classification_proposal.decided`), sonst `player_season.classification` jener Saison. Der Club kann sie nicht wählen. Bei der Freigabe der Meldung führt das System die Klassierung der noch provisorischen Spieler nach; so gilt auch ein Entscheid, der erst nach dem Bestätigen fiel. Schliesst die TK die Klassierung erst nach der Freigabe einer Meldung ab, bleibt deren Klassierung stehen (siehe «Offen»).

Die TK kann beim Melden eine eigene Klassierung mitgeben. Das gilt als Entscheid: Der Spieler ist sofort `active`, und die Freigabe der Meldung ändert den Wert nicht mehr.

## Prüfung durch die TK

Seite «Spielbetrieb → Meldungen» (`admin.php?page=ttleague-tk-meldungen`) mit einem Reiter je Saison (geplant, aktiv) und dem Stand je Club: offen, eingereicht oder geprüft, Anzahl Spieler (davon provisorisch), Mannschaften, offene Neumeldungen. «Prüfen» öffnet die Meldung des Clubs mit denselben Formularen wie in «Mein Bereich» und den Entscheiden der TK.

- **Neue Spieler freigeben:** Das System schlägt die nächste freie Lizenznummer vor (höchste vergebene plus eins); die TK kann sie und die Klassierung ändern. Gibt es Personen mit gleichem Namen oder gleicher E-Mail-Adresse, stehen sie zur Auswahl. Die Freigabe legt `person`, `player` und `player_season` (Status `active`) an und setzt `player_request.status = approved`. Mit einer bestehenden Person entsteht keine zweite; hat sie schon eine Lizenz, auch kein zweiter Spieler. Die E-Mail-Adresse einer bestehenden Person ändert die Freigabe nie. Gehört die gemeldete Adresse schon jemandem, verlangt das System die Auswahl dieser Person oder die Freigabe ohne Adresse.
- **Ablehnen** (Neumeldung oder Nachmeldung) verlangt eine Bemerkung; sie geht an den TK-Chef.
- **Nachmeldung freigeben** (aktive Saison): setzt `player_season.status = active`; ab dann spielberechtigt.
- **Meldung freigeben** (geplante Saison): setzt alle `player_season` des Clubs in dieser Saison auf `active`. Geht aus `open` oder `submitted`, aber nur ohne Verstösse und ohne offene Neumeldungen.
- **Zurückgeben** mit Bemerkung setzt eine eingereichte oder geprüfte Meldung wieder auf `open`; schon freigegebene Spieler bleiben freigegeben.
- Die TK darf jede Meldung selbst bearbeiten, auch nach Ablauf des Fensters. Was sie in der aktiven Saison oder in einer schon geprüften Meldung selbst meldet, ist freigegeben.

Übersicht der TK: Liste «Meldungen zu prüfen» (eingereichte Meldungen, offene Neumeldungen beider Saisons, provisorische Nachmeldungen der aktiven Saison) und Liste «Geänderte Lokale und Hallensperren» (aus dem `audit_log`, 14 Tage).

## Öffentliche Darstellung

Die geplante Saison ist nicht öffentlich. Provisorisch gemeldete Spieler der aktiven Saison (Nachmeldungen vor der Freigabe) erscheinen weder in der Spielerliste, im Club noch als Saison im Spielerprofil (`ReadModel\Players`, `ReadModel\PlayerProfile`); die Erfassung lässt sie nicht spielen (`Results\EligibilityLoader`).

## Dienste und Rechte

- `Registration\ClubRegistration` (Spieler, Neumeldungen, Mannschaften, Captains, Einreichen), `Registration\Venues` (Lokale, Sperren), `Registration\Review` (TK). Reine Regeln in `Domain\Registration\RegistrationRules`, Datenzugriff in `Registration\RegistrationRepository`.
- Jeder Schreibvorgang läuft in einer Transaktion, sperrt den Club (`SELECT … FOR UPDATE`, beim Melden auch den Spieler), liest Rechte und Stand unter der Sperre neu, schreibt ins `audit_log` (`player_season`, `player_request`, `team`, `club_season`, `venue`, `venue_closure`) und meldet danach `RegistrationEvents::changed()`; in WordPress folgen `DataVersion::bump()` und die Aktion `ttleague_data_changed` (`registration`, Club-ID). Entscheide der TK gehen zusätzlich als `Decision` an `RegistrationEvents::decided()` (Benachrichtigung, Aktion `ttleague_registration_decided`).
- Rechte: In der WordPress-Schicht `ttl_manage_club` mit der Club-ID, für Entscheide `ttl_tk`. Im Dienst (`RegistrationAccess`) zusätzlich: Die Person ist TK-Chef des Clubs **in der Saison der Meldung** (der TK-Chef nur der aktiven Saison meldet nicht für die geplante und umgekehrt), dazu Saisonstatus, Meldefenster und `club_season.status`. Lokale und Sperren ändert der TK-Chef der aktiven oder der geplanten Saison. Die TK immer, ausser in einer archivierten Saison.
- Ablehnungen der Dienste sind `RegistrationException` (`ttl_forbidden`, `ttl_not_found`, `ttl_conflict`, `ttl_invalid` mit Feldfehlern).
- Die Zahl der Stammspieler (3) und die Tage der Erinnerung (7, 2) sind Parameter der Regeln mit diesen Standardwerten, einstellbar über die Filter `ttleague_registration_stamm_size` und `ttleague_registration_reminder_days`. `SeasonRules` kennt dafür keinen Wert.

## Mein Bereich

Abschnitt «Meldung: {Club}» (`Registration\MyClubSection`, Aktion `ttleague_my_area_sections`, Priorität 30, nach «Mein Club») für jeden Club, den die Person als TK-Chef der aktiven oder der geplanten Saison verwaltet:

- **Meldung für die Saison …** (geplant): sobald das Meldefenster begonnen hat. Nach dem Einreichen, nach der Prüfung und nach Ablauf des Fensters bleibt sie zum Lesen sichtbar, ohne Formulare. Vor Beginn des Fensters und ohne geplante Saison erscheint sie nicht.
- **Laufende Saison …: Nachmeldungen und Captains** (aktiv): Spieler nachmelden bis zur Frist, eigene noch nicht freigegebene Nachmeldungen zurückziehen, Captain je Mannschaft.
- **Lokale und Hallensperren**.

Formulare serverseitig an `admin-post.php` (`Registration\Actions`, Aktionen `ttleague_reg_*`), Nonce pro Aktion und Club (Entscheide der TK: pro Aktion und Datensatz), Rückmeldung über `Flash`, danach Weiterleitung zum Abschnitt. Die Seite der TK verwendet dieselben Aktionen und kehrt mit dem Feld `back` ins Backend zurück. Stil `assets/css/registration.css` nur in diesem Abschnitt.

## Benachrichtigungen

`registration_reminder` und `registration_decided`, siehe [saisonbetrieb.md](saisonbetrieb.md); beide erscheinen in den Einstellungen in «Mein Bereich».

- **Empfänger** sind die TK-Chefs des Clubs in der Saison der Meldung (`club_role`), nur aktive Personen. Wer entschieden hat, erhält keine Nachricht über den eigenen Entscheid.
- **`registration_decided`**: sofort nach jedem Entscheid der TK (Meldung geprüft oder zurückgegeben, Neumeldung oder Nachmeldung freigegeben oder abgelehnt), mit der Bemerkung. Bezug im Versandprotokoll: `ref_type` `audit_log`, `ref_id` der Protokolleintrag des Entscheids.
- **`registration_reminder`**: läuft im stündlichen Fristenlauf der Benachrichtigungen mit (`ttleague_notifications_hourly`, Priorität 20; kein eigener Cron), nur zwischen 7 und 22 Uhr Ortszeit (Filter `ttleague_notification_hours`). Fällig ist die Stufe 7 ab sieben Tagen und die Stufe 2 ab zwei Tagen vor dem letzten Tag des Fensters, bis und mit diesem Tag; von mehreren fälligen Stufen geht nur die letzte hinaus, ein verpasster Lauf wird nachgeholt. Bezug: `ref_type` `club_season`, `ref_id` der Meldestand, `ref_key` letzter Tag und Stufe (`2027-05-31:7`); verlängert die TK das Fenster, gilt die Erinnerung neu.
- `wp ttleague notifications run --at` spielt nur die Fristen der Erfassung durch, nicht diese Erinnerungen; `wp cron event run ttleague_notifications_hourly` löst beide aus.

## Exporte

| Liste | Inhalt | Filter |
|---|---|---|
| `registrations` | Stand je Club: Saison, Club, Stand, eingereicht am, geprüft am, Spieler, davon provisorisch, Mannschaften, offene Neumeldungen, Bemerkung der TK | `season` (ID oder Kürzel wie `2027-28`; ohne Angabe die geplante, sonst die aktive Saison) |
| `registration-players` | Gemeldete Spieler: Saison, Club, Lizenz, Nachname, Vorname, Klassierung, Stand, Stammspieler in | `season`, `club` (ID) |

Im Backend als Knöpfe auf der Seite «Meldungen», dazu `wp ttleague export registrations` und `wp ttleague export registration-players --season=2027-28`.

## Tests

- Regeln (`tests/Unit/Domain/RegistrationRulesTest.php`): drei Stammspieler, doppelter Stammspieler, nicht gemeldeter Stammspieler, Lokal, Anspielzeit und Captain, Spieler in zwei Clubs, Meldefenster und Frist der Nachmeldung an den Grenzen, Lokaldaten, Hallensperren, Neumeldung, Einreichen mit Verstoss, Stufen der Erinnerung.
- Dienste gegen MariaDB (`tests/Integration/Registration/`): Vorjahresspieler bestätigen und abmelden, Klassierung vor und nach dem Abschluss, Clubwechsel, Mannschaften des Vorjahrs übernehmen (vollständig, mit fehlendem Stammspieler, zweiter Aufruf, Rechte, Fenster, eingereichte Meldung), Neumeldung bis zur Freigabe (kein `player` vorher), Freigabe mit bestehender Person mit und ohne Lizenz, vergebene Lizenznummer und E-Mail-Adresse, Rückgabe, Sperre nach dem Einreichen, Fenster und Frist auf die Stunde genau in Ortszeit, Rechte der geplanten und der aktiven Saison, archivierte Saison, Lokale und Sperren, Empfänger und Dedupe der Benachrichtigungen, provisorische Spieler in den öffentlichen Listen.
- WordPress (`tests/WordPress/Registration/`): Abschnitt in «Mein Bereich» mit Escaping und eindeutigen IDs, Formulare mit Nonce und Rechten (fremder Club, fremde Datensätze, Entscheide der TK), ganze Meldung über die Formulare, Knopf und Aktion «Mannschaften des Vorjahrs übernehmen», Seite «Meldungen» mit Entscheiden, Listen der Übersicht, E-Mail an den TK-Chef, Erinnerungen, CSV nur mit `ttl_tk`.

## Offen

- Schliesst die TK die Klassierung der aktiven Saison erst ab, nachdem eine Meldung geprüft ist, bleiben die Klassierungen dieser Meldung auf dem Stand der Freigabe. Das Nachführen gehört zum Abschluss der Klassierung ([klassierung.md](klassierung.md)) oder zur Freigabe der Saison.
- Die Liste «Mein Club» (Konten und Einladungen) zeigt auch provisorisch gemeldete Spieler der aktiven Saison.
- E-Mail-Adressen von Personen, die nur in der geplanten Saison zum Club gehören, setzt bis zur Freigabe der Saison die TK (`ttl_invite_person` bleibt bei der aktiven Saison). Ein Captain ohne Adresse lässt sich nicht wählen.
- Ein Captain pro Mannschaft: Setzt jemand den Captain über die Meldung, ersetzt das alle bisherigen Captains der Mannschaft.
