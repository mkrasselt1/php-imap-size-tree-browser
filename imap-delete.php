<?php
header('Content-Type: application/json');
$server = $_POST['server'] ?? '';
$port   = $_POST['port'] ?? '993';
$user   = $_POST['user'] ?? '';
$pass   = $_POST['pass'] ?? '';
$ssl    = ($_POST['ssl'] ?? 'false') === 'true';
$folder = $_POST['folder'] ?? '';
$uid    = $_POST['uid'] ?? '';

if (!$server || !$user || !$pass || !$folder || !$uid) {
    echo json_encode(['success' => false, 'error' => 'Fehlende Parameter']);
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";
$inbox = @imap_open($folder, $user, $pass);
if (!$inbox) {
    echo json_encode(['success' => false, 'error' => imap_last_error()]);
    exit;
}
// @imap_reopen($inbox, $folder);

if (@imap_delete($inbox, $uid, FT_UID)) {
    imap_expunge($inbox);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => imap_last_error()]);
}
imap_close($inbox);