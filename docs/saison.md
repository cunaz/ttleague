# Saison anlegen, einteilen, freigeben, abschliessen

Der Assistent der TK im Backend («Spielbetrieb → Saison», `admin.php?page=ttleague-tk-saison`). Er führt in festen Schritten vom Anlegen der neuen Saison bis zu ihrem Abschluss. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md).

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

Jeder Schritt zeigt, was noch fehlt. Die Schritte 2 bis 5 lassen sich bis zur Freigabe beliebig wiederholen. Die Schritte 1 bis 6 gelten der geplanten Saison, der Schritt 7 und die Aktionen «während der Saison» der aktiven.

## Saison anlegen (`Season\SeasonSetup`)

- **Mit aktiver Saison:** Name `2027/28`, Kürzel `2027-28`, Beginn und Ende ein Jahr nach der aktiven (`Domain\Season\SeasonDates::following()`). `rules_json` wird unverändert kopiert. Eine offene Meldung (`club_season.status = open`) erhält jeder Club mit mindestens einer nicht zurückgezogenen Mannschaft in der aktiven Saison; die TK-Chefs dieser Clubs werden in `club_role` vorgetragen, Captains nicht.
- **Ohne aktive Saison** (frische Installation): Name, Beginn und Ende kommen aus dem Formular, die Regeln sind die Standardwerte. Der Name hat die Form `2027/28`, der Beginn liegt im ersten Jahr, die Saison dauert höchstens ein Jahr.
- **Clubs aufnehmen und herausnehmen:** `add_club()` legt für einen weiteren Club die offene Meldung an (neuer Club, Club ohne Mannschaft im Vorjahr, jeder Club einer ersten Saison) und trägt seine TK-Chefs der Vorsaison vor, falls er in der geplanten Saison noch keinen hat. `remove_club()` geht, solange der Club nichts gemeldet hat.
- **Höchstens eine geplante Saison:** `create()` sperrt in seiner Transaktion alle Saisons (`SELECT … FOR UPDATE`) und prüft erst dann. Ein Kürzel oder Name, den es schon gibt, wird abgelehnt.
- **Löschen:** Die geplante Saison lässt sich löschen, solange sie leer ist: keine gemeldeten Spieler, Neumeldungen, eingereichten oder geprüften Meldungen, Mannschaften, Begegnungen und nichts im Cup oder Ranglistenturnier. Mit ihr verschwinden die offenen Meldungen, die vorgetragenen Funktionen und die leeren Konkurrenzen. Bestätigung mit dem Namen der Saison.
- **Regeln:** `Domain\Season\RulesInput` kennt jedes Feld von `SeasonRules` mit Typ und Grenzen und wandelt die Texte des Formulars um (Listen und Kategorien mit Komma). Ein Fehler in einem Feld verhindert das Speichern aller Felder; die Eingabe bleibt im Formular stehen.
- **Meldefenster:** Das Fenster endet nicht vor seinem Beginn (ein einzelner Tag ist erlaubt), nicht ohne Beginn und vor dem Saisonende. Die Frist der Nachmeldung liegt in der Saison und nicht vor dem Ende des Fensters. Leere Angaben löschen das Datum. In der aktiven Saison ändert die TK über denselben Dienst die Frist der Nachmeldung (Schritt 7).

## Kategorien und Gruppen (`Season\Grouping`)

- Gruppen der Meisterschaft (`competition.type = MM`) anlegen (Kategorie A bis Z, Gruppennummer 1 bis 99, Name; ohne Namen «Gruppe A» bzw. «Gruppe A2»), umbenennen und löschen (nur ohne Mannschaften und Begegnungen). `sort_order` folgt Kategorie und Gruppennummer.
- Das System schlägt die Einteilung aus dem Vorjahr vor (Art. 11.3): Jede Mannschaft, die es im Vorjahr gab (gleicher Club, gleiche Nummer), bleibt in ihrer Kategorie und Gruppe; die Schlussrangliste des Vorjahrs (`ReadModel\Championship`) steht daneben. «Vorschlag übernehmen» legt fehlende Gruppen des Vorjahrs an und teilt nur Mannschaften zu, die noch keine Gruppe haben. Auf- und Abstieg setzt die TK von Hand, ihr Entscheid ist endgültig (Art. 11.5).
- Neue Mannschaften stehen zuerst ohne Gruppe da.
- Weiche Hinweise (`Domain\Season\GroupingRules::hints()`): mehr als zwei Mannschaften eines Vereins in einer Kategorie, ausser in der untersten (Art. 11.4; alle Gruppen der Kategorie zählen zusammen, die unterste ist der im Alphabet letzte Buchstabe); Gruppe mit weniger als drei Mannschaften; Mannschaft ohne Gruppe. Sie verhindern nichts. Die Freigabe verlangt aber, dass jede Mannschaft zugeteilt ist.
- Eine Mannschaft mit Begegnungen im Spielplanentwurf wechselt die Gruppe nicht; zuerst wird der Entwurf ihrer Gruppe gelöscht.
- «Cup und Ranglistenturnier übernehmen» kopiert die Cup-Konkurrenzen je Kategorie und das Ranglistenturnier der Vorsaison: Typ, Name, Kategorie und `format_json` ohne die Schlüssel mit «deadline» (Fristen der alten Saison). Was es schon gibt (gleicher Typ, gleiche Kategorie), bleibt.

## Spielplan (`Season\Scheduling`)

- Die TK wählt die Spielwochen der Vor- und der Rückrunde (Montage). Das System schlägt alle Wochen zwischen Saisonbeginn und Saisonende vor, ohne die Wochen der Runden des Ranglistenturniers der geplanten Saison (`rl_round`); die Wochen bis zum Jahreswechsel gehören zur Vorrunde. Die TK streicht Ferienwochen. Gespeichert in `season.schedule_json` unter `first_leg_weeks` und `second_leg_weeks`.
- **Fehlende Angaben** stehen als Liste, bevor erzeugt wird: Spielwochen, Mannschaft ohne Heimlokal, Lokal ohne Spieltag, Mannschaft ohne Anspielzeit, Gruppe mit weniger als zwei Mannschaften. Solange etwas fehlt, gibt es keinen Entwurf.
- «Entwurf erzeugen» baut aus Gruppen, Mannschaften, Hallensperren (`venue_closure`) und Tischzahlen das `ScheduleInput`, ruft `SchedulePlanner::plan()` auf und schreibt die Begegnungen als `fixture` mit Status `scheduled`, `round`, `leg`, `match_no` fortlaufend je Gruppe und dem Lokal des Heimteams. Die Teams einer Gruppe stehen nach Namen geordnet in der Eingabe. Reichen die Spielwochen nicht, erscheint die Meldung des Generators.
- Ein neuer Entwurf ersetzt alle Begegnungen der Gruppe oder aller Gruppen, auch von Hand verlegte, solange keine ein Resultat hat (Status, Resultat, Aufstellung oder Spiele). Entwürfe gibt es nur in der geplanten Saison.
- Der Bericht prüft immer die gespeicherten Begegnungen (`SchedulePlanner::check()`) und listet die Kollisionen mit Ausweichvorschlag. Die TK übernimmt einen Vorschlag mit einem Klick (er muss im aktuellen Bericht stehen), verlegt eine Begegnung von Hand (Datum in der Saison, Anspielzeit, Lokal in Gebrauch) oder tauscht das Heimrecht (gleiche Woche, Heimspieltag, Anspielzeit und Lokal der neuen Heimmannschaft; auf Wunsch auch die Begegnung der anderen Halbserie). Danach wird neu geprüft.
- Eine Gruppe gilt als vollständig, wenn jede Paarung genau einmal je Heimrecht vorkommt. Kommt eine Mannschaft dazu oder tauscht die TK nur ein Heimrecht, passt der Plan nicht mehr und verhindert die Freigabe.
- Tische pro Begegnung: `SeasonRules::schedule_tables_per_match` der geplanten Saison.
- **Aktive Saison:** einzelne Begegnung ansetzen (Entscheidungsspiel, Art. 15; beide Mannschaften aus derselben Gruppe) und eine Begegnung ohne Resultat mit Begründung absagen (`cancelled`). Verschoben wird dort über [verschiebung.md](verschiebung.md).

## Freigabe (`Season\Lifecycle::publish()`)

Voraussetzungen (`Domain\Season\LifecycleRules::publish_blockers()`), als Liste «was noch fehlt»:

| Voraussetzung | Art |
|---|---|
| Regeln der Saison gültig | muss behoben sein |
| alle Meldungen `reviewed` (auch: kein Club mit Mannschaften ohne Meldung) | muss behoben sein |
| mindestens eine Mannschaft, jede einer Gruppe zugeteilt | muss behoben sein |
| jede Gruppe mit vollständigem Spielplan, jede Begegnung mit Termin und Lokal, Plan prüfbar | muss behoben sein |
| keine harte Kollision | beheben oder ausdrücklich bestätigen |
| aktive Saison ohne offene Begegnungen und mit abgeschlossener Klassierung | beheben oder ausdrücklich bestätigen |

Zur letzten Zeile: Die Klassierung arbeitet nur auf der aktiven Saison. Wird diese mit der Freigabe archiviert, bevor die Klassierung abgeschlossen ist, lässt sie sich über die Oberfläche nicht mehr abschliessen. Die TK muss das deshalb wissen und bestätigen.

In einer Transaktion unter der Sperre der Saisons und der teilnehmenden Clubs: Voraussetzungen prüfen, jede bisher aktive Saison `archived`, geplante `active`, beides im `audit_log`. Gemeldete Spieler bleiben, wie sie sind, auch `provisional`. Danach `DataVersion::bump()`, die Aktion `ttleague_season_published` (Saison, archivierte Saison, Konto) und die Mitteilungen. Die Rechte der Captains gelten ab jetzt; die Begegnungen des Entwurfs sind gewöhnliche Begegnungen.

Die Freigabe ist nicht umkehrbar über die Oberfläche. Deshalb verlangt sie eine Bestätigung mit dem Namen der Saison.

### Mitteilung `season_published`

- Empfänger: Captains und TK-Chefs der freigegebenen Saison (`club_role`), nur aktive Personen, jede einmal. Captains erhalten den Spielplan ihrer Mannschaft, TK-Chefs den aller Mannschaften ihres Clubs, dazu die Adressen der Kalender-Abos (Mannschaft, für TK-Chefs auch Club). Bezug im Versandprotokoll: `ref_type` `season`, `ref_id` die Saison.
- Nie in der Anfrage der Freigabe: Sie merkt den Versand nur vor (einmaliges Cron-Ereignis `ttleague_season_published_step` mit Saison und Person). Ein Schritt versendet höchstens einen Stapel (`SeasonNotifications::BATCH`, 25 Empfänger) und plant den Rest ein. Der stündliche Lauf der Benachrichtigungen versendet selbst nichts; er plant sieben Tage lang nach, falls Mitteilungen ausstehen und kein Schritt wartet. Lässt sich ein Schritt nicht einplanen, steht das im Fehlerprotokoll und geht an `ttleague_notification_error`.
- Mitteilungen gibt es nur für eine Saison, die über den Assistenten freigegeben wurde (Eintrag `published` im `audit_log`), nicht für eine importierte aktive Saison.

## Während der Saison

- **Rückzug einer Mannschaft** (Art. 13.3, `Lifecycle::withdraw_team()`): `team.status = withdrawn`. Alle ihre Begegnungen werden als Forfait gegen sie gewertet, auch gespielte; die Wertung kommt aus `SeasonRules::forfeit_score` (10 für 0:10). Geschrieben wird über `ResultService::decide()`, also mit denselben Folgen wie ein Forfait von Hand (Aufstellung und Spiele entfernt, Bindungen neu bestimmt, Verlauf, Protokoll), aber ohne Mitteilung je Begegnung. Abgesagte Begegnungen und Begegnungen gegen eine ebenfalls zurückgezogene Mannschaft bleiben, wie sie sind. Mit Begründung im `audit_log`. Danach wird die Wertungszahl neu gerechnet.
- **Tabellenende:** `StandingsCalculator::calculate()` nimmt die zurückgezogenen Teams entgegen und reiht sie hinter alle anderen, unter sich nach den üblichen Kriterien. `ReadModel\Championship` liefert in jeder Tabellenzeile `withdrawn`; die Tabellen kennzeichnen die Mannschaft mit «(zurückgezogen)».
- **Einzelne Begegnung ansetzen oder absagen** (Entscheidungsspiel, Art. 15): im Schritt Spielplan.
- **Frist der Nachmeldung** der aktiven Saison: im Schritt 7.

## Abschluss (`Lifecycle::close()`)

Voraussetzungen: keine Begegnung der Meisterschaft oder des Cups mehr `scheduled`, `postponed`, `provisional` oder `disputed`; Klassierung abgeschlossen (`season.classification_closed_at`). Bestätigung mit dem Namen der Saison. Archivieren ohne folgende Saison ist erlaubt; dann gibt es bis zur nächsten Freigabe keine aktive Saison.

## Dienste und Rechte

- `Season\SeasonSetup` (anlegen, Regeln, Fenster, Clubs, löschen), `Season\Grouping`, `Season\Scheduling` (Spielwochen, Entwurf, verlegen, prüfen), `Season\Lifecycle` (Freigabe, Rückzug, Abschluss), `Season\SeasonNotifications`. Reine Regeln in `Domain\Season` (`SeasonDates`, `RulesInput`, `GroupingRules`, `ScheduleWeeks`, `LifecycleRules`).
- Alles verlangt `ttl_tk`: in der WordPress-Schicht die Capability, im Dienst `Actor::is_tk`. Die lesenden Methoden der Dienste (`state()`, `overview()`, `readiness()` …) prüfen kein Recht; sie liefern auch Daten der geplanten Saison und sind nur für die Seite der TK gedacht, die `ttl_tk` verlangt.
- Jeder Schreibvorgang läuft in einer Transaktion, sperrt zuerst alle Saisons, steht im `audit_log` (`season`, `club_season`, `competition`, `team`, `fixture`) und meldet danach `SeasonEvents`; in WordPress folgen `DataVersion::bump()` und die Aktion `ttleague_data_changed` (`season`, Saison-ID).
- Ablehnungen der Dienste sind `SeasonException` (`ttl_forbidden`, `ttl_not_found`, `ttl_conflict`, `ttl_invalid` mit Feldfehlern).

## Seite und Formulare

- Eine Seite mit einem Reiter je Schritt (`Season\SeasonPage`, Inhalt in `Season\SeasonSteps`), Platz 5 im Menü «Spielbetrieb». Jeder Reiter trägt seinen Stand (erledigt, offen, noch nicht möglich); ohne Angabe öffnet die Seite den nächsten offenen Schritt.
- Formulare ohne JavaScript an `admin-post.php` (`Season\SeasonActions`, Aktionen `ttleague_season_*`), Nonce pro Aktion und Datensatz (Saison, Gruppe oder Begegnung), Rückmeldung über `Flash`, danach Weiterleitung auf den Schritt. Formulare mit Fehlern am Feld (Saison anlegen, Regeln, Meldefenster, neue Gruppe) zeigen Eingabe und Fehler bei der nächsten Ansicht einmal an.
- Übersicht der TK: Liste «Saison …: nächste Schritte» mit den offenen Schritten der geplanten Saison (`ttleague_tk_overview_sections`).

## Tests

- Regeln (`tests/Unit/Domain/Season*Test.php`): Name und Daten der Folgesaison, Meldefenster an den Grenzen, jedes Feld des Regelformulars, Vorschlag und Hinweise der Einteilung, Spielwochen, jede Voraussetzung der Freigabe und des Abschlusses einzeln, Tabellenende.
- Dienste gegen MariaDB (`tests/Integration/Season/`): Anlegen aus der aktiven Saison (Regeln, `club_season`, TK-Chefs), zweite geplante Saison abgelehnt, Sperre der Saisons, erste Saison, Löschen; Einteilung mit Vorschlag, Hinweisen, unterster Kategorie, Cup und Ranglistenturnier; Entwurf, neuer Entwurf, nicht mehr nach dem ersten Resultat, fehlende Angaben, zu wenige Spielwochen, Kollisionen mit Vorschlag, Verlegen und Tauschen mit neuer Prüfung; Freigabe mit jeder Voraussetzung, Bestätigungen, Fehler mitten in der Transaktion, Sichtbarkeit in den Lesemodellen vorher und nachher, Erfassung und Rechte der Captains vorher und nachher; Rückzug mit Wertung, Protokoll und Tabellenplatz; Abschluss; Mitteilungen in Stapeln.
- WordPress (`tests/WordPress/Season/`): Seite und Reiter, jedes Formular mit Nonce und `ttl_tk`, Fehler am Feld, Escaping, Freigabe mit Cache-Version und Rechten, Versand über WP-Cron (ein Stapel pro Schritt, Auffangnetz, gescheitertes Einplanen), Rückzug, Abschluss, Liste der Übersicht.

## Offen

- Die Klassierungen provisorisch gemeldeter Spieler führt die Freigabe nicht nach (siehe «Offen» in [meldung.md](meldung.md)); gemeldete Spieler bleiben unverändert.
- Der Rückzug benachrichtigt die Gegner nicht; sie sehen die Wertung in Tabelle und Matchreport.
- Personen ohne erreichbaren Kanal bleiben für `season_published` «ausstehend»: Der stündliche Lauf plant für sie sieben Tage lang einen Schritt ein, der nichts versendet.
- TK-Chefs eines neuen Clubs ohne Vorsaison trägt der Assistent nicht ein; dafür gibt es noch keine Oberfläche.
- [spielplan.md](spielplan.md) nennt die Tische pro Begegnung noch einen Parameter des Konstruktors; der Assistent übergibt den Wert aus `SeasonRules`.
