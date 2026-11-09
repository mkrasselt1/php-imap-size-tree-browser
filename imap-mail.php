<?php
set_time_limit(120);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';

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

function part_param($part, string $name): ?string {
    foreach (['parameters', 'dparameters'] as $prop) {
        if (!empty($part->$prop) && is_array($part->$prop)) {
            foreach ($part->$prop as $p) {
                if (strtolower($p->attribute) === $name) return $p->value;
            }
        }
    }
    return null;
}

/** Inhalt eines Teils dekodieren und in UTF-8 umwandeln. */
function decode_part(string $body, $part): string {
    $enc = (int) ($part->encoding ?? 0);
    if ($enc === 3) $body = base64_decode($body);
    elseif ($enc === 4) $body = quoted_printable_decode($body);
    return to_utf8((string) $body, part_param($part, 'charset'));
}

$text = '';
$html = '';
$attachments = [];

/**
 * MIME-Baum rekursiv durchlaufen. FT_PEEK: Abrufen setzt kein \Seen.
 * Teilnummern nach IMAP-Schema ("1", "2.1", …).
 */
function walk_parts($inbox, int $uid, $part, string $partNum, string &$text, string &$html, array &$attachments): void {
    $filename = part_param($part, 'filename') ?? part_param($part, 'name');
    $disposition = isset($part->disposition) ? strtolower($part->disposition) : '';
    $isAttachment = $disposition === 'attachment' || ($filename !== null && $filename !== '');

    if (!empty($part->parts)) {
        foreach ($part->parts as $i => $sub) {
            $subNum = $partNum === '' ? (string) ($i + 1) : $partNum . '.' . ($i + 1);
            walk_parts($inbox, $uid, $sub, $subNum, $text, $html, $attachments);
        }
        return;
    }

    $num = $partNum === '' ? '1' : $partNum;
    if ($isAttachment) {
        $attachments[] = [
            'filename' => safe_imap_utf8((string) $filename) ?: 'anhang.bin',
            'size'     => isset($part->bytes) ? (int) $part->bytes : null,
            'partNum'  => $num,
        ];
        return;
    }

    if ((int) $part->type === 0) {
        $subtype = strtolower($part->subtype ?? '');
        if ($subtype !== 'plain' && $subtype !== 'html') return;
        if (strlen($text) + strlen($html) > MAX_BODY_BYTES) return;
        $raw = $partNum === ''
            ? imap_body($inbox, $uid, FT_UID | FT_PEEK)
            : imap_fetchbody($inbox, $uid, $num, FT_UID | FT_PEEK);
        if ($raw === false) return;
        $decoded = decode_part($raw, $part);
        if ($subtype === 'plain') $text .= $decoded;
        else $html .= $decoded;
    }
}

walk_parts($inbox, $uid, $structure, '', $text, $html, $attachments);
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
