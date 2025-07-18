<?php
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

$server = $_POST['server'] ?? '';
$port   = $_POST['port'] ?? '993';
$user   = $_POST['user'] ?? '';
$pass   = $_POST['pass'] ?? '';
$ssl = in_array(strtolower($_POST['ssl'] ?? 'false'), ['true', 'on'], true);
$folder = $_POST['folder'] ?? '';
$uid    = $_POST['uid'] ?? '';

if (!$server || !$user || !$pass || !$folder || !$uid) {
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende Parameter']);
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";

// Set IMAP timeout to fail faster on wrong credentials
imap_timeout(IMAP_OPENTIMEOUT, 10);
imap_timeout(IMAP_READTIMEOUT, 10);
imap_timeout(IMAP_WRITETIMEOUT, 10);
imap_timeout(IMAP_CLOSETIMEOUT, 10);

$inbox = @imap_open($folder, $user, $pass);
if (!$inbox) {
    $error = imap_last_error();
    echo json_encode([
        'error' => 'IMAP-Verbindung fehlgeschlagen', 
        'details' => $error,
        'message' => strpos($error, 'AUTHENTICATE') !== false || strpos($error, 'LOGIN') !== false 
            ? 'Benutzername oder Passwort falsch' 
            : 'Verbindung zum Server fehlgeschlagen'
    ]);
    exit;
}

// Header holen
$header = imap_headerinfo($inbox, $uid, FT_UID);
if (!$header) {
    echo json_encode([
        'error' => 'E-Mail nicht gefunden',
        'details' => 'Die angegebene UID existiert nicht oder ist ungültig',
        'uid' => $uid
    ]);
    imap_close($inbox);
    exit;
}

// Absender und Empfänger extrahieren
$from = isset($header->fromaddress) ? imap_utf8($header->fromaddress) : '';
$to = isset($header->toaddress) ? imap_utf8($header->toaddress) : '';

// Subject
$subject = imap_utf8($header->subject ?? '');

// Datum hinzufügen
$date = isset($header->date) ? $header->date : '';

// Text und HTML-Teil extrahieren
$structure = imap_fetchstructure($inbox, $uid, FT_UID);
if (!$structure) {
    echo json_encode([
        'error' => 'E-Mail-Struktur nicht verfügbar',
        'details' => 'Die E-Mail-Struktur konnte nicht geladen werden',
        'uid' => $uid
    ]);
    imap_close($inbox);
    exit;
}
$textBody = '';
$htmlBody = '';
$attachments = [];

if (isset($structure->parts) && count($structure->parts)) {
    // Recursive function to extract text/html from all levels
    function getBodies($inbox, $uid, $parts, $prefix = '')
    {
        $result = ['text' => '', 'html' => ''];
        foreach ($parts as $i => $part) {
            $partNum = $prefix . ($i + 1);
            if ($part->type == 0 && strtolower($part->subtype) == 'plain') {
                $body = imap_fetchbody($inbox, $uid, $partNum, FT_UID);
                if ($body !== false) {
                    $bodyDecoded = ($part->encoding == 3) ? base64_decode($body) : ($part->encoding == 4 ? quoted_printable_decode($body) : $body);
                    $result['text'] .= $bodyDecoded;
                }
            }
            if ($part->type == 0 && strtolower($part->subtype) == 'html') {
                $body = imap_fetchbody($inbox, $uid, $partNum, FT_UID);
                if ($body !== false) {
                    $bodyDecoded = ($part->encoding == 3) ? base64_decode($body) : ($part->encoding == 4 ? quoted_printable_decode($body) : $body);
                    $result['html'] .= $bodyDecoded;
                }
            }
            // If multipart, recurse
            if (isset($part->parts) && count($part->parts)) {
                $subResult = getBodies($inbox, $uid, $part->parts, $partNum . '.');
                $result['text'] .= $subResult['text'];
                $result['html'] .= $subResult['html'];
            }
            // Attachments: nur Metadaten sammeln
            $isAttachment = false;
            $filename = '';
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                $isAttachment = true;
            }
            if (isset($part->parameters)) {
                foreach ($part->parameters as $param) {
                    if (strtolower($param->attribute) === 'name') {
                        $filename = $param->value;
                        $isAttachment = true;
                    }
                }
            }
            if (isset($part->dparameters)) {
                foreach ($part->dparameters as $param) {
                    if (strtolower($param->attribute) === 'filename') {
                        $filename = $param->value;
                        $isAttachment = true;
                    }
                }
            }
            if ($isAttachment && $filename) {
                global $attachments, $uid, $folder;
                $attachments[] = [
                    'filename' => $filename,
                    'size' => isset($part->bytes) ? $part->bytes : null,
                    'partNum' => $partNum,
                    'uid' => $uid,
                    'folder' => $folder
                ];
            }
        }
        return $result;
    }
    $bodies = getBodies($inbox, $uid, $structure->parts);
    $textBody = $bodies['text'];
    $htmlBody = $bodies['html'];
} else {
    // Singlepart-Mail wie gehabt
    $body = imap_body($inbox, $uid, FT_UID);
    if ($body === false) {
        $textBody = '';
        $htmlBody = '';
    } else {
        if ($structure->type == 0 && strtolower($structure->subtype) == 'plain') {
            $textBody = ($structure->encoding == 3) ? base64_decode($body) : ($structure->encoding == 4 ? quoted_printable_decode($body) : $body);
        }
        if ($structure->type == 0 && strtolower($structure->subtype) == 'html') {
            $htmlBody = ($structure->encoding == 3) ? base64_decode($body) : ($structure->encoding == 4 ? quoted_printable_decode($body) : $body);
        }
    }
}

echo json_encode([
    'subject' => $subject,
    'from' => $from,
    'to' => $to,
    'date' => $date,
    'text' => $textBody,
    'html' => $htmlBody,
    'attachments' => $attachments
], JSON_UNESCAPED_UNICODE);

imap_close($inbox);
