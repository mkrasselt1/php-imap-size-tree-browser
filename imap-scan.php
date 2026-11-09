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
$mailbox = $params['mailbox'];
session_write_close(); // lange Scans sollen andere Requests der Sitzung nicht blockieren

imapsize_timeouts(30);
$inbox = @imap_open($mailbox, $params['user'], $params['pass'], OP_READONLY);
if (!$inbox) {
    json_fail('IMAP-Verbindung fehlgeschlagen', 200, ['details' => imap_last_error()]);
}

// Alle Ordner EINMAL holen (inkl. Trennzeichen) und daraus den Baum bauen.
// Früher wurde jeder Unterordner zusätzlich rekursiv erneut gescannt → doppelte Einträge.
$boxes = imap_getmailboxes($inbox, $mailbox, '*') ?: [];

/** Mails eines Ordners mit einem einzigen Overview-Aufruf lesen. */
function scan_folder_mails($inbox, string $folderFull): array {
    if (!@imap_reopen($inbox, $folderFull, OP_READONLY)) {
        return [[], 0, 'Ordner konnte nicht geöffnet werden'];
    }
    $count = imap_num_msg($inbox);
    if ($count === 0) {
        return [[], 0, null];
    }
    $mails = [];
    $total = 0;
    foreach (@imap_fetch_overview($inbox, "1:{$count}") ?: [] as $o) {
        $size = (int) ($o->size ?? 0);
        $total += $size;
        if (empty($o->uid)) continue;
        $mails[] = [
            'name'       => isset($o->subject) ? (safe_imap_utf8($o->subject) ?: '(kein Betreff)') : '(kein Betreff)',
            'size'       => $size,
            'uid'        => (int) $o->uid,
            'type'       => 'mail',
            'folderFull' => $folderFull,
        ];
    }
    usort($mails, function ($a, $b) { return $b['size'] <=> $a['size']; });
    return [$mails, $total, null];
}

// Knoten je Ordner anlegen
$nodes = [];
foreach ($boxes as $box) {
    $full = $box->name;
    $relative = substr($full, strlen($mailbox));
    $delim = $box->delimiter ?: '.';
    $segments = explode($delim, $relative);
    [$mails, $total, $error] = ($box->attributes & LATT_NOSELECT) ? [[], 0, null] : scan_folder_mails($inbox, $full);

    $node = [
        'name'       => safe_imap_utf8(end($segments)),
        'folderFull' => $full,
        'type'       => 'folder',
        'children'   => $mails,
        'childrenTotalSize' => $total,
    ];
    if ($error) $node['error'] = $error;
    $nodes[$relative] = [
        'node'   => $node,
        'depth'  => count($segments),
        'parent' => count($segments) > 1 ? implode($delim, array_slice($segments, 0, -1)) : null,
    ];
}

imap_close($inbox);

// Hierarchie aufbauen: tiefste Ordner zuerst in ihre Eltern kopieren – so ist jeder Teilbaum
// vollständig, bevor er selbst eingehängt wird.
$order = array_keys($nodes);
usort($order, function ($a, $b) use ($nodes) { return $nodes[$b]['depth'] <=> $nodes[$a]['depth']; });
$roots = [];
foreach ($order as $rel) {
    $parent = $nodes[$rel]['parent'];
    if ($parent !== null && isset($nodes[$parent])) {
        $nodes[$parent]['node']['children'][] = $nodes[$rel]['node'];
    } else {
        $roots[] = $nodes[$rel]['node'];
    }
}

/** Größen aufsummieren und Kinder nach Größe sortieren. */
function finalize_node(array &$node): int {
    $sum = 0;
    foreach ($node['children'] as &$child) {
        $sum += isset($child['type']) && $child['type'] === 'folder' ? finalize_node($child) : (int) ($child['size'] ?? 0);
    }
    unset($child);
    usort($node['children'], function ($a, $b) {
        return ($b['size'] ?? $b['childrenTotalSize'] ?? 0) <=> ($a['size'] ?? $a['childrenTotalSize'] ?? 0);
    });
    $node['childrenTotalSize'] = $sum;
    return $sum;
}

$tree = ['name' => 'Root', 'children' => $roots];
finalize_node($tree);

json_out($tree);
