# ttleague

WordPress-Plugin für den Spielbetrieb einer Tischtennis-Liga im Firmen- und Freizeitsport: Mannschaftsmeisterschaft, Mannschaftscup, Ranglistenturnier, Spieler mit Klassierung und Wertungszahl, Resultaterfassung durch die Clubs. Entstanden für den SFFS Firmensport Tischtennis Region Zürich, gebaut für jede Liga mit ähnlichem Reglement.

Version 0.2.0 lässt sich als WordPress-Plugin-ZIP installieren. Das Paket funktioniert auf dem Server ohne Composer und Node.js.

## Installation und Betrieb

1. `bin/package.sh` erstellt aus dem aktuellen Worktree das ZIP `dist/ttleague.zip`. Zum Bauen braucht es Bash und Git mit Unterstützung für `git archive --mtime`. Für ein Release aus einer festgehaltenen Revision `bin/package.sh <commit>` verwenden. Das Paket enthält die Laufzeitdateien und die Lizenz. Auf dem WordPress-Server genügen PHP ab 8.2 mit DOM und mbstring, WordPress ab 6.7 und MariaDB oder MySQL mit InnoDB. Für Web-Push braucht PHP zusätzlich OpenSSL mit P-256.
2. Das ZIP unter «Plugins → Installieren → Plugin hochladen» einspielen und ttleague aktivieren. Für die Liga-Adressen unter «Einstellungen → Permalinks» eine Struktur wie «Beitragsname» wählen. Der Webserver muss Seitenpfade sowie `/sw.js` und `/manifest.webmanifest` an WordPress weiterleiten. Unter «Einstellungen → Allgemein» die Ortszeitzone der Liga setzen.
3. Unter «Spielbetrieb → Saison» die erste Saison anlegen, Club aufnehmen, TK-Chef erfassen oder zuweisen und einladen. Sobald das Meldefenster offen ist, kann der Club seine erste Mannschaft in «Mein Bereich» melden. Eine bestehende Liga übernimmt ihre Altdaten über ihr Instanz-Repository.
4. Für zeitgesteuerte Fristen WP-Cron regelmässig ausführen lassen. Anmeldelink und Benachrichtigungen mit einem Testkonto auf der Instanz prüfen. HTTPS und die geschützte Ablage der Spielberichtsfotos prüfen. Der Test «Fotos der Spielberichte» steht unter «Werkzeuge → Website-Zustand». Die Details zu Mail, Push und Cron stehen in [Erfassung und Betrieb](docs/erfassung.md).

**Update und Wiederherstellung.** Vor einem Update die Datenbank, WordPress-Konfiguration und privaten Fotoverzeichnisse gemeinsam sichern. Auf einer Kopie das neue ZIP einspielen, eine WordPress-Seite aufrufen und mit `wp ttleague info` die gespeicherte Schemaversion mit dem Code vergleichen. Bei einem unvollständigen Schema pausiert der Spielbetrieb. Die Verwaltung erhält einen Hinweis und WordPress versucht das Upgrade nach einer Minute erneut. Auf der Kopie auch Anmeldung, Spielplan und eine Resultaterfassung prüfen. Für die Rückkehr zum vorherigen Stand das vorherige Plugin-Paket und die dazu passende Sicherung wiederherstellen. Eingaben seit der Sicherung dabei berücksichtigen.

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

Voraussetzungen: PHP 8.2 oder neuer, Composer, für Integrationstests MariaDB oder MySQL. Die JavaScript-Tests und die Vergleichstests zwischen PHP und JavaScript verwenden Node.js 24; das Plugin selbst braucht Node nicht.

Nach dem Klonen die Git-Hooks aktivieren. Sie verhindern, dass KI-Verläufe, Zugangsdaten, lokale Pfade oder Links auf KI-Sitzungen versioniert werden; dieselbe Prüfung läuft in der CI:

```bash
git config core.hooksPath .githooks
```

```bash
composer install
composer test
composer test:js
composer lint
```

Integrationstests verbinden sich standardmässig mit `mysql:host=127.0.0.1;dbname=ttleague_test` (Benutzer und Passwort `ttleague`) und werden übersprungen, wenn keine Datenbank erreichbar ist. Andere Verbindungen über `TTLEAGUE_TEST_DB_DSN`, `TTLEAGUE_TEST_DB_USER`, `TTLEAGUE_TEST_DB_PASS`.

`composer test -- --fail-on-skipped` verlangt wie die CI einen vollständigen Lauf mit Datenbank und Node. `composer test:js` prüft die Browserlogik für Resultatentwürfe und Service Worker mit dem Test-Runner von Node, ohne zusätzliche npm-Pakete. Diese Tests ersetzen keinen Test im Browser. Test- und Lint-Befehle haben kein Composer-Prozesszeitlimit; umfangreiche Datenbanktests dürfen auch länger als fünf Minuten laufen.

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
