# Ranglistenturnier

Anmeldung mit Sperrdaten, Gruppen je Runde, Aufgebot, Nachtragen des Gruppenzettels, Wertung und Final. Der Spielmodus (Gruppengrösse, Punkte, Tiebreaks, Finalteilnehmer) steht in `SeasonRules`; die Wertung rechnen `GroupRanking` und `FinalQualification` schon. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Aufbau

Eine Konkurrenz vom Typ `RL` pro Saison. `competition.format_json` enthält `categories` (Grenzen der Klassierung je Kategorie, z. B. `[{"no":1,"min":5},{"no":2,"min":3},{"no":3,"min":1}]`) und `registration_deadline`. Runden stehen in `rl_round` (Woche `date_from` bis `date_to`, die letzte ist der Final), Gruppen in `rl_group`, Teilnahmen in `rl_group_player`.

| Status der Runde | Bedeutung |
|---|---|
| `planned` | Gruppen sind ein Vorschlag, nur die TK sieht sie. |
| `published` | Aufgebot verschickt, Gruppen öffentlich, Gruppenzettel können erfasst werden. |
| `closed` | Die TK hat die Runde abgeschlossen; Resultate und Punkte sind fest. |

## Anmeldung und Sperrdaten

- **Anmelden** kann sich jede gemeldete Person selbst in «Mein Bereich», oder der TK-Chef für Spieler seines Clubs in «Mein Club», bis `registration_deadline`. Danach nur die TK. Es entsteht `rl_participant` mit der Kategorie aus der Klassierung; die TK kann die Kategorie ändern.
- **Sperrdaten** sind Abende, an denen jemand nicht spielen kann: `availability` mit `context = rl`. Für jede kommende Runde zeigt das Formular die Abende der Rundenwoche (Montag bis Donnerstag) zum Ankreuzen. Änderbar, bis die Runde `published` ist.
- **Abmelden** vom ganzen Turnier (`rl_participant.status = withdrawn`) oder von einer aufgebotenen Runde (`rl_group_player.status = absent`); die Spiele einer abgemeldeten Person werden nicht gewertet.
- Vor dem Anmeldeschluss und vor jeder Einteilung erinnert `rl_reminder` Angemeldete ohne Sperrdaten für die Runde.

## Gruppenvorschlag

«Runde einteilen» schlägt je Kategorie Gruppen vor. Die TK ändert, was sie will, und gibt frei.

- Gruppengrösse höchstens `rl_group_size` (6), ausnahmsweise `rl_group_size_max` (7); möglichst gleich grosse Gruppen.
- Jede Gruppe erhält einen Platzclub mit Lokal und Abend. Kandidaten sind die Lokale der Clubs der Angemeldeten an ihrem Spieltag in der Rundenwoche; gesperrte Lokale fallen weg.
- Niemand steht in einer Gruppe an einem gesperrten Abend. Geht das nicht auf, bleibt die Person «nicht zugeteilt» und erscheint als Hinweis.
- Innerhalb der Kategorie werden die Gruppen so gemischt, dass möglichst wenige Paarungen aus früheren Runden wieder vorkommen. Der Final besteht aus den `rl_finalists` Besten je Kategorie nach `FinalQualification`.
- Der Vorschlag ist deterministisch (gleiche Eingabe, gleiches Ergebnis) und eine reine Funktion in `Domain\Ranking\GroupProposal`.
- **Gruppenchef** ist zunächst die erstgenannte Person des Platzclubs, sonst die erste der Gruppe; die TK kann ändern.

**Freigeben** setzt die Runde auf `published` und verschickt `rl_callup` an alle Aufgebotenen: Lokal, Adresse, Abend, Zeit, Mitspielende, Gruppenchef. Der Termin steht im Kalender-Abo der Person. Ändert die TK danach eine Gruppe, erhalten nur die Betroffenen ein neues Aufgebot.

## Gruppenzettel nachtragen

Der Gruppenchef trägt nach dem Spielabend nach; ersatzweise der TK-Chef seines Clubs, der TK-Chef des Platzclubs oder die TK (`ttl_enter_rl_group`).

- **Status je Person:** gespielt, abgemeldet, disqualifiziert (15 Minuten nach Spielbeginn nicht da), forfait (gibt ausstehende Partien auf). Spiele von Personen, die nicht «gespielt» haben, zählen nicht.
- **Partien** «jeder gegen jeden» in der Reihenfolge des Spielplans der Gruppengrösse, je mit Satzresultat (3:0 bis 0:3). Satzpunkte nur, wenn `set_points_enabled`.
- Rang und Punkte rechnet `GroupRanking` beim Speichern und zeigt sie zur Kontrolle. Bleibt ein Gleichstand ohne Satzpunkte unauflösbar, teilt die Domäne die Punkte; die TK kann Rang und Punkte beim Abschluss von Hand setzen (Los).
- **Sperrdaten für die nächste Runde** fragt das Formular für alle Anwesenden gleich mit ab, wie heute das Papierformular.
- Speichern setzt `rl_group.entered_by` und `entered_at`. Ändern kann der Gruppenchef, bis die Runde `closed` ist. Eine Bestätigung durch die Mitspielenden gibt es nicht.

**Abschliessen** der Runde durch die TK, wenn alle Gruppen erfasst sind (oder ausdrücklich ohne Resultat bleiben): Rang und Punkte werden in `rl_group_player` festgeschrieben, die Runde ist `closed`. Erst danach lässt sich die nächste einteilen.

## REST und Oberfläche

| Route | Methode | Zweck |
|---|---|---|
| `rl/groups/{id}/entry` | GET | Formulardaten: Personen, Spielfolge, erfasster Stand, Rechte |
| `rl/groups/{id}/result` | POST | Gruppenzettel speichern (mit `version` gegen gleichzeitige Änderungen) |

- «Mein Bereich»: Abschnitt «Ranglistenturnier» mit Anmeldung, Sperrdaten, eigenem Aufgebot und, für Gruppenchefs, dem Link zum Nachtragen.
- Seite zum Nachtragen wie die Erfassungsseite (Interactivity API, Entwurf im `localStorage`).
- Öffentlich wie bisher: Runden, Gruppen, Gesamtrangliste; neu das Aufgebot veröffentlichter Runden.
- Backend «Spielbetrieb → Ranglistenturnier»: Anmeldungen, Kategorien, Runden einteilen, freigeben, abschliessen, CSV der Anmeldungen und der Rangliste.

## Dienste und Rechte

`RankingTournament\Registrations`, `RankingTournament\Rounds` (einteilen, freigeben, abschliessen), `RankingTournament\GroupEntry`. Rechte nach [saisonbetrieb.md](saisonbetrieb.md).

## Benachrichtigungen

`rl_callup` (Aufgebot und Änderung), `rl_reminder` (Anmeldeschluss, fehlende Sperrdaten).

## Tests

- Vorschlag: Gruppengrössen bei 5 bis 40 Angemeldeten, Sperrdaten, gesperrtes Lokal, möglichst neue Gegner über drei Runden, nicht Zuteilbare, Determinismus.
- Gruppenzettel gegen MariaDB: Status mit ihren Folgen, Gleichstand, Änderung bis zum Abschluss, gleichzeitige Änderung, Rechte von Gruppenchef und TK-Chefs.
- Abschluss: Punkte festgeschrieben, nächste Runde erst danach, Finalteilnehmer.
- WordPress: Anmeldung selbst und durch den TK-Chef, REST mit 401, 403, 409, 422.
