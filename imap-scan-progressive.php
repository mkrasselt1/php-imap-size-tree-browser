<?php
set_time_limit(120);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';

csrf_enforce();
altcha_enforce();

$params = get_imap_params();
$mailbox = $params['mailbox'];

$action      = (string) ($_POST['action'] ?? 'init');
$folderIndex = max(0, (int) ($_POST['folderIndex'] ?? 0));
$cacheKey    = (string) ($_POST['cacheKey'] ?? '');

if ($cacheKey !== '' && !preg_match('/^scan_[a-f0-9]{32}$/', $cacheKey)) {
    json_fail('Ungültiger Cache-Key');
}
// Ein Scan gehört zu genau einer Sitzung – fremde Cache-Keys sind wertlos
if ($cacheKey !== '' && empty($_SESSION['scans'][$cacheKey])) {
    json_fail('Scan-Session nicht gefunden. Bitte neu starten.', 404);
}

// Zwischenergebnisse (mit Betreffzeilen!) liegen außerhalb des Webroots
$cacheDir = imapsize_data_dir('scans');

if ($action === 'init') {
    // Aufräumen: Scans älter als 1 Stunde
    foreach (glob($cacheDir . '/scan_*', GLOB_ONLYDIR) ?: [] as $oldDir) {
        if (@filemtime($oldDir) < time() - 3600) {
            array_map('unlink', glob($oldDir . '/*.json') ?: []);
            @rmdir($oldDir);
        }
    }
    $cacheKey = 'scan_' . bin2hex(random_bytes(16));
    $_SESSION['scans'][$cacheKey] = time();
    // Nur die letzten 5 Scans je Sitzung merken
    if (count($_SESSION['scans']) > 5) {
        $_SESSION['scans'] = array_slice($_SESSION['scans'], -5, null, true);
    }
}
session_write_close(); // lange IMAP-Aufrufe sollen die Sitzung nicht blockieren

imapsize_timeouts(30);
$inbox = @imap_open($mailbox, $params['user'], $params['pass'], OP_READONLY);
if (!$inbox) {
    json_fail('IMAP-Verbindung fehlgeschlagen', 200, ['details' => imap_last_error()]);
}

/** Lesbarer Ordnername inkl. Pfad, z. B. "Kunden / Alt" – im flachen Ergebnis sonst mehrdeutig. */
function display_name(string $folderFull, string $mailbox, string $delimiter): string {
    $relative = substr($folderFull, strlen($mailbox));
    $parts = array_map('safe_imap_utf8', explode($delimiter ?: '.', $relative));
    return implode(' / ', $parts);
}

if ($action === 'init') {
    $boxes = imap_getmailboxes($inbox, $mailbox, '*');
    imap_close($inbox);
    if (!$boxes) {
        json_fail('Keine Ordner gefunden', 200);
    }

    $folders = [];
    foreach ($boxes as $b) {
        if ($b->attributes & LATT_NOSELECT) continue;
        $folders[] = ['full' => $b->name, 'delimiter' => $b->delimiter ?: '.'];
    }

    $scanDir = $cacheDir . '/' . $cacheKey;
    @mkdir($scanDir, 0700, true);
    file_put_contents($scanDir . '/meta.json', json_encode([
        'folders'      => $folders,
        'mailbox'      => $mailbox,
        'totalFolders' => count($folders),
        'startTime'    => time(),
    ]), LOCK_EX);

    json_out([
        'status'       => 'initialized',
        'cacheKey'     => $cacheKey,
        'totalFolders' => count($folders),
        'folders'      => array_map(function ($f) use ($mailbox) {
            return ['full' => $f['full'], 'name' => display_name($f['full'], $mailbox, $f['delimiter'])];
        }, $folders),
    ]);
    exit;
}

/** Meta-Daten eines Scans laden (Mailbox muss zur aktuellen Anmeldung passen). */
function load_meta(string $cacheDir, string $cacheKey, string $mailbox): array {
    $meta = json_decode((string) @file_get_contents($cacheDir . '/' . $cacheKey . '/meta.json'), true);
    if (!is_array($meta) || !isset($meta['folders']) || ($meta['mailbox'] ?? '') !== $mailbox) {
        json_fail('Scan-Session nicht gefunden. Bitte neu starten.', 200);
    }
    return $meta;
}

if ($action === 'scan') {
    if ($cacheKey === '') {
        imap_close($inbox);
        json_fail('Cache-Key fehlt');
    }
    $meta = load_meta($cacheDir, $cacheKey, $mailbox);
    if ($folderIndex >= count($meta['folders'])) {
        imap_close($inbox);
        json_fail('Ungültiger Ordner-Index');
    }

    $folder = $meta['folders'][$folderIndex];
    $folderResult = scanSingleFolder($inbox, $mailbox, $folder['full'], $folder['delimiter']);
    imap_close($inbox);

    $scanDir = $cacheDir . '/' . $cacheKey;
    file_put_contents($scanDir . '/folder_' . $folderIndex . '.json', json_encode($folderResult, JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    $scanned = count(glob($scanDir . '/folder_*.json') ?: []);

    json_out([
        'status'   => 'folder_scanned',
        'folder'   => $folderResult,
        'progress' => [
            'current' => $scanned,
            'total'   => $meta['totalFolders'],
            'percent' => $meta['totalFolders'] ? round(($scanned / $meta['totalFolders']) * 100, 1) : 100,
        ],
    ]);
    exit;
}

if ($action === 'extended-scan') {
    $folderFullPath = validate_folder($_POST['folderFullPath'] ?? '', $mailbox);
    $startIndex = max(0, (int) ($_POST['startIndex'] ?? 0));
    $batchSize = min(1000, max(1, (int) ($_POST['batchSize'] ?? 500)));

    if (!@imap_reopen($inbox, $folderFullPath, OP_READONLY)) {
        imap_close($inbox);
        json_fail('Ordner konnte nicht geöffnet werden', 200);
    }
    $total = imap_num_msg($inbox);
    if ($total === 0) {
        imap_close($inbox);
        json_fail('Ordner ist leer', 200);
    }

    $endIndex = min($startIndex + $batchSize, $total);
    $mails = [];
    $totalSize = 0;
    if ($startIndex < $endIndex) {
        // Ein Overview-Aufruf für den ganzen Block statt zwei Aufrufen pro Mail
        foreach (@imap_fetch_overview($inbox, ($startIndex + 1) . ':' . $endIndex) ?: [] as $o) {
            $size = (int) ($o->size ?? 0);
            $totalSize += $size;
            if (empty($o->uid)) continue;
            $mails[] = [
                'name'          => isset($o->subject) ? (safe_imap_utf8($o->subject) ?: 'Kein Betreff') : 'Kein Betreff',
                'type'          => 'mail',
                'size'          => $size,
                'uid'           => (int) $o->uid,
                'messageNumber' => (int) ($o->msgno ?? 0),
                'folderFull'    => $folderFullPath,
            ];
        }
    }
    imap_close($inbox);

    usort($mails, function ($a, $b) { return $b['size'] <=> $a['size']; });

    json_out([
        'status'         => 'extended-scan-complete',
        'mails'          => array_slice($mails, 0, 20), // Top 20 aus diesem Block
        'totalSize'      => $totalSize,
        'scannedRange'   => [$startIndex + 1, $endIndex],
        'totalMails'     => $total,
        'hasMore'        => $endIndex < $total,
        'nextStartIndex' => $endIndex,
    ]);
    exit;
}

if ($action === 'finalize') {
    imap_close($inbox);
    if ($cacheKey === '') {
        json_fail('Cache-Key fehlt');
    }
    load_meta($cacheDir, $cacheKey, $mailbox);

    $scanDir = $cacheDir . '/' . $cacheKey;
    $files = glob($scanDir . '/folder_*.json') ?: [];
    natsort($files);
    $results = [];
    foreach ($files as $ff) {
        $data = json_decode((string) file_get_contents($ff), true);
        if ($data) $results[] = $data;
    }

    array_map('unlink', glob($scanDir . '/*.json') ?: []);
    @rmdir($scanDir);

    $total = 0;
    foreach ($results as $r) $total += (int) ($r['size'] ?? 0);
    usort($results, function ($a, $b) { return ($b['size'] ?? 0) <=> ($a['size'] ?? 0); });

    json_out([
        'name'              => 'E-Mail-Postfach',
        'type'              => 'root',
        'children'          => $results,
        'childrenTotalSize' => $total,
    ]);
    exit;
}

imap_close($inbox);
json_fail('Unbekannte Aktion');

function scanSingleFolder($inbox, string $mailbox, string $folderFull, string $delimiter): array {
    $base = [
        'name'       => display_name($folderFull, $mailbox, $delimiter),
        'folderFull' => $folderFull,
        'type'       => 'folder',
        'size'       => 0,
        'childrenTotalSize' => 0,
        'children'   => [],
    ];

    if (!@imap_reopen($inbox, $folderFull, OP_READONLY)) {
        return $base + ['error' => 'Ordner konnte nicht geöffnet werden'];
    }
    $count = imap_num_msg($inbox);
    if ($count === 0) {
        return $base;
    }

    // Maximal 500 Mails pro Ordner in einem Aufruf; größere Ordner per "extended-scan"
    $numMsgs = min($count, 500);
    $mails = [];
    $totalSize = 0;
    foreach (@imap_fetch_overview($inbox, "1:{$numMsgs}") ?: [] as $msg) {
        $size = (int) ($msg->size ?? 0);
        $totalSize += $size;
        if (empty($msg->uid)) continue;
        $mails[] = [
            'name'       => isset($msg->subject) ? (safe_imap_utf8($msg->subject) ?: 'Kein Betreff') : 'Kein Betreff',
            'type'       => 'mail',
            'size'       => $size,
            'uid'        => (int) $msg->uid,
            'folderFull' => $folderFull,
        ];
    }
    usort($mails, function ($a, $b) { return $b['size'] <=> $a['size']; });

    $result = array_merge($base, [
        'size'              => $totalSize,
        'childrenTotalSize' => $totalSize,
        'children'          => $mails,
        'totalMails'        => $count,
        'scannedMails'      => $numMsgs,
    ]);
    if ($count > $numMsgs) {
        // Rest des Ordners als geschätzter Block anzeigen – sonst erscheinen große Ordner
        // in der Treemap viel zu klein. Klick darauf bietet die erweiterte Analyse an.
        $rest = $count - $numMsgs;
        $estimate = $numMsgs > 0 ? (int) round($totalSize / $numMsgs * $rest) : 0;
        $result['children'][] = [
            'name'       => "≈ {$rest} weitere Mails (geschätzt)",
            'type'       => 'other-mails',
            'size'       => $estimate,
            'count'      => $rest,
            'warning'    => true,
            'details'    => "Nur die ersten {$numMsgs} von {$count} Mails wurden einzeln analysiert; die Größe des Rests ist geschätzt.",
            'folderFull' => $folderFull,
        ];
        $result['size'] += $estimate;
        $result['childrenTotalSize'] += $estimate;
    }
    return $result;
}
