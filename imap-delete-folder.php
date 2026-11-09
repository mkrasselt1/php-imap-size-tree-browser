<?php
set_time_limit(300);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';

csrf_enforce();
altcha_enforce();

$params = get_imap_params();
$folder = validate_folder($_POST['folder'] ?? '', $params['mailbox']);
session_write_close();

imapsize_timeouts(60);
$inbox = @imap_open($folder, $params['user'], $params['pass']);
if (!$inbox) {
    json_fail((string) imap_last_error(), 200);
}

$count = imap_num_msg($inbox);
if ($count === 0) {
    imap_close($inbox);
    json_out(['success' => true, 'deleted' => 0, 'message' => 'Ordner war bereits leer']);
    exit;
}

// Ein Befehl für alle Mails statt einer pro Mail
$ok = @imap_delete($inbox, '1:' . $count);
@imap_expunge($inbox);
$remaining = imap_num_msg($inbox);
imap_close($inbox);

if (!$ok) {
    json_fail('Löschen fehlgeschlagen: ' . imap_last_error(), 200);
}

$deleted = max(0, $count - $remaining);
json_out([
    'success' => true,
    'deleted' => $deleted,
    'total'   => $count,
    'message' => "$deleted von $count E-Mails gelöscht",
]);
