<?php
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

$folder = $_POST['folder'] ?? '';
$uid    = $_POST['uid'] ?? '';

if (!$folder || !$uid) {
    echo json_encode(['success' => false, 'error' => 'Fehlende Parameter']);
    exit;
}

$inbox = @imap_open($folder, $user, $pass);
if (!$inbox) {
    echo json_encode(['success' => false, 'error' => imap_last_error()]);
    exit;
}

if (@imap_delete($inbox, $uid, FT_UID)) {
    imap_expunge($inbox);
    imap_close($inbox);
    echo json_encode(['success' => true]);
} else {
    $error = imap_last_error();
    imap_close($inbox);
    echo json_encode(['success' => false, 'error' => $error]);
}
