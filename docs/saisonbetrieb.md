# Saisonbetrieb

Dieses Dokument ist der gemeinsame Vertrag für Phase 3: Meldung, Saison aufsetzen, Spielplan, Verschiebung, Ranglistenturnier, Cup, Klassierung und Anlässe. Es legt fest, was alle Module teilen. Jedes Modul hat dazu ein eigenes Dokument. Ergänzt `docs/erfassung.md`.

| Modul | Dokument | Namensraum |
|---|---|---|
| Online-Meldung der Clubs | [meldung.md](meldung.md) | `TTLeague\Registration` |
| Saison anlegen, einteilen, freigeben, abschliessen | [saison.md](saison.md) | `TTLeague\Season` |
| Spielplan-Generator | [spielplan.md](spielplan.md) | `TTLeague\Domain\Schedule` |
| Verschiebung und Terminvorschlag | [verschiebung.md](verschiebung.md) | `TTLeague\Postponement` |
| Ranglistenturnier | [ranglistenturnier.md](ranglistenturnier.md) | `TTLeague\RankingTournament` |
| Mannschaftscup | [cup.md](cup.md) | `TTLeague\Cup` |
| Klassierung zum Saisonende | [klassierung.md](klassierung.md) | `TTLeague\Classification` |
| Anlässe mit Anmeldung | [anlaesse.md](anlaesse.md) | `TTLeague\Events` |

## Grundsätze

- **Clubs und Personen arbeiten auf der Website**, in «Mein Bereich». Die TK arbeitet im Backend unter «Spielbetrieb».
- **Jeder Schreibvorgang läuft über einen Dienst des Moduls**, in einer Transaktion, mit Eintrag im `audit_log` und danach `DataVersion::bump()`. Formulare und REST-Routen rufen nur Dienste auf.
- **Regeln des Reglements sind Parameter** in `SeasonRules` oder `competition.format_json`, keine Konstanten im Code.
- **Nichts wird ohne Menschen definitiv.** Das System schlägt vor (Gruppen, Spielplan, Auslosung, Klassierung), die TK gibt frei.
- Texte, Escaping, Rechteprüfung und Tests wie in `docs/erfassung.md`.

## Saisonkontext

Eine Saison ist `planned`, `active` oder `archived`. Es gibt höchstens eine aktive und höchstens eine geplante Saison.

| Status | Bedeutung | Sichtbar |
|---|---|---|
| `planned` | Die TK setzt die Saison auf, die Clubs melden. | Nur für TK und TK-Chefs. Öffentliche Ansichten, Kalender-Abos und die Saisonwahl kennen sie nicht. |
| `active` | Laufender Spielbetrieb. | Öffentlich. |
| `archived` | Abgeschlossen. | Öffentlich. Captains und TK-Chefs können nichts mehr ändern; die TK kann ein Resultat weiterhin korrigieren (wie in `docs/erfassung.md`, mit Eintrag im Protokoll). |

- `ReadModel\Seasons` liefert nur aktive und archivierte Saisons. Auch Matchreport, «nächste Spiele» über alle Saisons und das Spielerprofil lassen Daten der geplanten Saison weg. Die Schreibseite liest über `Season\SeasonRepository` (`active()`, `planned()`, `by_id()`, `rules()`), ohne Cache; ungültige Regeln sind dort ein Fehler und werden nicht durch Standardwerte ersetzt.
- Ein Modul, das Daten der geplanten Saison auf der Website zeigt (Meldung in «Mein Club»), prüft das Recht selbst und verwendet keine öffentliche Ansicht.
- **Funktionen in der geplanten Saison:** Beim Anlegen trägt der Assistent die TK-Chefs der aktiven Saison in die geplante vor (`club_role`). `ttl_manage_club` gilt für TK-Chefs der aktiven **oder** der geplanten Saison. Captains der geplanten Saison haben bis zur Freigabe keine Rechte an Begegnungen.
- **Die Freigabe** macht die geplante Saison aktiv und archiviert die bisher aktive, in einer Transaktion (siehe [saison.md](saison.md)).
- Erfassung, Fristen und Benachrichtigungen aus Phase 2 arbeiten weiter nur auf der aktiven Saison. Begegnungen der geplanten Saison sind ein Entwurf: Die Erfassung nimmt für sie kein Resultat an, auch nicht von der TK.

## Rechte

Neue Meta-Capabilities, abgeleitet wie die bestehenden (`docs/erfassung.md`, «Rechte»). `ttl_tk` darf alles.

| Capability | Argument | Erlaubt für |
|---|---|---|
| `ttl_manage_club` | Club-ID | TK-Chef des Clubs in der aktiven oder der geplanten Saison (erweitert). `AccessPolicy::managed_clubs()` liefert die Clubs einer Person. `ttl_invite_person` bleibt bei den TK-Chefs der aktiven Saison. |
| `ttl_reschedule_fixture` | Fixture-ID | Wer für die Begegnung `ttl_enter_result` oder `ttl_confirm_result` hätte: beide Seiten |
| `ttl_enter_rl_group` | ID der RL-Gruppe | Gruppenchef (`rl_group.chief_player_id`), TK-Chef seines Clubs, TK-Chef des Platzclubs; nur in der aktiven Saison. Den Status der Runde prüft der Dienst. |
| `ttl_view_contacts` | – | Personen mit Funktion in der aktiven Saison (siehe `docs/erfassung.md`) |

Ohne eigene Capability, aber mit Prüfung im Dienst:

- **Für sich selbst** handelt jede angemeldete Person mit verknüpfter `person`: Sperrdaten, Anmeldung zum Ranglistenturnier, Anmeldung zu Anlässen.
- **Für andere** handelt der TK-Chef des Clubs, für den die Person in der betroffenen Saison gemeldet ist (`ttl_manage_club`), und die TK.

## Schema

Schemaversion 2026111007 bringt alle Änderungen der Phase auf einmal. Module ändern `Schema.php` nur, wenn ihr Dokument es verlangt; die Versionsnummer wird dann fortlaufend erhöht.

| Tabelle | Änderung | Für |
|---|---|---|
| `season` | `registration_opens`, `registration_closes`, `late_registration_until` (Datum), `schedule_json`, `classification_closed_at` | Meldung, Spielplan, Klassierung |
| `club_season` (neu) | Teilnahme und Meldestand eines Clubs: `season_id`, `club_id`, `status` (`open`, `submitted`, `reviewed`), `submitted_at`, `submitted_by`, `reviewed_at`, `reviewed_by`, `note` | Meldung |
| `player_request` (neu) | Meldung einer neuen Person ohne Lizenznummer: `season_id`, `club_id`, `last_name`, `first_name`, `email`, `classification`, `stt_license`, `status` (`requested`, `approved`, `rejected`), `player_id` nach Freigabe, `requested_by`, `requested_at`, `decided_by`, `decided_at`, `note` | Meldung |
| `player_season` | `registered_by`, `approved_by`, `approved_at`; `status` neu auch `provisional` | Meldung |
| `postponement` | `kind` (`postpone`, `propose`), `reason`, `response_note`, `responded_at`, `approved_at` | Verschiebung |
| `cup_entry` | `status` (`active`, `withdrawn`), `registered_by`, `registered_at` | Cup |
| `rl_participant` | `status` (`registered`, `withdrawn`), `registered_by`, `registered_at` | Ranglistenturnier |
| `rl_round` | `status` (`planned`, `published`, `closed`), `closed_by`, `closed_at` | Ranglistenturnier |
| `rl_group` | `entered_by`, `entered_at` | Ranglistenturnier |
| `classification_proposal` | `games_count` | Klassierung |
| `event` | `ends_at`, `location`, `status` (`open`, `closed`, `cancelled`), `created_by` | Anlässe |

Bestehende Zeilen: Runden des Ranglistenturniers erhalten den Standardwert `closed`, Cup-Meldungen `active`, Teilnahmen `registered`. So gelten importierte Saisons als abgeschlossen, ohne Datenmigration. Dienste setzen den Status neuer Runden ausdrücklich (`planned`). Aufgebotene, noch nicht gespielte Runden einer übernommenen laufenden Saison setzt der Import auf `published` (`docs/datenmodell.md`).

«Höchstens eine aktive und eine geplante Saison» sichern die Dienste in `Season` beim Anlegen und bei der Freigabe, nicht das Schema.

Neue Parameter in `SeasonRules`:

| Parameter | Standard | Bedeutung |
|---|---|---|
| `schedule_tables_per_match` | 2 | Tische pro Begegnung für die Kapazitätsprüfung des Spielplans |
| `classification_min_games` | 10 | Unter so vielen gewerteten Spielen schlägt das System keine Änderung der Klassierung vor |
| `rl_group_size` | 6 | Grösse einer Gruppe im Ranglistenturnier; grössere Gruppen erhalten den Zusatzpunkt (`rl_seven_player_bonus`) |
| `rl_group_size_max` | 7 | Ausnahmsweise erlaubte Grösse |

Parameter einer einzelnen Konkurrenz stehen in `competition.format_json`: `points_limit` (Cup, Art. 19.2), `categories` (Ranglistenturnier: Grenzen der Klassierung je Kategorie), `registration_deadline`.

## Erweiterungspunkte im Plugin

Damit Module einander nicht in dieselben Dateien schreiben:

- **Start:** `Plugin::boot()` ruft `register()` der Modulklassen auf, sobald sie existieren (`Plugin::MODULES`: `Registration\Registration`, `Season\SeasonAdmin`, `Postponement\Postponements`, `RankingTournament\RankingTournament`, `Cup\Cup`, `Classification\Classification`, `Events\Events`). Ein Modul hängt in `register()` nur Hooks ein.
- **Backend:** Seiten unter «Spielbetrieb» meldet ein Modul über den Filter `ttleague_tk_pages` an: `slug` (`ttleague-tk-…`), `title`, `render` (aufrufbar), `position` (Standard 50; «Konten und Einladungen» steht auf 90). Alle verlangen `ttl_tk`. Adresse über `Tk::page_url()`. Formulare gehen wie bisher an `admin-post.php` mit Nonce, Rückmeldung über `Flash`.
- **Mein Bereich:** Abschnitte über die Aktion `ttleague_my_area_sections` (wie «Meine Spiele» und «Mein Club»).
- **Benachrichtigungen:** Ereignistypen meldet ein Modul über den Filter `ttleague_notification_types` an (Typ => Bezeichnung; Konstanten in `Notifications\EventTypes`). Sie erscheinen damit in den Einstellungen in «Mein Bereich»; ohne Eintrag gilt wie bisher E-Mail an, Push aus. Ein Modul baut eine `Notification`, holt die Empfänger über `Notifications::recipients()` und versendet mit `Notifications::notifier()->send()`; Dedupe über `ref_type`, `ref_id` und `ref_key` wie in Phase 2.
- **TK-Übersicht:** Listen der Übersicht über den Filter `ttleague_tk_overview_sections`: `id` (Anker), `title`, `count`, `html` (fertiges, escaptes HTML). Sie stehen nach den Listen der Erfassung.
- **Exporte:** Listen über den Filter `ttleague_exports` (siehe «Exporte»).

Reservierte Ereignistypen:

| Typ | Modul | Empfänger |
|---|---|---|
| `registration_reminder` | Meldung | TK-Chefs vor Ablauf des Meldefensters |
| `registration_decided` | Meldung | TK-Chef nach Prüfung oder Freigabe eines Spielers |
| `season_published` | Saison | Captains und TK-Chefs bei der Freigabe des Spielplans |
| `postponement` | Verschiebung | beide Seiten: Antrag, Antwort, Entscheid |
| `postponement_tk` | Verschiebung | TK: zur Kenntnis oder zur Bewilligung |
| `rl_callup` | Ranglistenturnier | Aufgebot an die Spieler einer Gruppe |
| `rl_reminder` | Ranglistenturnier | Anmeldeschluss und fehlende Sperrdaten |
| `cup_round` | Cup | Auslosung und neue Runde an die Cupmannschaften |
| `cup_deadline` | Cup | Erinnerung vor Ablauf der Rundenfrist |
| `event_registration` | Anlässe | Bestätigung der An- oder Abmeldung |

## Exporte

Jedes Modul bietet seine Listen als CSV an (UTF-8 mit BOM, Semikolon, damit Excel sie direkt öffnet): im Backend als Download mit Nonce und `ttl_tk`, dazu `wp ttleague export <liste>` (ohne Namen zeigt der Befehl die Listen).

Ein Modul meldet eine Liste über den Filter `ttleague_exports` an: Name (Kleinbuchstaben, Ziffern, Bindestrich) => `title`, `header` (Spaltentitel) und `rows` (aufrufbar; erhält die Filter der Anfrage als Zeichenketten, prüft sie selbst und liefert die Zeilen). Den Link baut `Export\Exports::url()`. `Infrastructure\Csv` schreibt die Datei und entschärft Textzellen, die eine Tabellenkalkulation als Formel lesen würde.

## Tests

Wie in `docs/erfassung.md`: Regeln als Unit-Tests der Domäne, Dienste als Integrationstests gegen MariaDB, WordPress-nahe Teile (Rechte, Formulare, REST) mit wp-phpunit. Jeder Test zu einem Fehler muss ohne die Korrektur scheitern.
