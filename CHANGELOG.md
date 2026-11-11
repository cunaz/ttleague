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
- Ranglistenturnier: Anmeldung mit Sperrdaten in «Mein Bereich», Gruppenvorschlag je Runde, Aufgebot, Gruppenzettel (Formular und REST), Abschluss mit Rang und Punkten, Final, Backend-Seite und CSV-Exporte. Runden in Planung sind nicht öffentlich.
