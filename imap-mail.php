<?php
set_time_limit(120);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/mime-util.php';

csrf_enforce();
altcha_enforce();

$params = get_imap_params();
$mailbox = $params['mailbox'];
$folder = validate_folder($_POST['folder'] ?? '', $mailbox);
$uid = validate_uid($_POST['uid'] ?? '');
session_write_close();

// Maximale Textlänge, die an den Browser geht (sehr große Mails sonst mehrere MB JSON)
const MAX_BODY_BYTES = 2 * 1024 * 1024;

imapsize_timeouts(15);
// Direkt im Ordner öffnen, schreibgeschützt: Ansehen markiert die Mail nicht als gelesen
$inbox = @imap_open($folder, $params['user'], $params['pass'], OP_READONLY);
if (!$inbox) {
    $error = imap_last_error() ?: '';
    json_fail('IMAP-Verbindung fehlgeschlagen', 200, [
        'details' => $error,
        'message' => (stripos($error, 'AUTHENTICATE') !== false || stripos($error, 'LOGIN') !== false)
            ? 'Benutzername oder Passwort falsch'
            : 'Verbindung zum Server oder Ordner fehlgeschlagen',
    ]);
}

$msgno = @imap_msgno($inbox, $uid);
$header = $msgno ? @imap_headerinfo($inbox, $msgno) : false;
if (!$header) {
    imap_close($inbox);
    json_fail('E-Mail nicht gefunden', 200, ['details' => 'Die Mail existiert nicht mehr (evtl. gelöscht oder verschoben).']);
}

$from = isset($header->fromaddress) ? safe_imap_utf8($header->fromaddress) : '';
$to = isset($header->toaddress) ? safe_imap_utf8($header->toaddress) : '';
$subject = isset($header->subject) ? safe_imap_utf8($header->subject) : '';
if (!$subject || trim($subject) === '') {
    $subject = 'Kein Betreff';
}
$date = isset($header->date) ? to_utf8($header->date) : '';

$structure = imap_fetchstructure($inbox, $uid, FT_UID);
if (!$structure) {
    imap_close($inbox);
    json_fail('E-Mail-Struktur nicht verfügbar', 200);
}

$text = '';
$html = '';
$attachments = [];
$inlineImages = []; // Content-ID => Teil (für eingebettete Bilder im HTML)

foreach (list_leaf_parts($structure) as $leaf) {
    $part = $leaf['part'];
    if ($leaf['cid'] && in_array($leaf['mime'], PREVIEW_IMAGE_TYPES, true)) {
        $inlineImages[$leaf['cid']] = $leaf;
    }
    if ($leaf['attachment']) {
        $attachments[] = [
            'filename'  => $leaf['filename'] ?: 'anhang.bin',
            'size'      => isset($part->bytes) ? (int) $part->bytes : null,
            'partNum'   => $leaf['num'],
            'mime'      => $leaf['mime'],
            'previewable' => in_array($leaf['mime'], PREVIEW_IMAGE_TYPES, true),
        ];
        continue;
    }
    if ($leaf['mime'] !== 'text/plain' && $leaf['mime'] !== 'text/html') continue;
    if (strlen($text) + strlen($html) > MAX_BODY_BYTES) continue;

    // FT_PEEK: Abrufen setzt kein \Seen
    $raw = empty($structure->parts)
        ? imap_body($inbox, $uid, FT_UID | FT_PEEK)
        : imap_fetchbody($inbox, $uid, $leaf['num'], FT_UID | FT_PEEK);
    if ($raw === false) continue;
    if ($leaf['mime'] === 'text/plain') $text .= decode_part($raw, $part);
    else $html .= decode_part($raw, $part);
}

// Eingebettete Bilder (cid:) direkt im HTML anzeigen – als data:-URL, max. 2 MB je Bild / 8 MB gesamt
if ($html !== '' && $inlineImages) {
    $budget = 8 * 1024 * 1024;
    $html = preg_replace_callback('/cid:([^"\'\s)>]+)/i', function ($m) use ($inbox, $uid, $inlineImages, &$budget) {
        $cid = rawurldecode($m[1]);
        $leaf = $inlineImages[$cid] ?? null;
        $bytes = $leaf && isset($leaf['part']->bytes) ? (int) $leaf['part']->bytes : PHP_INT_MAX;
        if (!$leaf || $bytes > 2 * 1024 * 1024 * 4 / 3 || $bytes > $budget) {
            return $m[0];
        }
        $raw = imap_fetchbody($inbox, $uid, $leaf['num'], FT_UID | FT_PEEK);
        if ($raw === false) return $m[0];
        $data = decode_transfer($raw, $leaf['part']);
        $budget -= strlen($data);
        return 'data:' . $leaf['mime'] . ';base64,' . base64_encode($data);
    }, $html);
}

imap_close($inbox);

$truncated = false;
if (strlen($text) > MAX_BODY_BYTES) { $text = mb_strcut($text, 0, MAX_BODY_BYTES, 'UTF-8'); $truncated = true; }
if (strlen($html) > MAX_BODY_BYTES) { $html = mb_strcut($html, 0, MAX_BODY_BYTES, 'UTF-8'); $truncated = true; }

foreach ($attachments as &$a) {
    $a['uid'] = $uid;
    $a['folder'] = $folder;
}
unset($a);

json_out([
    'subject'     => $subject,
    'from'        => $from,
    'to'          => $to,
    'date'        => $date,
    'text'        => $text,
    'html'        => $html,
    'truncated'   => $truncated,
    'attachments' => $attachments,
]);
