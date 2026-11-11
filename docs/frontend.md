# Öffentliche Darstellung (Frontend)

Dieses Dokument beschreibt die lesende Darstellung des Plugins: Seiten und Adressen, Blöcke und Shortcode, die Lesemodelle, Caching, Gestaltung und Tests. Alle Beispiele verwenden erfundene Daten (Lizenznummern ab 90001, «TTC Seefeld»).

## Überblick

| Schicht | Ort | Aufgabe |
|---|---|---|
| Lesemodelle | `src/ReadModel/` | Abfragen über `Db`, Tabellenberechnung mit `StandingsCalculator`, RL-Ranglisten mit `GroupRanking` und `FinalQualification`, Statistik. Rückgabe als einfache Arrays. |
| Routing | `src/Frontend/Routes.php`, `Resolver.php`, `Router.php`, `PageInstaller.php` | Rewrite-Regeln, Auflösung der Adresse in einen Kontext (Objekt, Titel, 404, Weiterleitung), Seiten-Installer. |
| Ansichten | `src/Frontend/Views/` | Serverseitiges HTML pro Ansicht, escaped. |
| Blöcke, Shortcode | `src/Frontend/Blocks.php`, `Shortcode.php`, `blocks/*/block.json`, `assets/js/editor.js` | Dynamische Blöcke mit `render_callback`, Shortcode `[ttleague view="…"]`, Editor ohne Build-Schritt. |
| Gestaltung | `assets/css/frontend.css` | Mobile first, Tabellen werden unter 600px zu Karten, Farben und Abstände aus den Presets des aktiven Block-Themes. |
| Kalender-Abos | `src/Calendar/` | iCal-Feeds (RFC 5545) für Mannschaft, Club und Spieler, siehe [Kalender-Abos](#kalender-abos-ical). |
| Web-App | `src/Pwa/`, `assets/pwa/` | Manifest, Service Worker mit Offline-Rückfall, siehe [Web-App](#web-app-pwa). |

Einstieg ist `TTLeague\Frontend\Frontend::register()`, aufgerufen von `TTLeague\Plugin::boot()`.

## Seiten und Adressen

Jede Ansicht ist eine echte WordPress-Seite mit dem passenden Block als Inhalt. So rendert das Block-Theme Kopf- und Fusszeile, und die TK kann über oder unter dem Block Text ergänzen. Detailansichten (Gruppe, Club, Spielerprofil) sind Unterseiten; Begegnung und Mannschaft sind eigene Seiten.

| Adresse | Seite (Schlüssel) | Block |
|---|---|---|
| `/meisterschaft/`, `/meisterschaft/2026-27/` | Meisterschaft (`championship`) | `ttleague/season-overview` |
| `/meisterschaft/2026-27/gruppe-a/`, `…/gruppe-a-2/` (group_no > 1) | Meisterschaft › Gruppe (`group`) | `ttleague/group` |
| `/begegnung/123/` (Meisterschaft und Cup) | Begegnung (`fixture`) | `ttleague/match-report` |
| `/mannschaft/2026-27/seefeld-1/` | Mannschaft (`team`) | `ttleague/team` |
| `/clubs/`, `/clubs/?saison=2025-26` | Clubs (`clubs`) | `ttleague/clubs` |
| `/clubs/seefeld/`, `/clubs/seefeld/?saison=2025-26` | Clubs › Club (`club`) | `ttleague/club` |
| `/spieler/`, `/spieler/?club=seefeld&q=muster` | Spieler (`players`) | `ttleague/players` |
| `/spieler/90001-hans-muster/`, `…/?saison=2025-26` | Spieler › Spielerprofil (`player`) | `ttleague/player` |
| `/ranglistenturnier/`, `/ranglistenturnier/2026-27/` | Ranglistenturnier (`ranking`) | `ttleague/ranking-tournament` |
| `/cup/`, `/cup/2026-27/`, `/cup/2026-27/a/` | Mannschaftscup (`cup`) | `ttleague/cup` |
| `/dokumente/` | Dokumente (`documents`) | `ttleague/documents` (Inhaltsmodul) |
| `/archiv/` | Archiv (`archive`) | `ttleague/archive` |

Regeln:

- Ohne Saison gilt die aktive Saison (`season.status = active`, sonst die neueste nicht geplante).
- Unbekannte Saison, Gruppe, Begegnung, Mannschaft, Club, Lizenznummer oder Cup-Kategorie: HTTP 404, die Seite zeigt eine freundliche Meldung mit Wegweisern, `noindex`.
- Spielerprofil mit falschem oder fehlendem Namensteil: 301 auf `/spieler/<Lizenz>-<vorname-nachname>/` (ein Saisonfilter bleibt erhalten).
- Detailseite ohne Parameter (z. B. `/meisterschaft/gruppe/`, `/begegnung/`): 302 auf die Übersicht.
- Dokumenttitel und Seitenüberschrift zeigen das konkrete Objekt: Überschrift «Hans Muster», Titel «Hans Muster – Spieler – Website». Die kanonische Adresse ist die des Objekts.
- Slugs: Kleinbuchstaben, Umlaute als ae/oe/ue, ß als ss (`ReadModel\Support\Slug`). Teamslug aus dem Teamnamen, Spielerslug aus «Vorname Nachname».
- Die Rewrite-Regeln zeigen per `page_id` auf die Seiten und folgen deren aktuellen Pfaden: Benennt die TK eine Seite um, werden die Regeln beim nächsten Aufruf neu aufgebaut.
- Abfrageparameter (`saison`, `club`, `q`) werden bewusst nicht als WordPress-Query-Variablen registriert, damit z. B. `/?saison=…` die Startseite nicht verändert.

### Seiten installieren

Die Seiten werden beim ersten Aufruf nach der Aktivierung (bzw. nach einem Update mit neuer Seitenversion) automatisch angelegt, danach die Rewrite-Regeln erneuert. Manuell und idempotent:

```bash
wp ttleague pages              # Tabelle mit Seite, ID, Adresse, Aktion (angelegt, übernommen, vorhanden; «(Block fehlt)», wenn der Block fehlt)
wp ttleague pages --format=json
```

Vorhandene Seiten (auch umbenannte oder bearbeitete) bleiben unverändert; fehlt eine, wird sie über ihren Pfad wiedergefunden oder neu angelegt. Eine wiedergefundene Seite wird auch ohne ihren Block übernommen und nicht verändert. Die IDs stehen in der Option `ttleague_pages`. Bei der Deaktivierung werden die Rewrite-Regeln verworfen.

«Werkzeuge → Website-Zustand» meldet jede Seite, die fehlt, nicht veröffentlicht ist oder ihren Block nicht enthält, mit Link zum Bearbeiten (Test `ttleague_pages`). Der Test prüft bei jedem Aufruf den aktuellen Stand, also auch nach einem automatischen Update oder einer Bearbeitung durch die TK. Eine fehlende Seite legt `wp ttleague pages` oder das erneute Aktivieren des Plugins neu an.

## Blöcke und Shortcode

Alle Blöcke sind dynamisch (`blocks/<name>/block.json` mit `render_callback` in `Blocks::render_block()`), Kategorie «Spielbetrieb (ttleague)». Ohne Attribut lesen sie ihren Parameter aus der Adresse, sonst gilt die aktive Saison.

| Block | Attribute |
|---|---|
| `ttleague/season-overview` | `season` |
| `ttleague/group` | `season`, `group` (`a`, `a-2`), `show` (`all`, `table`, `schedule`) |
| `ttleague/match-report` | `fixture` (ID) |
| `ttleague/team` | `season`, `team` (Slug) |
| `ttleague/clubs` | `season` |
| `ttleague/club` | `season`, `club` (Slug) |
| `ttleague/players` | `season`, `club` |
| `ttleague/player` | `player` (Lizenznummer) |
| `ttleague/ranking-tournament` | `season`, `category` (1–3, 0 = alle) |
| `ttleague/cup` | `season`, `category` (`a`, `b`) |
| `ttleague/latest-results` | `season`, `count` |
| `ttleague/upcoming-fixtures` | `season`, `count` |
| `ttleague/standings-compact` | `season`, `group` |
| `ttleague/season-switcher` | `label` |
| `ttleague/archive` | `compact` |
| `ttleague/event-registration` | `event` (ID des Anlasses; Anmeldung, siehe [anlaesse.md](anlaesse.md)) |

Shortcode: `[ttleague view="<Blockname ohne ttleague/>" …Attribute…]`, z. B. `[ttleague view="group" season="2026-27" group="a"]`, `[ttleague view="player" player="90001"]`, `[ttleague view="latest-results" count="5"]`. Deutsche Kurzformen: `meisterschaft`, `gruppe`, `begegnung`, `mannschaft`, `spieler`, `spielerprofil`, `ranglistenturnier`, `resultate`, `termine`, `tabellen`, `saisonwahl`, `archiv`, `anlass`.

Editor: `assets/js/editor.js` registriert alle Blöcke (auch `ttleague/documents` aus dem Inhaltsmodul) mit `ServerSideRender` und einfachen Einstellungen (Saison, Gruppe, Kategorie, Anzahl …). Saisons und Clubs für die Auswahllisten kommen als `window.ttleagueEditor`.

**Saisonwahl** (`ttleague/season-switcher`, in der Kopfzeile des Themes): ein `<details>`-Menü ohne JavaScript. Es wechselt dieselbe Ansicht in eine andere Saison: Gruppe → gleiche Gruppe, Mannschaft → gleiche Mannschaft (sonst Saisonübersicht), Club, Spielerliste, Spielerprofil (Saisonfilter), Ranglistenturnier, Cup; auf anderen Seiten zur Meisterschaft der Saison.

## Lesemodelle

`TTLeague\ReadModel\ReadModel` bündelt alle Abfragen (`ReadModel::instance()` in WordPress, in Tests `new ReadModel( $pdo_db, new NullCache() )`).

| Klasse | Liefert |
|---|---|
| `Seasons` | Saisons, aktive Saison, `SeasonRules` aus `rules_json` |
| `Championship` | pro Saison: Gruppen mit berechneter Tabelle, Teams, Begegnungen mit Satzsummen; `standings()` als reine Funktion |
| `MatchReports` | Kopf, Lokal, Aufstellungen mit Klassierung und Ersatz, Spiele in Spielfolge (Slots aus `GameOrder`), Summen |
| `Teams` | Stammspieler und gebundene Spieler, Captains, Heimlokal, Einsatzmatrix (`Teams::matrix()` als reine Funktion) |
| `Clubs` | Clubliste einer Saison, Club-Detail mit Teams, Lokalen (Anspielzeiten der Heimteams), Funktionen (Name und `person_id`, keine Kontaktdaten), Spielern |
| `Players` | Spielerliste einer Saison mit Wertungszahl, Suche (Name mit und ohne Umlaut-Ausschreibung, Lizenznummer), Lookup per Lizenz |
| `PlayerProfile` | Klassierung pro Saison, Wertungszahl, Verlauf, alle Spiele aus Spielersicht; `balance()` und `classification_analysis()` als reine Funktionen |
| `RankingTournament` | Runden → Kategorien → Gruppen mit Rangliste, Partien, Aufgebot; Gesamtrangliste mit `FinalQualification` |
| `Cup` | Kategorien mit Meldeliste (Setzung, Spieler mit Klassierung, Punktesumme) und Begegnungen |
| `Fixtures` | letzte Resultate und nächste Begegnungen über Meisterschaft und Cup |

Fachliche Festlegungen:

- **Tabelle** immer aus den Begegnungen mit `StandingsCalculator`; gezählt werden `provisional`, `confirmed`, `disputed`, `final`, `forfeit` mit erfasstem Resultat. Sätze nur aus Spielen mit `status = played`; Ballpunkte nur, wenn für alle gespielten Spiele einer Begegnung Sätze erfasst sind. Geteilte Ränge erhalten ein Sternchen und den Hinweis des Tabellenrechners (erst, wenn in der Gruppe gespielt wurde). Provisorische Resultate sind eingerechnet und im Spielplan markiert.
- **Bilanz und Klassierungsauswertung** zählen nur Spiele mit `status = played`; kampflose (w.o.) und nicht gespielte erscheinen in der Spielliste mit Kennzeichnung. Die Gegner-Klassierung ist die Klassierung des Gegners in der Saison des Spiels.
- **Einsatzmatrix**: Spalten sind die Begegnungen des Teams mit Aufstellung (Forfaits ohne Aufstellung fehlen), Zeilen zuerst die Stammspieler, dann weitere eingesetzte Spieler; «S» bei `is_substitute = 0`, «E» bei `is_substitute = 1`, «–» ohne Einsatz.
- **Ranglistenturnier**: Rang und Punkte aus `rl_group_player` (offiziell, Punkte als Dezimalzahl, z. B. 5,5 bei geteilten Punkten); fehlen sie, rechnet `GroupRanking`. Siege und Sätze rechnet immer `GroupRanking` aus den Partien. Gesamtrangliste: Summe der gespeicherten Punkte der Runden 1–3 pro Kategorie (float), geordnet mit `FinalQualification`; Finalisten und nötige Lose werden markiert. Gruppen ohne Partien zeigen das Aufgebot.
- **Wertungszahl**: aktueller Wert aus `player_rating`, Verlauf aus `rating_event`. Die Grafik zeigt pro Saison eine eigene Linie (Neuverankerung bei Klassierung × 100 zu Saisonbeginn) mit senkrechter Saisongrenze, ohne Daten eine freundliche Meldung. Der Klassierungsvorschlag ist `ClassificationScale::from_rating( rating, 100 )`.

## Caching

`WpCache` speichert Ergebnisse im Object-Cache (Gruppe `ttleague`) und, ohne persistenten Object-Cache, zusätzlich als Transient (24 Stunden). Der Schlüssel enthält `DataVersion::key()`; jeder Schreibvorgang, der `DataVersion::bump()` aufruft, macht alle abgeleiteten Daten ungültig. Gecacht werden u. a. Meisterschaft pro Saison, Matchreports, Teamdetails, Clubdetails, Spielerlisten, Spielerprofile, RL und Cup pro Saison. Ungecacht kostet das Laden aller Daten einer Saison (Meisterschaft, RL, Cup, Clubs, Spieler) rund 0,6 s; gecacht liefert WordPress die Seiten in unter 0,1 s.

Persönliches steht nie im Cache. Kontaktdaten der Captains und TK-Chefs lädt `TTLeague\Accounts\Contacts` bei jeder Anfrage direkt aus der Datenbank, und nur für angemeldete Personen mit `ttl_view_contacts` (`ContactBox`). Für Gäste bleibt die Ausgabe von Clubseite, Mannschaftsseite und Matchreport unverändert. Details in `docs/erfassung.md`, Abschnitt «Kontaktdaten».

## Gestaltung

- `assets/css/frontend.css`: mobile first, nur Theme-Presets (`--wp--preset--color--base|contrast|accent-1…6`, Abstände, Schriftgrössen) mit Fallbacks, keine externen Ressourcen. Tabellen mit `ttl-table--cards` werden unter 600px zu Karten (`data-label`); Tabelle, Spielplan und Spiellisten haben eigene Kartenlayouts (Rang, Team und Punkte bzw. Datum und Resultat oben). Die Einsatzmatrix scrollt horizontal mit fixierter Namensspalte. Touch-Ziele mindestens 44px, sichtbarer Fokus, `forced-colors` und Druck berücksichtigt.
- Verlaufsgrafik: gestrecktes Inline-SVG mit nicht skalierenden Linien, Beschriftungen als HTML (auf dem Handy lesbar), `role="img"` mit Titel und Beschreibung, Hover-Bänder mit nativer Beschriftung pro Spieltag, Tabelle als Alternative. Das SVG läuft durch `wp_kses`.
- Theme: Die Blöcke funktionieren mit jedem Block-Theme und übernehmen dessen Farben, Schriften und Abstände (getestet mit Twenty Twenty-Five). Ein eigenes Child-Theme mit Navigation, Startseite und Kontaktangaben gehört zur jeweiligen Instanz und wird in deren Repository gepflegt. Navigationslinks auf die Seiten des Plugins sind relative Pfade (`/meisterschaft/` …).

## Teilen-Knopf

Der Matchreport zeigt einen Knopf «Teilen», sobald ein Resultat vorliegt (auch bei einem Forfait). Derselbe Knopf steht auf der Erfassungsseite in der Leseansicht «Erfasstes Resultat».

- Geteilt werden ein Titel wie «Seefeld 1 – Wollishofen 1 6:4» und die Adresse des Matchreports, auch von der Erfassungsseite aus.
- Mit der Web Share API (`navigator.share`) öffnet der Knopf den Teilen-Dialog des Geräts. Ohne sie heisst er «Link kopieren» und legt die Adresse in die Zwischenablage. Scheitert das Teilen, kopiert er ebenfalls; ein Abbruch durch die Person gilt nicht als Fehler.
- Rückmeldung («Link kopiert.» oder der Hinweis auf die Adresszeile) über eine Live-Region (`role="status"`).
- Ohne JavaScript, und ohne Web Share API und Zwischenablage, bleibt der Knopf verborgen (`hidden`).
- Der Knopf ist für alle Besucher gleich; die Ausgabe für Gäste bleibt zwischenspeicherbar.
- `TTLeague\Frontend\Views\ShareButton` baut die Auszeichnung, `assets/js/share.js` (ohne Abhängigkeiten, verzögert im Fuss der Seite) wird nur eingereiht, wo der Knopf erscheint. Touch-Ziel mindestens 44px, sichtbarer Fokus, im Druck ausgeblendet.

## Kalender-Abos (iCal)

Spielpläne lassen sich als Kalender abonnieren (Handy, Outlook, Google Kalender). Die Feeds sind öffentlich wie die Seiten und enthalten die ganze Saison inklusive vergangener Spiele.

| Adresse | Inhalt |
|---|---|
| `/kalender/team/2026-27/seefeld-1.ics` | Begegnungen der Mannschaft in der Meisterschaft dieser Saison (jede Saison, auch archivierte) |
| `/kalender/club/seefeld.ics` | Begegnungen aller Teams des Clubs in der aktiven Saison, Meisterschaft und Cup (über `cup_entry.club_id`) |
| `/kalender/spieler/90001.ics` | Aktive Saison: Begegnungen aller Teams, in denen die Person Stammspieler, gebunden oder eingesetzt ist (Meisterschaft über `team_roster` und `lineup`, Cup über `cup_entry` und `lineup`), dazu ihre Ranglistenturnier-Gruppen mit `start_at` |

Unbekannte Saison, Mannschaft, Club oder Lizenz und jede andere `.ics`-Adresse unter `/kalender/` antworten mit 404 (Text). Grossbuchstaben im Slug werden toleriert. Ein gemeldeter Spieler ohne Team erhält einen leeren, gültigen Kalender.

**Ereignisse**

- `UID` stabil: `fixture-{id}@{host}`, Ranglistenturnier `rlgroup-{id}@{host}` (Host der Website).
- `DTSTART`/`DTEND` mit `TZID=Europe/Zurich` (Ortszeit aus der Datenbank), der Kalender enthält den `VTIMEZONE`-Block mit den Regeln für Sommer- und Winterzeit. Dauer: Meisterschaft 2½ h, Cup 2 h, Ranglistenturnier 3 h. Eine Anspielzeit 00:00 gilt als unbekannt: Ganztagesereignis (`VALUE=DATE`, frei statt besetzt) mit dem Hinweis «Anspielzeit noch offen». Begegnungen ohne Datum fehlen.
- `SUMMARY` «Seefeld 1 – Altstetten 1 (Gruppe A)», nach dem Spiel mit Resultat «… 6:4» (Status mit erfasstem Resultat wie in der Tabelle), bei Forfait «… 0:10 Forfait». Verschobene Begegnungen: «Verschoben: …» mit `STATUS:TENTATIVE`; annullierte: «Annulliert: …» mit `STATUS:CANCELLED`. Ranglistenturnier: «Ranglistenturnier: 2. Runde, Kategorie 1, Gruppe 3».
- `LOCATION` Lokal mit Adresse (Lokal der Begegnung, sonst Heimlokal des Heimteams; im Ranglistenturnier das Lokal der Gruppe, sonst der Platzclub), `GEO` wenn Koordinaten erfasst sind.
- `URL` und letzte Zeile der `DESCRIPTION`: Link auf den Matchreport bzw. die Seite des Ranglistenturniers. Die Beschreibung nennt ausserdem Konkurrenz und Saison, Resultat mit Status (provisorisch, strittig, Forfait) und das Spiellokal.
- `DTSTAMP` (UTC) ist der Zeitpunkt, zu dem die Daten berechnet und gecacht wurden; `CATEGORIES` Meisterschaft, Cup oder Ranglistenturnier.

**Kalenderkopf**: `PRODID`, `VERSION:2.0`, `CALSCALE:GREGORIAN`, `METHOD:PUBLISH`, Name als `NAME` und `X-WR-CALNAME` («Seefeld 1 – Spiele 2026/27»), Beschreibung als `DESCRIPTION` und `X-WR-CALDESC`, `X-WR-TIMEZONE:Europe/Zurich`, `REFRESH-INTERVAL;VALUE=DURATION:PT6H` und `X-PUBLISHED-TTL:PT6H`.

**Formalien** (`TTLeague\Calendar\IcsWriter`, reine Klasse ohne WordPress): Zeilenende CRLF, Faltung bei 75 Oktetten nur zwischen ganzen Zeichen (Graphem-Clustern, UTF-8-sicher), Maskierung von Backslash, Semikolon, Komma und Zeilenumbruch in Textwerten, Steuerzeichen werden entfernt.

**Antwort**: `Content-Type: text/calendar; charset=utf-8`, `Content-Disposition: inline; filename="seefeld-1-2026-27.ics"`, `Cache-Control: public, max-age=900`, `X-Robots-Tag: noindex, nofollow`. Das schwache `ETag` entsteht aus Datenversion (`DataVersion::get()`), Feed-Typ und Parametern, Formatversion, Startadresse, Seitenpfaden und Sprache; passt `If-None-Match`, antwortet der Feed mit 304 ohne die Ereignisse zu laden.

**Abschnitt «Kalender abonnieren»** (`SubscribeBox`): auf der Mannschaftsseite nach dem Spielplan (nicht in archivierten Saisons), auf der Clubseite nach den Teams (nur in der aktiven Saison, deren Spiele der Feed enthält) und am Ende des Spielerprofils (nur bei Meldung in der aktiven Saison). Er enthält einen Satz Erklärung, den Knopf «Im Kalender abonnieren» mit `webcal://`-Link (öffnet Apple Kalender, Outlook und andere Kalender-Apps zum Abonnieren) und die `https://`-Adresse in einem schreibgeschützten Feld zum Kopieren (Google Kalender «Per URL hinzufügen», Outlook im Web). Der Knopf «Adresse kopieren» erscheint nur mit JavaScript und Zwischenablage (`assets/js/calendar.js`, nur auf Seiten mit dem Abschnitt geladen) und meldet das Ergebnis über eine Live-Region; ohne JavaScript bleibt das Feld markierbar. Touch-Ziele mindestens 44px, sichtbarer Fokus, im Druck ausgeblendet.

**Aufbau**

| Klasse | Aufgabe |
|---|---|
| `Calendar` | Registrierung (aus `Plugin::boot()`), Query-Variablen `ttl_ical`, `ttl_ical_season`, `ttl_ical_key`, Rewrite-Regeln, Auslieferung bei `parse_request` (vor Hauptabfrage und Template) |
| `FeedUrls` | Adressen, `webcal://`-Variante, Rewrite-Regeln als reine Funktion |
| `Feeds` | Lesemodell: Auflösung der Adresse über die bestehenden Lesemodelle, eine Abfrage pro Feed, gecacht über den Cache des Lesemodells (Schlüssel mit `DataVersion::key()`, Name `ical_team`, `ical_club`, `ical_player`) |
| `EventFactory` | Titel, Beschreibung, Ort, Status und Dauer der Ereignisse; Kalendername |
| `IcsWriter`, `IcsEvent` | iCalendar-Text |
| `ConditionalGet` | ETag und Vergleich mit `If-None-Match` |
| `SubscribeBox` | Abschnitt «Kalender abonnieren» für Mannschafts-, Club- und Spielerseite |

Neue oder geänderte Rewrite-Regeln erneuert das Plugin einmalig selbst (Option `ttleague_calendar_routes`); manuell mit `wp rewrite flush --hard`.

## Web-App (PWA)

Grundlage einer installierbaren Web-App. Registrierung aus `Plugin::boot()` über `TTLeague\Pwa\Pwa`; Manifest und Service Worker werden wie die Kalender bei `parse_request` ausgeliefert. Push-Benachrichtigungen (Abos, VAPID, Versand) beschreibt `docs/erfassung.md`, Abschnitt «Web Push».

| Adresse | Antwort |
|---|---|
| `/manifest.webmanifest` | `application/manifest+json`, `Cache-Control: public, max-age=3600` |
| `/sw.js` | `application/javascript`, `Service-Worker-Allowed: /`, `Cache-Control: no-cache` |

**Manifest** (`Manifest`, reine Klasse): `name` aus dem Seitentitel (Filter `ttleague_pwa_name`), `short_name` aus ganzen Wörtern bis zwölf Zeichen («Freizeitliga Tischtennis Seefeld» → «Freizeitliga», Filter `ttleague_pwa_short_name`), `description` aus dem Untertitel, `lang`, `dir`, `id` und `scope` = Pfad der Startadresse, `start_url` `/?quelle=app` (App-Starts in Statistiken erkennbar), `display: standalone`. `theme_color` aus dem Preset `accent-1` (sonst `primary`, `accent`), `background_color` aus `base` (sonst `background`, `white`); nur Hex-Werte, sonst `#0b4a9e` bzw. `#ffffff`. Liegt WordPress in einem Unterordner, gelten dessen Pfade (`/liga/?quelle=app`). Das ganze Manifest lässt sich mit `ttleague_pwa_manifest` anpassen.

**Icons**: Ist ein Website-Icon gesetzt, stehen dessen Bilder für 192 und 512 px mit den tatsächlichen Abmessungen im Manifest (Zweck `any`, denn ein Website-Icon ist nicht für das Beschneiden auf Kreis oder Tropfen gestaltet; eigene `maskable`-Icons über den Filter). Sonst die mitgelieferten, neutralen Icons (Schläger und Ball) aus `assets/pwa/`: `icon-192.png`, `icon-512.png` (`any`) und `icon-maskable-192.png`, `icon-maskable-512.png` (`maskable`, Motiv innerhalb der Sicherheitszone von 80 %), dazu `apple-touch-icon.png` (180 px); Quellen `icon.svg` und `icon-maskable.svg`.

**Kopfzeilen** (`wp_head`): `<link rel="manifest">`, `<meta name="theme-color">`, `<meta name="apple-mobile-web-app-title">` (Kurzname) und `<link rel="apple-touch-icon">` mit dem mitgelieferten Icon; mit Website-Icon gibt WordPress das apple-touch-icon selbst aus.

**Service Worker** (`assets/pwa/sw.js` als Vorlage, `ServiceWorker` setzt die Konstante `CONFIG` davor):

- Seitenaufrufe zuerst aus dem Netz; erfolgreiche HTML-Antworten werden gespeichert (höchstens 60 Seiten). Offline erscheint die zuletzt gespeicherte Fassung, sonst eine kleine Offline-Seite, die der Service Worker selbst erzeugt (Name der Website, «Erneut versuchen», «Zur Startseite», Farben aus der Palette).
- Stile, Skripte, Schriften und Bilder der eigenen Website: stale-while-revalidate (höchstens 120 Dateien, die ältesten fallen weg).
- Nie gespeichert: alles ausser GET, fremde Adressen, die Pfade `wp-admin`, `wp-login.php`, `wp-json`, `wp-cron.php`, `xmlrpc.php`, `anmelden`, `mein-bereich`, `erfassen`, `kalender` (Filter `ttleague_pwa_never_cache`), Vorschau, Customizer und REST über Abfrageparameter sowie Antworten mit `Cache-Control: private` oder `no-store` – WordPress sendet beides für angemeldete Personen. Ausgeschlossene Seiten kommen nur aus dem Netz, offline erscheint die Offline-Seite. Neue Module mit persönlichen Seiten tragen ihre Pfade in den Filter ein oder senden `nocache_headers()`.
- Beim Installieren werden die Startadresse und bereits offene Seiten gespeichert, damit sie schon nach dem ersten Besuch offline verfügbar sind.
- Push: Das Ereignis `push` zeigt eine Benachrichtigung aus `{title, body, url, tag, icon}` an, ein Klick darauf fokussiert ein offenes Fenster mit der Adresse oder öffnet sie; Adressen anderer Herkunft ersetzt der Service Worker durch die Startadresse.
- Cache-Namen `ttleague:{pfad}:pages:{version}` und `…:assets:{version}`; die Version ist ein Hash aus Vorlage und Konfiguration. Ein Update des Plugins ändert die Bytes von `/sw.js`, der neue Service Worker übernimmt sofort (`skipWaiting`, `clients.claim`) und löscht ältere Caches mit demselben Pfad; Caches anderer Websites auf derselben Domain bleiben.

**Registrierung**: `assets/pwa/register.js` (verzögert, nach dem Laden) mit den Einstellungen aus `window.ttleaguePwa`. Nicht eingebunden für angemeldete Personen mit Redaktionsrechten (`edit_posts`), in Vorschau und Customizer; ganz abschaltbar mit `ttleague_pwa_service_worker`. Ohne Unterstützung für Service Worker oder ohne HTTPS bleibt die Website unverändert.

**Server**: `/sw.js` und `/manifest.webmanifest` müssen WordPress erreichen. Im Webroot dürfen keine Dateien mit diesen Namen liegen, und nginx-Regeln, die `.js` mit `try_files $uri =404` statisch ausliefern, müssen `/sw.js` ausnehmen. Neue Rewrite-Regeln erneuert das Plugin einmalig selbst (Option `ttleague_pwa_routes`).

## Tests

- `tests/Unit/Frontend/`: Formate (Datum «Di, 8.9.2026», Punkte «5,5», Status), Adressbau und Rewrite-Regeln, Slugs, Shortcode-Namen, Verlaufsgrafik, Knopf «Teilen» (Auszeichnung und Maskierung in `ShareButtonTest`; Verhalten von `share.js` in `ShareScriptTest` mit Node und einem nachgebildeten Dokument: teilen, kopieren, Abbruch, Rückfall, verborgen ohne beides), Links der Kontaktdaten. WordPress-Funktionen sind in `Stubs/WordPress.php` minimal nachgebildet.
- `tests/Unit/Calendar/`: Faltung (Grenzen, UTF-8, Graphem-Cluster), Maskierung, `VTIMEZONE` im Abgleich mit der Zeitzonendatenbank, Dauer über die Zeitumstellung, Ganztag, Titel und Status der Ereignisse, Adressen, Rewrite-Regeln, ETag.
- `tests/Integration/Calendar/`: Feeds für Mannschaft, Club und Spieler gegen das Szenario (Forfait, provisorisch, Cup, Ranglistenturnier, Ersatzeinsatz, annulliert, Anspielzeit offen, unbekannte Adressen) und der Abschnitt «Kalender abonnieren» auf den Seiten.
- `tests/Unit/Pwa/`: Manifest (Namen, Kurzname, Unterordner, Farben aus der Palette, mitgelieferte Icons mit Abmessungen, Website-Icon, Rewrite-Regeln) und Service Worker (Konfiguration, Cache-Namen pro Website, Version, ausgeschlossene Pfade, Strategien der Vorlage). Registrierung, Offline-Rückfall und Ausschlüsse im Browser lassen sich mit Playwright gegen eine Instanz prüfen (`context.setOffline(true)`).
- `tests/Integration/Frontend/`: `Seeder` legt zwei erfundene Saisons an (vier Clubs, sechzehn Spieler, Forfait, provisorisches Resultat, kampflose Spiele, Cup mit Spiel nach der Entscheidung, RL-Runde mit Aufgebot, Wertungsverlauf). `ReadModelTest` prüft Tabelle, Spielerstatistik, Einsatzmatrix, RL und Cup; `ResolverTest` 404, 301 und 302; `ViewsTest` rendert alle Ansichten und prüft Inhalte und Escaping, auch den Knopf «Teilen» nur mit Resultat.
- `tests/WordPress/Frontend/`: `ShareButtonTest` (Skript verzögert im Fuss registriert und nur mit dem Knopf eingereiht, gleiche Ausgabe für Gäste und Angemeldete) und `ContactViewsTest` (Kontaktdaten, siehe `docs/erfassung.md`).
- Im Browser nicht automatisiert geprüft: der Teilen-Dialog des Geräts und das Kopieren in die Zwischenablage.

```bash
vendor/bin/phpunit
vendor/bin/phpcs
```
