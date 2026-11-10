# Saison anlegen, einteilen, freigeben, abschliessen

Der Assistent der TK im Backend («Spielbetrieb → Saison»). Er führt in festen Schritten vom Anlegen der neuen Saison bis zu ihrem Abschluss. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

## Schritte

| Nr. | Schritt | Wirkung |
|---|---|---|
| 1 | Saison anlegen | Neue Saison mit Status `planned` aus der aktiven: Name und Daten, `rules_json` übernommen, `club_season` für jeden Club mit Mannschaft in der aktiven Saison, TK-Chefs vorgetragen. Nur möglich, wenn es keine geplante Saison gibt. |
| 2 | Regeln | Formular für die Parameter von `SeasonRules`. Gespeichert wird nur, was `SeasonRules::from_array()` annimmt; Fehler stehen am Feld. |
| 3 | Meldefenster | `registration_opens`, `registration_closes`, `late_registration_until`. Danach arbeiten die Clubs ([meldung.md](meldung.md)). Der Schritt zeigt den Meldestand je Club. |
| 4 | Kategorien und Gruppen | Konkurrenzen der Meisterschaft anlegen (Kategorie, Gruppe) und Mannschaften zuteilen. |
| 5 | Spielplan | Spielwochen wählen, Entwurf erzeugen, Kollisionen bereinigen ([spielplan.md](spielplan.md)). |
| 6 | Freigabe | Die geplante Saison wird aktiv, die bisher aktive archiviert. |
| 7 | Abschluss | Am Saisonende: Klassierungen bestätigen ([klassierung.md](klassierung.md)), Saison archivieren. |

Jeder Schritt zeigt, was noch fehlt. Die Schritte 2 bis 5 lassen sich bis zur Freigabe beliebig wiederholen.

## Kategorien und Gruppen

- Das System schlägt die Einteilung aus der Schlussrangliste des Vorjahrs vor (Art. 11.3): Jede Mannschaft bleibt in ihrer Kategorie; die Rangliste steht daneben. Auf- und Abstieg setzt die TK von Hand, ihr Entscheid ist endgültig (Art. 11.5).
- Neue Mannschaften stehen zuerst ohne Kategorie da.
- Prüfungen: höchstens zwei Mannschaften eines Vereins pro Kategorie, ausser in der untersten (Art. 11.4, weicher Hinweis, weil die TK entscheidet); jede Mannschaft der freigegebenen Meldungen ist zugeteilt; keine Gruppe mit weniger als drei Mannschaften.

## Spielplan

- Die TK wählt die Spielwochen der Vor- und der Rückrunde (Montage). Das System schlägt alle Wochen zwischen Saisonbeginn und Saisonende vor, ohne die Wochen des Ranglistenturniers; die TK streicht Ferienwochen. Gespeichert in `season.schedule_json`.
- «Entwurf erzeugen» ruft den Planer auf und schreibt die Begegnungen als `fixture` mit Status `scheduled` in die geplante Saison. Sie sind bis zur Freigabe nicht öffentlich. Ein neuer Entwurf ersetzt alle Begegnungen der Konkurrenz, solange keine ein Resultat hat.
- Der Bericht listet die Kollisionen mit Ausweichvorschlag. Die TK verlegt einzelne Begegnungen (Datum, Zeit, Lokal) oder tauscht das Heimrecht; danach wird neu geprüft.
- Tische pro Begegnung: `SeasonRules::schedule_tables_per_match`.

## Freigabe

Voraussetzungen: alle Meldungen `reviewed`, jede Mannschaft zugeteilt, ein Spielplan ohne harte Kollision (oder die TK bestätigt die verbleibenden ausdrücklich).

In einer Transaktion: bisher aktive Saison `archived`, geplante `active`, `DataVersion::bump()`. Danach gehen `season_published` an Captains und TK-Chefs (Spielplan ihrer Mannschaften, Link zum Kalender-Abo). Die Rechte der Captains gelten ab jetzt.

Die Freigabe ist nicht umkehrbar über die Oberfläche. Deshalb verlangt sie eine Bestätigung mit dem Namen der Saison.

## Während der Saison

- **Rückzug einer Mannschaft** (Art. 13.3): `team.status = withdrawn`. Alle ihre Begegnungen werden als Forfait 0:10 gewertet, auch gespielte; die Mannschaft steht am Tabellenende. Mit Begründung im `audit_log`.
- **Einzelne Begegnung ansetzen oder absagen** (Entscheidungsspiel, Art. 15): im Schritt Spielplan.

## Abschluss

Voraussetzungen: keine Begegnung mehr `scheduled`, `postponed`, `provisional` oder `disputed`; Klassierung abgeschlossen (`season.classification_closed_at`). Archivieren ohne folgende Saison ist erlaubt; dann gibt es bis zur nächsten Freigabe keine aktive Saison.

## Dienste und Rechte

- `Season\SeasonSetup` (anlegen, Regeln, Fenster), `Season\Grouping`, `Season\Scheduling` (Entwurf, verlegen, prüfen), `Season\Lifecycle` (Freigabe, Rückzug, Abschluss).
- Alles verlangt `ttl_tk`. Formulare im Backend über `admin-post.php` mit Nonce, wie die Schnellaktionen der Übersicht.

## Tests

- Anlegen aus der aktiven Saison (Regeln, `club_season`, TK-Chefs), zweite geplante Saison abgelehnt.
- Einteilung: Vorschlag aus der Vorjahresrangliste, Hinweis bei drei Mannschaften eines Vereins, unterste Kategorie ausgenommen.
- Spielplan: Entwurf schreibt Begegnungen, neuer Entwurf ersetzt sie, nicht mehr nach dem ersten Resultat; Verlegen prüft neu.
- Freigabe: Voraussetzungen, Statuswechsel beider Saisons in einer Transaktion, geplante Saison vorher in keiner öffentlichen Ansicht.
- Rückzug: Wertung und Tabellenplatz. Abschluss: Voraussetzungen.
