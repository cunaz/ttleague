# Anlässe mit Anmeldung

Turniere, Trainings und Sitzungen, zu denen man sich auf der Website anmeldet. Ersetzt externe Formulare. Gemeinsame Regeln in [saisonbetrieb.md](saisonbetrieb.md). Dieses Dokument beschreibt, was gebaut ist.

## Anlass

Die TK legt Anlässe im Backend an («Spielbetrieb → Anlässe»): `type` (`tournament`, `training`, `meeting`, `other`), Titel, Saison, Beginn, Ende, Ort, Anmeldeschluss, Höchstzahl, optional ein verknüpfter Beitrag oder eine Seite (`post_id`) mit der Ausschreibung. Das Modul legt keine Beiträge an.

| Status | Bedeutung |
|---|---|
| `open` | Anmeldung möglich bis zum Anmeldeschluss. |
| `closed` | Die TK hat die Anmeldung geschlossen; sie kann sie wieder öffnen. |
| `cancelled` | Abgesagt; alle Teilnehmenden und die Warteliste erhalten eine Mitteilung. Endgültig: Der Anlass lässt sich nicht mehr ändern, die Anmeldungen bleiben zur Nachvollziehbarkeit stehen. |

Eingaben (`Events\EventInput`): Titel Pflicht, höchstens 160 Zeichen; Ort höchstens 160 Zeichen; Beginn Pflicht; Ende nicht vor dem Beginn; Anmeldeschluss nicht nach dem Beginn; Höchstzahl 1 bis 65535 oder leer (unbegrenzt); die Saison muss es geben. Die Ausschreibung gibt die TK als Adresse oder ID an; sie muss ein Beitrag oder eine Seite sein.

**Zeit:** `starts_at`, `ends_at` und `deadline` sind Ortszeit der Website, `registration.created_at` ist UTC. «Jetzt» kommt von `Clock` und wird in der Zeitzone der Website verglichen. Genau zum Anmeldeschluss ist die Anmeldung noch möglich, eine Sekunde danach nicht mehr. Ohne Anmeldeschluss gilt der Beginn als Frist.

**Saison:** `event.season_id` bestimmt, in welcher Saison die Clubzugehörigkeit gilt (für TK-Chefs und die Clubspalte der Teilnehmerliste). Ohne Saison gilt die aktive. Neue Anlässe schlagen die aktive Saison vor.

## Anmeldung

Block `ttleague/event-registration` (Attribut `event`, im Editor als Auswahlliste der Anlässe), in der Ausschreibung platziert; Shortcode `[ttleague view="event-registration" event="…"]`, Kurzform `view="anlass"`. Registriert wie die übrigen Ansichten über `Frontend\Blocks::VIEWS` (Ansicht `Events\RegistrationView`), Stil `assets/css/events.css` nur auf Seiten mit dem Block.

| Wer | Sieht |
|---|---|
| Alle | Titel, Art, Termin, Ort, wirksamer Anmeldeschluss (ohne gesetzte Frist der Beginn), Anzahl Angemeldete (mit Länge der Warteliste) und freie Plätze; Hinweis, wenn der Anlass abgesagt, geschlossen oder die Frist vorbei ist. |
| Nicht eingeloggte Besucher | dazu den Link zum Login, der zur Seite zurückführt. |
| Eingeloggte Personen mit verknüpfter `person` | ihren Stand (angemeldet, Platz auf der Warteliste, eigene Bemerkung) und das Formular zum An- oder Abmelden mit kurzer Bemerkung (höchstens 200 Zeichen). |
| TK-Chefs | dazu «Spieler deines Clubs»: gemeldete Spieler ihrer Clubs in der Saison des Anlasses anmelden (Auswahlliste, Bemerkung) und abmelden. |
| Personen mit Funktion in der aktiven Saison (Captain, TK-Chef) und die TK | dazu die Namen der Teilnehmenden und der Warteliste mit Club. Bemerkungen anderer stehen nur im Backend. |
| Konten ohne Person | einen Hinweis; TK-Konten ohne Person melden im Backend an. |

Regeln (`Events\RegistrationService`, reine Regeln in `Domain\Events\RegistrationRules`):

- Ist die Höchstzahl erreicht, kommt die Anmeldung auf die Warteliste (`registration.status = waitlist`). Das gilt auch für Anmeldungen durch die TK; sie erhöht bei Bedarf die Höchstzahl.
- Eine Abmeldung löscht die Zeile. Wird ein Platz frei, rückt nach, wer am längsten wartet (`created_at`, dann `id`); die Person erhält eine Mitteilung. Das gilt auch, wenn die TK die Höchstzahl erhöht oder aufhebt. Senkt sie die Höchstzahl unter die Zahl der Teilnehmenden, bleiben alle angemeldet; neue Anmeldungen warten, bis wieder Plätze frei sind.
- Eine Person ist pro Anlass höchstens einmal angemeldet (auch die Warteliste zählt).
- Nach dem Anmeldeschluss und bei geschlossener Anmeldung ändert nur noch die TK. Bei einem abgesagten Anlass ändert niemand.
- Nur aktive Personen (`person.status = active`) lassen sich anmelden.

**Gleichzeitige Anmeldungen:** Jeder Schreibvorgang (anmelden, abmelden, Anlass ändern, schliessen, öffnen, absagen) sperrt als erste Abfrage seiner Transaktion die Zeile des Anlasses (`EventRepository::lock()`, `SELECT … FOR UPDATE`), liest den Anlass neu und zählt erst dann. Zwei gleichzeitige Anmeldungen laufen so nacheinander und überschreiten die Höchstzahl nicht.

**Formulare:** ohne JavaScript an `admin-post.php` (`ttleague_event_register`, `ttleague_event_unregister`), Nonce pro Aktion und Anlass. Die Rückmeldung liegt im `Flash` des Anlasses; zurück geht es zur Seite mit dem Block (`redirect_to`, nur eigene Adressen; sonst zur Ausschreibung, sonst zu «Mein Bereich»), Anker `#ttl-anlass-{id}`. Nicht eingeloggte Besucher landen beim Login.

**Zwischenspeicher:** Der öffentliche Teil enthält keine Namen. Der personenbezogene Teil (eigener Stand, Clubspieler, Namen) wird bei jeder Anfrage aus der Datenbank gebaut und liegt nie im Cache der Lesemodelle. Für eingeloggte Personen sendet WordPress Kopfzeilen gegen das Zwischenspeichern, der Service Worker speichert solche Antworten nicht. Ein Seitencache vor WordPress kann Besuchern die Zahl der freien Plätze verzögert zeigen. In der Vorschau des Editors erscheint nur der öffentliche Teil.

## Rechte

| Aktion | Erlaubt für |
|---|---|
| Anlass anlegen, ändern, schliessen, öffnen, absagen; CSV | `ttl_tk` |
| Für sich selbst an- und abmelden | jede eingeloggte Person mit verknüpfter `person` |
| Für andere an- und abmelden | TK-Chef eines Clubs, für den die Person in der Saison des Anlasses gemeldet ist (`ttl_manage_club`), und die TK |

Die Formulare prüfen Nonce und Capability, die Dienste prüfen dasselbe fachlich noch einmal (`RegistrationService::may_act_for()`, `EventService` verlangt die TK).

## Backend

«Spielbetrieb → Anlässe» (`admin.php?page=ttleague-tk-anlaesse`, Filter `ttleague_tk_pages`, Position 70):

- **Liste** aller Anlässe, die neuesten zuerst: Art, Beginn, Anmeldeschluss, Status, Teilnehmende («3 von 16»), Warteliste. «Anlass anlegen».
- **Anlass** (`&anlass={id}`): Teilnehmerliste und Warteliste (Nummer, Name, Club, Zeitpunkt in Ortszeit, Bemerkung) mit «Abmelden» je Zeile, «Von Hand anmelden» (Auswahl aus den gemeldeten Spielern und Funktionsträgern der Saison, ohne Saison alle aktiven Personen), Formular zum Ändern, Anmeldung schliessen oder wieder öffnen, Anlass absagen (mit Häkchen zur Bestätigung), Link «Teilnehmerliste als CSV» und der Shortcode zum Einbinden.
- Formulare an `admin-post.php` (`ttleague_event_save`, `ttleague_event_status`, `ttleague_event_tk_register`, `ttleague_event_tk_unregister`) mit Nonce und `ttl_tk`, Rückmeldung über `Flash`. Eine abgewiesene Eingabe bleibt im Formular stehen (Transient pro Konto, fünf Minuten).

**CSV:** Liste `event-participants` über `ttleague_exports`, Filter `event` (ID des Anlasses): Anlass, Beginn, Nummer, Status, Nachname, Vorname, Club, Zeitpunkt (Ortszeit), Bemerkung; zuerst die Teilnehmenden, dann die Warteliste. `wp ttleague export event-participants --event=12`. Ohne gültigen Anlass ist die Liste leer.

## Benachrichtigungen

Ereignistyp `event_registration` («Anlässe: An- und Abmeldung, Nachrücken, Absage», Einstellungen in «Mein Bereich»; Standard E-Mail an, Push aus). Versand über `Notifications::notifier()`.

| Art (`event_type` im Protokoll) | Empfänger | Bezug (`ref_type`, `ref_id`, `ref_key`) |
|---|---|---|
| `event_registered` | angemeldete Person, auch bei eigener Anmeldung; auf der Warteliste mit entsprechendem Text | `registration`, ID der Anmeldung, `registered` oder `waitlist` |
| `event_unregistered` | abgemeldete Person | `registration`, ID der gelöschten Anmeldung |
| `event_promoted` | nachgerückte Person | `registration`, ID der Anmeldung |
| `event_cancelled` | alle Teilnehmenden und die Warteliste, ausser dem Konto, das abgesagt hat; Versand in Schritten, siehe unten | `event`, ID des Anlasses |

- Hat jemand anderes an- oder abgemeldet (TK-Chef, TK), nennt die Nachricht den Namen.
- Eine neue Anmeldung nach einer Abmeldung hat eine neue ID und wird wieder bestätigt.
- Der Link «Zum Anlass» führt zur veröffentlichten Ausschreibung; ohne Ausschreibung fehlt er.
- Nur aktive Personen; ohne gültige E-Mail-Adresse geht keine E-Mail hinaus.
- Ein Fehler beim Versand macht die An- oder Abmeldung nicht rückgängig: Die Nachrichten gehen erst nach dem Speichern hinaus, Fehler landen im Fehlerprotokoll und in `ttleague_notification_error`. Eine gescheiterte Bestätigung oder Mitteilung zum Nachrücken wird nicht wiederholt.

**Absagemitteilungen** gehen nicht in der Anfrage der Absage hinaus: Bei vielen Angemeldeten und langsamem Versand bräche sonst das Zeitlimit ab, der Anlass wäre abgesagt und der Rest erhielte nichts.

- Die Absage merkt den Versand nur vor: einmaliges WP-Cron-Ereignis `ttleague_event_cancellation` (Anlass-ID, Person-ID, nach der der Schritt weitermacht), sofort fällig. WP-Cron startet es mit der nächsten Anfrage, also schon mit der Weiterleitung nach dem Formular; mit `DISABLE_WP_CRON` der Cron des Servers. Ein eigener Aufruf von `spawn_cron()` ist deshalb nicht nötig. Die Rückmeldung an die TK sagt, dass die Mitteilungen versendet werden.
- Ein Schritt (`EventNotifications::send_cancellations()`) versendet an höchstens 25 Empfänger (`CANCEL_BATCH`), in der Reihenfolge der Person-ID, und nur an Personen, die laut Versandprotokoll noch keine Absagemitteilung zu diesem Anlass haben. Bleibt ein Rest, plant er sich für die Personen danach erneut ein. Der Schritt ist beliebig wiederholbar; das Versandprotokoll verhindert doppelten Versand.
- Das absagende Konto steht im `audit_log` der Absage und bleibt in jedem Lauf ausgenommen. Die Kanaleinstellungen gelten wie sonst. Personen ohne Kanal (inaktiv, ohne gültige Adresse, abgewählt) zählen nicht zum Schritt.
- Ein Fehler bei einem Empfänger (auch eine Ausnahme vor dem eigentlichen Versand) wird gemeldet, zählt zum Schritt und hält die übrigen nicht auf.
- Höchstens ein Stapel pro Anfrage: `wp-cron.php` führt alle fälligen Ereignisse im selben PHP-Prozess aus. Lief darin schon ein Schritt (gleich für welchen Anlass), versendet ein weiterer Aufruf nichts und plant sich mit 60 Sekunden Abstand neu ein (`Events::DEFER_SECONDS`). Das merkt sich der Handler pro Prozess; `Events::reset_request()` setzt es zurück (Tests).
- Auffangnetz: Der stündliche Lauf der Benachrichtigungen (`ttleague_notifications_hourly`) versendet selbst nichts. Für Anlässe, die in den letzten 7 Tagen abgesagt wurden (`CANCEL_CATCH_UP_DAYS`, Zeitpunkt aus dem `audit_log`) und bei denen noch Mitteilungen ausstehen, plant er denselben Schritt von vorn neu ein, sofern für den Anlass nicht schon ein Schritt wartet (gleich ab welcher Person). Das holt ein verlorenes Ereignis, einen abgebrochenen Lauf und gescheiterte Zustellungen nach, auch nachts. Nach 7 Tagen wird nichts mehr nachgeholt.
- Pro Lauf macht er höchstens 10 Versuche (`CANCEL_CATCH_UP_EVENTS`), im Abstand von einer Minute eingeplant; auch ein gescheitertes Einplanen zählt als Versuch.
- Er geht reihum vor, in der Reihenfolge der Absage (die älteste zuerst): Er merkt sich den zuletzt eingeplanten Anlass (Option `ttleague_event_cancellation_cursor`, nicht automatisch geladen) und setzt beim nächsten Lauf danach fort; nach dem letzten beginnt er von vorn, ebenso, wenn der gemerkte Anlass nicht mehr im Zeitraum liegt. So verdrängen ältere Anlässe, bei denen dauerhaft etwas aussteht, die späteren nicht.
- «Ausstehend» heisst: Eine angemeldete, aktive Person, die nicht das absagende Konto ist, hat noch keine bestätigt versendete Absagemitteilung. Personen ohne Kanal (keine gültige Adresse, Benachrichtigung abgewählt) und dauerhaft scheiternde Adressen zählen dabei mit; für sie plant der stündliche Lauf bis zum Ende der 7 Tage immer wieder einen Schritt ein, der nichts zustellt.
- Lässt sich ein Schritt nicht einplanen (`wp_schedule_single_event()` meldet einen Fehler), steht das im Fehlerprotokoll und geht an `ttleague_notification_error`; der stündliche Lauf plant den Anlass später neu ein. Die Rückmeldung an die TK bleibt dieselbe. Ein schon gleich eingeplanter Schritt gilt nicht als Fehler.
- Bei der Deaktivierung des Plugins werden geplante Schritte und die gemerkte Stelle entfernt.

## Dienste und Ereignisse

| Klasse | Aufgabe |
|---|---|
| `Domain\Events\RegistrationRules` | Anmeldefenster, Platzierung, freie Plätze, Nachrücken (rein) |
| `Events\EventService` | anlegen, ändern, schliessen, öffnen, absagen |
| `Events\RegistrationService` | an- und abmelden, Rechte, Clubspieler eines TK-Chefs |
| `Events\Waitlist` | freie Plätze mit den ältesten Anmeldungen der Warteliste füllen |
| `Events\EventRepository` | Abfragen und Sperre |
| `Events\EventListener` | Ereignisse nach dem Speichern: `WordPressEventListener` (Cache-Version, Aktionen), `EventNotifications` (Nachrichten, Absagemitteilungen in Schritten) |
| `Events\Events` | `register()`: Hooks, Cron-Schritte der Absagemitteilungen |
| `Events\AdminPage`, `AdminActions` | Backend |
| `Events\RegistrationView`, `BlockActions` | Block, Shortcode-Ansicht und ihre Formulare |
| `Events\Export` | CSV |

Jeder Schreibvorgang läuft in einer Transaktion und schreibt das `audit_log` (`event`: `created`, `updated`, `closed`, `reopened`, `cancelled`; `registration`: `registered`, `unregistered`, `promoted`). Danach folgen `DataVersion::bump()` und die Aktionen:

| Aktion | Parameter |
|---|---|
| `ttleague_event_changed` | Anlass-ID, User-ID, `created`, `updated`, `closed` oder `reopened` |
| `ttleague_event_cancelled` | Anlass-ID, User-ID |
| `ttleague_event_registered` | Anlass-ID, ID der Anmeldung, Person-ID, Status, User-ID |
| `ttleague_event_unregistered` | Anlass-ID, ID der gelöschten Anmeldung, Person-ID, Status vorher, User-ID |
| `ttleague_event_promoted` | Anlass-ID, ID der Anmeldung, Person-ID |
| `ttleague_data_changed` | `event`, Anlass-ID |

Wirft ein Listener eine Ausnahme, steht sie im Fehlerprotokoll, und die Aktion bleibt gespeichert.

Nicht in diesem Paket: Kalender-Abo für Anlässe, eine Übersicht der Anlässe auf der Website oder in «Mein Bereich».

## Tests

- `tests/Unit/Domain/EventRegistrationRulesTest.php`: Frist an der Grenze (auch in der Sommerzeit), Sperrgründe, Platzierung genau an der Höchstzahl, Nachrücken.
- `tests/Unit/Events/`: Eingabeprüfung mit Grenzen, Texte der Nachrichten (Schweizer Schreibweise, Escaping in der E-Mail).
- `tests/Integration/Events/` gegen MariaDB: anmelden, doppelt anmelden, Höchstzahl und Warteliste, Nachrücken in der Reihenfolge der Anmeldung (Zeitpunkt vor ID), Anmeldeschluss an der Grenze, geschlossen und abgesagt, Rechte je Fall, Saison des Anlasses, Höchstzahl erhöhen, aufheben und senken, Statuswechsel, Absage. Gleichzeitigkeit mit einer zweiten Verbindung: Eine Anmeldung wartet auf die Sperre des Anlasses; der Platz wird unter der Sperre gezählt; eine Absage zwischen Prüfung und Schreiben weist die Anmeldung ab; jede Schreibtransaktion beginnt mit der Sperre. Benachrichtigungen über aufgezeichnete Kanäle: Empfänger, Bezug, Dedupe, Einstellungen; Absagemitteilungen in Schritten (Stapelgrenze, Fortsetzung, Durchgang von vorn, Fehler bei einem Empfänger, Fenster von 7 Tagen an der Grenze, nur Anlässe mit ausstehenden Mitteilungen, Fortsetzung nach dem zuletzt eingeplanten Anlass).
- `tests/WordPress/Events/`: Block für nicht eingeloggte Besucher, Personen ohne Funktion, Captains, TK-Chefs und TK (wer Namen sieht), Shortcode, Escaping; Formulare mit Nonce und Rechten, Rückmeldung und Weiterleitung nur auf eigene Adressen; ein Fehlschlag von `wp_mail` oder eines Listeners lässt die Anmeldung bestehen; Backend mit Nonce und `ttl_tk` für jede Aktion; CSV nur mit `ttl_tk`; Absage: versendet selbst nichts und plant den Versand, Läufe zu 25 mit eingeplantem Rest, das absagende Konto erhält nichts, zwei fällige Schritte in einer Anfrage ergeben einen Stapel und ein neu eingeplantes Ereignis; der stündliche Lauf versendet selbst nichts und plant pro Anlass höchstens einen Schritt (kein zweiter neben einem wartenden, höchstens zehn Versuche auch bei scheiterndem Einplanen, reihum über mehr als zehn Anlässe mit dauerhaft ausstehenden Personen), so werden ein verlorenes Ereignis und eine gescheiterte E-Mail nachgeholt; ein gescheitertes Einplanen wird gemeldet.

Nicht automatisiert geprüft: der Start des Cron-Ereignisses durch WP-Cron selbst (die Tests lösen den Hook aus), die Vorschau im Editor über die REST-Route (nur das HTML der Vorschau), die Auswahlliste im Editor (`assets/js/editor.js`), die Darstellung im Browser.
