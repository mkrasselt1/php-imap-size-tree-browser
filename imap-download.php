<?php
$server   = $_POST['server'] ?? '';
$port     = $_POST['port'] ?? '993';
$user     = $_POST['user'] ?? '';
$pass     = $_POST['pass'] ?? '';
$ssl      = ($_POST['ssl'] ?? 'false') === 'true';
$folder   = $_POST['folder'] ?? '';
$uid      = $_POST['uid'] ?? '';
$partNum  = $_POST['partNum'] ?? '';
$filename = $_POST['filename'] ?? 'attachment.bin';

if (!$server || !$user || !$pass || !$folder || !$uid || !$partNum) {
    http_response_code(400);
    echo "Fehlende Parameter";
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";
$inbox = @imap_open($mailbox, $user, $pass);
if (!$inbox) {
    http_response_code(500);
    echo "IMAP-Verbindung fehlgeschlagen";
    exit;
}

@imap_reopen($inbox, $folder);
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