<?php
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

// Hilfsfunktion für robuste UTF-8 Dekodierung
function safe_imap_utf8($text) {
    if (empty($text)) return $text;
    
    $decoded = @imap_utf8($text);
    if ($decoded !== false && !empty(trim($decoded))) {
        return $decoded;
    }
    
    // Fallback: Versuche manuelle Dekodierung
    if (function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($text);
        if ($decoded !== false && !empty(trim($decoded))) {
            return $decoded;
        }
    }
    
    // Letzte Hoffnung: Original-Text zurückgeben
    return $text;
}

$server = $_POST['server'] ?? '';
$port   = $_POST['port'] ?? '993';
$user   = $_POST['user'] ?? '';
$pass   = $_POST['pass'] ?? '';
$ssl = in_array(strtolower($_POST['ssl'] ?? 'false'), ['true', 'on'], true);

if (!$server || !$user || !$pass) {
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende Zugangsdaten']);
    exit;
}

$mailbox = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";

$inbox = @imap_open($mailbox, $user, $pass);
if (!$inbox) {
    echo json_encode(['error' => 'IMAP-Verbindung fehlgeschlagen', 'details' => imap_last_error()]);
    exit;
}

$folders = imap_list($inbox, $mailbox, '*');

function getFolderTree($inbox, $mailbox, $folderFull) {
    // Trenne den Ordnernamen anhand des IMAP-Trennzeichens (z.B. Punkt oder Slash)
    $delimiter = '.';
    $info = imap_getmailboxes($inbox, $mailbox, '*');
    if ($info && isset($info[0]->delimiter)) {
        $delimiter = $info[0]->delimiter;
    }
    $shortName = str_replace($mailbox, '', $folderFull);

    if (strpos($folderFull, $mailbox) === 0) {
        $shortName = substr($folderFull, strlen($mailbox));
    }
    if (strpos($shortName, $delimiter) !== false) {
        $parts = explode($delimiter, $shortName);
        $shortName = end($parts);
    }
    
    // Ordnername korrekt dekodieren für Umlaute
    $shortName = safe_imap_utf8($shortName);

    // Ordner öffnen
    $box = @imap_reopen($inbox, $folderFull);
    if (!$box) return [
        'name' => $shortName,
        'children' => [],
        'childrenTotalSize' => 0
    ];

    $numMessages = imap_num_msg($inbox);
    $mails = [];
    $totalSize = 0;

    // Alle Mails durchgehen und Größe sammeln
    for ($i = 1; $i <= $numMessages; $i++) {
        $overview = imap_fetch_overview($inbox, $i);
        if ($overview && isset($overview[0]->size)) {
            $mail = [
                'name' => safe_imap_utf8($overview[0]->subject ?? '(kein Betreff)'),
                'from' => $overview[0]->from ?? '',
                'date' => $overview[0]->date ?? '',
                'size' => $overview[0]->size,
                'uid'  => $overview[0]->uid,
                'folder' => $folderFull,
                'type' => 'mail',
                'folderFull' => $folderFull,
                'isTrash' => strpos(strtolower($folderFull), 'trash') !== false || 
                            strpos(strtolower($folderFull), 'deleted') !== false ||
                            strpos(strtolower($folderFull), 'papierkorb') !== false,
                'rawSubject' => $overview[0]->subject ?? '',
                'debug' => [
                    'folderFull' => $folderFull,
                    'uid' => $overview[0]->uid,
                    'msgNum' => $i
                ]
            ];
            $mails[] = $mail;
            $totalSize += $overview[0]->size;
        }
    }

    // Die 10 größten Mails als eigene Knoten
    usort($mails, function($a, $b) { return $b['size'] - $a['size']; });
    $biggestMails = array_slice($mails, 0, 10);

    // Restliche Mails zusammenfassen
    $otherMails = array_slice($mails, 10);
    $otherSize = 0;
    foreach ($otherMails as $mail) {
        $otherSize += $mail['size'];
    }
    $children = [];
    foreach ($biggestMails as $mail) {
        $children[] = $mail;
    }
    if ($otherSize > 0) {
        $children[] = [
            'name' => 'Weitere Mails (' . $shortName . ')',
            'size' => $otherSize,
            'type' => 'other-mails',
            'count' => count($otherMails)
        ];
    }

    // Subfolder suchen, aber nur echte Unterordner
    $subfolders = imap_list($inbox, $mailbox, $shortName . $delimiter . '*');
    if ($subfolders) {
        foreach ($subfolders as $sub) {
            if ($sub !== $folderFull) {
                $children[] = getFolderTree($inbox, $mailbox, $sub);
            }
        }
    }

    // Summe aller Kindgrößen berechnen
    $childrenTotalSize = 0;
    foreach ($children as $child) {
        if (isset($child['size'])) {
            $childrenTotalSize += $child['size'];
        }
    }

    // Ordner (children) nach Größe sortieren: zuerst große Ordner/Mails
    usort($children, function($a, $b) {
        $sizeA = $a['size'] ?? ($a['childrenTotalSize'] ?? 0);
        $sizeB = $b['size'] ?? ($b['childrenTotalSize'] ?? 0);
        return $sizeB <=> $sizeA;
    });

    return [
        'name' => $shortName,
        'children' => $children,
        'childrenTotalSize' => $childrenTotalSize
    ];
}

$tree = [
    'name' => 'Root',
    'children' => []
];

if ($folders) {
    foreach ($folders as $fullName) {
        $tree['children'][] = getFolderTree($inbox, $mailbox, $fullName);
    }
}

imap_close($inbox);

echo json_encode($tree, JSON_UNESCAPED_UNICODE);
