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
