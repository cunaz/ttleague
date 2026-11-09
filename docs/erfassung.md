# Konten, Rechte und Resultaterfassung

Dieses Dokument ist der Vertrag für Phase 2: Konten, Anmeldung, Rechte, Statusübergänge der Begegnungen, die REST-API der Erfassung, Protokoll und Benachrichtigungen. Ergänzt `docs/datenmodell.md`.

## Konten

- Eine Person (`person`) hat höchstens ein WordPress-Konto (`person.user_id`). Konten entstehen nur über Einladungen oder die erste Anmeldung einer Person mit hinterlegter E-Mail-Adresse; offenes Registrieren gibt es nicht.
- Anmeldename `ttl-{person_id}`, Anzeigename «Vorname Nachname», E-Mail aus `person.email`, Rolle `subscriber`. Mitglieder der TK erhalten zusätzlich die Rolle `ttl_tk`.
- Funktionen (Captain, TK-Chef) stehen in `club_role` der aktiven Saison und werden nie als WordPress-Rolle gespeichert.
- Ein Konto gehört höchstens einer Person (UNIQUE-Index auf `person.user_id`). Ist ein Konto aus Altdaten doch mehreren Personen zugeordnet, gibt es dafür keine Person (keine fachlichen Rechte) und keine Anmeldung per Link, bis die Zuordnung bereinigt ist.
- Gibt es schon ein WordPress-Konto mit der E-Mail-Adresse der Person, wird es nur verknüpft, wenn es zu keiner anderen Person gehört und keine erweiterten Rechte hat (`manage_options`, `edit_posts`, `ttl_tk`). Sonst schlägt die Anmeldung mit einem Hinweis fehl. Ein Administrator verknüpft solche Konten von Hand: `wp ttleague account link <person-id> <konto>` und `wp ttleague account unlink <person-id>`.

## Anmeldung mit Magic Link

1. Seite `/anmelden/` (Block `ttleague/login`): E-Mail eingeben. Antwort ist immer neutral («Falls die Adresse bei uns hinterlegt ist, haben wir dir einen Link geschickt»), damit niemand Adressen ausprobieren kann.
2. Gibt es eine Person mit dieser Adresse, wird ein Token erzeugt: 32 Zufallsbytes, gespeichert wird nur der SHA-256-Hash in `login_token`, gültig 15 Minuten (Einladung 7 Tage), einmal verwendbar. Höchstens 5 Anfragen pro Adresse und 20 pro IP-Adresse und Stunde. Das Rate-Limit zählt über Transients und ist nicht atomar: Gleichzeitige Anfragen können es knapp überschreiten. Es bremst Mail-Fluten, ist aber bewusst keine harte Grenze.
3. Der Link führt auf `/anmelden/?anmeldung={token}`. Diese Seite meldet nicht sofort an, sondern zeigt einen Knopf «Jetzt anmelden» (POST). So verbrauchen Link-Scanner von E-Mail-Programmen das Token nicht. Der POST muss von der eigenen Seite kommen: Schema, Host und Port aus Origin oder Referer müssen zur Website passen (Schutz vor Login-CSRF, denn der Nonce ist für alle abgemeldeten Besucher gleich).
4. Nach dem POST: Token prüfen und verbrauchen, Konto anlegen falls nötig, anmelden mit «angemeldet bleiben» (60 Tage), weiter zu `redirect_to` (nur eigene Seiten) oder `/mein-bereich/`.
5. Rückfall: Anmeldung mit Passwort über `wp-login.php` bleibt möglich (Passwort setzen über «Passwort vergessen»). Passkeys und Zwei-Faktor für die TK über ein etabliertes Plugin.
6. Administratorkonten melden sich nie per Link an, nur mit Passwort (und gegebenenfalls zweitem Faktor). Fordert eine Person mit Administratorkonto einen Link an, erhält sie stattdessen einen Hinweis mit dem Link zur Passwort-Anmeldung. Anpassbar über den Filter `ttleague_magic_link_allowed` (`bool $allowed`, `int $user_id`).
7. Auf `/anmelden/` und `/mein-bereich/` gelten `noindex`, keine Zwischenspeicherung und `Referrer-Policy: same-origin`, damit das Token in der Adresse nicht an fremde Seiten gelangt.
8. Konten ohne redaktionelle Rechte (`edit_posts`) und ohne `ttl_tk` sehen keine Werkzeugleiste und werden aus dem Backend nach `/mein-bereich/` umgeleitet (`admin-ajax.php` und `admin-post.php` bleiben erreichbar).
9. Block `ttleague/account-link` für die Kopfzeile: Symbol mit «Anmelden» für Gäste, «Mein Bereich» für Angemeldete.

**Zeiten:** Tokens, Protokolle und Zeitstempel (`created_at`, `entered_at`, `sent_at`) sind UTC. Spieltermine (`fixture.scheduled_at`) sind Ortszeit der Website. Fristen rechnen deshalb in der Zeitzone der Website (`wp_timezone()`).

## Einwilligung und Mein Bereich

- Beim ersten Login muss die Person den Datenschutzhinweis bestätigen (`person.consent_at`). Bis dahin zeigt `/mein-bereich/` nur die Einwilligung.
- `/mein-bereich/` (Block `ttleague/my-area`) zeigt Profil (Name, E-Mail, Telefon mit Sichtbarkeit), Funktionen in der aktiven Saison, Benachrichtigungseinstellungen und weitere Abschnitte, die Module über die Aktion `ttleague_my_area_sections` (Parameter: `MyAreaContext`) einhängen, z. B. «Meine Spiele» oder «Mein Club».

## Rechte

Alle fachlichen Rechte sind Meta-Capabilities, die `map_meta_cap` aus den Daten der aktiven Saison ableitet. Wer `ttl_tk` hat (Rolle `ttl_tk` und Administratoren), darf alles.

| Capability | Argument | Erlaubt für |
|---|---|---|
| `ttl_tk` | – | Rolle `ttl_tk`, Administratoren |
| `ttl_enter_result` | Fixture-ID | MM: Captain des Heimteams, TK-Chef des Heimclubs. Cup: Spieler der Heim-Cup-Mannschaft, TK-Chef ihres Clubs |
| `ttl_confirm_result` | Fixture-ID | wie oben für die Gastseite |
| `ttl_manage_club` | Club-ID | TK-Chef des Clubs |
| `ttl_invite_person` | Person-ID | TK-Chef des Clubs, für den die Person in der aktiven Saison gemeldet ist oder eine Funktion hat |

Ausserhalb der aktiven Saison gibt es nur `ttl_tk`. Ob eine Aktion im aktuellen Status erlaubt ist, entscheidet der `ResultService`, nicht die Capability.

Weitere Regeln:

- Spielt ein Club gegen sich selbst (Clubduell), hat der TK-Chef dieses Clubs beide Rechte. Bestätigen darf trotzdem nie, wer das Resultat erfasst oder zuletzt geändert hat; ausgenommen ist die TK. Das prüft der `ResultService`.
- E-Mail-Adressen ändern TK-Chefs nur für Personen ihres Clubs, deren Konto keine erweiterten Rechte hat (`ttl_tk`, `edit_posts`, `manage_options`). Sonst könnten sie über einen Anmeldelink fremde Konten übernehmen. Bei jeder Änderung erhält die bisherige Adresse eine Mitteilung.

## Status einer Begegnung

| Von | Aktion | Nach | Wer |
|---|---|---|---|
| `scheduled`, `postponed` | Resultat erfassen | `provisional` | Heimseite |
| `provisional` | Resultat ändern | `provisional` | Heimseite, solange nicht bestätigt |
| `provisional` | bestätigen | `confirmed` | Gastseite oder TK |
| `provisional` | Einsprache mit Begründung | `disputed` | Gastseite |
| `disputed` | entscheiden (übernehmen oder korrigieren) | `final` | TK |
| `confirmed` | korrigieren oder abschliessen | `final` | TK |
| beliebig | Forfait | `forfeit` | TK |
| beliebig | zurücksetzen | `scheduled` | TK |

Tabellen zählen `provisional`, `confirmed`, `disputed`, `final` und `forfeit`. Bei `provisional` und `disputed` wird das Resultat als vorläufig markiert.

## REST-API

Basis `/wp-json/ttleague/v1`, Authentifizierung über das WordPress-Cookie mit `X-WP-Nonce`. Fehlertexte auf Deutsch. Validierungsfehler: HTTP 422 mit `{ "code": "ttl_invalid", "message": "...", "data": { "status": 422, "errors": [ { "field": "...", "message": "..." } ] } }`.

### `GET fixtures/{id}/entry`

Liefert alles für das Formular:

```json
{
  "fixture": { "id": 1, "type": "MM", "status": "scheduled", "scheduled_at": "2026-11-10 20:00:00", "competition": "Gruppe A", "season": "2026-27",
    "home": { "name": "Seefeld 1", "team_id": 3, "entry_id": null, "club_id": 1 }, "away": { "name": "Altstetten 1", "team_id": 4, "entry_id": null, "club_id": 2 },
    "home_score": null, "away_score": null },
  "can": { "enter": true, "confirm": false, "decide": false },
  "rules": { "sets_to_win": 3, "set_points_enabled": false, "cup_mode": "first_to_three", "slots": { "home": ["A","B","C"], "away": ["X","Y","Z"] } },
  "order": [ { "sequence": 1, "type": "single", "home": "A", "away": "X" } ],
  "candidates": { "home": [ { "player_id": 7, "name": "Hans Muster", "classification": 6, "label": "C6", "eligible": true, "reason": "stamm", "message": "Stammspieler.", "substitute_appearances": 0, "remaining": null } ], "away": [] },
  "current": { "lineup": { "home": { "A": 7 }, "away": {} }, "games": [], "entered_by": null, "entered_at": null, "warnings": [] },
  "history": [ { "type": "entered", "by": "Hans Muster", "at": "2026-11-10T21:30:00Z", "comment": null } ]
}
```

`candidates` enthält alle Spieler des Clubs mit Spielberechtigung für dieses Team (`EligibilityChecker`), sortiert nach Stammspielern, dann Klassierung.

### `POST fixtures/{id}/result`

```json
{
  "lineup": { "home": { "A": 7, "B": 8, "C": null }, "away": { "X": 11, "Y": 12, "Z": 13 } },
  "games": [
    { "sequence": 1, "status": "played", "sets_home": 3, "sets_away": 1, "set_points": [[11,8],[9,11],[11,5],[11,7]] },
    { "sequence": 3, "status": "walkover", "winner": "away" },
    { "sequence": 7, "status": "played", "sets_home": 2, "sets_away": 3, "home_players": [7, 8], "away_players": [11, 12] }
  ],
  "photo_id": null,
  "comment": null
}
```

- Ein Spieler pro Slot, jeder Spieler höchstens einmal pro Seite, mindestens zwei Spieler pro Seite.
- Alle Spiele der Spielfolge müssen vorhanden sein (MM 10, Cup 5). Im Cup dürfen Spiele nach dem dritten Sieg fehlen oder als `not_played` kommen.
- `status` `played` (Sätze nach Gewinnsätzen), `walkover` (mit `winner`), `not_played`. Fehlt ein Spieler, sind seine Einzel `walkover` für die Gegenseite (`LineupValidator`).
- Doppel: `home_players` und `away_players` aus der Aufstellung der Seite.
- `set_points` nur, wenn `set_points_enabled`.
- Antwort 200: `{ "status": "provisional", "home_score": 6, "away_score": 4, "warnings": [ ... ] }`.

**Harte Fehler (422):** Spieler nicht spielberechtigt (anderer Club, an anderes Team gebunden, Stammspieler eines höheren Teams), unvollständige oder widersprüchliche Spiele, Status erlaubt keine Erfassung.

**Hinweise (`warnings`):** Bleiben gespeichert und erscheinen im TK-Dashboard, z. B. «3. Ersatzeinsatz: Spieler ist ab jetzt an dieses Team gebunden».

### Weitere Endpunkte

| Methode und Pfad | Inhalt | Recht |
|---|---|---|
| `POST fixtures/{id}/confirm` | `{}` | `ttl_confirm_result` oder `ttl_tk` |
| `POST fixtures/{id}/objection` | `{ "reason": "…" }` (Pflicht, 10 bis 2000 Zeichen) | `ttl_confirm_result` |
| `POST fixtures/{id}/decision` | `{ "action": "confirm"\|"finalize"\|"correct"\|"forfeit"\|"reset", "winner": "home"\|"away", "result": { wie POST result }, "comment": "…" }` | `ttl_tk` |
| `POST fixtures/{id}/photo` | Multipart `file` (JPEG, PNG, HEIC, höchstens 10 MB) → `{ "attachment_id": 5, "url": "…" }` | `ttl_enter_result` |
| `GET me/fixtures` | `{ "to_enter": [], "to_confirm": [], "upcoming": [] }` für die angemeldete Person | angemeldet |

## Protokoll

- `fixture_event`: jedes fachliche Ereignis (`entered`, `edited`, `confirmed`, `disputed`, `decided`, `forfeit`, `reset`) mit Person, Kommentar, Zusatzdaten (z. B. Hinweise) und Zeitpunkt. Wird im Matchreport als Verlauf gezeigt.
- `audit_log`: technische Nachvollziehbarkeit jeder Änderung an `fixture`, `lineup`, `game` mit Zustand vorher und nachher als JSON.

## Ereignisse

Der `ResultService` löst WordPress-Aktionen aus; Benachrichtigungen, Dashboard und Wertungszahl hängen sich daran:

| Aktion | Parameter |
|---|---|
| `ttleague_result_entered` | Fixture-ID, User-ID, `bool $edited` |
| `ttleague_result_confirmed` | Fixture-ID, User-ID, `bool $by_tk` |
| `ttleague_result_disputed` | Fixture-ID, User-ID, Begründung |
| `ttleague_result_decided` | Fixture-ID, User-ID, Aktion, Kommentar |
| `ttleague_data_changed` | Bereich (z. B. `fixture`), ID |

Nach jeder Änderung: `DataVersion::bump()` und eine entprellte Neuberechnung der Wertungszahl (`ttleague_rating_recalc`, einmalig geplant in 2 Minuten).

## Benachrichtigungen und Fristen

| Ereignis | Empfänger | Zeitpunkt |
|---|---|---|
| Spiel steht an | Captains und TK-Chefs beider Seiten | 2 Tage vorher |
| Resultat fehlt | Heimseite | 24 h nach Anspielzeit |
| Resultat fehlt weiterhin | TK | 48 h nach Anspielzeit |
| Bitte bestätigen | Gastseite | sofort nach Erfassung |
| Bestätigung ausstehend | Gastseite | 3 und 6 Tage nach Erfassung |
| Bestätigung durch die TK nötig | TK | 7 Tage nach Erfassung |
| Einsprache | TK und Heimseite | sofort |
| Entscheid der TK | beide Seiten | sofort |
| Forfait-Vorschlag | TK | 16 Tage nach Anspielzeit ohne Resultat |

Jede Benachrichtigung wird pro Person, Ereignis, Bezug und Kanal genau einmal versendet (`notification_log`). Personen wählen pro Ereignistyp E-Mail und Push (`notification_pref`); ohne Eintrag gilt E-Mail an.
