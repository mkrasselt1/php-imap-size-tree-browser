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
$result = [];

function getFolderSize($inbox, $mailbox, $folderFull) {
    $box = @imap_reopen($inbox, $folderFull);
    if (!$box) return 0;

    $numMessages = imap_num_msg($inbox);
    $totalSize = 0;

    for ($i = 1; $i <= $numMessages; $i++) {
        $totalSize += imap_fetch_overview($inbox, $i)[0]->size ?? 0;
    }

    return $totalSize;
}

foreach ($folders as $fullName) {
    $shortName = str_replace($mailbox, '', $fullName);
    $size = getFolderSize($inbox, $mailbox, $fullName);

    $result[] = [
        'name' => $shortName,
        'size' => $size
    ];
}

imap_close($inbox);

echo json_encode([
    'name' => 'Root',
    'children' => $result
]);
