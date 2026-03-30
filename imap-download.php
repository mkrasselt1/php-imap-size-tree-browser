<?php
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';

csrf_enforce();
altcha_enforce();

$params = extract_imap_params();
$mailbox = $params['mailbox'];
$user = $params['user'];
$pass = $params['pass'];

$folder   = $_POST['folder'] ?? '';
$uid      = $_POST['uid'] ?? '';
$partNum  = $_POST['partNum'] ?? '';
$filename = sanitize_filename($_POST['filename'] ?? 'attachment.bin');

if (!$folder || !$uid) {
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende Parameter']);
    exit;
}

$inbox = @imap_open($folder, $user, $pass);
if (!$inbox) {
    echo json_encode(['error' => 'IMAP-Verbindung fehlgeschlagen', 'details' => imap_last_error()]);
    exit;
}

$structure = imap_fetchstructure($inbox, $uid, FT_UID);
$part = $structure->parts[$partNum - 1] ?? null;
if (!$part) {
    http_response_code(404);
    imap_close($inbox);
    echo "Attachment nicht gefunden";
    exit;
}
$body = imap_fetchbody($inbox, $uid, $partNum, FT_UID);
if ($part->encoding == 3) { // BASE64
    $body = base64_decode($body);
} elseif ($part->encoding == 4) { // QUOTED-PRINTABLE
    $body = quoted_printable_decode($body);
}

imap_close($inbox);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $body;
