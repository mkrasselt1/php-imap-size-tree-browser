<?php
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

$server   = $_POST['server'] ?? '';
$port     = $_POST['port'] ?? '993';
$user     = $_POST['user'] ?? '';
$pass     = $_POST['pass'] ?? '';
$ssl      = ($_POST['ssl'] ?? 'false') === 'true';
$folder   = $_POST['folder'] ?? '';
$uid      = $_POST['uid'] ?? '';
$partNum  = $_POST['partNum'] ?? '';
$filename = $_POST['filename'] ?? 'attachment.bin';

if (!$server || !$user || !$pass || !$folder || !$uid) {
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende Parameter']);
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";
$inbox = @imap_open($folder, $user, $pass);
if (!$inbox) {
    echo json_encode(['error' => 'IMAP-Verbindung fehlgeschlagen', 'details' => imap_last_error()]);
    exit;
}

$structure = imap_fetchstructure($inbox, $uid, FT_UID);
$part = $structure->parts[$partNum - 1] ?? null;
if (!$part) {
    http_response_code(404);
    echo "Attachment nicht gefunden";
    exit;
}
$body = imap_fetchbody($inbox, $uid, $partNum, FT_UID);
if ($part->encoding == 3) { // BASE64
    $body = base64_decode($body);
} elseif ($part->encoding == 4) { // QUOTED-PRINTABLE
    $body = quoted_printable_decode($body);
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $body;
imap_close($inbox);