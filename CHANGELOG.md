# Changelog

Alle nennenswerten Änderungen an ttleague werden hier festgehalten.

## [Unreleased]

### Hinzugefügt
- Plugin-Gerüst mit Composer, PHPUnit, PHPCS und GitHub-Actions-CI.
- Domänenschicht: Saison-Parameter, Spielfolge, Resultatlogik, Tabellenrechner mit Tiebreaks, Spielberechtigung, Ranglistenturnier-Wertung, Finalqualifikation, Wertungszahl, Spielplan-Generator.
- Datenbankschema für alle Spielbetriebstabellen und Installer.
- Datenbank-Abstraktion `Db` mit Implementierungen für wpdb und PDO, Integrationstests gegen MariaDB.
- Datenversion für die Cache-Invalidierung.
- WP-CLI-Befehl `wp ttleague info`.
- Grundlage für den Saisonbetrieb: geplante Saison (nicht öffentlich), Schemaversion 2026111007, Rechte für Verschiebung und Gruppenzettel, Erweiterungspunkte für Module, CSV-Exporte (`wp ttleague export`).
- Online-Meldung der Clubs: Spieler, Neumeldungen, Mannschaften mit Stammspielern, Captains, Lokale und Hallensperren in «Mein Bereich», Prüfung durch die TK unter «Spielbetrieb → Meldungen», Benachrichtigungen `registration_reminder` und `registration_decided`, CSV-Listen `registrations` und `registration-players`.
- Verschiebung und Terminvorschlag: Antrag, Zustimmung der Gegenseite, Bewilligung der TK bei mehr als 14 Tagen oder nach der Frist der Cup-Runde; REST-API, Formulare auf der Erfassungsseite und in «Mein Bereich», Liste in der Übersicht der TK, Benachrichtigungen mit Erinnerung, CSV-Export.
- Klassierung zum Saisonende: Vorschlag aus der Wertungszahl, Entscheid der TK im Backend («Spielbetrieb → Klassierung»), Abschluss mit Sperre, CSV «Klassierungssitzung» (`wp ttleague export classification`).
- Anlässe mit Anmeldung: Backend «Spielbetrieb → Anlässe», Block `ttleague/event-registration` mit An- und Abmeldung, Höchstzahl, Warteliste und Nachrücken, Benachrichtigung `event_registration`, CSV der Teilnehmerliste.
- Ranglistenturnier: Anmeldung mit Sperrdaten in «Mein Bereich», Gruppenvorschlag je Runde, Aufgebot, Gruppenzettel (Formular und REST), Abschluss mit Rang und Punkten, Final, Backend-Seite und CSV-Exporte. Runden in Planung sind nicht öffentlich.
- Saison-Assistent («Spielbetrieb → Saison»): Saison anlegen, Regeln, Meldefenster, Gruppen aus der Schlussrangliste, Spielplan-Entwurf mit Prüfung, Vorschlägen und Verlegen, Freigabe, Rückzug einer Mannschaft, Abschluss; Benachrichtigung `season_published`.
- Spielplan-Generator: Mit mehr Spielwochen als Runden verteilt der Entwurf die Runden über alle Spielwochen und versetzt die Gruppen gegeneinander; so spielen seltener mehrere Mannschaften eines Clubs am selben Abend.
- Mannschaftscup: Meldung durch den TK-Chef mit Punktelimite und Meldeschluss, Auslosung mit Setzung und Freilosen, Tableau im Backend («Spielbetrieb → Cup») und öffentlich, Rundenfristen, Aufstellungsregel bei der Erfassung (Art. 20.1 und 20.2), Benachrichtigungen `cup_round` und `cup_deadline`, CSV `cup-meldungen`.
