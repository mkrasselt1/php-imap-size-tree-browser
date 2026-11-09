# IMAP Size Tree Browser

Analyse und Visualisierung der Speicherbelegung eines IMAP-Kontos. Zeigt die Groesse einzelner IMAP-Ordner als interaktive Baumkarte (Treemap) im Browser an.

## Features

- Verbindung zu beliebigen IMAP-Servern (mit SSL-Unterstuetzung)
- Analyse der Ordnergroessen im IMAP-Konto
- Interaktive Treemap-Visualisierung mit D3.js
- Progressiver Scan fuer grosse Postfaecher (>5GB) mit Fortschrittsanzeige
- Erweiterte Analyse fuer Ordner mit >1000 E-Mails
- E-Mail-Details anzeigen, Anhaenge herunterladen, E-Mails loeschen
- Demo-Modus mit Beispieldaten
- Schnellauswahl fuer gaengige E-Mail-Anbieter (Gmail, Outlook, Yahoo, iCloud, Web.de, GMX)

## Sicherheit

- **CSRF-Schutz**: Alle POST-Requests sind durch CSRF-Tokens geschuetzt
- **ALTCHA Proof-of-Work**: Self-hosted Captcha verhindert automatisierten Missbrauch ohne Third-Party-Dienste
- **XSS-Schutz**: Alle Benutzereingaben werden escaped, DOM-API statt innerHTML
- **Input-Validierung**: Servernamen, Ports und Dateinamen werden serverseitig validiert
- **Passwort-Schutz**: Passwoerter werden nur in sessionStorage gespeichert (nicht persistent)
- **Sandboxed HTML-Mails**: HTML-E-Mails werden in sandboxed iframes angezeigt
- **Header-Injection-Schutz**: Dateinamen fuer Downloads werden sanitisiert
- **SSRF-Schutz**: Nur oeffentliche IMAP-Server (keine internen/privaten Adressen) und nur erlaubte Ports
  (Standard 143/993); Ordner-Parameter muessen zum angemeldeten Postfach gehoeren
- **ALTCHA mit Replay-Schutz**: zufaelliger, geheimer HMAC-Schluessel; jede Loesung ist nur einmal gueltig
- **Verschluesseltes Passwort in der Session**: Schluessel liegt nur im HttpOnly-Cookie des Besuchers;
  Zugangsdaten verfallen nach 30 Minuten Inaktivitaet
- **Sichere Cookies**: HttpOnly, SameSite=Strict, Secure (bei HTTPS), neue Session-ID beim Anmelden
- **Keine Zwischenergebnisse im Webroot**: Scan-Daten liegen im temporaeren Verzeichnis des Servers
- **HTML-Mails**: ohne Skripte, ohne externe Bilder/Tracking-Pixel (CSP im iframe)
- **Nur lesend**: Ansehen und Scannen markieren Mails nicht als gelesen
- **Fremd-Skripte gepinnt**: D3 und ALTCHA mit fester Version und Subresource Integrity

## Installation

1. **Voraussetzungen:**
   - PHP 7.4 bis 8.3 mit IMAP-Erweiterung (`php-imap`) und `sodium` (ab PHP 8.4 ist `imap` nur noch per PECL verfuegbar)
   - Webserver (Apache, nginx, oder PHP Built-in Server)
   - PHP Session-Support (fuer CSRF-Tokens)

2. **Projektdateien kopieren:**
   Kopiere alle Dateien in ein Verzeichnis auf deinem Webserver.

3. **IMAP-Erweiterung aktivieren:**
   Stelle sicher, dass die PHP-IMAP-Erweiterung installiert und aktiviert ist:
   ```bash
   # Debian/Ubuntu
   sudo apt install php-imap
   sudo phpenmod imap
   sudo systemctl restart apache2
   ```

4. **Zwischenspeicher:**
   Scan-Zwischenstaende landen im Temp-Verzeichnis von PHP (nicht im Webroot). Ein altes `cache/`-Verzeichnis
   aus frueheren Versionen kann geloescht werden.

5. **Optionale Einstellungen (Umgebungsvariablen):**
   | Variable | Bedeutung |
   |---|---|
   | `IMAPSIZE_ALTCHA_KEY` | Eigener ALTCHA-Schluessel (min. 32 Zeichen). Sonst wird einer zufaellig erzeugt und gespeichert. |
   | `IMAPSIZE_DATA_DIR` | Verzeichnis fuer Scan-Zwischenstaende und ALTCHA-Daten (Standard: Temp-Verzeichnis, ausserhalb des Webroots) |
   | `IMAPSIZE_ALLOWED_PORTS` | Erlaubte IMAP-Ports, z. B. `143,993,1143` (Standard: `143,993`) |
   | `IMAPSIZE_ALLOW_PRIVATE` | `1` erlaubt IMAP-Server im eigenen Netz (nur fuer Selbst-Hosting im LAN!) |

## Nutzung

1. Oeffne `index.html` im Browser
2. Gib die Zugangsdaten zu deinem IMAP-Konto ein
3. Loese das ALTCHA-Captcha
4. Klicke auf **Analyse starten**
5. Waehle zwischen normalem und progressivem Scan
6. Navigiere durch die Treemap-Visualisierung

## Dateien

### Frontend
- `index.html` — Hauptseite
- `tree.html` — Treemap-Visualisierung (Standalone-Version)
- `script.js` — Hauptlogik fuer die neue UI
- `style.css` — Styling fuer die neue UI
- `demo-data.json` — Demo-Daten fuer den Testmodus

### Backend (PHP)
- `imap-scan.php` — Vollstaendiger IMAP-Scan
- `imap-scan-progressive.php` — Progressiver Scan mit Fortschritt
- `imap-mail.php` — E-Mail-Details abrufen
- `imap-download.php` — Anhaenge herunterladen
- `imap-delete.php` — E-Mail loeschen
- `imap-delete-folder.php` — Ordner leeren

### Sicherheit (PHP)
- `bootstrap.php` — Gemeinsame Basis: Cookies, Sicherheits-Header, JSON, UTF-8
- `session-auth.php` — Zugangsdaten verschluesselt in der Session
- `csrf.php` — CSRF-Token-Verwaltung (Session-basiert)
- `csrf-token.php` — Endpoint: Gibt CSRF-Token als JSON zurueck
- `validate.php` — Input-Validierung (Server, Port, Dateinamen)
- `altcha.php` — ALTCHA Proof-of-Work Captcha
- `altcha-challenge.php` — Endpoint: Gibt ALTCHA-Challenge als JSON zurueck

## Sicherheitshinweis

Gib deine Zugangsdaten nur auf vertrauenswuerdigen Systemen ein. Passwoerter werden nur in der Browser-Session (sessionStorage) gehalten und nach dem Schliessen des Tabs geloescht. Alle Daten werden direkt per IMAP verarbeitet und nicht dauerhaft auf dem Server gespeichert
(Scan-Zwischenstaende werden nach Abschluss bzw. spaetestens nach einer Stunde geloescht).

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz.
