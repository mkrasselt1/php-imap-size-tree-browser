# Demo-Daten

Diese Datei enthält die Demo-Daten für den IMAP Speicher-Analyse-Browser.

## Struktur

Die Demo-Daten simulieren ein typisches E-Mail-Postfach mit:

- **INBOX**: Hauptordner mit aktuellen E-Mails
- **Sent**: Ordner mit gesendeten E-Mails  
- **Drafts**: Ordner mit Entwürfen
- **Trash**: Papierkorb mit gelöschten E-Mails und Warnung für nicht gescannte E-Mails

## Größen

- Gesamtgröße: ~50 MB
- INBOX: ~20 MB
- Sent: ~15 MB
- Drafts: ~5 MB
- Trash: ~15 MB

## Verwendung

Die Demo-Daten werden automatisch geladen, wenn der Demo-Modus in der Anwendung aktiviert ist.

## Anpassung

Sie können die Demo-Daten in `demo-data.json` nach Bedarf anpassen:

- Neue Ordner hinzufügen
- E-Mail-Eigenschaften ändern (Größe, Datum, Absender)
- Warnungen für große Ordner konfigurieren
- Zusätzliche Mailtypen hinzufügen

## Validierung

Die JSON-Datei sollte der folgenden Struktur entsprechen:

```json
{
  "name": "Ordnername",
  "type": "folder",
  "size": Größe_in_Bytes,
  "children": [
    {
      "name": "E-Mail-Betreff",
      "type": "mail",
      "size": Größe_in_Bytes,
      "uid": "eindeutige_id",
      "from": "absender@example.com",
      "subject": "E-Mail-Betreff",
      "date": "ISO-Datum"
    }
  ]
}
```

## Testfälle

Die Demo-Daten enthalten verschiedene Testfälle:
- Normale E-Mails verschiedener Größen
- Große E-Mails mit Anhängen
- Ordner mit vielen E-Mails
- Warnungen für nicht gescannte E-Mails
- Verschiedene Datumsformate
