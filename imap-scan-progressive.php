<?php
set_time_limit(60);
ini_set('max_execution_time', 60);
ini_set('memory_limit', '256M');

header('Content-Type: application/json');

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

$server = $_POST['server'] ?? '';
$port   = $_POST['port'] ?? '993';
$user   = $_POST['user'] ?? '';
$pass   = $_POST['pass'] ?? '';
$ssl = in_array(strtolower($_POST['ssl'] ?? 'false'), ['true', 'on'], true);

// Progressive Scan Parameter
$action = $_POST['action'] ?? 'init';
$folderIndex = (int)($_POST['folderIndex'] ?? 0);
$cacheKey = $_POST['cacheKey'] ?? '';

if (!$server || !$user || !$pass) {
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende Zugangsdaten']);
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";

// IMAP-Verbindung mit Timeouts
imap_timeout(IMAP_OPENTIMEOUT, 15);
imap_timeout(IMAP_READTIMEOUT, 15);
imap_timeout(IMAP_WRITETIMEOUT, 15);
imap_timeout(IMAP_CLOSETIMEOUT, 15);

$inbox = @imap_open($mailbox, $user, $pass);
if (!$inbox) {
    echo json_encode(['error' => 'IMAP-Verbindung fehlgeschlagen', 'details' => imap_last_error()]);
    exit;
}

// Cache-Verzeichnis erstellen
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

if ($action === 'init') {
    // Schritt 1: Ordnerliste abrufen
    $folders = imap_list($inbox, $mailbox, '*');
    if (!$folders) {
        echo json_encode(['error' => 'Keine Ordner gefunden']);
        exit;
    }
    
    // Cache-Key generieren
    $cacheKey = 'scan_' . md5($server . $user . time());
    
    // Ordnerliste in Cache speichern
    $cacheData = [
        'folders' => $folders,
        'mailbox' => $mailbox,
        'totalFolders' => count($folders),
        'scannedFolders' => 0,
        'results' => [],
        'startTime' => time()
    ];
    
    file_put_contents($cacheDir . '/' . $cacheKey . '.json', json_encode($cacheData));
    
    echo json_encode([
        'status' => 'initialized',
        'cacheKey' => $cacheKey,
        'totalFolders' => count($folders),
        'folders' => array_map(function($folder) use ($mailbox) {
            $shortName = str_replace($mailbox, '', $folder);
            // Ordnername korrekt dekodieren für Umlaute
            $shortName = safe_imap_utf8($shortName);
            return [
                'full' => $folder,
                'name' => $shortName
            ];
        }, $folders)
    ], JSON_UNESCAPED_UNICODE);
    
} elseif ($action === 'scan') {
    // Schritt 2: Einzelnen Ordner scannen
    if (!$cacheKey) {
        echo json_encode(['error' => 'Cache-Key fehlt']);
        exit;
    }
    
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    if (!file_exists($cacheFile)) {
        echo json_encode(['error' => 'Cache-Datei nicht gefunden']);
        exit;
    }
    
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    
    if ($folderIndex >= count($cacheData['folders'])) {
        echo json_encode(['error' => 'Ungültiger Ordner-Index']);
        exit;
    }
    
    $folderFull = $cacheData['folders'][$folderIndex];
    $folderResult = scanSingleFolder($inbox, $cacheData['mailbox'], $folderFull);
    
    // Ergebnis in Cache speichern
    $cacheData['results'][] = $folderResult;
    $cacheData['scannedFolders']++;
    
    file_put_contents($cacheFile, json_encode($cacheData));
    
    echo json_encode([
        'status' => 'folder_scanned',
        'folder' => $folderResult,
        'progress' => [
            'current' => $cacheData['scannedFolders'],
            'total' => $cacheData['totalFolders'],
            'percent' => round(($cacheData['scannedFolders'] / $cacheData['totalFolders']) * 100, 1)
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} elseif ($action === 'finalize') {
    // Schritt 3: Ergebnisse zusammenfassen
    if (!$cacheKey) {
        echo json_encode(['error' => 'Cache-Key fehlt']);
        exit;
    }
    
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    if (!file_exists($cacheFile)) {
        echo json_encode(['error' => 'Cache-Datei nicht gefunden']);
        exit;
    }
    
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    
    // Hierarchie aufbauen
    $tree = buildFolderHierarchy($cacheData['results'], $cacheData['mailbox']);
    
    // Cache-Datei löschen
    unlink($cacheFile);
    
    echo json_encode($tree, JSON_UNESCAPED_UNICODE);
    
} else {
    echo json_encode(['error' => 'Unbekannte Aktion']);
}

imap_close($inbox);

function scanSingleFolder($inbox, $mailbox, $folderFull) {
    $delimiter = '.';
    $info = imap_getmailboxes($inbox, $mailbox, '*');
    if ($info && isset($info[0]->delimiter)) {
        $delimiter = $info[0]->delimiter;
    }
    
    $shortName = str_replace($mailbox, '', $folderFull);
    if (strpos($folderFull, $mailbox) === 0) {
        $shortName = substr($folderFull, strlen($mailbox));
    }
    if (strpos($shortName, $delimiter) !== false) {
        $parts = explode($delimiter, $shortName);
        $shortName = end($parts);
    }
    
    // Ordnername korrekt dekodieren für Umlaute
    $shortName = safe_imap_utf8($shortName);

    // Ordner öffnen
    $box = @imap_reopen($inbox, $folderFull);
    if (!$box) {
        return [
            'name' => $shortName,
            'folderFull' => $folderFull,
            'type' => 'folder',
            'size' => 0,
            'childrenTotalSize' => 0,
            'children' => [],
            'error' => 'Ordner konnte nicht geöffnet werden'
        ];
    }

    $check = imap_check($inbox);
    if (!$check || $check->Nmsgs == 0) {
        return [
            'name' => $shortName,
            'folderFull' => $folderFull,
            'type' => 'folder',
            'size' => 0,
            'childrenTotalSize' => 0,
            'children' => []
        ];
    }

    $children = [];
    $totalSize = 0;
    $maxMails = 300; // Limite pro Ordner für Performance
    $mails = []; // Array für alle Mails sammeln

    // Mails in Batches verarbeiten
    $numMsgs = min($check->Nmsgs, $maxMails);
    
    for ($i = 1; $i <= $numMsgs; $i++) {
        $header = imap_headerinfo($inbox, $i);
        if (!$header) continue;
        
        $size = $header->Size ?? 0;
        $totalSize += $size;
        
        // UID für diese Mail abrufen
        $uid = imap_uid($inbox, $i);
        if (!$uid) continue;
        
        // Sicherstellen, dass Subject richtig kodiert ist
        $subject = '';
        if (isset($header->subject)) {
            $subject = safe_imap_utf8($header->subject);
            // Zusätzliche Bereinigung für kaputte Encoding
            if (!$subject || trim($subject) === '') {
                $subject = isset($header->Subject) ? safe_imap_utf8($header->Subject) : '';
            }
        }
        
        $from = '';
        if (isset($header->fromaddress)) {
            $from = safe_imap_utf8($header->fromaddress);
        } elseif (isset($header->from)) {
            $from = safe_imap_utf8($header->from[0]->mailbox . '@' . $header->from[0]->host);
        }
        
        $date = '';
        if (isset($header->date)) {
            $date = $header->date;
        } elseif (isset($header->Date)) {
            $date = $header->Date;
        }
        
        // Alle Mails sammeln (wie im normalen Scan)
        $mails[] = [
            'name' => $subject ?: 'Kein Betreff',
            'type' => 'mail',
            'size' => $size,
            'from' => $from,
            'date' => $date,
            'uid' => $uid,
            'folderFull' => $folderFull,
            'folder' => $shortName,
            'messageNumber' => $i, // Für Debugging
            'rawSubject' => $header->subject ?? '', // Für Debugging
        ];
    }
    
    // Die 10 größten Mails als eigene Knoten (wie im normalen Scan)
    usort($mails, function($a, $b) { return $b['size'] - $a['size']; });
    $biggestMails = array_slice($mails, 0, 10);
    
    // Restliche Mails zusammenfassen
    $otherMails = array_slice($mails, 10);
    $otherSize = 0;
    foreach ($otherMails as $mail) {
        $otherSize += $mail['size'];
    }
    
    // Größte Mails als eigene Knoten hinzufügen
    foreach ($biggestMails as $mail) {
        $children[] = $mail;
    }
    
    // Restliche Mails zusammenfassen
    if ($otherSize > 0) {
        $children[] = [
            'name' => "Weitere E-Mails (" . count($otherMails) . ")",
            'type' => 'other-mails',
            'size' => $otherSize,
            'count' => count($otherMails),
            'folderFull' => $folderFull,
            'folder' => $shortName
        ];
    }
    
    // Wenn mehr Mails vorhanden sind, als verarbeitet wurden
    if ($check->Nmsgs > $maxMails) {
        $remainingMails = $check->Nmsgs - $maxMails;
        $children[] = [
            'name' => "Nicht gescannte E-Mails ($remainingMails)",
            'type' => 'other-mails',
            'size' => 0,
            'count' => $remainingMails,
            'folderFull' => $folderFull,
            'folder' => $shortName
        ];
    }

    return [
        'name' => $shortName,
        'folderFull' => $folderFull,
        'type' => 'folder',
        'size' => $totalSize,
        'childrenTotalSize' => $totalSize,
        'children' => $children,
        'totalMails' => $check->Nmsgs,
        'scannedMails' => $numMsgs
    ];
}

function buildFolderHierarchy($folderResults, $mailbox) {
    $delimiter = '.';
    $tree = [
        'name' => 'E-Mail-Postfach',
        'type' => 'root',
        'children' => [],
        'childrenTotalSize' => 0
    ];
    
    $totalSize = 0;
    
    foreach ($folderResults as $folder) {
        $totalSize += $folder['size'];
        $tree['children'][] = $folder;
    }
    
    $tree['childrenTotalSize'] = $totalSize;
    
    return $tree;
}
?>
