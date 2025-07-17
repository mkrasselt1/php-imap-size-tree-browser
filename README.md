# IMAP Size Tree Browser

Dieses Projekt ermöglicht die Analyse und Visualisierung der Speicherbelegung eines IMAP-Kontos. Es zeigt die Größe der einzelnen IMAP-Ordner als interaktive Baumkarte (Treemap) im Browser an.

## Features

- Verbindung zu beliebigen IMAP-Servern (mit SSL-Unterstützung)
- Analyse der Ordnergrößen im IMAP-Konto
- Visualisierung als interaktive Treemap mit D3.js
- Anzeige von Detailinformationen zu jedem Ordner

## Installation

1. **Voraussetzungen:**  
   - PHP mit IMAP-Erweiterung
   - Webserver (z.B. Apache, nginx, oder PHP Built-in Server)

2. **Projektdateien kopieren:**  
   Kopiere alle Dateien in ein Verzeichnis auf deinem Webserver.

3. **IMAP-Erweiterung aktivieren:**  
   Stelle sicher, dass die PHP-IMAP-Erweiterung installiert und aktiviert ist.

## Nutzung

1. Öffne die Datei [`index.html`](.//index.html) im Browser.
2. Gib die Zugangsdaten zu deinem IMAP-Konto ein und klicke auf **Analysieren**.
3. Die Analyseergebnisse werden angezeigt.  
   (Optional: Die Visualisierung ist über [`tree.html`](.//tree.html) erreichbar.)

## Dateien

- [`index.html`](.//index.html): Formular zur Eingabe der IMAP-Zugangsdaten und Start der Analyse.
- [`imap-scan.php`](.//imap-scan.php): PHP-Skript zur Verbindung mit dem IMAP-Server und Ermittlung der Ordnergrößen.
- [`tree.html`](.//tree.html): Visualisierung der Ordnergrößen als Treemap.

## Sicherheitshinweis

Gib deine Zugangsdaten nur auf vertrauenswürdigen Systemen ein. Die Zugangsdaten werden nicht gespeichert, sondern nur zur Analyse verwendet.

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz.