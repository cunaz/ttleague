# ttleague

WordPress-Plugin für den Spielbetrieb einer Tischtennis-Liga im Firmen- und Freizeitsport: Mannschaftsmeisterschaft, Mannschaftscup, Ranglistenturnier, Spieler mit Klassierung und Wertungszahl, Resultaterfassung durch die Clubs. Entstanden für den SFFS Firmensport Tischtennis Region Zürich, gebaut für jede Liga mit ähnlichem Reglement.

Status: in Entwicklung, noch nicht einsatzbereit.

## Aufbau

| Pfad | Inhalt |
|---|---|
| `ttleague.php` | Plugin-Hauptdatei, Autoloader, Aktivierung |
| `src/Domain/` | Reglement als Code, ohne WordPress-Abhängigkeit: Saison-Parameter, Spielfolge, Resultatlogik, Tabellenrechner, Spielberechtigung, Ranglistenturnier, Wertungszahl, Spielplan-Generator |
| `src/Infrastructure/` | Datenbankzugriff (`Db` mit wpdb- und PDO-Implementierung), Schema (`wp_ttl_*`), Installer, Cache-Version |
| `src/Frontend/`, `src/ReadModel/`, `blocks/`, `assets/` | Öffentliche Darstellung: Routing, Seiten, Blöcke, Shortcodes |
| `src/Cli/` | WP-CLI-Befehle (`wp ttleague …`) |
| `tests/` | PHPUnit: Unit-Tests ohne WordPress, Integrationstests gegen MariaDB/MySQL |
| `docs/` | Datenmodell und Konventionen, Entwicklung |

## Plugin und Instanz

Das Plugin enthält nur generischen Code und keine Personendaten. Alles, was zu einer konkreten Liga gehört, lebt in einem eigenen, privaten Instanz-Repository: Theme und Inhalte, Seiten-Setup, Deployment sowie die Übernahme von Altdaten aus früheren Systemen (als Begleit-Plugin, das die Tabellen von ttleague befüllt). Die Datenkonventionen dafür stehen in [docs/datenmodell.md](docs/datenmodell.md).

## Entwicklung

Voraussetzungen: PHP 8.2 oder neuer, Composer, für Integrationstests MariaDB oder MySQL.

```bash
composer install
composer test
composer lint
```

Integrationstests verbinden sich standardmässig mit `mysql:host=127.0.0.1;dbname=ttleague_test` (Benutzer und Passwort `ttleague`) und werden übersprungen, wenn keine Datenbank erreichbar ist. Andere Verbindungen über `TTLEAGUE_TEST_DB_DSN`, `TTLEAGUE_TEST_DB_USER`, `TTLEAGUE_TEST_DB_PASS`.

WordPress lokal mit dem Plugin starten, wenn Docker verfügbar ist:

```bash
npx @wordpress/env start
```

## Fachliche Grundlagen

- Wettspielreglement der Liga mit Mannschaftsmeisterschaft (Dreierteams, zehn Spiele), Mannschaftscup (Zweierteams, fünf Spiele) und Ranglistenturnier
- Klassierungsskala nach Swiss Table Tennis: D1 bis D5, C6 bis C10, B11 bis B15, A16 bis A22

Alle Regelparameter sind pro Saison konfigurierbar (`SeasonRules`).

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
