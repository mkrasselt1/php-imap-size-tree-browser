<?php
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';

csrf_enforce();
altcha_enforce();

$params = get_imap_params();
$folder  = validate_folder($_POST['folder'] ?? '', $params['mailbox']);
$uid     = validate_uid($_POST['uid'] ?? '');
$partNum = validate_part_number($_POST['partNum'] ?? '');
$filename = sanitize_filename((string) ($_POST['filename'] ?? 'attachment.bin'));
session_write_close();

imapsize_timeouts(30);
$inbox = @imap_open($folder, $params['user'], $params['pass'], OP_READONLY);
if (!$inbox) {
    json_fail('IMAP-Verbindung fehlgeschlagen', 502, ['details' => imap_last_error()]);
}

$structure = imap_fetchstructure($inbox, $uid, FT_UID);

/** Teil anhand der IMAP-Teilnummer finden, auch verschachtelt ("2.1"). */
function find_part($structure, string $partNum) {
    $part = $structure;
    foreach (explode('.', $partNum) as $idx) {
        if (empty($part->parts) || !isset($part->parts[(int) $idx - 1])) {
            // Einteilige Mail: Teil "1" ist der Body selbst
            return ($partNum === '1' && empty($structure->parts)) ? $structure : null;
        }
        $part = $part->parts[(int) $idx - 1];
    }
    return $part;
}

$part = $structure ? find_part($structure, $partNum) : null;
if (!$part) {
    imap_close($inbox);
    json_fail('Anhang nicht gefunden', 404);
}

$body = imap_fetchbody($inbox, $uid, $partNum, FT_UID | FT_PEEK);
imap_close($inbox);
if ($body === false) {
    json_fail('Anhang konnte nicht geladen werden', 502);
}

$enc = (int) ($part->encoding ?? 0);
if ($enc === 3) {
    $body = base64_decode($body);
} elseif ($enc === 4) {
    $body = quoted_printable_decode($body);
}

// Bildvorschau: nur echte Rasterbilder (Typ am Inhalt erkannt, nicht an der Endung) inline ausliefern
if (($_POST['preview'] ?? '') === '1') {
    $info = @getimagesizefromstring($body);
    $mime = $info ? (string) $info['mime'] : '';
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
        json_fail('Für diesen Anhang gibt es keine Vorschau.', 415);
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline');
    header('Content-Length: ' . strlen($body));
    header("Content-Security-Policy: default-src 'none'; sandbox");
    echo $body;
    exit;
}

// Sonst immer als Download ausliefern, nie im Browser rendern (HTML/SVG-Anhänge wären sonst XSS)
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($body));
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $body;
