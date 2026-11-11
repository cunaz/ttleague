# Ranglistenturnier

Anmeldung mit Sperrdaten, Gruppen je Runde, Aufgebot, Nachtragen des Gruppenzettels, Wertung und Final. Der Spielmodus (Gruppengrösse, Punkte, Tiebreaks, Finalteilnehmer) steht in `SeasonRules`; die Wertung rechnen `GroupRanking` und `FinalQualification`. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md). Dieses Dokument beschreibt, was gebaut ist.

## Aufbau

Eine Konkurrenz vom Typ `RL` pro Saison. Die TK richtet sie im Backend ein (oder der Import bringt sie mit). `competition.format_json` enthält:

| Schlüssel | Bedeutung | Standard |
|---|---|---|
| `categories` | Grenzen der Klassierung je Kategorie, z. B. `[{"no":1,"min":16},{"no":2,"min":9},{"no":3,"min":1}]`: Eine Klassierung ab `min` gehört zur Kategorie, die höchste passende Grenze gewinnt (`Domain\Ranking\Categories`). | keine: Die Kategorie bleibt leer (0), die TK setzt sie |
| `registration_deadline` | Anmeldeschluss (Datum); der Tag zählt noch dazu, Ortszeit | kein Anmeldeschluss |
| `start_time` | Spielbeginn der Gruppen (`19:45`) | Öffnungszeit des Lokals, sonst 19:30 |
| `reminder_days` | Tage vor dem Anmeldeschluss, ab denen `rl_reminder` hinausgeht; 0 schaltet aus | 3 |
| `blocked_reminder_days` | Tage vor Beginn einer nicht eingeteilten Runde, ab denen `rl_reminder` hinausgeht; 0 schaltet aus | 10 |
| `regular_rounds` | Zahl der regulären Runden laut Reglement. Bestimmt nur, wie viele Rundenspalten die öffentliche Gesamtrangliste mindestens zeigt; gezählt werden immer alle regulären Runden. | 3 |

`Registrations::configure()` ändert nur, was übergeben ist: Fehlt ein Schlüssel, bleibt die bestehende Einstellung; ein ausdrücklich leerer Wert löscht sie. Das Formular im Backend schickt immer alle Felder.

Runden stehen in `rl_round` (Woche `date_from` bis `date_to`), Gruppen in `rl_group`, Teilnahmen in `rl_group_player`.

**Reguläre Runden und Finalrunde:** Regulär ist jede Runde, die nicht die Finalrunde ist (`is_final`). Feste Rundennummern gibt es nicht; üblich sind drei reguläre Runden und die Finalrunde als vierte, die TK kann aber auch eine vierte reguläre Runde anlegen. Die Finalrunde gibt es höchstens einmal, und sie ist die letzte Runde: Nach ihr legt der Dienst keine Runde mehr an, und eine frühere Runde wird nicht nachträglich zur Finalrunde. Dienst (`Standings`: Export, Finalqualifikation) und öffentliche Gesamtrangliste (`ReadModel\RankingTournament`) zählen dieselben Runden.

| Status der Runde | Bedeutung |
|---|---|
| `planned` | Entwurf: Angemeldete melden Sperrdaten, die Gruppen sind ein Vorschlag. Nur die TK sieht die Runde; öffentliche Ansicht und Kalender-Abo kennen sie nicht. |
| `published` | Aufgebot verschickt, Gruppen öffentlich, Gruppenzettel können erfasst werden. |
| `closed` | Die TK hat die Runde abgeschlossen; Resultate und Punkte sind fest. Importierte Runden haben diesen Status. |

Neue Runden legt der Dienst ausdrücklich als `planned` an. `rl_group_player.status = scheduled` heisst aufgeboten, noch nicht gespielt; Rang und Punkte sind dann leer.

## Anmeldung und Sperrdaten

Dienst `RankingTournament\Registrations`, nur in der aktiven Saison.

- **Anmelden** kann sich jede in der Saison aktiv gemeldete Person selbst in «Mein Bereich», oder der TK-Chef für Personen seines Clubs (Abschnitt «Ranglistenturnier» in «Mein Bereich», nicht in «Mein Club»), bis und mit dem Tag des Anmeldeschlusses. Danach nur die TK. Es entsteht `rl_participant` mit der Kategorie aus der Klassierung, `registered_by` und `registered_at`. Wer schon angemeldet ist, bleibt es unverändert. Nur die TK kann eine andere Kategorie setzen; sie bleibt bei einer Wiederanmeldung erhalten.
- **Sperrdaten** sind Abende, an denen jemand nicht spielen kann: `availability` mit `context = rl`, eine Zeile pro Abend. Das Formular zeigt für jede Runde in Planung die Tage der Rundenwoche (`date_from` bis `date_to`) zum Ankreuzen. Änderbar, bis die Runde `published` ist. Eine Zeile mit `context = rl_answer` über die ganze Rundenwoche hält fest, dass die Person gemeldet hat, auch «an jedem Abend frei»; daran erkennen die Erinnerungen, wer noch fehlt.
- **Abmelden** vom ganzen Turnier (`rl_participant.status = withdrawn`): Aus einer Runde in Planung fällt die Person aus ihrer Gruppe, in einer freigegebenen Runde wird sie `absent`. **Abmelden von einer freigegebenen Runde** setzt nur `rl_group_player.status = absent`, solange der Gruppenzettel nicht erfasst ist; die Person verschwindet aus dem Kalender-Abo, im öffentlichen Aufgebot steht «abgemeldet».
- Für sich selbst handelt jede Person, für andere der TK-Chef des Clubs (in der WordPress-Schicht `ttl_manage_club`, im Dienst die Funktion in der aktiven Saison) und die TK.

## Gruppenvorschlag

«Einteilen» (`Rounds::propose()`) rechnet mit der reinen Funktion `Domain\Ranking\GroupProposal` je Kategorie Gruppen und speichert sie; ein früherer Vorschlag der Runde wird ersetzt. Die TK ändert, was sie will, und gibt frei.

- **Gruppengrösse** höchstens `rl_group_size` (6), möglichst gleich gross. Die Ausnahmegrösse `rl_group_size_max` (7) gilt nur, wenn sonst wegen so weniger Personen, wie zusätzlich in eine Gruppe passen (üblich: eine Person), eine weitere Gruppe nötig wäre: 13 Angemeldete ergeben 7 und 6, 14 ergeben 5, 5 und 4.
- **Abende:** Kandidaten sind die aktiven Lokale der Clubs der Angemeldeten an ihrem Spieltag (`venue.weekday`) innerhalb der Rundenwoche; gesperrte Lokale (`venue_closure`) fallen weg. Ein Lokal nimmt an einem Abend so viele Gruppen auf, wie seine Tische erlauben (`tables_count` geteilt durch die halbe Gruppengrösse; ohne Angabe eine Gruppe). Bevorzugt werden Abende, an denen möglichst viele noch nicht berücksichtigte Personen frei sind, dann solche mit einer Person des Platzclubs in der Kategorie, dann Clubs, die in früheren Runden seltener Platzclub waren. Reichen die Abende nicht, bleiben Gruppen ohne Lokal und Termin; die TK setzt sie von Hand.
- **Sperrdaten:** Niemand steht in einer Gruppe an einem gesperrten Abend. Wer an keinem gewählten Abend kann oder keinen Platz findet (eine Gruppe wächst höchstens bis zur Ausnahmegrösse), bleibt «nicht zugeteilt». Wer nichts gemeldet hat, gilt als frei.
- **Neue Gegner:** Jede Gruppe erhält zuerst eine Person des Platzclubs. Danach werden die Personen so verteilt und getauscht, dass möglichst wenige Paarungen aus früheren Runden wiederkommen (frühere Begegnungen zählen im Quadrat, damit eine dritte Begegnung schwerer wiegt). Als frühere Paarung gilt, wer in derselben Gruppe gespielt hat.
- **Reihenfolge:** «Erstgenannt» ist, wer die höhere Klassierung hat, dann nach Name. In dieser Reihenfolge stehen die Personen auf dem Gruppenzettel.
- **Gruppenchef** ist zunächst die erstgenannte Person des Platzclubs, sonst die erste der Gruppe; die TK kann ändern.
- **Spielbeginn:** Abend des Lokals mit `start_time` des Turniers, sonst der Öffnungszeit des Lokals, sonst 19:30.
- **Final:** Eine Gruppe pro Kategorie mit den `rl_finalists` Besten der Gesamtrangliste aller abgeschlossenen regulären Runden (`RankingTournament\Standings` mit `FinalQualification`), die noch angemeldet sind. Läuft die Grenze durch einen Gleichstand, nennt die Rückmeldung die Betroffenen; die TK passt die Gruppe nach dem Los an.
- Der Vorschlag ist deterministisch: gleiche Eingabe, gleiches Ergebnis.

Eingeteilt und freigegeben wird eine Runde erst, wenn alle früheren abgeschlossen sind.

**Gruppe ändern** (`Rounds::save_group()`, `delete_group()`): Lokal (sein Club ist Platzclub), Datum und Zeit, Gruppenchef, Bemerkung, Mitglieder. In eine Gruppe gehören Angemeldete ihrer Kategorie; wer in einer anderen Gruppe der Runde steht, wechselt. Sind für eine Gruppe Partien erfasst, bleiben ihre Mitglieder, bis die TK den Gruppenzettel zurücksetzt.

**Freigeben** (`Rounds::publish()`) verlangt für jede Gruppe mindestens zwei Personen und einen Termin, setzt die Runde auf `published` und merkt den Versand von `rl_callup` an alle Aufgebotenen vor: Lokal, Adresse, Abend, Zeit, Mitspielende, Gruppenchef. Der Termin steht im Kalender-Abo der Person. Ändert die TK danach eine Gruppe, erhalten nur die Aufgebotenen der geänderten Gruppen ein neues Aufgebot. Versendet wird nicht in der Anfrage der TK, sondern danach in Schritten (siehe «Benachrichtigungen»).

## Gruppenzettel nachtragen

Dienst `RankingTournament\GroupEntry`. Der Gruppenchef trägt nach dem Spielabend nach; ersatzweise der TK-Chef seines Clubs, der TK-Chef des Platzclubs oder die TK (`ttl_enter_rl_group`). Möglich ist es, solange die Runde `published` ist.

- **Status je Person:** gespielt, abgemeldet, disqualifiziert (15 Minuten nach Spielbeginn nicht da), forfait (gibt ausstehende Partien auf). Jede Person braucht einen Status. Fehlt er oder ist er ungültig, meldet der Dienst genau diesen Fehler (`players.{id}`) und keinen weiteren zu den Partien der Person.
- **Partien** «jeder gegen jeden» in der Reihenfolge von `Domain\Ranking\GroupGameOrder` (Kreisverfahren des `RoundRobinScheduler`: In jedem Durchgang spielt jede Person höchstens einmal). Zwischen zwei Personen, die gespielt haben, ist das Satzresultat Pflicht (bei drei Gewinnsätzen 3:0 bis 0:3). Für abgemeldete und disqualifizierte Personen gibt es keine Partien. Partien einer Person mit Forfait sind freiwillig; sie bleiben gespeichert, zählen aber nicht (`counts_for_result = 0`). Satzpunkte nur, wenn `set_points_enabled`; sie müssen zum Resultat passen.
- Die Partien stehen in `game` mit `rl_group_id`, `played_at` ist der Termin der Gruppe. Nach jedem Speichern wird die Wertungszahl wie bei der Meisterschaft entprellt neu gerechnet (`ttleague_rating_recalc`); gespielte Partien zählen dafür, auch die nicht gewerteten einer Person mit Forfait.
- **Rang und Punkte** rechnet `GroupRanking` beim Speichern, schreibt sie nach `rl_group_player` und zeigt sie zur Kontrolle. Bleibt ein Gleichstand unauflösbar, teilen die Personen Rang und Punkte.
- **Sperrdaten für die nächste Runde** (die nächste in Planung) fragt das Formular mit ab: Mit dem Häkchen «Sperrdaten übernehmen» gelten die angekreuzten Abende für alle, die gespielt haben.
- Speichern setzt `rl_group.entered_by` und `entered_at` und schreibt den Stand vorher und nachher ins `audit_log`. Ändern lässt sich der Gruppenzettel, bis die Runde `closed` ist. Eine Bestätigung durch die Mitspielenden gibt es nicht. Die TK kann einen Gruppenzettel zurücksetzen; weil dabei alle erfassten Partien der Gruppe gelöscht werden, verlangt das Formular im Backend ein Pflicht-Kontrollkästchen, das auch die Aktion prüft.
- **Gleichzeitige Änderungen:** `entry` liefert `version` (Status der Runde, Erfassung, Termin, Gruppenchef, Mitglieder mit Status, Partien). Wird sie beim Speichern mitgeschickt und hat sich die Gruppe geändert, antwortet der Dienst mit 409. Jedes Speichern sperrt zudem Saison und Gruppe, liest neu und bricht ab, wenn sich der Stand seit der Prüfung geändert hat, zum Beispiel weil die TK die Runde inzwischen abgeschlossen hat.

**Abschliessen** (`Rounds::close()`) durch die TK, wenn alle Gruppen erfasst sind oder ausdrücklich ohne Resultat bleiben: Rang und Punkte werden in `rl_group_player` festgeschrieben, die Runde ist `closed` mit `closed_by` und `closed_at`. Es gelten die berechneten Werte, ausser die TK setzt Rang oder Punkte von Hand (Los); dann steht ein Eintrag `rank_manual` mit den berechneten und den gesetzten Werten im `audit_log`. Erst danach lässt sich die nächste Runde einteilen. Wieder öffnen lässt sich eine abgeschlossene Runde nicht.

## REST und Oberfläche

| Route | Methode | Zweck |
|---|---|---|
| `rl/groups/{id}/entry` | GET | Formulardaten: `group`, `can.enter`, `rules`, `players`, `order`, `games`, `ranking`, `next_round`, `version` |
| `rl/groups/{id}/result` | POST | Gruppenzettel speichern: `players` (Spieler => Status), `games` (`sequence`, `sets_a`, `sets_b`, `set_points`), `next_blocked`, `version`; Antwort `ranking`, `entered_at`, `version` |

Fehler wie in `docs/erfassung.md`: 401 `rest_forbidden`, 403 `ttl_forbidden`, 404 `ttl_not_found` (auch für Gruppen einer Runde in Planung, ausser für die TK), 409 `ttl_conflict`, 422 `ttl_invalid` mit Feldfehlern (`players.{id}`, `games.{nummer}`, `games.{nummer}.set_points`, `next_blocked.{id}`).

- **Seite zum Nachtragen:** die Seite «Resultat erfassen» mit `?rl-gruppe={id}` (`RankingTournament::entry_url()`). Das Modul ersetzt dort den Block über `pre_render_block`; es gelten dieselben Kopfzeilen wie für die Erfassung der Meisterschaft. Anders als im Entwurf ist das Formular serverseitig gerendert und geht ohne JavaScript an `admin-post.php` (Nonce pro Gruppe, `ttl_enter_rl_group`): Status je Person, pro Partie das Satzresultat als Auswahl. Nach einer ungültigen Eingabe bleiben die Felder stehen (Transient, zehn Minuten). Interactivity API und Entwurf im `localStorage` gibt es nicht; die REST-Routen stehen dafür bereit.
- **«Mein Bereich»:** Abschnitt «Ranglistenturnier» mit Anmeldung und Abmeldung, Sperrdaten der Runden in Planung, Aufgebot der freigegebenen Runden (mit Abmeldung für die Runde) und den Gruppenzetteln, die die Person nachtragen darf. TK-Chefs melden dort Personen ihres Clubs an und ab.
- **Öffentlich** wie bisher: Runden, Gruppen, Gesamtrangliste; neu das Aufgebot freigegebener Runden. Runden in Planung fehlen ganz. Die Gesamtrangliste hat eine Spalte je regulärer Runde (mindestens `regular_rounds`, bei drei Runden wie bisher «Gesamtrangliste nach drei Runden»). Sie zählt erfasste Gruppen schon vor dem Abschluss mit; für den Final zählen nur abgeschlossene Runden.
- **Backend «Spielbetrieb → Ranglistenturnier»:** Einstellungen, Anmeldungen und Kategorien, Runden anlegen, einteilen, Gruppen ändern, freigeben, abschliessen (mit Rang und Punkten von Hand). CSV über `ttleague_exports`: `rl-anmeldungen` und `rl-rangliste`.

## Dienste und Rechte

`RankingTournament\Registrations`, `Rounds`, `GroupEntry`, dazu `Repository` (Datenzugriff ohne Cache), `Standings` (Gesamtrangliste für Final und Export) und `Notices` (Benachrichtigungen). Jeder Schreibvorgang sperrt in seiner Transaktion zuerst die Saison, schreibt ins `audit_log` (`rl_competition`, `rl_participant`, `rl_round`, `rl_group`) und erhöht danach die Datenversion. Fachliche Fehler sind `ResultException` mit denselben Codes wie in der Erfassung. Rechte nach [saisonbetrieb.md](saisonbetrieb.md); alle Aktionen an Runden gehören der TK.

## Benachrichtigungen

- `rl_callup`: nach dem Freigeben und nach jeder Änderung einer freigegebenen Gruppe, an die Aufgebotenen der Gruppe (nicht an Abgemeldete, nicht mehr nach dem Termin der Gruppe). Bezug ist die Gruppe, der Zusatz (`ref_key`) eine Kennung aus Termin, Lokal, Platzclub, Gruppenchef, Mitgliedern und der letzten Änderung durch die TK: Dasselbe Aufgebot geht nie doppelt hinaus, ein geändertes immer, auch wenn die TK eine Änderung zurücknimmt. Meldet sich jemand für die Runde ab, ändert sich das Aufgebot der anderen nicht. Wer schon ein Aufgebot dieser Runde hatte, erhält das neue als «Geändertes Aufgebot».
  - **Versand in Schritten:** Freigabe und Änderung versenden nichts, sie planen das einmalige WP-Cron-Ereignis `ttleague_rl_callups` (Runde, Fortsetzung) ein; bei über 60 Aufgebotenen und langsamem Versand scheiterte die Anfrage der TK sonst am Zeitlimit. Scheitert das Einplanen, steht es im Fehlerprotokoll der Benachrichtigungen (`ttleague_notification_error`); ein schon wartender gleicher Schritt gilt nicht als Fehler.
  - Ein Schritt (`Notices::send_callups()`) versendet an höchstens `Notices::CALLUP_BATCH` (25) Personen, denen laut Versandprotokoll das aktuelle Aufgebot ihrer Gruppe fehlt, in der Reihenfolge der Person-ID, und plant den Rest für die Personen danach erneut ein. Er ist beliebig oft wiederholbar. Ein Fehler bei einer Person wird gemeldet und hält die übrigen nicht auf. Personen ohne Kanal (inaktiv, keine Adresse, abgewählt) zählen nicht zum Stapel.
  - Der stündliche Fristenlauf versendet selbst nichts: Er plant für jede freigegebene Runde, in der einer erreichbaren Person das aktuelle Aufgebot fehlt, einen Schritt ein, falls keiner wartet. So werden ein verlorenes Ereignis, ein abgebrochener Schritt und gescheiterte Zustellungen nachgeholt. Wer das Aufgebot abgewählt hat, gilt dabei bis zum Termin der Gruppe als ausstehend; der Schritt, den der Lauf dann einplant, versendet nichts.
- `rl_reminder`: an Angemeldete ohne gemeldete Sperrdaten für die nächste Runde in Planung, in den Tagen vor dem Anmeldeschluss und vor jeder Runde, die noch nicht eingeteilt ist. Pro Person, Runde und Anlass einmal; sind beide fällig, geht nur die Erinnerung vor der Einteilung hinaus. Die Erinnerungen laufen im stündlichen Fristenlauf mit (`ttleague_notifications_hourly`), ausserhalb der Ruhezeit; `wp ttleague notifications run --at` spielt sie nicht mit.

## Tests

- Vorschlag (`tests/Unit/Domain/GroupProposalTest.php`): Gruppengrössen bei 5 bis 40 Angemeldeten, Sperrdaten, gesperrtes Lokal und Spieltag, Lokal mit Platz für zwei Gruppen, neue Gegner über drei Runden, wechselnde Platzclubs, nicht Zuteilbare, Final, Determinismus. Dazu Spielfolge und Kategorien.
- Dienste gegen MariaDB (`tests/Integration/RankingTournament/`): Anmeldung mit Rechten und Anmeldeschluss, Sperrdaten, Abmeldung; Runden von der Einteilung bis zum Abschluss, Final, Los; Gruppenzettel mit allen Status, Gleichstand, Änderung bis zum Abschluss, gleichzeitiger Änderung und Rechten; Aufgebot und Erinnerungen an ihren Grenzen.
- Öffentlich (`tests/Integration/Frontend/RankingRoundStatusTest.php`): Runden in Planung fehlen in Lesemodell, Ansicht und Kalender-Abo; importierte Runden bleiben unverändert, auch mit drei Runden und Finalrunde. Bei vier regulären Runden stimmen Dienst und öffentliche Gesamtrangliste überein (`RoundsTest`).
- WordPress (`tests/WordPress/RankingTournament/`): Anmeldung selbst und durch den TK-Chef, Backend von der Einteilung bis zum Abschluss, Formular des Gruppenzettels, REST mit 401, 403, 404, 409, 422, Exporte, Erinnerungen über den Fristenlauf, Versand der Aufgebote über WP-Cron (Vormerken, Stapel und Rest, Nachplanen im stündlichen Lauf, gescheitertes Einplanen).
