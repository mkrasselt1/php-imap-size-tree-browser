<?php
set_time_limit(60);
ini_set('max_execution_time', 60);
ini_set('memory_limit', '256M');

header('Content-Type: application/json');

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
            return [
                'full' => $folder,
                'name' => $shortName
            ];
        }, $folders)
    ]);
    
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
    ]);
    
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
    
    echo json_encode($tree);
    
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

    // Mails in Batches verarbeiten
    $numMsgs = min($check->Nmsgs, $maxMails);
    
    for ($i = 1; $i <= $numMsgs; $i++) {
        $header = imap_headerinfo($inbox, $i);
        if (!$header) continue;
        
        $size = $header->Size ?? 0;
        $totalSize += $size;
        
        // Nur große Mails einzeln anzeigen (> 1MB)
        if ($size > 1048576) {
            $from = isset($header->fromaddress) ? imap_utf8($header->fromaddress) : '';
            $subject = isset($header->subject) ? imap_utf8($header->subject) : '';
            $date = isset($header->date) ? $header->date : '';
            $uid = imap_uid($inbox, $i);
            
            $children[] = [
                'name' => $subject ?: 'Kein Betreff',
                'type' => 'mail',
                'size' => $size,
                'from' => $from,
                'date' => $date,
                'uid' => $uid,
                'folderFull' => $folderFull,
                'folder' => $shortName
            ];
        }
    }
    
    // Kleine Mails zusammenfassen
    $smallMailsCount = $numMsgs - count($children);
    if ($smallMailsCount > 0) {
        $smallMailsSize = $totalSize - array_sum(array_column($children, 'size'));
        $children[] = [
            'name' => "Weitere E-Mails ($smallMailsCount)",
            'type' => 'other-mails',
            'size' => $smallMailsSize,
            'count' => $smallMailsCount,
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
