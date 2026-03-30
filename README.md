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

## Installation

1. **Voraussetzungen:**
   - PHP 7.4+ mit IMAP-Erweiterung (`php-imap`)
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

4. **Cache-Verzeichnis:**
   Das `cache/`-Verzeichnis wird automatisch erstellt. Stelle sicher, dass PHP Schreibrechte hat.

5. **ALTCHA-Secret anpassen (empfohlen):**
   In `altcha.php` den `ALTCHA_HMAC_KEY` auf einen eigenen zufaelligen Wert aendern.

## Nutzung

1. Oeffne `index-new.html` (neue UI) oder `index.html` (einfache UI) im Browser
2. Gib die Zugangsdaten zu deinem IMAP-Konto ein
3. Loese das ALTCHA-Captcha
4. Klicke auf **Analyse starten**
5. Waehle zwischen normalem und progressivem Scan
6. Navigiere durch die Treemap-Visualisierung

## Dateien

### Frontend
- `index-new.html` — Hauptseite mit modernem UI
- `index.html` — Einfaches Login-Formular
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

### Sicherheit (PHP)
- `csrf.php` — CSRF-Token-Verwaltung (Session-basiert)
- `csrf-token.php` — Endpoint: Gibt CSRF-Token als JSON zurueck
- `validate.php` — Input-Validierung (Server, Port, Dateinamen)
- `altcha.php` — ALTCHA Proof-of-Work Captcha
- `altcha-challenge.php` — Endpoint: Gibt ALTCHA-Challenge als JSON zurueck

## Sicherheitshinweis

Gib deine Zugangsdaten nur auf vertrauenswuerdigen Systemen ein. Passwoerter werden nur in der Browser-Session (sessionStorage) gehalten und nach dem Schliessen des Tabs geloescht. Alle Daten werden direkt per IMAP verarbeitet und nicht dauerhaft auf dem Server gespeichert.

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz.
