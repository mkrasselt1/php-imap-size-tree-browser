<?php
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';

csrf_enforce();
altcha_enforce();

$params = get_imap_params();
$folder = validate_folder($_POST['folder'] ?? '', $params['mailbox']);
$uid    = validate_uid($_POST['uid'] ?? '');
session_write_close();

imapsize_timeouts(30);
$inbox = @imap_open($folder, $params['user'], $params['pass']);
if (!$inbox) {
    json_fail((string) imap_last_error(), 200);
}

if (@imap_delete($inbox, (string) $uid, FT_UID)) {
    @imap_expunge($inbox);
    imap_close($inbox);
    json_out(['success' => true]);
} else {
    $error = imap_last_error();
    imap_close($inbox);
    json_fail((string) $error, 200);
}
