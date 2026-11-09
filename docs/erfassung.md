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
6. Konten mit erweiterten Rechten (Verwaltung `manage_options`, Redaktion `edit_posts`, TK `ttl_tk`) melden sich standardmässig nicht per Link an, sondern mit Passwort (und gegebenenfalls zweitem Faktor): Wer ihr Postfach liest, bekäme sonst diese Rechte. Ist die Person mit einem solchen Konto verknüpft, erhält sie auf die Anfrage statt des Links eine E-Mail mit dem Hinweis auf die Passwort-Anmeldung. Wird ein Link für ein solches Konto eingelöst (etwa eine ältere Einladung), bricht die Anmeldung mit demselben Hinweis auf `/anmelden/` ab. Der Filter `ttleague_magic_link_allowed` (`bool $allowed`, `int $user_id`) entscheidet pro Konto und kann die Anmeldung per Link auch für Konten mit erweiterten Rechten erlauben; wer das tut, nimmt dieses Risiko in Kauf.
7. Auf `/anmelden/` und `/mein-bereich/` gelten `noindex`, keine Zwischenspeicherung und `Referrer-Policy: same-origin`, damit das Token in der Adresse nicht an fremde Seiten gelangt.
8. Konten ohne erweiterte Rechte (`manage_options`, `edit_posts`, `ttl_tk`, dieselbe Grenze wie in Punkt 6) sehen keine Werkzeugleiste und werden aus dem Backend nach `/mein-bereich/` umgeleitet (`admin-ajax.php` und `admin-post.php` bleiben erreichbar).
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

Forfait und Zurücksetzen löschen Aufstellung, Spiele und deren Hinweise (`current.warnings` ist danach leer). Das Forfait behält das Foto als Beleg. Zurücksetzen führt zum Ausgangszustand zurück und löst auch das Foto von der Begegnung; der Anhang bleibt in der Mediathek, und der Verlauf behält das Ereignis `photo`.

## Erfassungsseite

Seite «Resultat erfassen» (Schlüssel `entry`, Pfad `/erfassen/`, Block `ttleague/result-entry`), Aufruf mit `?begegnung={id}`. Adresse immer über `Results::entry_url( $fixture_id )`. Dort erfasst die Heimseite, die Gastseite bestätigt oder erhebt Einsprache, die TK entscheidet.

- Wie Anmelden und Mein Bereich: `noindex`, keine Zwischenspeicherung, `Referrer-Policy: same-origin`.
- Abgemeldet: Hinweis und Link zur Anmeldung mit `redirect_to` zurück auf die Seite. Ohne Rolle für die Begegnung (oder ohne verknüpfte Person): verständlicher Hinweis mit Links zum Matchreport und zu «Mein Bereich».
- Mit Rolle rendert der Block den Startzustand aus `ResultService::entry()` serverseitig; danach übernimmt das Skriptmodul `@ttleague/entry` (`assets/js/entry.js`, Interactivity API, Namensraum `ttleague/entry`, ohne Build-Schritt). Es spricht nur die REST-API an (Cookie mit `X-WP-Nonce`). Bis es bereit ist, bleiben Knöpfe und Felder gesperrt; ohne JavaScript erscheint ein Hinweis.

**Formular (Heimseite, TK):**

- Aufstellung: pro Slot eine Auswahl aus `candidates`, gruppiert nach Stammspieler, gebunden, Ersatz und nicht spielberechtigt. Nicht berechtigte Spieler sind sichtbar, aber gesperrt (Kurzgrund in der Option, volle Begründung in der aufklappbaren Liste); im Korrekturmodus der TK sind sie wählbar, weil die API dort nur Hinweise gibt. «fehlt (nicht angetreten)» sendet den Slot als `null`; ein leerer Slot ist ein Fehler.
- Spiele in Spielfolge (Cup fünf): Schnellknöpfe 3:0 bis 0:3 (nochmals tippen hebt die Wahl auf), Schalter «kampflos» mit Siegerseite, Doppelpaarung pro Seite (bei genau zwei Spielern automatisch, dann nicht gesendet), Satzpunkte nur bei `set_points_enabled` (freiwillig, nur vollständig gesendet).
- Fehlt ein Spieler, schlägt das Formular «kampflos» für die andere Seite vor (fehlen beide: «nicht gespielt»), solange das Spiel nicht ausdrücklich erfasst ist. Widerspricht ein erfasstes Spiel der Aufstellung, erscheint ein Hinweis mit «Übernehmen». Im Cup mit `first_to_three` sind die Spiele nach dem dritten Sieg freiwillig; nicht erfasste werden nicht gesendet.
- Laufendes Gesamtresultat (`aria-live`) in der Fusszeile mit «Speichern», Bemerkung, Foto des Spielberichts (`capture="environment"`, Upload über `POST photo` gleich nach der Auswahl).
- Absenden: `POST result` mit `version`, im Korrekturmodus der TK `POST decision` mit `action: correct`. Pflichtangaben prüft schon der Browser. Feldfehler (422) erscheinen am Feld und in einer Übersicht mit Sprungmarken, 409 mit «Seite neu laden», abgelaufene Anmeldung mit Link zur Anmeldung. Nach jedem Erfolg lädt die Seite neu (Meldung über `sessionStorage`); Status, Hinweise und Verlauf kommen dann vom Server.
- Entwurf im `localStorage` (pro Begegnung und Konto), gelöscht nach dem Speichern. Beruht er auf dem aktuellen Stand (`version`), wird er wiederhergestellt, sonst angeboten.

**Gastseite:** das erfasste Resultat zum Lesen (Aufstellung, Spiele, Hinweise, Foto) und der Verlauf aus `history`. «Resultat bestätigen» (`POST confirm` mit `version`) und «Einsprache erheben» (Begründung 10 bis 2000 Zeichen, `POST objection`), je nach `can.confirm` und `can.object`.

**TK:** Bei `can.decide` ein Bereich «Entscheid der TK» mit den im Status erlaubten Entscheiden (`FixtureWorkflow`, «abschliessen» nur mit Resultat), Forfait mit Sieger und Bemerkung (`POST decision`). «Korrigieren» öffnet das Formular im Korrekturmodus.

**Meine Spiele:** Abschnitt in «Mein Bereich» über `ttleague_my_area_sections`, serverseitig aus `ResultService::my_fixtures()`: zu erfassen, zu bestätigen, demnächst, jeweils mit Link auf die Erfassungsseite. Nur für Personen mit Funktion in der aktiven Saison.

**Matchreport:** Angemeldete Personen mit `ttl_enter_result` oder `ttl_confirm_result` (Fixture-ID) oder `ttl_tk` sehen einen Knopf «Resultat erfassen», «Resultat ändern», «Resultat bestätigen» bzw. «Entscheid der TK». Für Gäste bleibt die Ausgabe gleich, damit sie zwischengespeichert werden kann.

**Technik:** Unveränderliches (Spielfolge, Kandidaten, Texte, Adressen, Nonce) kommt über `wp_interactivity_config`, der Zustand über `wp_interactivity_state`. Das Ansichtsmodell, an das die Direktiven gebunden sind, rechnet `EntryForm::view()` in PHP für den Startzustand und `buildView()` im Skriptmodul `@ttleague/entry-view` (`assets/js/entry-view.js`, reine Funktionen ohne Abhängigkeiten, auch Prüfung und Daten für die API) im Browser; beide müssen übereinstimmen. `EntryViewParityTest` vergleicht sie mit Node, falls vorhanden, und schickt die im Skript gebauten Daten an den `ResultService`. Stile in `assets/css/entry.css`, geladen nur auf der Erfassungsseite und in «Mein Bereich». `current.warnings` gehört zur letzten Erfassung; nach einem Zurücksetzen oder Forfait (keine Spiele) zeigt die Seite sie nicht an.

## REST-API

Basis `/wp-json/ttleague/v1`, Authentifizierung über das WordPress-Cookie mit `X-WP-Nonce`. Fehlertexte auf Deutsch. Validierungsfehler: HTTP 422 mit `{ "code": "ttl_invalid", "message": "...", "data": { "status": 422, "errors": [ { "field": "...", "message": "..." } ] } }`.

| HTTP | Code | Bedeutung |
|---|---|---|
| 401 | `rest_forbidden` | nicht angemeldet |
| 403 | `ttl_forbidden` | keine Rolle für diese Aktion (z. B. Gastseite will erfassen, eigene Erfassung bestätigen) |
| 404 | `ttl_not_found` | Begegnung gibt es nicht |
| 409 | `ttl_conflict` | Status erlaubt die Aktion nicht, `version` ist veraltet, oder die Begegnung hat sich während der Aktion geändert |
| 422 | `ttl_invalid` | Eingabe ungültig, mit Feldfehlern (`lineup.home.A`, `games.7.home_players`, `games.1.set_points`, `reason`, `file` …) |
| 503 | `ttl_unavailable` | nur beim Foto: Ablage nicht geschützt oder der Schutz liess sich nicht prüfen (siehe Foto) |

**Gleichzeitige Änderungen:** `GET entry` liefert `version`. Wird sie bei `result`, `confirm`, `objection` oder `decision` mitgeschickt und hat sich die Begegnung inzwischen geändert, antwortet der Server mit 409. So bestätigt niemand ein Resultat, das er nicht gesehen hat. Jede schreibende Aktion sperrt zudem in ihrer Transaktion zuerst die Saison und dann die Begegnung (`SELECT … FOR UPDATE`), liest die Begegnung neu und bricht mit 409 ab, wenn sie sich seit der Prüfung von Rechten und Status geändert hat. Von zwei gleichzeitigen Aktionen gilt so nur die erste; die zweite kann weder eine veraltete Version noch einen inzwischen unzulässigen Statuswechsel durchsetzen, auch ohne mitgeschickte `version`. Die Sperre der Saison reiht alle Schreibvorgänge einer Saison hintereinander, denn Spielberechtigung und Bindungen hängen von Einsätzen in anderen Begegnungen ab. `result` und die Korrektur der TK prüfen die Aufstellung deshalb erst unter dieser Sperre: Erfassen zwei Captains gleichzeitig Begegnungen mit demselben Ersatzspieler, sieht die zweite Prüfung den Einsatz aus der ersten.

### `GET fixtures/{id}/entry`

Liefert alles für das Formular. Das Beispiel ist gekürzt: `order` enthält die ganze Spielfolge (Meisterschaft 10, Cup 5 Spiele), `candidates` alle Spieler beider Clubs.

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

Gekürztes Beispiel: Eine Meisterschaftsbegegnung braucht alle 10 Spiele (Cup bis zur Entscheidung). `set_points` werden nur mit `set_points_enabled: true` gespeichert, sonst ignoriert.

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

- Ein Spieler pro Slot, jeder Spieler höchstens einmal pro Seite, mindestens zwei Spieler pro Seite (Meisterschaft Art. 14.2; die Cup-Mannschaft besteht aus zwei Spielern, Art. 19.1, tritt sie unvollständig an, entscheidet die TK über ein Forfait).
- Alle Spiele der Spielfolge müssen vorhanden sein (MM 10, Cup 5). Im Cup dürfen Spiele nach dem dritten Sieg fehlen oder als `not_played` kommen.
- `status` `played` (Sätze nach Gewinnsätzen), `walkover` (mit `winner`), `not_played`. Fehlt ein Spieler, sind seine Einzel `walkover` für die Gegenseite (`LineupValidator`).
- Doppel: `home_players` und `away_players` aus der Aufstellung der Seite. Pflicht bei einem gespielten Doppel, ausser die Seite hat genau zwei Spieler (dann automatisch).
- `set_points` sind freiwillig und werden nur gespeichert, wenn `set_points_enabled`. Ein Satz geht auf 11, ab 10:10 mit zwei Punkten Vorsprung; die Sätze müssen das Spielresultat ergeben.
- `photo_id` wird ignoriert; das Foto verknüpft `POST fixtures/{id}/photo`.
- Spielberechtigung (Meisterschaft): Stammspieler, Ersatz aus einem tieferen Team (höchstens drei Einsätze, beim dritten wird der Spieler an das Team gebunden), Bindung aus dem Kader. Es zählen nur Einsätze in Begegnungen vor der erfassten (frühere Anspielzeit, bei gleicher Anspielzeit die kleinere ID) und Bindungen ab dem Tag nach dem bindenden Einsatz. Hat die erfasste Begegnung keinen Termin, gilt der Zeitpunkt der Erfassung als Stichtag; Einsätze in Begegnungen ohne Termin zählen nie (wie bei den Bindungen). So ergibt eine nachgetragene oder korrigierte ältere Begegnung dasselbe wie eine Erfassung in der richtigen Reihenfolge. Cup: alle für den Club gemeldeten Spieler, Spieler ausserhalb der gemeldeten Cup-Mannschaft als Hinweis.
- Bindungen im Kader (`team_roster.kind = bound`, `valid_from` = Tag des dritten Ersatzeinsatzes) folgen allein aus den Einsätzen. Nach jeder Erfassung, Korrektur, jedem Forfait und Zurücksetzen bestimmt der ResultService sie für alle Spieler der bisherigen und der neuen Aufstellung neu; entfällt der bindende Einsatz, entfällt die Bindung.
- Die TK korrigiert über `decision` mit `action: correct`; fehlende Spielberechtigungen sind dann Hinweise statt Fehler.
- Antwort 200: `{ "status": "provisional", "home_score": 6, "away_score": 4, "warnings": [ ... ], "version": "..." }`.

**Harte Fehler (422):** Spieler nicht spielberechtigt (anderer Club, an anderes Team gebunden, Stammspieler eines höheren Teams), unvollständige oder widersprüchliche Spiele. Erlaubt der Status keine Erfassung (z. B. schon bestätigt), antwortet der Server mit 409 (siehe Tabelle oben).

**Hinweise (`warnings`):** Bleiben gespeichert und erscheinen im TK-Dashboard, z. B. «3. Ersatzeinsatz: Spieler ist ab jetzt an dieses Team gebunden».

### Weitere Endpunkte

| Methode und Pfad | Inhalt | Recht |
|---|---|---|
| `POST fixtures/{id}/confirm` | `{}` | `ttl_confirm_result` oder `ttl_tk` |
| `POST fixtures/{id}/objection` | `{ "reason": "…" }` (Pflicht, 10 bis 2000 Zeichen) | `ttl_confirm_result` |
| `POST fixtures/{id}/decision` | `{ "action": "confirm"\|"finalize"\|"correct"\|"forfeit"\|"reset", "winner": "home"\|"away", "result": { wie POST result }, "comment": "…" }` | `ttl_tk` |
| `POST fixtures/{id}/photo` | Multipart `file` (JPEG, PNG, WebP, HEIC, höchstens 10 MB) → `{ "attachment_id": 5, "url": "…" }`. `url` ist die geschützte Adresse (siehe Foto). Scheitert das Verknüpfen, weil sich die Begegnung inzwischen geändert hat, wird der Anhang wieder gelöscht. | Heimseite bis zur Bestätigung, TK immer |
| `GET fixtures/{id}/photo` | Liefert die Bilddatei (`Cache-Control: private, no-store`). Ohne Parameter das aktuelle Foto; `?attachment={id}` ein früheres Foto derselben Begegnung (z. B. nach dem Zurücksetzen). | aktuelles Foto: Heimseite, Gastseite, TK; frühere Fotos: TK |
| `GET me/fixtures` | `{ "to_enter": [], "to_confirm": [], "upcoming": [] }` für die angemeldete Person: zu erfassen (Heimseite, Anspielzeit vorbei), zu bestätigen (Gastseite, provisorisch, nicht selbst erfasst), demnächst (21 Tage) | angemeldet |

Antwort von `confirm` und `objection`: `{ "status": "...", "version": "..." }`; von `decision`: `{ "status", "home_score", "away_score", "version" }`, bei `confirm` wie bei `POST confirm`. Die Bestätigung als Entscheid der TK speichert die Bemerkung und gilt immer als Bestätigung durch die TK (`by_tk`), auch wenn das Konto zugleich zur Gastseite gehört.

**Foto:** Ein Foto des Spielberichts zeigt Namen, Lizenznummern und Unterschriften. Der Anhangsstatus «privat» schützt nur den Eintrag in der Mediathek, nicht die Datei. Deshalb:

- Ablage ohne Bildvarianten im Verzeichnis `uploads/ttleague-private/` mit `.htaccess` (sperrt den Zugriff unter Apache) und `index.php`. Unter nginx braucht es eine Regel im Server, z. B. `location ^~ /wp-content/uploads/ttleague-private/ { deny all; }`. Besser ist ein Verzeichnis ausserhalb des Web-Roots in `wp-config.php`: `define( 'TTLEAGUE_PRIVATE_UPLOADS', '/pfad/ausserhalb/des/webroots' );`. Dass es ausserhalb liegt, verantwortet die Verwaltung; das Plugin erkennt ein Verzeichnis innerhalb von WordPress (uploads, `wp-content` oder `ABSPATH`) oder des Web-Roots der Anfrage (`DOCUMENT_ROOT`, z. B. wenn WordPress in einem Unterverzeichnis liegt) und prüft es dann wie das Standardverzeichnis.
- Ausgeliefert wird nur über `GET fixtures/{id}/photo` mit Rechteprüfung. `GET entry` liefert dafür `current.photo_url`, `Results::photo_url()` baut sie. Die Adresse enthält den REST-Nonce, damit sie auch in `<img>` funktioniert; er gilt nur zusammen mit dem Anmelde-Cookie derselben Person.
- Zurücksetzen löst das Foto von der Begegnung; der Anhang bleibt erhalten und ist für die TK weiter abrufbar.
- Wird der Anhang gelöscht (Mediathek, Aufräumen nach einem gescheiterten Upload), löscht das Plugin seine Dateien im Verzeichnis der Fotos selbst. WordPress löscht nur innerhalb von uploads; ohne das blieben Fotos in einem Verzeichnis der Konstante liegen.
- **Prüfung im Betrieb:** Vor jedem Upload stellt das Plugin `.htaccess` und `index.php` wieder her (fehlen sie oder weichen sie ab und lassen sie sich nicht schreiben, gibt es keinen Upload). Zudem legt es Testdateien mit zufälligem Inhalt ab und ruft sie über HTTP ab, beim Upload und in «Website-Zustand»: eine pro erlaubter Dateiendung der Fotos (jpg, jpeg, jpe, png, webp, heic, heif), im Unterverzeichnis, in das auch der nächste Upload kommt (z. B. `2026/11`). So fällt auch eine Serverregel auf, die nur bestimmte Endungen sperrt; unter nginx gewinnt etwa eine Regel für Bilder mit regulärem Ausdruck gegen eine Sperre ohne `^~`. Uploads gibt es nur, wenn die Prüfung den Schutz bestätigt (jede Testdatei mit 401, 403 oder 404 abgewiesen). Liefert der Webserver eine Testdatei aus (offen) oder lässt sich die Prüfung nicht durchführen (unbekannt, z. B. eigene Website nicht erreichbar, Weiterleitung, Zeitüberschreitung), antworten Uploads mit 503 `ttl_unavailable`. Ein bestätigter Schutz gilt 12 Stunden und nur für dasselbe Verzeichnis, dasselbe Upload-Unterverzeichnis (ein neuer Monat wird neu geprüft) und denselben Web-Root (WP-CLI kennt keinen); jedes andere Ergebnis prüft der nächste Upload neu, damit eine korrigierte Serverregel gleich wirkt. Ein Verzeichnis der Konstante ausserhalb von WordPress und Web-Root hat keine Adresse, die sich über HTTP prüfen liesse (Zustand «ausserhalb»). Uploads sind dann erlaubt; «Website-Zustand» nennt den Fall und verlangt, einmal von Hand zu prüfen, dass der Webserver das Verzeichnis nicht ausliefert, auch nicht über einen Alias. So geht es auch dort, wo die Website sich selbst nicht erreicht (z. B. `wp server`). Das Ergebnis steht unter «Werkzeuge → Website-Zustand» («Fotos der Spielberichte»); der Aufruf dort prüft immer neu, z. B. nach Änderungen am Webserver. Nach der Einrichtung des Servers zusätzlich von Hand prüfen: Die direkte Adresse eines Fotos liefert ohne Anmeldung keine Bilddaten.

**Hinweise zur Zeit:** Erfassung nach Ablauf von `entry_deadline_hours` (48 h) nach Anspielzeit oder mehr als zwei Stunden vor dem Termin ergibt einen Hinweis, keinen Fehler.

## Protokoll

- `fixture_event`: jedes fachliche Ereignis (`entered`, `edited`, `confirmed`, `disputed`, `decided`, `forfeit`, `reset`, `photo`) mit Konto, Person, Kommentar, Zusatzdaten (`score`, `warnings`, `action`, `winner`, `by_tk`) und Zeitpunkt (UTC). `GET entry` liefert ihn als `history`.
- `audit_log`: technische Nachvollziehbarkeit jeder Änderung an `fixture`, `lineup`, `game` mit Zustand vorher und nachher als JSON.

## Ereignisse

Der `ResultService` löst WordPress-Aktionen aus; Benachrichtigungen, Dashboard und Wertungszahl hängen sich daran:

| Aktion | Parameter |
|---|---|
| `ttleague_result_entered` | Fixture-ID, User-ID, `bool $edited` |
| `ttleague_result_confirmed` | Fixture-ID, User-ID, `bool $by_tk` |
| `ttleague_result_disputed` | Fixture-ID, User-ID, Begründung |
| `ttleague_result_decided` | Fixture-ID, User-ID, Aktion, Kommentar |
| `ttleague_result_photo` | Fixture-ID, User-ID, Anhang-ID |
| `ttleague_data_changed` | Bereich (z. B. `fixture`), ID |

`ttleague_result_confirmed` kommt auch, wenn die TK über `decision` mit `action: confirm` bestätigt (`$by_tk = true`).

Nach jeder Änderung an Resultat oder Status: `DataVersion::bump()` und eine entprellte Neuberechnung der Wertungszahl (`ttleague_rating_recalc`, einmalig geplant in 2 Minuten). Beides läuft vor den Aktionen. Ausnahme ist das Foto: Es erscheint nur mit Rechten, nie auf öffentlichen Seiten, und ändert keine Wertung. Die Aktionen kommen erst nach dem Speichern; wirft ein Listener eine Ausnahme, landet sie im Fehlerprotokoll (`ttleague: Fehler in …`), und die Antwort bleibt erfolgreich. So wiederholt niemand eine gespeicherte Aktion.

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

## Tests

| Ebene | Ordner | Inhalt |
|---|---|---|
| Unit | `tests/Unit/` | Eingabeformat (`ResultInput`), Herkunftsprüfung (`LoginFlow::origin_matches`), Rate-Limit, ohne WordPress |
| Integration | `tests/Integration/` | `ResultService`, `AccessPolicy`, `AccountService`, `MagicLinks` und der eindeutige Index auf `person.user_id` gegen MariaDB über `PdoDb`, ohne WordPress |
| WordPress | `tests/WordPress/` | mit echtem WordPress (wp-phpunit, `composer test:wp`, siehe README), siehe unten |

Die WordPress-Tests prüfen automatisiert, was bisher nur von Hand geprüft war:

- `AccountService::ensure_user()` mit echten Konten: Ein bestehendes Konto mit erweiterten Rechten (Rollen `administrator`, `editor`, `contributor`, `ttl_tk` sowie Abonnenten mit `manage_options`, `edit_posts` oder zusätzlicher Rolle `ttl_tk`) und das Konto einer anderen Person werden nicht verknüpft; ein freies Abonnenten-Konto wird verknüpft, sonst wird ein Konto angelegt.
- Anmeldung per Link: Anfrage (Link oder Passwort-Hinweis, neutrale Antwort, Rate-Limit), Einlösen mit Konto und 60 Tagen «angemeldet bleiben», abgelaufene und verbrauchte Tokens, Herkunftsprüfung über Origin und Referer, Nonce, Weiterleitung nur auf eigene Seiten, Einwilligung und Profil.
- Rechte über `map_meta_cap` mit echten Konten und Rollen (Captain, TK-Chef, Cup-Mannschaft, TK, Administrator, Vorsaison).
- REST-API über den REST-Server von WordPress: 401, 403, 404, 422 mit Feldfehlern, 409 bei veralteter `version`, Erfassen und Bestätigen mit Verlauf und Ereignissen, `me/fixtures`, Rechte und fehlende Datei beim Foto.
- Ereignisse: Ein Listener, der eine Ausnahme wirft, lässt die gespeicherte Erfassung erfolgreich; Cache-Version und Neuberechnung sind vorher erledigt, der Fehler steht im Fehlerprotokoll.
- Ablage der Fotos mit einem gespielten Webserver (`pre_http_request`): Testdateien aller Bildformate im Upload-Unterverzeichnis; ausgeliefert (offen, Uploads gesperrt, auch wenn nur `.webp` ausgeliefert wird), 401/403/404 (geschützt), Weiterleitung, andere Seite, Serverfehler und keine Verbindung (unbekannt, gesperrt); nach «offen» hebt nur ein bestätigter Schutz die Sperre auf; gemerktes Ergebnis nur für dasselbe Verzeichnis, denselben Monat und denselben Web-Root; Ablage ohne Bildvarianten; Löschen der Dateien mit dem Anhang, nur im Verzeichnis der Fotos; Schutzdateien wiederhergestellt; Adresse eines Verzeichnisses für `TTLEAGUE_PRIVATE_UPLOADS` (auch über symbolische Links und den Web-Root); «Website-Zustand» mit einem Bericht je Zustand (auch «ausserhalb» für ein Verzeichnis der Konstante ohne Adresse).
- Blöcke `ttleague/login`, `ttleague/my-area` und `ttleague/account-link` für Gäste und Angemeldete; Umleitung aus dem Backend und Werkzeugleiste.
- Schema über `dbDelta()` (vollständig, InnoDB, ohne Änderungen bei einem erneuten Lauf) und die Rolle der TK.

Weiterhin von Hand: Speichern eines Fotos (`POST fixtures/{id}/photo` verlangt eine echte Upload-Datei, `is_uploaded_file()`), die Sperre der Ablage durch den echten Webserver der Produktion (Checkliste der Inbetriebnahme), Anmeldung mit echten Cookies und `X-WP-Nonce` im Browser, Versand über einen echten Mailserver.
