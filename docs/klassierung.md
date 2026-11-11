# Klassierung zum Saisonende

Die offizielle Klassierung gilt eine Saison lang. Zum Saisonende schlägt das System aus der Wertungszahl die Klassierung der nächsten Saison vor; die TK bestätigt oder übersteuert. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

Alles arbeitet auf der **aktiven** Saison. Die Seite im Backend heisst «Spielbetrieb → Klassierung» (`admin.php?page=ttleague-tk-klassierung`).

## Vorschlag

«Vorschlag berechnen» schreibt für jeden in der aktiven Saison gemeldeten Spieler (`player_season`, jeder Status) eine Zeile in `classification_proposal`:

| Feld | Inhalt |
|---|---|
| `current_value` | Klassierung dieser Saison (`player_season.classification`) |
| `rating` | Wertungszahl beim Berechnen (`player_rating.rating`); 0 für Spieler ohne Wertungszahl |
| `games_count` | gewertete Spiele in dieser Saison (Einzel und Doppel, wie in der Wertungszahl) |
| `proposed` | `ClassificationScale::from_rating()` mit `rating_points_per_class`; bei weniger als `classification_min_games` Spielen oder ohne Wertungszahl die bisherige Klassierung |

- **Gewertete Spiele** sind die Einträge in `rating_event` des Spielers, deren Spiel zu dieser Saison gehört: über die Begegnung (`fixture.competition_id`) oder die Gruppe des Ranglistenturniers (`rl_group` → `rl_round.competition_id`). Eine Abfrage zählt für alle Spieler. Was nicht in die Wertungszahl eingeht (kampflos, nicht gespielt, Doppel mit Gewicht 0), zählt auch hier nicht.
- **Ohne Wertungszahl** (kein Eintrag in `player_rating`): Die Spalte `rating` ist nicht leer erlaubt, deshalb steht dort 0 bei 0 Spielen. Seite und Export zeigen dann keinen Wert.
- Die **Regel** ist `Domain\Rating\ClassificationProposal::propose()`: genau `classification_min_games` Spiele genügen, eines weniger nicht. Mit `classification_min_games` 0 folgt der Vorschlag immer der gespeicherten Wertungszahl, auch ohne Spiel in dieser Saison.
- **Neu berechnen** überschreibt `current_value`, `rating`, `games_count` und `proposed`. Nachgemeldete Spieler erhalten eine Zeile. Was mit bestehenden Entscheiden geschieht, steht unter «Aktuelle Wertungszahlen».
- Zeilen **ohne** Entscheid von Spielern, die in der Saison nicht mehr gemeldet sind, entfernt die Neuberechnung. Entschiedene Zeilen bleiben und stehen ohne Club am Schluss der Tabelle.
- Sind die Regeln der Saison ungültig, wird nicht gerechnet (kein Rückfall auf Standardwerte).

## Aktuelle Wertungszahlen

Nach jeder Resultatänderung plant die Erfassung die Neuberechnung der Wertungszahl verzögert ein (`ttleague_rating_recalc`, siehe `docs/erfassung.md`). Bis sie gelaufen ist, sind `player_rating` und `rating_event` alt. Die Klassierung rechnet deshalb nie stillschweigend mit einem alten Stand:

1. **Vor dem Berechnen** führt der Dienst eine ausstehende Neuberechnung aus (`Classification::refresh_ratings()`, siehe «Koordination mit der Neuberechnung»). Steht nichts aus, wird nicht neu gerechnet. Die Rückmeldung nennt es, wenn zuvor neu gerechnet wurde.
2. **Beim Neuberechnen** bleibt ein Entscheid stehen, solange er noch zu dem passt, was die TK gesehen hat:

   | Entscheid der Zeile | Vorschlag gleich | Vorschlag geändert |
   |---|---|---|
   | reine Übernahme (`decided` gleich dem bisherigen `proposed`, ohne Bemerkung) | bleibt | **zurückgesetzt**: `decided`, `decided_by`, `decided_at` sind wieder `NULL`, die Zeile ist offen |
   | mit Bemerkung (übersteuert, auch auf den Wert des Vorschlags) | bleibt | bleibt; die Zeile zeigt den neuen Vorschlag neben dem Entscheid |

   Die Rückmeldung nennt die Zahl der zurückgesetzten Entscheide (als Warnung); die Zeilen stehen unter dem Filter «noch offen». Jede Rücksetzung steht im `audit_log`.
3. **Vor dem Abschliessen** führt der Dienst ebenfalls eine ausstehende Neuberechnung aus und vergleicht dann unter der Sperre der Saison jede gespeicherte Zeile mit dem, was eine Berechnung jetzt ergäbe: Kreis der gemeldeten Spieler (fehlende Zeilen, offene Zeilen nicht mehr Gemeldeter), `current_value`, `rating`, `games_count` und `proposed`. Weicht etwas ab, lehnt er ab, nennt die Zahl der betroffenen Zeilen und verweist auf «Vorschlag neu berechnen». Die Prüfung ändert keine Zeile; auch eine geänderte Wertungszahl bei gleichem Vorschlag gilt als Abweichung.

### Koordination mit der Neuberechnung

Die Neuberechnung läuft an einem Ort (`Results::recalc_ratings()`, als Lauf von WP-Cron) und hält für ihre Dauer eine benannte Datenbanksperre (`Rating\RecalcLock`, `GET_LOCK`). Der Name enthält Datenbank und Tabellenpräfix als Hash, damit sich Installationen auf demselben Datenbankserver nicht stören. `Classification::refresh_ratings()` arbeitet unter derselben Sperre:

1. **Sperre nehmen**, mit höchstens 20 Sekunden Wartezeit (`Results::RECALC_LOCK_WAIT`, Filter `ttleague_rating_recalc_lock_wait`). Rechnet gerade ein Lauf im Hintergrund, wartet die Klassierung so auf sein Ende. WP-Cron entfernt den geplanten Eintrag, bevor der Lauf beginnt; ohne die Sperre sähe die Klassierung in dieser Zeit nichts Ausstehendes.
2. **Erst unter der Sperre prüfen**, ob ein Lauf eingeplant ist (frisch aus der Datenbank gelesen). Wenn nicht, sind die Wertungszahlen aktuell.
3. **Neu berechnen** (`Results::recalc_ratings_unlocked()`, ohne zweite Sperre) und den geplanten Eintrag **erst nach dem Erfolg entfernen**. Scheitert die Berechnung oder bricht der Prozess ab, bleibt sie eingeplant. Ein gleichzeitiges Berechnen oder Abschliessen einer zweiten Person wartet in Schritt 1.
4. Ist während der Berechnung etwas ins `audit_log` geschrieben worden, bleibt der Eintrag stehen. So geht ein Resultat nicht verloren, das während der Berechnung gespeichert wird: Sein eigenes Einplanen ändert am bestehenden Eintrag nichts, und die laufende Berechnung hat es womöglich nicht mehr gelesen. Im Zweifel wird einmal zu viel gerechnet.
5. Sperre freigeben, auch nach einem Fehler.

Der Lauf von WP-Cron wartet ebenso lange auf die Sperre. Hält sie dann noch ein anderer Lauf, plant er sich neu ein, statt doppelt zu rechnen.

**Fehler:** Scheitert «Wertungszahlen aktuell machen» (Fehler in der Neuberechnung, auch ein `Error`; Sperre nach der Wartezeit noch belegt), lehnt der Dienst mit `ClassificationException::UNAVAILABLE` ab: «Die ausstehende Neuberechnung der Wertungszahl ist gescheitert; sie bleibt eingeplant. Bitte später erneut versuchen.» Es wird weder berechnet noch abgeschlossen. Die Ursache steht in `getPrevious()` und im Fehlerprotokoll (`ttleague: Klassierung abgelehnt …`), nicht in der Rückmeldung.

Grenze: Die Erfassung plant die Neuberechnung erst nach dem Speichern eines Resultats ein. Ein Resultat, das im selben Augenblick gespeichert wird, in dem die TK berechnet oder abschliesst, kann deshalb noch fehlen. Vor dem Abschluss fällt es beim nächsten Versuch auf, danach nicht mehr. Die Sperre gehört der Datenbankverbindung: Bricht die Verbindung während der Berechnung ab, ist sie frei, bevor der Lauf gescheitert ist.

## Entscheid der TK

Tabelle aller Spieler auf einer Seite, nach Club und Name: Club, bisherige Klassierung, Wertungszahl, Spiele, Vorschlag, Abweichung (in Stufen, «+2», «−1»), Entscheid, Bemerkung. Filter nach Club (`club`), «Vorschlag weicht ab» (`deviating=1`: Vorschlag ungleich bisherige Klassierung) und «noch offen» (`open=1`: ohne Entscheid). Die Zahlen über der Tabelle gelten für die ganze Saison.

- **Übernehmen:** `decided = proposed`, einzeln oder mit «Alle offenen übernehmen» für alle Zeilen ohne Entscheid, die zum Filter passen (eine Transaktion). Das einzelne Formular schickt den angezeigten Vorschlag mit; wurde inzwischen neu berechnet, lehnt der Dienst ab.
- **Übersteuern:** eigener Wert D1 bis A22 mit Pflichtbemerkung (`note`, höchstens 1000 Zeichen).
- Bis zum Abschluss lässt sich jeder Entscheid ändern. Übernehmen löscht die Bemerkung eines früheren Entscheids. Ein unveränderter Entscheid wird nicht erneut geschrieben.
- `decided` ist `NULL`, solange nicht entschieden ist. `decided_by` ist das Konto, `decided_at` der Zeitpunkt in UTC.
- **Abschliessen** setzt `season.classification_closed_at` (UTC). Das geht erst, wenn es einen Vorschlag gibt, die Zeilen dem aktuellen Stand entsprechen (siehe «Aktuelle Wertungszahlen») und jede Zeile entschieden ist. Danach sind Berechnung und Entscheide gesperrt.
- **Wieder öffnen** kann nur die TK, mit Bemerkung; `classification_closed_at` ist danach wieder `NULL`.

Die abgeschlossenen Werte sind die Klassierung der Vorjahresspieler in der nächsten Meldung ([meldung.md](meldung.md)): Die Meldung liest `classification_proposal.decided` der aktiven Saison, sobald `classification_closed_at` gesetzt ist. Wer nicht mehr gemeldet wird, behält seine Klassierung (Art. 28.4): Bei einer späteren Meldung gilt der letzte entschiedene Wert. `player_season.classification` der laufenden Saison ändert die Klassierung nie.

### Protokoll

| `entity` | `action` | Inhalt |
|---|---|---|
| `season` | `classification_computed` | Anzahl Spieler, neue, aktualisierte und entfernte Zeilen, zurückgesetzte Entscheide (`reset`), ob zuvor die Wertungszahl neu berechnet wurde (`refreshed`) |
| `classification_proposal` | `decision_reset` | beim Neuberechnen zurückgesetzte Übernahme: vorher `decided` und bisheriger `proposed`, nachher `decided` `NULL` und neuer `proposed`; Konto, das neu berechnet hat |
| `classification_proposal` | `accepted` | vorher `decided` und `note`, nachher `decided`, `note`, `proposed`; bei «Alle offenen übernehmen» ein Eintrag pro Zeile |
| `classification_proposal` | `overridden` | wie oben, Bemerkung auch in `audit_log.note` |
| `season` | `classification_closed` | Zeitpunkt und Anzahl Zeilen |
| `season` | `classification_reopened` | bisheriger Zeitpunkt, Bemerkung |

Nach jedem gespeicherten Schreibvorgang folgt `DataVersion::bump()`.

## Export

CSV «Klassierungssitzung» (Liste `classification`): Lizenznummer, Name («Nachname Vorname»), Club, bisherige Klassierung, Wertungszahl, Spiele, Vorschlag, Entscheid, Bemerkung; Klassierungen als D1 bis A22, sortiert wie die Tabelle. Im Backend mit dem Filter der Seite, dazu `wp ttleague export classification`. Filter: `club` (ID), `deviating=1`, `open=1`, `season` (ID einer anderen als der aktiven Saison, z. B. für die Liste einer archivierten Saison).

## Darstellung

Das Spielerprofil zeigt weiter die offizielle Klassierung der Saison und die Wertungszahl mit Verlauf. Vorschläge und offene Entscheide sind nicht öffentlich: Kein Lesemodell, keine Ansicht, kein Kalender und keine REST-Route liest `classification_proposal`.

## Dienste und Rechte

- `Domain\Rating\ClassificationProposal`: die Regel für den Vorschlag als reine Funktion.
- `Classification\ProposalService` (`compute`, `accept`, `accept_open`, `override`, `close`, `reopen`, dazu `overview` und `rows` zum Lesen) mit `ProposalRepository` und `ProposalFilter`. Jeder Schreibvorgang verlangt die TK (`Actor::is_tk`), sperrt in seiner Transaktion zuerst die Saison (`SELECT … FOR UPDATE`, wie die Erfassung) und prüft unter der Sperre, dass sie aktiv und nicht abgeschlossen ist. Ablehnungen sind `ClassificationException` mit Klartext. «Wertungszahlen aktuell machen» ist eine Abhängigkeit des Dienstes (Closure, liefert, ob neu berechnet wurde); er ruft sie vor der Transaktion von `compute` und `close` auf, nicht ohne Recht und nicht nach dem Abschluss, und macht aus jedem Fehler darin eine Ablehnung. So bleibt er ohne WordPress testbar.
- `Classification\Classification::register()` meldet die Seite (`ttleague_tk_pages`, Position 70), den Export (`ttleague_exports`) und die Formularaktionen an. `Classification\Page` rendert, `Classification\Actions` nimmt die Formulare an `admin-post.php` entgegen: Nonce je Aktion, `ttl_tk`, Rückmeldung über `Flash`, zurück auf die Seite mit dem bisherigen Filter und der Zeile als Sprungmarke.
- Alles verlangt `ttl_tk`: Seite, Aktionen und CSV in WordPress, dazu die Prüfung im Dienst.

## Tests

- Regel (`tests/Unit/Domain/ClassificationProposalTest.php`): Grenzen der Stufen und der Skala, genau `classification_min_games` Spiele und eines weniger, Spieler ohne Wertungszahl, Punkte pro Stufe aus den Regeln.
- Dienst gegen MariaDB (`tests/Integration/Classification/`): berechnen (nur gemeldete Spieler der aktiven Saison, Spiele nur dieser Saison, Zählung gleich wie in der Wertungszahl), ausstehende Neuberechnung der Wertungszahl zuerst, gescheiterte Neuberechnung als Ablehnung ohne Änderung, neu berechnen (Übersteuerung und Übernahme bei gleichem Vorschlag bleiben, Übernahme bei geändertem Vorschlag zurückgesetzt und protokolliert), Zeilen nicht mehr gemeldeter Spieler, übernehmen einzeln und für einen Filter in einer Transaktion, übersteuern ohne Bemerkung und ausserhalb der Skala abgelehnt, abschliessen mit offenen Zeilen abgelehnt, abschliessen abgelehnt nach geänderter Wertungszahl, Spielzahl, Klassierung oder Meldung und nach einer ausstehenden Neuberechnung, ohne dass sich eine Zeile ändert, Sperre nach dem Abschluss, wieder öffnen nur mit Bemerkung, jeder Schreibweg nur für die TK, Protokoll mit Wert vorher und nachher, Profil und Spielerliste unverändert.
- WordPress (`tests/WordPress/Classification/`): Seite, jede Formularaktion und CSV nur mit Nonce und `ttl_tk` (Abonnent, TK-Chef, Gast), Filter aus der Anfrage, Escaping, gesperrte Ansicht nach dem Abschluss, Rückmeldungen, Cache-Version; eingeplante Neuberechnung der Wertungszahl vor dem Berechnen und dem Abschliessen ausgeführt; nach einem Fehler weiter eingeplant, Rückmeldung statt Abbruch, Ursache im Fehlerprotokoll; mit einer zweiten Datenbankverbindung als «laufendem Lauf»: Berechnen und Abschliessen warten und lehnen nach der Wartezeit ab, der geplante Lauf hält die Sperre und plant sich bei belegter Sperre neu ein; ein Schreibvorgang während der Berechnung lässt den Eintrag stehen.
- Sperre (`tests/Integration/Rating/RecalcLockTest.php`): zwei Verbindungen, Wartezeit, Wiedereintritt, eigener Name je Installation.

Nicht automatisch geprüft: der Aufruf über WP-CLI (`wp ttleague export classification` nutzt denselben Weg wie der geprüfte Download, `Exports::csv()`).
