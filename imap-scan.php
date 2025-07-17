<?php
header('Content-Type: application/json');

$server = $_POST['server'] ?? '';
$port   = $_POST['port'] ?? '993';
$user   = $_POST['user'] ?? '';
$pass   = $_POST['pass'] ?? '';
$ssl    = ($_POST['ssl'] ?? 'false') === 'true';

if (!$server || !$user || !$pass) {
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende Zugangsdaten']);
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";

$inbox = @imap_open($mailbox, $user, $pass);
if (!$inbox) {
    echo json_encode(['error' => 'IMAP-Verbindung fehlgeschlagen', 'details' => imap_last_error()]);
    exit;
}

$folders = imap_list($inbox, $mailbox, '*');

function getFolderTree($inbox, $mailbox, $folderFull) {
    $shortName = str_replace($mailbox, '', $folderFull);

    // Ordner öffnen
    $box = @imap_reopen($inbox, $folderFull);
    if (!$box) return [
        'name' => $shortName,
        'size' => 0,
        'children' => [],
        'biggestMails' => []
    ];

    $numMessages = imap_num_msg($inbox);
    $totalSize = 0;
    $mails = [];

    // Alle Mails durchgehen und Größe sammeln
    for ($i = 1; $i <= $numMessages; $i++) {
        $overview = imap_fetch_overview($inbox, $i);
        if ($overview && isset($overview[0]->size)) {
            $mail = [
                'subject' => $overview[0]->subject ?? '(kein Betreff)',
                'from' => $overview[0]->from ?? '',
                'date' => $overview[0]->date ?? '',
                'size' => $overview[0]->size,
                'uid' => $overview[0]->uid ?? $i
            ];
            $mails[] = $mail;
            $totalSize += $overview[0]->size;
        }
    }

    // Die 10 größten Mails
    usort($mails, function($a, $b) { return $b['size'] - $a['size']; });
    $biggestMails = array_slice($mails, 0, 10);

    // Subfolder suchen
    $subfolders = imap_list($inbox, $folderFull, '*');
    $children = [];
    if ($subfolders) {
        foreach ($subfolders as $sub) {
            if ($sub !== $folderFull) {
                $children[] = getFolderTree($inbox, $mailbox, $sub);
            }
        }
    }

    return [
        'name' => $shortName,
        'size' => $totalSize,
        'children' => $children,
        'biggestMails' => $biggestMails
    ];
}

$tree = [
    'name' => 'Root',
    'children' => []
];

if ($folders) {
    foreach ($folders as $fullName) {
        $tree['children'][] = getFolderTree($inbox, $mailbox, $fullName);
    }
}

imap_close($inbox);

echo json_encode($tree);
