# Changelog

Alle nennenswerten Änderungen an ttleague werden hier festgehalten.

## [Unreleased]

### Korrigiert
- Fotos der Spielberichte sind auf fünf gespeicherte Anhänge pro Begegnung begrenzt, einschliesslich Historie und Papierkorb; eine Datenbanksperre verhindert parallele Überschreitungen. Bestehende Belege werden nicht automatisch gelöscht.
- Die Rate-Limits für Anmeldelinks und Club-Einladungen zählen atomar in der Datenbank statt über Transients; gleichzeitige Anfragen können die Grenzen nicht mehr überschreiten. Schemaversion 2026111201 ergänzt die Zählertabelle und deren tägliche Bereinigung.
- Die Sitzungsdauer von 60 Tagen gilt nur für den Magic-Link-Login gewöhnlicher Spielerkonten, nicht für normale WordPress-Anmeldungen oder privilegierte Konten.
- Datenbanktransaktionen rollen auch dann vollständig zurück, wenn eine innere Ausnahme abgefangen wurde. Ein fehlgeschlagener Commit beschädigt den Zustand für die nächste Transaktion nicht; wpdb bewahrt die ursprüngliche Fehlermeldung.
- An- und Abmeldungen prüfen die Frist erneut mit der Zeit nach dem Erlangen der Datenbanksperre. Der Anmeldezeitpunkt stammt ebenfalls aus diesem Moment.
- Hinweise auf verspätete oder frühe Resultaterfassung rechnen auch über die Zeitumstellung mit tatsächlich verstrichenen Stunden.
- Die Resultaterfassung weist übergrosse Spieler-IDs und Zahlen mit nachgestelltem Zeilenumbruch zurück.
- Resultatentwürfe werden beim Ausblenden und Verlassen sofort gesichert. Ungespeicherte Eingaben und laufende Uploads lösen beim Verlassen eine Browserwarnung aus. Beschädigte Entwürfe blockieren das Formular nicht; fehlerhafte Erfolgsantworten löschen keine Eingaben.
- Die Web-App bleibt bei gesperrtem oder vollem Cache nutzbar, beachtet Antwortvarianten und entfernt alte Offline-Kopien nach privaten Antworten oder entfernten Seiten. Push-Nachrichten ohne Ziel öffnen auch bei Installationen im Unterverzeichnis die Liga.
- Links, Schaltflächen, Hinweistexte und Fokusmarkierungen nutzen die Grund- und Kontrastfarbe des Themes. Helle Dekorationsfarben wie das Gelb von Twenty Twenty-Five beeinträchtigen die Lesbarkeit nicht mehr; auch die Offline-Seite verwendet das Kontrastpaar.
- Die CI führt ausführbare JavaScript-Tests aus und meldet übersprungene PHP-Tests als Fehler. Test- und Lint-Befehle brechen nicht mehr am pauschalen Composer-Zeitlimit ab.

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
