<?php
header('Content-Type: application/json');

$server = $_POST['server'] ?? '';
$port   = $_POST['port'] ?? '993';
$user   = $_POST['user'] ?? '';
$pass   = $_POST['pass'] ?? '';
$ssl    = ($_POST['ssl'] ?? 'false') === 'true';
$folder = $_POST['folder'] ?? '';
$uid    = $_POST['uid'] ?? '';
$getAttachments = isset($_POST['attachments']) && $_POST['attachments'] === 'true';

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
$body = imap_body($inbox, $uid, FT_UID);
$header = imap_headerinfo($inbox, $uid, FT_UID);

$result = [
    'subject' => imap_utf8($header->subject ?? ''),
    'from' => imap_utf8($header->fromaddress ?? ''),
    'date' => $header->date ?? '',
    'body' => $body
];

// Anhänge extrahieren, wenn gewünscht
if ($getAttachments) {
    $structure = imap_fetchstructure($inbox, $uid, FT_UID);
    $attachments = [];
    if (isset($structure->parts) && count($structure->parts)) {
        for ($i = 0; $i < count($structure->parts); $i++) {
            $part = $structure->parts[$i];
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
            if ($isAttachment) {
                $attachmentBody = imap_fetchbody($inbox, $uid, $i+1, FT_UID);
                // Base64 oder Quoted-Printable dekodieren
                if ($part->encoding == 3) { // BASE64
                    $attachmentBody = base64_decode($attachmentBody);
                } elseif ($part->encoding == 4) { // QUOTED-PRINTABLE
                    $attachmentBody = quoted_printable_decode($attachmentBody);
                }
                $attachments[] = [
                    'filename' => $filename,
                    'size' => strlen($attachmentBody),
                    'data' => base64_encode($attachmentBody)
                ];
            }
        }
    }
    $result['attachments'] = $attachments;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);

imap_close($inbox);