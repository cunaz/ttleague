# Klassierung zum Saisonende

Die offizielle Klassierung gilt eine Saison lang. Zum Saisonende schlägt das System aus der Wertungszahl die Klassierung der nächsten Saison vor; die TK bestätigt oder übersteuert. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Vorschlag

«Spielbetrieb → Klassierung → Vorschlag berechnen» schreibt für jeden in der aktiven Saison gemeldeten Spieler eine Zeile in `classification_proposal`:

| Feld | Inhalt |
|---|---|
| `current_value` | Klassierung dieser Saison |
| `rating` | Wertungszahl beim Berechnen |
| `games_count` | gewertete Spiele in dieser Saison (Einzel und Doppel, wie in der Wertungszahl) |
| `proposed` | `ClassificationScale::from_rating()`; bei weniger als `classification_min_games` Spielen die bisherige Klassierung |

Neu berechnen überschreibt `rating`, `games_count` und `proposed`, aber keine Entscheide der TK.

## Entscheid der TK

Tabelle aller Spieler mit Club, bisheriger Klassierung, Wertungszahl, Spielen, Vorschlag und Abweichung. Filter nach Club, nach «Vorschlag weicht ab» und nach «noch offen».

- **Übernehmen:** `decided = proposed`, einzeln oder für alle offenen Zeilen des Filters.
- **Übersteuern:** eigener Wert mit Pflichtbemerkung (`note`).
- Jeder Entscheid steht mit vorherigem und neuem Wert im `audit_log`.
- **Abschliessen** setzt `season.classification_closed_at`. Das geht erst, wenn jede Zeile entschieden ist. Danach sind die Werte gesperrt; wieder öffnen kann nur die TK, mit Bemerkung.

Die abgeschlossenen Werte sind die Klassierung der Vorjahresspieler in der nächsten Meldung ([meldung.md](meldung.md)). Wer nicht mehr gemeldet wird, behält seine Klassierung (Art. 28.4): Bei einer späteren Meldung gilt der letzte entschiedene Wert.

## Export

CSV «Klassierungssitzung»: Lizenznummer, Name, Club, bisherige Klassierung, Wertungszahl, Spiele, Vorschlag, Entscheid, Bemerkung. Im Backend und als `wp ttleague export classification`.

## Darstellung

Das Spielerprofil zeigt weiter die offizielle Klassierung der Saison und die Wertungszahl mit Verlauf. Vorschläge und offene Entscheide sind nicht öffentlich.

## Dienste und Rechte

`Classification\ProposalService` (berechnen, entscheiden, abschliessen); die Regel für den Vorschlag als reine Funktion in `Domain\Rating`. Alles verlangt `ttl_tk`.

## Tests

- Regel: Grenzen der Skala, zu wenige Spiele, Spieler ohne Wertungszahl.
- Dienst gegen MariaDB: berechnen, neu berechnen ohne Verlust der Entscheide, übernehmen für einen Filter, übersteuern ohne Bemerkung abgelehnt, abschliessen mit offenen Zeilen abgelehnt, Sperre nach dem Abschluss.
- WordPress: Seite und CSV nur mit `ttl_tk`.
