# Kehai: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](PRIVACY.md). Optionen heißen `kehai.*`; die
Namen stehen im [README](README.md#configuration).)

Dieser Baustein beschreibt die Implementierung. Rechtsgrundlage,
Interessenabwägung und die Formulierung müssen vor Veröffentlichung für den
konkreten Betrieb fachlich geprüft und ergänzt werden. Der Text ist keine
Rechtsberatung.

## Hinweise auf aktuelle Buchungen — technischer Textbaustein

Auf unserer Website weisen wir auf aktuelle Buchungen unserer Angebote hin,
zum Beispiel „Jemand aus Österreich hat ‚Jahreskurs‘ gebucht · vor
2 Stunden“ oder „In den letzten 7 Tagen wurde ‚Jahreskurs‘ 12-mal gebucht“.

Diese Hinweise werden **nicht beim Aufruf der Seite von einem fremden Server
geladen**. Ihr Browser baut dafür keine Verbindung zu Digistore24 oder einem
anderen Dritten auf; Skript, Gestaltung und Daten kommen von unserem eigenen
Server. Dabei werden keine Daten über Sie an Dritte übermittelt.

Angezeigt werden ausschließlich **anonymisierte Kaufereignisse**: welches
Angebot gebucht wurde, wann (auf die Minute abgerundet) und — falls
angezeigt — in welchem Land die Käuferin oder der Käufer ansässig ist. Namen,
E-Mail-Adressen, Anschriften, Bestellnummern, Beträge oder Zahlungsdaten
werden weder angezeigt noch auf unserer Website gespeichert. Aus einem
Hinweis lässt sich nicht erkennen, wer gebucht hat.

Grundlage ist eine Datei auf unserem Server, die etwa stündlich ersetzt wird.
Sie enthält je Buchung nur Art des Ereignisses, Angebot, Zeitpunkt und
gegebenenfalls Land. Erstellt wird sie aus den Bestelldaten unseres
Zahlungsanbieters Digistore24, die dort ohnehin vorliegen; dabei werden alle
übrigen Angaben verworfen, bevor die Datei entsteht. Erstattete, stornierte
und Testbestellungen erscheinen nicht. Ein Hinweis wird höchstens 14 Tage
lang gezeigt.

Für diese Hinweise werden **keine Cookies gesetzt** und **keine Daten in
Ihrem Browser gespeichert** (kein Local Storage, kein Session Storage).
Schließen Sie den Hinweis, gilt das nur für die gerade geöffnete Seite. Über
Sie als Besucherin oder Besucher werden dafür keine Daten erhoben; der Abruf
der Hinweise durch Ihren Browser ist eine gewöhnliche Anfrage an unseren
Server und wird wie jede andere Anfrage behandelt (siehe Abschnitt
Server-Logfiles).

### Ergänzung, wenn das Land angezeigt wird (`kehai.country`)

Bei Hinweisen nennen wir das Land, das bei der Bestellung für die Umsatzsteuer
angegeben wurde. Weitere Angaben zur Person, insbesondere Name und Ort,
werden nicht verwendet.

### Ergänzung für den Abruf auf dem Webserver (`kehai.pull`)

Unser Webserver ruft die Bestelldaten höchstens alle 15 Minuten selbst bei
Digistore24 ab und speichert daraus nur die oben genannten anonymen Angaben.
Dieser Abruf geschieht unabhängig von Ihrem Besuch; dabei werden keine Daten
über Sie übermittelt.

## Hinweise für den Betreiber

- **Abrufort.** Im empfohlenen Betrieb läuft `kehai fetch` stündlich auf einem
  CI-Läufer (GitHub Actions, eigener Läufer) mit dem API-Schlüssel als
  Repository-Secret; auf den Webserver wird nur die anonyme Datei geladen. Der
  Läufer verarbeitet für einen Augenblick die vollständigen Bestelldaten (mit
  Namen und E-Mail-Adressen) im Arbeitsspeicher. Wer den Läufer betreibt,
  gehört deshalb in die Liste der Auftragsverarbeiter bzw. Dienstleister —
  bei einem eigenen Läufer niemand zusätzlich, bei GitHub-gehosteten Läufern
  GitHub.
- **Was gespeichert wird.** `storage/kehai/events.json` (oder `kehai.file`):
  je Buchung Ereignistyp (`purchase`), Produkt-Schlüssel aus der eigenen
  Konfiguration, Zeitpunkt (UTC, auf Minuten abgerundet) und mit
  `kehai.country` das Land. Kein Name, keine E-Mail-Adresse, keine
  Bestellnummer, kein Betrag, kein Affiliate, keine Kampagne — auch nicht in
  Ausgaben oder Logzeilen von `kehai fetch` und `kehai check`. Jeder Abruf
  ersetzt die Datei vollständig; es gibt kein Archiv.
- **Personenbezug.** Für Besucher ist ein Hinweis nicht auf eine Person
  rückführbar. Wer selbst die Bestellliste kennt (der Betreiber), kann
  Zeitpunkt und Land einer Bestellung zuordnen; die Datei enthält aber nichts,
  was nicht ohnehin in den eigenen Bestelldaten steht. Bei sehr wenigen
  Bestellungen aus einem kleinen Land kann das Land zusammen mit der Uhrzeit
  für Eingeweihte auffällig sein — dann `kehai.country` ausgeschaltet lassen.
- **Schlüssel.** Der Digistore24-API-Schlüssel liegt nur am Ort des Abrufs,
  nie in `config.php`. Nur im Pull-Modus liegt er auf dem Webserver, in
  `site/config/kehai.php` (nicht im Git, nicht über das Web abrufbar).
  Empfehlung: ein Schlüssel nur mit Leserechten.
- **Was gezeigt wird.** Nur echte Buchungen der konfigurierten Produkte, keine
  Erstattungen, Rückbuchungen, Stornos, Testkäufe oder Folgeraten; höchstens
  14 Tage alt; nichts, wenn die Datei älter als 48 Stunden ist. Irreführende
  Hinweise wären wettbewerbswidrig (§ 5 UWG); das Paket kann keine Einträge
  erfinden oder wiederholen (README, „Honesty“).
- **Ersetzt die „Social Proof Bubble“ von Digistore24.** Deren Skript wurde von
  digistore24.com geladen und zeigte Vornamen und Ort; nach dem Wechsel kann
  der entsprechende Abschnitt der Datenschutzerklärung entfallen, wenn
  Digistore24 nicht anderweitig eingebunden ist (Bestellformulare,
  Zahlungsseiten).
