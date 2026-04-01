<?php
header('Content-Type: application/json');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';

csrf_enforce();
altcha_enforce();

$params = get_imap_params();
$user = $params['user'];
$pass = $params['pass'];

$folder = $_POST['folder'] ?? '';

if (!$folder) {
    echo json_encode(['success' => false, 'error' => 'Fehlender Ordner-Parameter']);
    exit;
}

$inbox = @imap_open($folder, $user, $pass);
if (!$inbox) {
    echo json_encode(['success' => false, 'error' => imap_last_error()]);
    exit;
}

$check = imap_check($inbox);
if (!$check || $check->Nmsgs == 0) {
    imap_close($inbox);
    echo json_encode(['success' => true, 'deleted' => 0, 'message' => 'Ordner war bereits leer']);
    exit;
}

// Alle Mails zum Löschen markieren
$count = $check->Nmsgs;
$deleted = 0;

for ($i = 1; $i <= $count; $i++) {
    if (@imap_delete($inbox, $i)) {
        $deleted++;
    }
}

// Endgültig löschen
imap_expunge($inbox);
imap_close($inbox);

echo json_encode([
    'success' => true,
    'deleted' => $deleted,
    'total' => $count,
    'message' => "$deleted von $count E-Mails gelöscht"
]);
