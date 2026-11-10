# Spielplan der Meisterschaft

Dieses Dokument beschreibt den Spielplan-Generator: wie aus Gruppen, Spielwochen, Hallensperren und Tischzahlen der Entwurf eines Spielplans entsteht, welche Kollisionen die Prüfung meldet und wie sie Ausweichtermine vorschlägt. Es beschreibt nur die Domäne (`src/Domain/Schedule/`, reines PHP ohne WordPress und ohne Datenbank). Die Oberfläche der TK (Spielwochen wählen, Entwurf erzeugen, verlegen) beschreibt `docs/saison.md`. Alle Beispiele verwenden erfundene Daten.

## Zweck

Die Meisterschaft wird mit Vor- und Rückrunde gespielt (Art. 13.1 des Wettspielreglements). Jedes Team hat ein Heimlokal mit festem Heimspieltag und Anspielzeit; der Termin einer Begegnung ist deshalb «Spielwoche plus Heimspieltag des Heimteams». In Ligen mit festen Heimspieltagen liegt der grösste Teil der Begegnungen auf diesem Tag. Der Generator liefert genau diesen Regelfall als Entwurf. Was nicht aufgeht (gesperrte Halle, zu wenige Tische, zwei Mannschaften eines Clubs am selben Abend), meldet die Prüfung mit einem Ausweichvorschlag. Verlegt wird nie automatisch: Die TK entscheidet, ändert den Plan von Hand und lässt ihn neu prüfen.

| Aufgabe | Klasse | Methode |
|---|---|---|
| Entwurf erzeugen und gleich prüfen | `SchedulePlanner` | `plan( ScheduleInput ): ScheduleDraft` |
| Beliebigen Plan prüfen | `SchedulePlanner`, `ScheduleChecker` | `check( ScheduleInput, list<PlannedFixture> ): ScheduleReport` |
| Nur erzeugen | `ScheduleGenerator` | `generate( ScheduleInput ): list<PlannedFixture>` |
| Zwei Pläne abgleichen | `ScheduleComparator` | `compare( $plan, $reference ): ScheduleComparison` |

«Erzeugen» und «Prüfen» sind getrennt: `plan()` ruft beides nacheinander auf, `check()` gilt für jede Liste datierter Begegnungen. Die Paarungen stammen vom bestehenden `RoundRobinScheduler` (`double_round_robin()`, `assign_dates()`).

```php
$input = new ScheduleInput(
	array(
		new ScheduleGroup(
			'A',
			array(
				new ScheduleTeam( 3, 1, 10, 2, '19:30' ), // Team, Club, Lokal, Dienstag, Anspielzeit.
				new ScheduleTeam( 4, 2, 11, 4, '20:00' ),
				// …
			)
		),
	),
	$first_leg_weeks,  // Montage der Vorrunde.
	$second_leg_weeks, // Montage der Rückrunde.
	array( new VenueClosure( 10, new DateTimeImmutable( '2026-10-05' ), new DateTimeImmutable( '2026-10-16' ), 'Herbstferien' ) ),
	array( 10 => 4, 11 => null ) // Tische je Lokal, null: unbekannt.
);
$planner = new SchedulePlanner( 2 ); // Tische pro Begegnung.
$draft   = $planner->plan( $input );            // $draft->fixtures, $draft->report.
$report  = $planner->check( $input, $changed ); // Nach jeder Verlegung von Hand.
```

## Eingaben

Alle Eingaben sind unveränderliche Wertobjekte; ungültige Werte lösen eine `InvalidArgumentException` mit deutscher Meldung aus. IDs dürfen Zahlen oder Zeichenketten sein, `12` und `'12'` gelten als dieselbe ID.

| Objekt | Felder | Regeln |
|---|---|---|
| `ScheduleTeam` | `id`, `club_id`, `venue_id`, `home_weekday`, `start_time` | Heimspieltag 1 (Montag) bis 7 (Sonntag), Anspielzeit «HH:MM» |
| `ScheduleGroup` | `id`, `teams` | mindestens zwei Teams, jedes nur einmal; die Reihenfolge der Teams bestimmt die Paarungen |
| `VenueClosure` | `venue_id`, `from`, `to`, `reason` | ganze Kalendertage, erster und letzter Tag eingeschlossen; Uhrzeiten zählen nicht |
| `ScheduleInput` | `groups`, `first_leg_weeks`, `second_leg_weeks`, `closures`, `venue_tables` | siehe unten |

**Spielwochen.** Die TK wählt die Spielwochen selbst und lässt Ferien und die Wochen des Ranglistenturniers weg. Jede Spielwoche ist ihr Montag als `DateTimeImmutable`. Vor- und Rückrunde haben **getrennte Listen**: So beginnt die Rückrunde für alle Gruppen in derselben Woche, auch wenn eine Gruppe mit sechs Teams (fünf Runden pro Halbserie) früher fertig ist als eine mit sieben (sieben Runden). Jede Liste muss aufsteigend und ohne Wiederholung sein, und die Rückrunde beginnt nach der letzten Spielwoche der Vorrunde. Wochen, die eine Gruppe nicht braucht, bleiben Reserve und sind Kandidaten für Ausweichvorschläge.

**Tische.** `venue_tables` ordnet der Lokal-ID die Anzahl Tische zu (ganze Zahl ab 1). `null` oder kein Eintrag heisst «unbekannt». Wie viele Tische eine Begegnung braucht, ist ein Parameter des Konstruktors von `SchedulePlanner` und `ScheduleChecker` (Standard 2).

**Zeit.** Alle Termine sind Ortszeit, ohne Umrechnung. Verglichen wird der Kalendertag, wie er im jeweiligen Objekt steht; alle Eingaben sollen deshalb dieselbe Zeitzone verwenden. Die Domäne liest weder die Systemzeit noch Zufallszahlen: Gleiche Eingabe ergibt denselben Plan und denselben Bericht.

## Regeln des Entwurfs

- Pro Gruppe ein Rundenturnier nach dem Kreisverfahren. Bei ungerader Teamzahl hat pro Runde ein Team spielfrei: sechs Teams spielen 2 × 5 Runden mit je drei Begegnungen (30), sieben Teams 2 × 7 Runden mit je drei Begegnungen (42).
- Die Rückrunde spiegelt die Vorrunde: dieselben Paarungen in derselben Reihenfolge, Heimrecht getauscht.
- Runde n der Vorrunde fällt in die n-te Spielwoche der Vorrunde, Runde n der Rückrunde in die n-te Spielwoche der Rückrunde.
- Termin: Montag der Spielwoche plus Heimspieltag des Heimteams, zur Anspielzeit des Heimteams, im Heimlokal des Heimteams. Wochen über den Jahreswechsel sind gewöhnliche Wochen (Montag, 28. Dezember 2026, mit Heimspieltag Freitag ergibt den 1. Januar 2027).
- Reichen die Spielwochen nicht für die Runden, gibt es keinen Entwurf, sondern eine `InvalidArgumentException`, die alle betroffenen Gruppen und Halbserien nennt, zum Beispiel «Zu wenige Spielwochen für die Anzahl Runden: Gruppe B braucht in der Rückrunde 7, vorhanden sind 6.»

Eine Begegnung (`PlannedFixture`) trägt Gruppe, Runde (über beide Halbserien fortlaufend ab 1; `null` bei Plänen ohne Runden, etwa aus Altdaten), Halbserie (`leg` 1 Hinspiel, 2 Rückspiel), Heim, Gast, Datum mit Anspielzeit (`starts_at`) und Lokal. `moved_to()` liefert dieselbe Begegnung an einem anderen Termin; so verlegt die Anwendung ein Spiel, bevor sie neu prüft.

## Kollisionsarten

Die Prüfung liefert einen `ScheduleReport` mit einer Liste von `ScheduleConflict`. Jede Kollision nennt Art (`ConflictKind`), Meldung, die betroffenen Begegnungen, Ausweichvorschläge sowie Tag, Lokal, Club oder Team, soweit sie zur Art gehören. Die Meldung nennt keine IDs; die Oberfläche setzt Namen aus diesen Feldern ein. Ein «Abend» ist ein Kalendertag.

| Art | Härte | Bedingung | Betroffene Begegnungen |
|---|---|---|---|
| `venue_closed` | hart | Das Lokal der Begegnung ist an ihrem Tag gesperrt. | die Begegnung (eine Kollision pro Begegnung) |
| `venue_capacity` | hart | Begegnungen am selben Abend im selben Lokal × Tische pro Begegnung > Tische des Lokals. Genau ausgelastet ist erlaubt. | alle Begegnungen des Abends im Lokal |
| `team_double_booked` | hart | Dieselbe Mannschaft hat am selben Abend mehrere Begegnungen. Der Generator erzeugt das nie, eine Verlegung von Hand kann es auslösen. | alle Begegnungen der Mannschaft an diesem Abend |
| `club_same_evening` | weich | Mindestens zwei Mannschaften desselben Clubs spielen am selben Abend in verschiedenen Begegnungen (Ersatzspieler fehlen dann). Ein Clubduell allein zählt nicht. | alle Begegnungen des Clubs an diesem Abend |
| `tables_unknown` | Hinweis | Die Tischzahl eines Lokals im Plan ist unbekannt: keine Kapazitätsprüfung, dafür ein Hinweis pro Lokal mit dem Bedarf des vollsten Abends. | alle Begegnungen in diesem Lokal |

`ScheduleReport::hard()` liefert die harten Kollisionen, `hints()` die übrigen, `of_kind()` eine Art, `has_hard_conflicts()` sagt, ob der Plan so spielbar ist. Die Reihenfolge ist fest: nach Tag, innerhalb eines Tages in der Reihenfolge der Tabelle, die Hinweise `tables_unknown` am Schluss.

Zu wenige Spielwochen sind keine Kollision im Bericht, sondern ein Fehler beim Erzeugen (siehe oben): Ohne genügend Wochen gibt es keinen Plan, den man prüfen könnte.

## Ausweichvorschlag

Zu jeder betroffenen Begegnung sucht die Prüfung höchstens einen Ausweichtermin (`ScheduleSuggestion`: Begegnung und neuer Termin, `moved()` liefert die verlegte Begegnung). Für `tables_unknown` gibt es keine Vorschläge.

**Kandidaten** sind die Spielwochen derselben Halbserie, jeweils am Heimspieltag des Heimteams zur Anspielzeit, im Lokal der Begegnung. Ein Hinspiel wird nie in die Rückrunde vorgeschlagen und umgekehrt.

**Suchreihenfolge:**

1. zuerst die Termine nach dem bisherigen Termin, aufsteigend (der nächste zuerst);
2. dann die Termine vor dem bisherigen Termin, absteigend (der nächstfrühere zuerst).

Der erste **freie** Termin wird vorgeschlagen. Ein Termin ist frei, wenn alle Bedingungen gelten:

- beide Teams haben in dieser Woche (Montag bis Sonntag) keine andere Begegnung;
- das Lokal ist an diesem Tag nicht gesperrt;
- das Lokal hat Platz für eine weitere Begegnung (bei unbekannter Tischzahl gilt es als frei);
- nur beim Hinweis `club_same_evening`: Keiner der beiden Clubs hat an diesem Abend eine andere Begegnung. Sonst bliebe der Hinweis nach der Verlegung bestehen. Bei harten Kollisionen zählt diese Bedingung nicht, damit ein spielbarer Termin nicht an einem Hinweis scheitert.

Gibt es keinen freien Termin, bleibt die Liste der Vorschläge leer. Das ist der Normalfall, wenn die TK genau so viele Spielwochen meldet, wie die Gruppe Runden hat: Vorschläge brauchen Reservewochen.

Jeder Vorschlag gilt für sich und gegen den geprüften Plan. Bei einer Kapazitätskollision mit zwei Begegnungen können beide denselben Abend vorgeschlagen bekommen; es genügt, eine zu verlegen. Nach jeder Verlegung wird neu geprüft.

## Abgleich zweier Pläne

`ScheduleComparator::compare()` ordnet die Begegnungen zweier Pläne über Heim, Gast und Halbserie einander zu und zählt in `ScheduleComparison`:

| Feld | Bedeutung |
|---|---|
| `matched` | Begegnungen in beiden Plänen |
| `same_date`, `same_date_share()` | davon am selben Kalendertag (Anspielzeit und Lokal zählen nicht) |
| `same_week`, `same_week_share()` | davon in derselben Woche (Montag bis Sonntag, auch über den Jahreswechsel) |
| `same_weekday`, `same_weekday_share()` | davon am selben Wochentag |
| `only_in_plan`, `only_in_reference` | Begegnungen ohne Gegenstück |

Die Anteile beziehen sich auf `matched` und sind `null`, wenn keine Begegnung zugeordnet werden konnte. Kommt eine Paarung in einem Plan mehrfach vor, ist der Abgleich nicht eindeutig und bricht mit einer Meldung ab. Gedacht ist der Abgleich für die Validierung gegen die Spielpläne früherer Saisons: Entwurf aus den damaligen Eingaben erzeugen und mit dem gespielten Plan vergleichen.

## Grenzen

- Der Entwurf optimiert nicht. Er verteilt keine Runden um, um Kollisionen zu vermeiden, und tauscht kein Heimrecht.
- Gruppen mit weniger Runden belegen die ersten Spielwochen jeder Halbserie. Wer eine Gruppe über die ganze Halbserie strecken will, verlegt von Hand.
- Vorschläge bleiben am Heimspieltag, im Lokal der Begegnung und in der eigenen Halbserie. Andere Wochentage, andere Lokale und Wochen ausserhalb der gemeldeten Spielwochen schlägt die Prüfung nicht vor.
- Die Domäne kennt das heutige Datum nicht. Wird ein laufender Plan geprüft, kann ein früherer Ausweichtermin in der Vergangenheit liegen; die Anwendung muss solche Vorschläge ausblenden.
- Die Kapazität gilt pro Kalendertag, unabhängig von der Anspielzeit. Zwei Begegnungen nacheinander am selben Abend zählen wie zwei gleichzeitige.
- Der Hinweis `club_same_evening` vergleicht nur Clubs, nicht Kategorien oder Kader. Ob tatsächlich Ersatzspieler fehlen, beurteilt die TK.
- Geprüft wird nur, was im Plan steht: keine Vollständigkeit (fehlende oder doppelte Paarungen), keine Fristen für Verschiebungen (Art. 10.2), kein Cup und kein Ranglistenturnier. Eine Begegnung mit einem Team, das in keiner Gruppe der Eingabe steht, wird abgewiesen.
- Die Tische pro Begegnung sind vorerst ein Parameter des Konstruktors und noch kein Saison-Parameter.

## Tests

Unit-Tests ohne WordPress und ohne Datenbank in `tests/Unit/Domain/`, gemeinsame Hilfen in `ScheduleTestCase` (erfundene Liga: Team «Seefeld 1», Club «Seefeld», Lokal «Halle Seefeld»).

| Datei | Inhalt |
|---|---|
| `ScheduleInputTest` | Wertobjekte: Heimspieltag, Anspielzeit, Gruppen, Spielwochen (Montag, aufsteigend, Rückrunde nach Vorrunde), Sperre am ersten und letzten Tag, unbekannte Tischzahl, Woche über den Jahreswechsel |
| `ScheduleGeneratorTest` | 6 und 7 Teams, Vor- und Rückrunde gespiegelt, Termin aus Spielwoche und Heimspieltag, Reservewochen, Jahreswechsel, zu wenige Spielwochen, gleiche Eingabe ergibt gleichen Plan |
| `ScheduleCheckerTest` | jede Kollisionsart mit und ohne Ausweichvorschlag, Suchreihenfolge, Sperre an den Grenzen, unbekannte Tischzahl, Tische pro Begegnung, Clubduell, Jahreswechsel, Reihenfolge des Berichts |
| `SchedulePlannerTest` | Entwurf mit Bericht, Entwurf bleibt trotz Kollision unverändert, von Hand geänderter Plan wird neu geprüft, Vorschlag übernehmen |
| `ScheduleComparatorTest` | gleicher Tag, gleiche Woche, gleicher Wochentag, Zuordnung über Heim, Gast und Halbserie, fehlende Gegenstücke, doppelte Paarungen |

`RoundRobinSchedulerTest` bleibt unverändert gültig.
