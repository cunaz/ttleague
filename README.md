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
| `tests/` | PHPUnit: Unit-Tests ohne WordPress, Integrationstests gegen MariaDB/MySQL, WordPress-Tests mit wp-phpunit |
| `docs/` | Datenmodell und Konventionen, Entwicklung |

## Theme

Die Darstellung besteht aus Blöcken und funktioniert mit jedem Block-Theme; Farben, Schriften und Abstände kommen aus dem aktiven Theme (getestet mit Twenty Twenty-Five). Ein eigenes Child-Theme gehört zur jeweiligen Instanz.

## Plugin und Instanz

Das Plugin enthält nur generischen Code und keine Personendaten. Alles, was zu einer konkreten Liga gehört, lebt in einem eigenen, privaten Instanz-Repository: Theme und Inhalte, Seiten-Setup, Deployment sowie die Übernahme von Altdaten aus früheren Systemen (als Begleit-Plugin, das die Tabellen von ttleague befüllt). Die Datenkonventionen dafür stehen in [docs/datenmodell.md](docs/datenmodell.md).

## Entwicklung

Voraussetzungen: PHP 8.2 oder neuer, Composer, für Integrationstests MariaDB oder MySQL.

Nach dem Klonen die Git-Hooks aktivieren. Sie verhindern, dass KI-Verläufe, Zugangsdaten, lokale Pfade oder Links auf KI-Sitzungen versioniert werden; dieselbe Prüfung läuft in der CI:

```bash
git config core.hooksPath .githooks
```

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

### WordPress-Tests

Was echtes WordPress braucht (Rollen und Konten, Anmeldung per Link, Rechte über `map_meta_cap`, REST-API, Blöcke), prüfen die Tests in `tests/WordPress/` mit der Testbibliothek von WordPress ([wp-phpunit](https://github.com/wp-phpunit/wp-phpunit)) gegen ein WordPress aus Composer (`vendor/roots/wordpress-full`). Der Bootstrap lädt das Plugin vor `plugins_loaded` und legt Schema und Rolle wie bei der Aktivierung an; jeder Test läuft in einer Transaktion, die am Ende zurückgerollt wird.

Die Testsuite löscht und installiert ihre Tabellen (Präfix `wptests_`) bei jedem Lauf, deshalb braucht sie eine eigene, leere Datenbank:

```bash
mariadb -uroot -e "CREATE DATABASE ttleague_wptest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ttleague_wptest.* TO 'ttleague'@'localhost';"
composer test:wp
```

Verbindung über Umgebungsvariablen: `TTLEAGUE_WP_TEST_DB_NAME` (Standard `ttleague_wptest`), `TTLEAGUE_WP_TEST_DB_HOST` (`127.0.0.1`, auch mit Port wie `127.0.0.1:3306`), `TTLEAGUE_WP_TEST_DB_USER` und `TTLEAGUE_WP_TEST_DB_PASS` (beide `ttleague`). Ist die Datenbank nicht erreichbar, bricht der Lauf mit der Meldung von WordPress ab. Die CI führt `composer test` und `composer test:wp` für jede PHP-Version aus.

## Fachliche Grundlagen

- Wettspielreglement der Liga mit Mannschaftsmeisterschaft (Dreierteams, zehn Spiele), Mannschaftscup (Zweierteams, fünf Spiele) und Ranglistenturnier
- Klassierungsskala nach Swiss Table Tennis: D1 bis D5, C6 bis C10, B11 bis B15, A16 bis A22

Alle Regelparameter sind pro Saison konfigurierbar (`SeasonRules`).

## Lizenz

Copyright (C) 2026 Rupert Wildner.

ttleague ist freie Software unter der GNU General Public License, Version 2 oder (nach deiner Wahl) jeder späteren Version, siehe [LICENSE](LICENSE). Das entspricht der Lizenz von WordPress selbst.

Beiträge sind willkommen; die Bedingungen dafür stehen in [CONTRIBUTING.md](CONTRIBUTING.md).
