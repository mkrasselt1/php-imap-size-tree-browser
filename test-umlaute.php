<?php
// Test-Datei für Umlaute-Dekodierung
// Diese Datei kann zu Testzwecken verwendet werden

// Hilfsfunktion für robuste UTF-8 Dekodierung
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

// Teste verschiedene Umlaute-Kodierungen
$testStrings = [
    'Wichtige Mails',
    'Müll',
    'Überwachung',
    'Tschüss',
    'Grüße',
    'Größe',
    'Anhänge',
    'Entwürfe',
    'Gelöschte Elemente',
    'Öffentliche Ordner',
    'Spaß',
    'Weiß',
    'Heiß',
    'Süß',
    'Größte E-Mails',
    'Küche',
    'Büro',
    'Straße',
    'Prüfung',
    'Lösung',
    'Björn',
    'Åse',
    'Nöel',
    'François',
    'José',
    'Zürich',
    'Köln',
    'München',
    'Düsseldorf',
    'Göteborg',
    'Århus'
];

echo "<!DOCTYPE html>\n<html>\n<head>\n";
echo "<meta charset=\"UTF-8\">\n";
echo "<title>Umlaute-Test</title>\n";
echo "</head>\n<body>\n";
echo "<h1>Test der Umlaute-Dekodierung</h1>\n";
echo "<p>Diese Seite testet die robuste UTF-8-Dekodierung für Ordner- und Mail-Namen.</p>\n";
echo "<table border=\"1\" style=\"border-collapse: collapse;\">\n";
echo "<tr><th>Original</th><th>Dekodiert</th><th>JSON</th></tr>\n";

foreach ($testStrings as $test) {
    $decoded = safe_imap_utf8($test);
    $json = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    
    echo "<tr>\n";
    echo "<td>" . htmlspecialchars($test) . "</td>\n";
    echo "<td>" . htmlspecialchars($decoded) . "</td>\n";
    echo "<td>" . htmlspecialchars($json) . "</td>\n";
    echo "</tr>\n";
}

echo "</table>\n";
echo "<p><strong>Hinweis:</strong> Bei korrekter Implementierung sollten alle Umlaute korrekt angezeigt werden.</p>\n";
echo "</body>\n</html>\n";
?>
