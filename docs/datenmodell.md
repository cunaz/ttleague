# Datenmodell und Konventionen

Dieses Dokument beschreibt, wie die Daten in den Tabellen `wp_ttl_*` abgelegt werden. Es ist der Vertrag zwischen Import, Erfassung und Darstellung. Das Schema selbst steht in `src/Infrastructure/Database/Schema.php`.

## Zugriff

- Alle Zugriffe laufen über `TTLeague\Infrastructure\Database\Db` (Produktion: `WpdbDb::from_global()`, Tests: `PdoDb`).
- SQL mit Platzhaltern wie bei `$wpdb->prepare()`: `%d`, `%s`, `%f`, `%%` für ein Prozentzeichen. Tabellennamen über `$db->table( 'fixture' )`.
- Ergebnisse sind assoziative Arrays mit String-Werten, `NULL` bleibt `null`.

## Zeit

- `scheduled_at`, `start_at`, `played_at`, `date_*`: **Ortszeit Europe/Zurich**, Format `Y-m-d H:i:s` bzw. `Y-m-d`.
- Systemzeitpunkte (`created_at`, `updated_at`, `entered_at`, `confirmed_at`, `decided_at`, `expires_at`, `used_at`): **UTC** (`Db::now()`).

## Saison (`season`)

| Feld | Konvention |
|---|---|
| `slug` | `2024-25` |
| `name` | `2024/25` |
| `start_date`, `end_date` | 1. August bis 31. Juli |
| `status` | `active` (höchstens eine Saison), `archived`, `planned` (höchstens eine; nicht öffentlich, siehe `docs/saisonbetrieb.md`) |
| `rules_json` | `SeasonRules::to_array()`, die Regeln dieser Saison (z. B. `substitute_max`, `cup_mode`) |

## Clubs, Lokale, Personen, Spieler

| Tabelle | Konvention |
|---|---|
| `club.number` | Clubnummer des Verbands (eindeutig); ein Pseudo-Club «Gäste» ist möglich |
| `club.name` / `short_name` / `slug` | `TTC Seefeld` / `Seefeld` / `seefeld` (Umlaute als ae, oe, ue) |
| `venue.weekday` | 1 = Montag … 7 = Sonntag |
| `venue.open_time`, `close_time` | `HH:MM:SS` |
| `person.last_name`, `first_name` | getrennt gespeichert; Importe aus Altsystemen liefern die Aufteilung |
| `player.license_no` | Lizenznummer, stabil über alle Saisons |
| `player_season.classification` | offizielle Klassierung 1 bis 22 dieser Saison (Anzeige D1 bis A22 über `ClassificationScale::label()`) |
| `club_role.role` | `tk_chef` oder `captain` (mit `team_id`) |

## Konkurrenzen (`competition`)

| `type` | Bedeutung | Felder |
|---|---|---|
| `MM` | eine Gruppe der Mannschaftsmeisterschaft | `category` `A` bis `D`, `group_no` 1, `name` «Gruppe A», `sort_order` 1 bis 4 |
| `CUP` | eine Kategorie des Mannschaftscups | `category` `A` oder `B`, `name` «Mannschaftscup A» |
| `RL` | Ranglistenturnier der Saison | `category` null, `name` «Ranglistenturnier» |

## Teams (`team`, `team_roster`)

- `name` wie «Seefeld 1», `number` = Mannschaftsnummer, `competition_id` = MM-Gruppe.
- `home_venue_id` und `default_start_time` aus der Lokalzuordnung des Clubs.
- `team_roster.kind`: `stamm` (gemeldete Stammspieler) oder `bound` (durch Ersatzeinsätze gebunden, `valid_from` = Tag des bindenden Einsatzes; folgt aus den Einsätzen und wird nach jeder Erfassung neu bestimmt, siehe erfassung.md).

## Begegnungen (`fixture`)

| Feld | Konvention |
|---|---|
| `competition_id` | MM-Gruppe oder Cup-Kategorie |
| `home_team_id`, `away_team_id` | nur MM |
| `home_entry_id`, `away_entry_id` | nur Cup (`cup_entry`) |
| `match_no` | laufende Nummer innerhalb der Gruppe (MM) bzw. Spielnummer (Cup) |
| `leg` | MM: 1 Vorrunde, 2 Rückrunde |
| `scheduled_at` | Datum und Anspielzeit, Ortszeit; ohne bekannte Zeit die Standardzeit des Heimteams |
| `status` | `scheduled`, `provisional` (erfasst, unbestätigt), `confirmed`, `disputed`, `final`, `postponed`, `forfeit`, `cancelled` |
| `home_score`, `away_score` | offizielles Resultat; Forfait 10:0 bzw. 0:10 ohne Spiele |

Importierte, gespielte Begegnungen haben den Status `final`. Tabellen zählen `provisional`, `confirmed`, `disputed`, `final` und `forfeit`.

## Aufstellung (`lineup`)

- `side`: `home` oder `away`.
- `slot`: Meisterschaft `A`, `B`, `C` (Heim) und `X`, `Y`, `Z` (Gast); Cup `A`, `B` (Heim) und `Y`, `Z` (Gast). Fehlende Spieler haben keine Zeile.
- `is_substitute`: 1, wenn der Spieler nicht Stammspieler des Teams (MM) bzw. nicht gemeldeter Spieler der Cup-Mannschaft ist.

## Spiele (`game`)

| Feld | Konvention |
|---|---|
| `fixture_id` oder `rl_group_id` | genau eines ist gesetzt |
| `sequence` | MM 1 bis 10 in Spielfolge (Doppel = 7), Cup 1 bis 5 (Doppel = 3), RL fortlaufend |
| `type` | `single` oder `double` |
| `a1_player_id`, `a2_player_id` | Heimseite (MM, Cup) bzw. links genannter Spieler (RL); `a2` nur im Doppel |
| `b1_player_id`, `b2_player_id` | Gastseite bzw. rechts genannter Spieler |
| `sets_a`, `sets_b` | gewonnene Sätze; `walkover`: Sieger 3, Verlierer 0; `not_played`: 0:0 |
| `status` | `played`, `walkover` (kampflos oder Sieger ohne Satzresultat bekannt), `not_played` |
| `counts_for_result` | 0 für Cup-Spiele nach der Entscheidung (ab 2025/26 bei `first_to_three`) |
| `played_at` | Zeitpunkt der Begegnung bzw. RL-Gruppe |

Satz- und Punkteverhältnisse zählen nur Spiele mit `status = played`.

## Cup (`cup_entry`, `cup_tie`)

- `cup_entry`: gemeldete 2er-Mannschaft mit `name` «Seefeld 1», `player1_id`, `player2_id`, `points_total` (Summe der Klassierungen bei der Meldung), `seed` (Setzung).
- `cup_entry.status`: `active` (Standard) oder `withdrawn`.
- `cup_tie` (Tableau-Positionen) ist optional; Importe aus Altsystemen können es leer lassen.

## Ranglistenturnier (`rl_*`)

- `rl_round.round_no` 1 bis 3, Finalrunde 4 mit `is_final` 1.
- `rl_round.status`: `planned`, `published` (aufgeboten) oder `closed`. Der Standardwert ist `closed`, damit Runden aus Altdaten als abgeschlossen gelten. Ein Import, der eine laufende Saison übernimmt, setzt aufgebotene, noch nicht gespielte Runden auf `published`.
- `rl_group.category` 1 bis 3, `group_no` ab 1, `start_at` Ortszeit, `host_club_id` Platzclub, `note` Rohtext des Aufgebots.
- `rl_group_player.status`: `played`, `absent`, `disqualified`, `forfeit` oder `scheduled` (aufgeboten, Gruppe hat noch nicht gespielt); `rank_no` und `points` nach `GroupRanking`, bei `scheduled` leer.
- `rl_group.venue_id`: Lokal des Platzclubs, sofern bekannt.
- `rl_participant.category` 1 bis 3; `status` `registered` (Standard) oder `withdrawn`.

## Wertungszahl (`player_rating`, `rating_event`)

- `player_rating.rating`: aktuelle Wertungszahl (Klassierung × 100 entspricht der Mitte einer Stufe).
- `rating_event`: ein Eintrag pro Spieler und gewertetem Spiel mit Wert vorher, nachher und Differenz, chronologisch über `played_at`.
- Vollständig neu berechenbar mit `wp ttleague rating recalc`.

## Herkunft (`source_site`, `source_type`, `source_id`)

Importierte Zeilen tragen ihre Quelle, z. B. `altsystem:2425`, `fixture`, `21`. Ein erneuter Import aktualisiert dieselben Zeilen statt neue anzulegen.
