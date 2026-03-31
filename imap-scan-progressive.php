<?php
set_time_limit(60);
ini_set('max_execution_time', 60);
ini_set('memory_limit', '256M');

header('Content-Type: application/json');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';

csrf_enforce();
altcha_enforce();

// Hilfsfunktion für robuste UTF-8 Dekodierung
function safe_imap_utf8($text) {
    if (empty($text)) return $text;

    // IMAP Modified UTF-7 Ordnernamen dekodieren (z.B. "Entw&APw-rfe" -> "Entwürfe")
    if (strpos($text, '&') !== false && strpos($text, '-') !== false) {
        $decoded = @mb_convert_encoding($text, 'UTF-8', 'UTF7-IMAP');
        if ($decoded !== false && !empty(trim($decoded))) {
            return $decoded;
        }
    }

    // MIME-Header dekodieren (z.B. "=?UTF-8?B?..." Subjects)
    $decoded = @imap_utf8($text);
    if ($decoded !== false && !empty(trim($decoded))) {
        return $decoded;
    }

    if (function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($text);
        if ($decoded !== false && !empty(trim($decoded))) {
            return $decoded;
        }
    }

    return $text;
}

$params = extract_imap_params();
$mailbox = $params['mailbox'];
$user = $params['user'];
$pass = $params['pass'];

// Progressive Scan Parameter
$action = $_POST['action'] ?? 'init';
$folderIndex = (int)($_POST['folderIndex'] ?? 0);
$cacheKey = $_POST['cacheKey'] ?? '';

// Validate cacheKey format
if ($cacheKey !== '' && !preg_match('/^scan_[a-f0-9]{32}$/', $cacheKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger Cache-Key']);
    exit;
}

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

// Cache cleanup: remove files older than 1 hour
$cacheFiles = glob($cacheDir . '/scan_*.json');
if ($cacheFiles) {
    foreach ($cacheFiles as $file) {
        if (filemtime($file) < time() - 3600) {
            @unlink($file);
        }
    }
}

if ($action === 'init') {
    // Schritt 1: Ordnerliste abrufen
    $folders = imap_list($inbox, $mailbox, '*');
    if (!$folders) {
        imap_close($inbox);
        echo json_encode(['error' => 'Keine Ordner gefunden']);
        exit;
    }

    // Cache-Key generieren
    $cacheKey = 'scan_' . md5($params['server'] . $user . time());

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

    imap_close($inbox);
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
        imap_close($inbox);
        echo json_encode(['error' => 'Cache-Key fehlt']);
        exit;
    }

    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    if (!file_exists($cacheFile)) {
        imap_close($inbox);
        echo json_encode(['error' => 'Cache-Datei nicht gefunden']);
        exit;
    }

    $cacheData = json_decode(file_get_contents($cacheFile), true);

    if ($folderIndex >= count($cacheData['folders'])) {
        imap_close($inbox);
        echo json_encode(['error' => 'Ungültiger Ordner-Index']);
        exit;
    }

    $folderFull = $cacheData['folders'][$folderIndex];
    $folderResult = scanSingleFolder($inbox, $cacheData['mailbox'], $folderFull);

    // Ergebnis in Cache speichern
    $cacheData['results'][] = $folderResult;
    $cacheData['scannedFolders']++;

    file_put_contents($cacheFile, json_encode($cacheData));

    imap_close($inbox);
    echo json_encode([
        'status' => 'folder_scanned',
        'folder' => $folderResult,
        'progress' => [
            'current' => $cacheData['scannedFolders'],
            'total' => $cacheData['totalFolders'],
            'percent' => round(($cacheData['scannedFolders'] / $cacheData['totalFolders']) * 100, 1)
        ]
    ], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'extended-scan') {
    // Schritt 2b: Erweiterten Scan für spezifischen Ordner (braucht keinen cacheKey)
    $folderFullPath = $_POST['folderFullPath'] ?? '';
    $startIndex = (int)($_POST['startIndex'] ?? 0);
    $batchSize = (int)($_POST['batchSize'] ?? 500);

    if (!$folderFullPath) {
        imap_close($inbox);
        echo json_encode(['error' => 'Ordner-Pfad fehlt']);
        exit;
    }

    // Ordner öffnen
    $box = @imap_reopen($inbox, $folderFullPath);
    if (!$box) {
        imap_close($inbox);
        echo json_encode(['error' => 'Ordner konnte nicht geöffnet werden']);
        exit;
    }

    $check = imap_check($inbox);
    if (!$check || $check->Nmsgs == 0) {
        imap_close($inbox);
        echo json_encode(['error' => 'Ordner ist leer']);
        exit;
    }

    $endIndex = min($startIndex + $batchSize, $check->Nmsgs);
    $mails = [];
    $totalSize = 0;

    for ($i = $startIndex + 1; $i <= $endIndex; $i++) {
        $header = imap_headerinfo($inbox, $i);
        if (!$header) continue;

        $size = $header->Size ?? 0;
        $totalSize += $size;

        $uid = imap_uid($inbox, $i);
        if (!$uid) continue;

        $subject = '';
        if (isset($header->subject)) {
            $subject = safe_imap_utf8($header->subject);
        }

        $mails[] = [
            'name' => $subject ?: 'Kein Betreff',
            'type' => 'mail',
            'size' => $size,
            'uid' => $uid,
            'messageNumber' => $i
        ];
    }

    // Nur die größten Mails zurückgeben
    usort($mails, function($a, $b) { return $b['size'] - $a['size']; });
    $bigMails = array_slice($mails, 0, 20); // Top 20 aus diesem Batch

    imap_close($inbox);
    echo json_encode([
        'status' => 'extended-scan-complete',
        'mails' => $bigMails,
        'totalSize' => $totalSize,
        'scannedRange' => [$startIndex + 1, $endIndex],
        'totalMails' => $check->Nmsgs,
        'hasMore' => $endIndex < $check->Nmsgs,
        'nextStartIndex' => $endIndex
    ], JSON_UNESCAPED_UNICODE);

} elseif ($action === 'finalize') {
    // Schritt 3: Ergebnisse zusammenfassen
    if (!$cacheKey) {
        imap_close($inbox);
        echo json_encode(['error' => 'Cache-Key fehlt']);
        exit;
    }

    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    if (!file_exists($cacheFile)) {
        imap_close($inbox);
        echo json_encode(['error' => 'Cache-Datei nicht gefunden']);
        exit;
    }

    $cacheData = json_decode(file_get_contents($cacheFile), true);

    // Hierarchie aufbauen
    $tree = buildFolderHierarchy($cacheData['results'], $cacheData['mailbox']);

    // Cache-Datei löschen
    unlink($cacheFile);

    imap_close($inbox);
    echo json_encode($tree, JSON_UNESCAPED_UNICODE);

} else {
    imap_close($inbox);
    echo json_encode(['error' => 'Unbekannte Aktion']);
}

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
    $maxMails = 1000; // Erhöht von 300 auf 1000 für bessere Abdeckung
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
        } elseif (isset($header->from) && isset($header->from[0])) {
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
            'isTrash' => strpos(strtolower($folderFull), 'trash') !== false ||
                        strpos(strtolower($folderFull), 'deleted') !== false ||
                        strpos(strtolower($folderFull), 'papierkorb') !== false,
            'debug' => [
                'folderFull' => $folderFull,
                'shortName' => $shortName,
                'msgNum' => $i,
                'uid' => $uid
            ]
        ];
    }

    // Alle Mails nach Größe sortieren — alle einzeln anzeigen
    usort($mails, function($a, $b) { return $b['size'] - $a['size']; });
    foreach ($mails as $mail) {
        $children[] = $mail;
    }

    // Wenn mehr Mails vorhanden sind, als verarbeitet wurden
    if ($check->Nmsgs > $maxMails) {
        $remainingMails = $check->Nmsgs - $maxMails;
        $remainingPercent = round(($remainingMails / $check->Nmsgs) * 100, 1);

        $children[] = [
            'name' => "Nicht gescannte E-Mails: $remainingMails ({$remainingPercent}%)",
            'type' => 'other-mails',
            'size' => 0,
            'count' => $remainingMails,
            'folderFull' => $folderFull,
            'folder' => $shortName,
            'warning' => true,
            'details' => "Von {$check->Nmsgs} E-Mails wurden nur $maxMails gescannt. Das kann zu einem falschen Bild führen."
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
