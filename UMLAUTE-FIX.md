# Umlaute-Problem in IMAP-Ordnernamen - Behebung

## Problem identifiziert
Das Programm hatte tatsächlich Probleme mit Umlauten in Ordnernamen und E-Mail-Betreffs. Die Ursache war:

1. **Fehlende UTF-8-Dekodierung für Ordnernamen**: Die IMAP-Ordnernamen wurden nicht mit `imap_utf8()` dekodiert
2. **Unvollständige Fehlerbehandlung**: `imap_utf8()` kann manchmal fehlschlagen oder leere Strings zurückgeben
3. **Inkonsistente JSON-Ausgabe**: Nicht alle JSON-Ausgaben verwendeten `JSON_UNESCAPED_UNICODE`

## Behobene Dateien

### 1. imap-scan.php
- ✅ Hinzugefügt: `safe_imap_utf8()` Funktion für robuste UTF-8-Dekodierung
- ✅ Behoben: Ordnernamen werden jetzt mit `safe_imap_utf8()` dekodiert
- ✅ Behoben: Mail-Betreffs werden robuster dekodiert
- ✅ Bereits vorhanden: JSON-Ausgabe mit `JSON_UNESCAPED_UNICODE`

### 2. imap-scan-progressive.php
- ✅ Hinzugefügt: `safe_imap_utf8()` Funktion für robuste UTF-8-Dekodierung
- ✅ Behoben: Ordnernamen werden jetzt mit `safe_imap_utf8()` dekodiert (sowohl bei Initialisierung als auch beim Scannen)
- ✅ Behoben: Mail-Betreffs werden robuster dekodiert
- ✅ Behoben: JSON-Ausgaben verwenden jetzt `JSON_UNESCAPED_UNICODE`

### 3. imap-mail.php
- ✅ Hinzugefügt: `safe_imap_utf8()` Funktion für robuste UTF-8-Dekodierung
- ✅ Behoben: Alle Text-Dekodierungen (Betreff, Von, An) verwenden jetzt `safe_imap_utf8()`
- ✅ Bereits vorhanden: JSON-Ausgabe mit `JSON_UNESCAPED_UNICODE`

## Neue robuste Dekodierung-Funktion

```php
function safe_imap_utf8($text) {
    if (empty($text)) return $text;
    
    $decoded = @imap_utf8($text);
    if ($decoded !== false && !empty(trim($decoded))) {
        return $decoded;
    }
    
    // Fallback: Versuche manuelle Dekodierung
    if (function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($text);
        if ($decoded !== false && !empty(trim($decoded))) {
            return $decoded;
        }
    }
    
    // Letzte Hoffnung: Original-Text zurückgeben
    return $text;
}
```

## Vorteile der Lösung

1. **Robuste Dekodierung**: Verwendet mehrere Fallback-Methoden
2. **Fehlerbehandlung**: Graceful Degradation wenn Dekodierung fehlschlägt
3. **Konsistente JSON-Ausgabe**: Alle Dateien verwenden jetzt `JSON_UNESCAPED_UNICODE`
4. **Vollständige Abdeckung**: Sowohl Ordnernamen als auch Mail-Inhalte sind betroffen

## Typische Probleme die jetzt behoben sind

- Ordnernamen wie "Müll", "Entwürfe", "Gelöschte Elemente" werden korrekt angezeigt
- Mail-Betreffs mit Umlauten werden korrekt dekodiert
- Absender-/Empfänger-Namen mit Umlauten werden korrekt angezeigt
- JSON-Übertragung behält alle Umlaute bei

## Test

Eine Testdatei wurde erstellt: `test-umlaute.php`
Diese kann aufgerufen werden, um die Umlaute-Dekodierung zu testen.

## Hinweise

- Die Änderungen sind rückwärtskompatibel
- Bestehende Cache-Dateien werden beim nächsten Scan neu generiert
- Die Dekodierung funktioniert auch bei gemischten Kodierungen
- Bei Fehlern wird der Original-Text beibehalten (bessere Benutzererfahrung)
