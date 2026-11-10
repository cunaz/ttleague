# Anlässe mit Anmeldung

Turniere, Trainings und Sitzungen, zu denen man sich auf der Website anmeldet. Ersetzt externe Formulare. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Anlass

Die TK legt Anlässe im Backend an («Spielbetrieb → Anlässe»): `type` (`tournament`, `training`, `meeting`, `other`), Titel, Beginn, Ende, Ort, Anmeldeschluss, Höchstzahl, optional ein verknüpfter Beitrag oder eine Seite (`post_id`) mit der Ausschreibung.

| Status | Bedeutung |
|---|---|
| `open` | Anmeldung möglich bis zum Anmeldeschluss. |
| `closed` | Die TK hat die Anmeldung geschlossen. |
| `cancelled` | Abgesagt; alle Teilnehmenden und die Warteliste erhalten eine Mitteilung. |

## Anmeldung

Block `ttleague/event-registration` (Attribut `event`), in der Ausschreibung platziert; Shortcode `[ttleague view="event-registration" event="…"]`.

- Nicht eingeloggte Besucher sehen Titel, Termin, Anmeldeschluss, freie Plätze und den Link zum Login.
- Eingeloggte Personen mit verknüpfter `person` melden sich mit einem Klick für den Anlass an oder ab und können eine kurze Bemerkung mitgeben. TK-Chefs melden zusätzlich Spieler ihres Clubs an.
- Ist die Höchstzahl erreicht, kommt die Anmeldung auf die Warteliste (`registration.status = waitlist`). Wird ein Platz frei, rückt die älteste Anmeldung der Warteliste nach; die Person erhält eine Mitteilung.
- Nach dem Anmeldeschluss ändert nur noch die TK.
- An- und Abmeldung bestätigt `event_registration` per E-Mail.

Die Namen der Teilnehmenden sehen nur eingeloggte Personen mit Funktion (Captain, TK-Chef) und die TK; öffentlich steht nur die Anzahl.

## Backend

Liste der Anlässe mit Anzahl Teilnehmenden und Warteliste; je Anlass die Teilnehmerliste (Name, Club, Zeitpunkt, Bemerkung), von Hand an- und abmelden, CSV.

## Dienste und Rechte

`Events\EventService` (anlegen, ändern, schliessen, absagen; `ttl_tk`) und `Events\RegistrationService` (an- und abmelden, nachrücken). Für sich selbst jede Person, für andere nach [saisonbetrieb.md](saisonbetrieb.md).

## Tests

- Dienst gegen MariaDB: anmelden, doppelt anmelden, Höchstzahl und Warteliste, Nachrücken in der Reihenfolge der Anmeldung, Anmeldeschluss an der Grenze, Absage.
- WordPress: Block für nicht eingeloggte Besucher, eingeloggte Personen und TK-Chefs; Formulare mit Nonce und Rechten; CSV nur mit `ttl_tk`.
