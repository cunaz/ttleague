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

- **Bewilligung nötig** ist sie, wenn der neue Termin mehr als `postponement_approval_days` (14) Tage nach dem bisherigen liegt. Vorverschieben und kürzeres Nachverschieben gelten mit der Zustimmung; die TK wird informiert.
- Stellt die TK den Antrag selbst, gilt er sofort als `approved`.
- Im Clubduell stimmt nicht dieselbe Person zu, die beantragt hat.
- Pro Begegnung gibt es höchstens einen offenen Antrag (`requested` oder `accepted`).
- Möglich nur bei Status `scheduled` oder `postponed` der Begegnung, in der aktiven Saison.

## Wirkung von `approved`

- `fixture.scheduled_at` erhält den neuen Termin, der Status wird `postponed`. Der bisherige Termin bleibt in `postponement.old_date`.
- Erinnerungen und Fristen aus `docs/erfassung.md` rechnen ab dem neuen Termin; die Erinnerung vor dem Spiel geht für den neuen Termin erneut hinaus. Kalender-Abos zeigen ihn sofort.
- Eintrag im Verlauf der Begegnung (`fixture_event`, Typ `postponed`) und im `audit_log`.

## Terminvorschlag

Für Begegnungen ohne `scheduled_at` (Cup-Runden): `postponement.kind = propose`. Das Heimteam schlägt vor, der Gegner stimmt zu. Eine Bewilligung braucht es nicht, solange der Termin vor der Frist der Runde liegt (`cup_tie.deadline`); danach wie oben. Der Status der Begegnung bleibt `scheduled`.

## Reagiert niemand

Drei Tage nach dem Antrag ohne Antwort erhält die Gegenseite eine Erinnerung. Nach sieben Tagen erscheint der Antrag in der Übersicht der TK unter «Verschiebungen»; die TK kann an Stelle der Gegenseite entscheiden.

## REST

Unter `/wp-json/ttleague/v1`, Cookie mit `X-WP-Nonce`, Fehler wie in `docs/erfassung.md`.

| Route | Methode | Zweck |
|---|---|---|
| `fixtures/{id}/postponement` | GET | offener Antrag, Verlauf, was die anfragende Person darf |
| `fixtures/{id}/postponement` | POST | Antrag: `new_date` (Ortszeit), `reason` |
| `postponements/{id}/accept`, `/decline`, `/withdraw` | POST | Antwort der Gegenseite bzw. Rückzug |
| `postponements/{id}/decision` | POST | TK: `approve` oder `reject` mit `comment` |

## Oberfläche

- Erfassungsseite und «Meine Spiele»: bei offenen Begegnungen «Termin verschieben»; ein offener Antrag der Gegenseite steht zuoberst mit «Zustimmen» und «Ablehnen».
- Matchreport und Spielpläne zeigen bei verschobenen Begegnungen den neuen Termin mit dem Vermerk «verschoben».
- TK-Übersicht: Liste «Verschiebungen» (zu bewilligen, unbeantwortet).

## Dienste und Rechte

- `Postponement\PostponementService` mit den Übergängen oben; reine Regel `Domain\Match\PostponementRules` (Bewilligung nötig, erlaubte Übergänge).
- `ttl_reschedule_fixture` mit der Fixture-ID; welche Seite jemand vertritt, kommt aus `AccessPolicy::side_for()`.

## Benachrichtigungen

`postponement` an beide Seiten (Antrag an die Gegenseite, Antwort an den Antragsteller, Entscheid der TK an beide), `postponement_tk` an die TK (zur Kenntnis oder zur Bewilligung).

## Tests

- Regel: Grenze bei genau 14 Tagen, Vorverschieben, Antrag der TK.
- Dienst gegen MariaDB: alle Übergänge, zweiter offener Antrag abgelehnt, Clubduell, Wirkung auf Termin und Status, Erinnerung vor dem Spiel für den neuen Termin, Terminvorschlag ohne bisherigen Termin.
- WordPress: REST mit 401, 403, 409 und 422; Rechte beider Seiten.
