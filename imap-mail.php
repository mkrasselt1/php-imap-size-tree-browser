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
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende Parameter']);
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";
$inbox = @imap_open($mailbox, $user, $pass);
if (!$inbox) {
    echo json_encode(['error' => 'IMAP-Verbindung fehlgeschlagen', 'details' => imap_last_error()]);
    exit;
}

@imap_reopen($inbox, $folder);

// Header holen
$header = imap_headerinfo($inbox, $uid, FT_UID);

// Absender und Empfänger extrahieren
$from = isset($header->fromaddress) ? imap_utf8($header->fromaddress) : '';
$to = isset($header->toaddress) ? imap_utf8($header->toaddress) : '';

// Subject
$subject = imap_utf8($header->subject ?? '');

// Text und HTML-Teil extrahieren
$structure = imap_fetchstructure($inbox, $uid, FT_UID);
$textBody = '';
$htmlBody = '';
$attachments = [];

if (isset($structure->parts) && count($structure->parts)) {
    foreach ($structure->parts as $i => $part) {
        // Text und HTML wie gehabt
        if ($part->type == 0 && strtolower($part->subtype) == 'plain') {
            $body = imap_fetchbody($inbox, $uid, $i+1, FT_UID);
            $textBody = ($part->encoding == 3) ? base64_decode($body) :
                        ($part->encoding == 4 ? quoted_printable_decode($body) : $body);
        }
        if ($part->type == 0 && strtolower($part->subtype) == 'html') {
            $body = imap_fetchbody($inbox, $uid, $i+1, FT_UID);
            $htmlBody = ($part->encoding == 3) ? base64_decode($body) :
                        ($part->encoding == 4 ? quoted_printable_decode($body) : $body);
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
            $attachments[] = [
                'filename' => $filename,
                'size' => isset($part->bytes) ? $part->bytes : null,
                'partNum' => $i + 1,
                'uid' => $uid,
                'folder' => $folder
            ];
        }
    }
} else {
    // Singlepart-Mail wie gehabt
    $body = imap_body($inbox, $uid, FT_UID);
    if ($structure->type == 0 && strtolower($structure->subtype) == 'plain') {
        $textBody = ($structure->encoding == 3) ? base64_decode($body) :
                    ($structure->encoding == 4 ? quoted_printable_decode($body) : $body);
    }
    if ($structure->type == 0 && strtolower($structure->subtype) == 'html') {
        $htmlBody = ($structure->encoding == 3) ? base64_decode($body) :
                    ($structure->encoding == 4 ? quoted_printable_decode($body) : $body);
    }
}

echo json_encode([
    'subject' => $subject,
    'from' => $from,
    'to' => $to,
    'text' => $textBody,
    'html' => $htmlBody,
    'attachments' => $attachments
], JSON_UNESCAPED_UNICODE);

imap_close($inbox);
