# Beiträge

Danke, dass du ttleague verbessern willst. Bitte beachte diese Punkte.

## Ablauf

1. Für grössere Änderungen zuerst ein Issue eröffnen und die Idee kurz beschreiben.
2. Änderungen in einem eigenen Branch, Pull Request auf `main`. Pull Requests werden automatisch von CodeRabbit und der CI geprüft.
3. Vor dem Pull Request lokal: `composer test -- --fail-on-skipped`, `composer test:wp` (WordPress-Tests, siehe README), `composer test:js` und `composer lint` müssen grün sein, die Git-Hooks müssen aktiv sein (`git config core.hooksPath .githooks`).

## Keine Personendaten, keine KI-Verläufe

Dieses Repository ist öffentlich. Nicht hinein gehören:

- echte Personendaten: Namen, Lizenznummern, Klassierungen, Kontaktdaten realer Spielerinnen und Spieler; Tests und Beispiele verwenden erfundene Namen
- Zugangsdaten, Schlüssel, Datenbank-Exporte, `.env`-Dateien
- Verläufe, Einstellungen und Gedächtnisdateien von KI-Werkzeugen sowie Links auf KI-Sitzungen
- Inhalte, die nur zu einer bestimmten Liga gehören (Theme, Texte, Altdaten); diese gehören ins Repository der jeweiligen Instanz

## Rechte an Beiträgen

Mit dem Einreichen eines Beitrags (Code, Dokumentation, Tests, Gestaltung) bestätigst du, dass du ihn selbst erstellt hast oder zur Weitergabe berechtigt bist.

Dein Beitrag wird unter der Lizenz des Projekts veröffentlicht (GPL-2.0-or-later). Zusätzlich räumst du dem Projektinhaber, Rupert Wildner, ein nicht ausschliessliches, zeitlich unbegrenztes, weltweites und unentgeltliches Recht ein, deinen Beitrag auch unter anderen Lizenzbedingungen zu nutzen, zu bearbeiten und weiterzugeben, auch kommerziell. Dein Urheberrecht an deinem Beitrag bleibt bei dir.

Bestätige das, indem du deine Commits mit `git commit -s` signierst (Zeile «Signed-off-by»). Für umfangreiche Beiträge kann vor dem Zusammenführen eine schriftliche Beitragsvereinbarung verlangt werden.
