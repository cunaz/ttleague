# Verschiebung und Terminvorschlag

Eine Seite schlägt einen neuen Termin vor, die andere stimmt zu, bei grossen Verschiebungen bewilligt die TK (Art. 10). Derselbe Ablauf vereinbart den Termin einer Begegnung, die noch keinen hat (Cup). Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Ablauf

| Von | Aktion | Nach | Wer |
|---|---|---|---|
| – | Antrag mit neuem Termin und Begründung | `requested` | eine Seite oder TK |
| `requested` | zurückziehen | `withdrawn` | Antragsteller |
| `requested` | ablehnen (Bemerkung freiwillig) | `declined` | Gegenseite |
| `requested` | zustimmen, Bewilligung nicht nötig | `approved` | Gegenseite |
| `requested` | zustimmen, Bewilligung nötig | `accepted` | Gegenseite |
| `accepted` | bewilligen | `approved` | TK |
| `accepted` | ablehnen (Bemerkung Pflicht) | `rejected` | TK |
| `requested` | an Stelle der Gegenseite gutheissen | `approved` | TK |
| `requested` | an Stelle der Gegenseite ablehnen (Bemerkung Pflicht) | `rejected` | TK |

Entscheidet die TK über einen Antrag im Status `requested`, schliesst ihr Entscheid die Bewilligung ein: Der Antrag wird direkt `approved` oder `rejected`, nie `accepted`. Das darf die TK jederzeit; die Übersicht führt unbeantwortete Anträge nach sieben Tagen auf (siehe «Reagiert niemand»).

- **Bewilligung nötig** ist sie, wenn der neue Termin mehr als `postponement_approval_days` (14) Tage nach dem bisherigen liegt. Vorverschieben und kürzeres Nachverschieben gelten mit der Zustimmung; die TK wird informiert.
- Stellt die TK den Antrag selbst, gilt er sofort als `approved`.
- Im Clubduell stimmt nicht dieselbe Person zu, die beantragt hat.
- Pro Begegnung gibt es höchstens einen offenen Antrag (`requested` oder `accepted`).
- Möglich nur bei Status `scheduled` oder `postponed` der Begegnung, in der aktiven Saison. Das gilt auch für die TK: Den Spielplan der geplanten Saison bearbeitet sie im Saison-Assistenten.

Was die Umsetzung dazu festlegt:

- **Tage sind Kalendertage.** «Mehr als 14 Tage» vergleicht die Daten, nicht die Uhrzeiten: Vom 10.11., 20:00 auf den 24.11., 21:30 sind es 14 Tage (die Zustimmung genügt), auf den 25.11. sind es 15 (Bewilligung nötig). Vorverschieben braucht nie eine Bewilligung.
- **Neuer Termin:** Ortszeit der Website mit Datum und Zeit, in der Zukunft, innerhalb der Saison (`season.start_date` bis und mit `end_date`) und nicht gleich dem bisherigen. Einen Zeitpunkt, den es wegen der Zeitumstellung nicht gibt, lehnt der Dienst ab.
- **Begründung:** Pflicht bei einer Verschiebung, freiwillig beim Terminvorschlag, höchstens 2000 Zeichen. Die Bemerkung zur Ablehnung der Gegenseite ist freiwillig, die der TK zur Ablehnung Pflicht.
- **Antragsteller und Gegenseite sind Seiten**, nicht einzelne Personen: Zurückziehen kann, wer den Antrag gestellt hat oder dieselbe Seite vertritt (zweiter Captain, TK-Chef des Clubs); antworten kann, wer die andere Seite vertritt. Im Clubduell vertritt der TK-Chef beide Seiten; wer den Antrag gestellt hat, beantwortet ihn nie selbst. Die Seite des Antrags folgt aus den heutigen Funktionen der antragstellenden Person (`postponement.requested_by` ist ein Konto, die Tabelle hat keine Spalte für die Seite): Verliert sie ihre Funktion, kann nur noch die TK entscheiden.
- **TK:** Wer `ttl_tk` hat, stellt Anträge immer als TK (sofort `approved`), auch wenn das Konto zugleich eine Seite vertritt.
- **Zustimmen** kann die Gegenseite nur, solange der neue Termin in der Zukunft liegt, die Begegnung noch offen ist und ihren Termin seit dem Antrag nicht geändert hat. Sonst bleibt Ablehnen oder Zurückziehen. Die TK kann auch einen vergangenen Termin gutheissen, etwa wenn schon gespielt wurde.
- **Gleichzeitige Aktionen:** Jeder Übergang sperrt in seiner Transaktion zuerst die Saison und dann die Begegnung (dieselbe Reihenfolge wie die Erfassung) und liest Antrag, Rollen und Status neu. Von zwei gleichzeitigen Aktionen gilt die erste, die zweite erhält 409.

## Wirkung von `approved`

- `fixture.scheduled_at` erhält den neuen Termin. Der bisherige Termin bleibt in `postponement.old_date`.
- Status der Begegnung: Bei einer Verschiebung (`kind = postpone`) wird er `postponed`, und die Ansichten zeigen «verschoben». Bei einem Terminvorschlag (`kind = propose`) bleibt er `scheduled`, weil es der erste Termin ist; `old_date` ist dann leer und nichts erscheint als verschoben.
- Erinnerungen und Fristen aus `docs/erfassung.md` rechnen ab dem neuen Termin; die Erinnerung vor dem Spiel geht für den neuen Termin erneut hinaus. Kalender-Abos zeigen ihn sofort.
- Eintrag im Verlauf der Begegnung (`fixture_event`, Typ `postponed`, mit neuem und bisherigem Termin und der Begründung; `data.action` ist `postpone` oder `propose`) und im `audit_log` (`fixture`: `postponed` bzw. `date_set`; jeder Statuswechsel des Antrags unter `postponement`).
- Danach `DataVersion::bump()` und die Aktion `ttleague_postponement_changed` (Antrag, Aktion `request`, `withdraw`, `decline`, `accept`, `approve` oder `reject`, Konto, Person). Ein Fehler in einem Listener landet im Fehlerprotokoll; die gespeicherte Aktion bleibt erfolgreich.
- Die Erfassung läuft wie gewohnt weiter: Eine Begegnung im Status `postponed` wird erfasst und bestätigt wie eine angesetzte.

Spalten von `postponement`: `old_date` und `new_date` sind Ortszeit, alle anderen Zeitpunkte UTC. `accepted_by` und `responded_at` halten die Antwort der Gegenseite fest (`responded_at` auch bei einer Ablehnung), `approved_by` und `approved_at` den Entscheid der TK (auch eine Ablehnung). Gilt ein Antrag mit der Zustimmung, bleibt `approved_by` leer und `approved_at` ist der Zeitpunkt der Zustimmung. `response_note` ist die Bemerkung zur Ablehnung der Gegenseite oder zum Entscheid der TK.

## Terminvorschlag

Für Begegnungen ohne `scheduled_at` (Cup-Runden): `postponement.kind = propose`. Das Heimteam schlägt vor, der Gegner stimmt zu. Der Status der Begegnung bleibt `scheduled` (siehe «Wirkung von `approved`»).

Weil es keinen bisherigen Termin gibt, entfällt der Vergleich mit `postponement_approval_days`. Massgebend ist die Frist der Runde (`cup_tie.deadline`):

- Termin spätestens am Tag der Frist, oder keine Frist gesetzt: Die Zustimmung des Gegners genügt (`approved`).
- Termin nach der Frist: Die Zustimmung führt zu `accepted`, die TK bewilligt oder lehnt ab.

Hat die Cup-Begegnung schon einen Termin, gilt für eine weitere Änderung der normale Ablauf (`kind = postpone`) mit beiden Regeln: Bewilligung nötig, wenn der neue Termin mehr als `postponement_approval_days` Tage nach dem bisherigen **oder** nach der Frist der Runde liegt.

Die Frist liest der Dienst aus `cup_tie.deadline` der Zeile mit `cup_tie.fixture_id`; ohne solche Zeile gibt es keine Frist. Den Vorschlag macht die Heimseite (Spieler der Heim-Cup-Mannschaft, TK-Chef ihres Clubs) oder die TK. Begegnungen ohne Termin stehen in «Mein Bereich» unter «Ohne Termin».

## Reagiert niemand

Drei Tage nach dem Antrag ohne Antwort erhält die Gegenseite eine Erinnerung. Nach sieben Tagen erscheint der Antrag in der Übersicht der TK unter «Verschiebungen»; die TK kann an Stelle der Gegenseite entscheiden.

- Die Erinnerung hängt am stündlichen Lauf der Fristen (`ttleague_notifications_hourly`), ohne eigenen Cron, und beachtet dessen Versandfenster (7 bis 22 Uhr, Filter `ttleague_notification_hours`). Sie geht pro Antrag und Person einmal hinaus (Versandprotokoll) und nur, solange die Begegnung offen ist. `wp cron event run ttleague_notifications_hourly` löst sie aus; `wp ttleague notifications run` ruft nur die Fristen der Erfassung auf.
- Drei und sieben Tage sind verstrichene Zeit seit dem Antrag (`PostponementService::REMINDER_DAYS`, `UNANSWERED_DAYS`), keine Regeln des Reglements.
- Eine eigene Nachricht an die TK nach sieben Tagen gibt es nicht; der Antrag steht in ihrer Übersicht.

## REST

Unter `/wp-json/ttleague/v1`, Cookie mit `X-WP-Nonce`, Fehler wie in `docs/erfassung.md`.

| Route | Methode | Zweck |
|---|---|---|
| `fixtures/{id}/postponement` | GET | offener Antrag, Verlauf, was die anfragende Person darf |
| `fixtures/{id}/postponement` | POST | Antrag: `new_date` (Ortszeit), `reason` |
| `postponements/{id}/accept`, `/decline`, `/withdraw` | POST | Antwort der Gegenseite bzw. Rückzug |
| `postponements/{id}/decision` | POST | TK: `action` `approve` oder `reject`, dazu `comment` (bei `reject` Pflicht) |

`decline` nimmt eine freiwillige Bemerkung `note`. Jede Route verlangt eine Anmeldung (401) und `ttl_reschedule_fixture` für die Begegnung (403); welche Aktion die Person im aktuellen Stand ausführen darf, entscheidet der Dienst.

| HTTP | Code | Bedeutung |
|---|---|---|
| 401 | `rest_forbidden` | nicht angemeldet |
| 403 | `ttl_forbidden` | keine Seite der Begegnung, falsche Rolle für die Aktion (eigenen Antrag beantworten, als Gegenseite zurückziehen, ohne `ttl_tk` entscheiden), Terminvorschlag der Gastseite |
| 404 | `ttl_not_found` | Begegnung oder Antrag gibt es nicht |
| 409 | `ttl_conflict` | schon ein offener Antrag, Antrag schon beantwortet oder entschieden, Begegnung nicht mehr offen, Saison nicht aktiv, Termin der Begegnung seit dem Antrag geändert, vorgeschlagener Termin vorbei |
| 422 | `ttl_invalid` | Eingabe ungültig, mit Feldfehlern `new_date`, `reason`, `note`, `comment`, `action` |

`GET fixtures/{id}/postponement` (gekürzt):

```json
{
  "fixture": { "id": 1, "type": "MM", "status": "scheduled", "scheduled_at": "2026-11-10 20:00:00", "competition": "Gruppe B", "home": "Seefeld 1", "away": "Wollishofen 1", "deadline": null },
  "kind": "postpone",
  "open": { "id": 7, "kind": "postpone", "status": "requested", "status_label": "beantragt", "old_date": "2026-11-10 20:00:00", "new_date": "2026-11-17 20:00:00",
    "reason": "Die Halle ist belegt.", "requested_by": "Hanna Heim", "requested_at": "2026-11-02T08:00:00Z", "requester_side": "home",
    "accepted_by": null, "responded_at": null, "response_note": null, "decided_by": null, "decided_at": null, "needs_approval": false },
  "history": [],
  "can": { "request": false, "accept": true, "decline": true, "withdraw": false, "approve": false, "reject": false },
  "rules": { "approval_days": 14, "season_start": "2026-08-01", "season_end": "2027-07-31" }
}
```

`kind` sagt, was ein neuer Antrag wäre (`propose` ohne bisherigen Termin). `history` enthält die abgeschlossenen Anträge, neueste zuerst. `needs_approval` gilt für offene Anträge: ob nach der Zustimmung die TK bewilligen muss. Die schreibenden Routen antworten mit dem Antrag (wie `open`) und `fixture`.

## Oberfläche

- Erfassungsseite und «Meine Spiele»: bei offenen Begegnungen «Termin verschieben»; ein offener Antrag der Gegenseite steht zuoberst mit «Zustimmen» und «Ablehnen».
- Matchreport und Spielpläne zeigen bei verschobenen Begegnungen den neuen Termin mit dem Vermerk «verschoben».
- TK-Übersicht: Liste «Verschiebungen» (zu bewilligen, unbeantwortet).

Umsetzung, ohne JavaScript: serverseitig gerenderte Formulare an `admin-post.php` (`Postponement\Forms`: Nonce pro Begegnung bzw. Antrag, `ttl_reschedule_fixture`, beim Entscheid `ttl_tk`), Rückmeldung über `Flash`, danach zurück zur Erfassungsseite, zu «Mein Bereich» oder zur Übersicht der TK. Formulare und REST-Routen rufen denselben Dienst auf.

- **Erfassungsseite:** Abschnitt «Termin» (`#ttl-termin`) direkt unter dem Kopf, für beide Seiten und die TK. Mit offenem Antrag: Termine, Begründung, Stand und die Aktionen der Person (zustimmen, ablehnen, zurückziehen, als TK bewilligen oder ablehnen). Sonst das aufklappbare Formular «Termin verschieben» (ohne bisherigen Termin «Termin vorschlagen», aufgeklappt); die TK legt damit direkt fest. Darunter frühere Anträge. Der Verlauf nennt «Termin verschoben» bzw. «Termin vereinbart».
- **Mein Bereich:** Abschnitt «Verschiebungen und Termine» vor «Meine Spiele», nur wenn es etwas gibt: offene Anträge zu den eigenen Begegnungen (zuerst die zu beantwortenden) mit denselben Formularen und Cup-Begegnungen ohne Termin. In «Meine Spiele» steht bei offenen Begegnungen der Link «Termin verschieben».
- **Matchreport:** Personen mit `ttl_reschedule_fixture` sehen bei offenen Begegnungen «Termin verschieben», «Termin vorschlagen» oder den Hinweis auf den offenen Antrag. Für Gäste bleibt die Ausgabe unverändert. Den neuen Termin und den Vermerk «verschoben» zeigten Matchreport, Spielpläne und «nächste Spiele» schon für den Status `postponed`; das Kalender-Abo stellt «Verschoben:» vor den Titel.
- **TK-Übersicht:** Liste «Verschiebungen» (`#ttl-verschiebungen`) mit den Formularen «Bewilligen» bzw. «An Stelle der Gegenseite gutheissen» und «Ablehnen» sowie dem Link auf den CSV-Export. Über jüngere Anträge entscheidet die TK auf der Erfassungsseite der Begegnung.
- **Export:** Liste `verschiebungen` (alle Anträge der aktiven Saison, mit `saison=<Slug>` einer anderen): `wp ttleague export verschiebungen` oder der Link in der Übersicht.

Neue Erweiterungspunkte der Erfassung, an die sich das Modul hängt (jeweils fertiges, escaptes HTML; jedes Modul prüft das Recht selbst):

| Filter | Parameter | Ort |
|---|---|---|
| `ttleague_entry_sections` | HTML, Fixture-ID | Erfassungsseite, unter dem Kopf |
| `ttleague_my_games_actions` | HTML, Begegnung aus `ResultService::my_fixtures()` | «Meine Spiele», pro Begegnung |
| `ttleague_report_actions` | HTML, Fixture-ID, Status | Matchreport, nur für Angemeldete |

## Dienste und Rechte

- `Postponement\PostponementService` mit den Übergängen oben; reine Regel `Domain\Match\PostponementRules` (Bewilligung nötig, erlaubte Übergänge).
- `ttl_reschedule_fixture` mit der Fixture-ID; welche Seite jemand vertritt, kommt aus `AccessPolicy::side_for()`.

Code in `src/Postponement/`, Einstieg `Postponements::register()`:

| Klasse | Aufgabe |
|---|---|
| `Domain\Match\PostponementRules` | rein: Übergänge je Rolle, Rollen aus den Seiten, Bewilligung, Art des Antrags, Status der Begegnung danach |
| `PostponementService` | Übergänge in Transaktionen, Eingabeprüfung, Wirkung auf die Begegnung, Listen für «Mein Bereich» und die TK; hängt nur an `Db`, `Clock`, `AccessPolicy`, `AuditLog` und der Domäne |
| `PostponementRepository`, `Parties` | Abfragen; Seiten und Rollen einer Person für einen Antrag |
| `PostponementEvents`, `WordPressPostponementEvents` | Ereignis nach dem Speichern: Datenversion, Aktion `ttleague_postponement_changed` |
| `PostponementNotifications`, `PostponementMessages` | Sofortmeldungen, Erinnerung, Texte |
| `RestController`, `Forms`, `Views` | REST-Routen, Formulare, HTML |

Fehler sind `Results\ResultException` mit denselben Codes wie in der Erfassung.

## Benachrichtigungen

`postponement` an beide Seiten (Antrag an die Gegenseite, Antwort an den Antragsteller, Entscheid der TK an beide), `postponement_tk` an die TK (zur Kenntnis oder zur Bewilligung).

| Art (`event_type`) | Einstellung | Empfänger | Anlass |
|---|---|---|---|
| `postponement_request` | `postponement` | Gegenseite | Antrag |
| `postponement_reminder` | `postponement` | Gegenseite | drei Tage ohne Antwort |
| `postponement_withdrawn` | `postponement` | Gegenseite | Rückzug |
| `postponement_declined` | `postponement` | Antragsteller | Ablehnung der Gegenseite |
| `postponement_accepted` | `postponement` | Antragsteller | Zustimmung (gilt oder wartet auf die TK) |
| `postponement_decided` | `postponement` | beide Seiten | Entscheid der TK, auch ihr eigener Antrag |
| `postponement_tk_info` | `postponement_tk` | TK | Zustimmung ohne nötige Bewilligung |
| `postponement_tk_approval` | `postponement_tk` | TK | Zustimmung, Bewilligung nötig |

Empfänger einer Seite wie in der Erfassung (Captains bzw. Cup-Mannschaft und TK-Chefs des Clubs). Die handelnde Person erhält keine Nachricht über ihre eigene Aktion. Bezug im Versandprotokoll ist der Antrag (`ref_type` `postponement`), jede Art geht pro Antrag und Person einmal hinaus. Der Link führt auf die Erfassungsseite der Begegnung. Sofortmeldungen hängen an `ttleague_postponement_changed`; scheitert der Versand, bleibt die Aktion gespeichert (wie in `docs/erfassung.md`).

## Grenzen

- Ein offener Antrag bleibt offen, wenn inzwischen ein Resultat erfasst wird. Er lässt sich dann nur noch ablehnen, zurückziehen oder von der TK ablehnen und steht weiter in «Mein Bereich» bzw. in der Übersicht der TK.
- Fristen der Erfassung («Resultat fehlt», Forfait-Vorschlag) laufen weiter, solange ein Antrag nur gestellt und nicht gutgeheissen ist.
- Begründungen und Bemerkungen sind freie Texte; die Datenschutz-Werkzeuge erfassen sie nicht (wie Bemerkungen der Erfassung).

## Tests

- Regel: Grenze bei genau 14 Tagen, Vorverschieben, Antrag der TK, Terminvorschlag am Tag der Frist und am Tag danach, ohne Frist.
- Dienst gegen MariaDB: alle Übergänge (auch der Entscheid der TK an Stelle der Gegenseite), Status der Begegnung nach Verschiebung und nach Terminvorschlag, zweiter offener Antrag abgelehnt, Clubduell, Wirkung auf Termin und Status, Erinnerung vor dem Spiel für den neuen Termin, Terminvorschlag ohne bisherigen Termin.
- WordPress: REST mit 401, 403, 409 und 422; Rechte beider Seiten.

Umgesetzt in `tests/Unit/Domain/PostponementRulesTest.php`, `tests/Integration/Postponement/` (Dienst, Terminvorschlag und Cup-Frist, Benachrichtigungen mit Erinnerung und den Fristen nach dem neuen Termin, Sperre der Begegnung über eine zweite Verbindung) und `tests/WordPress/Postponement/` (REST, Formulare mit Nonce und Rechten, Darstellung auf Erfassungsseite, «Mein Bereich», Matchreport, Spielplan und TK-Übersicht, Export, E-Mail).
