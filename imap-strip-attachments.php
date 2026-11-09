<?php
/**
 * Anhänge aus einer Mail entfernen.
 *
 * IMAP kann Mails nicht verändern. Darum: Mail laden → Anhänge durch kurzen Hinweis ersetzen →
 * als neue Mail (gleiche Flags, gleiches Empfangsdatum) im selben Ordner ablegen → prüfen, dass die
 * neue Mail angekommen ist → erst dann das Original löschen. Schlägt ein Schritt fehl, bleibt das
 * Original unverändert erhalten.
 */
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/altcha.php';
require_once __DIR__ . '/session-auth.php';
require_once __DIR__ . '/mime-util.php';

csrf_enforce();
altcha_enforce();

$params = get_imap_params();
$folder = validate_folder($_POST['folder'] ?? '', $params['mailbox']);
$uid    = validate_uid($_POST['uid'] ?? '');
$partsParam = (string) ($_POST['parts'] ?? 'all');
session_write_close();

const MAX_MESSAGE_BYTES = 60 * 1024 * 1024;

imapsize_timeouts(60);
$inbox = @imap_open($folder, $params['user'], $params['pass']);
if (!$inbox) {
    json_fail('IMAP-Verbindung fehlgeschlagen: ' . imap_last_error(), 200);
}

$msgno = @imap_msgno($inbox, $uid);
$overview = $msgno ? (imap_fetch_overview($inbox, (string) $uid, FT_UID)[0] ?? null) : null;
$header = $msgno ? @imap_headerinfo($inbox, $msgno) : null;
$structure = $msgno ? imap_fetchstructure($inbox, $uid, FT_UID) : null;
if (!$overview || !$header || !$structure) {
    imap_close($inbox);
    json_fail('E-Mail nicht gefunden', 200);
}
if ((int) ($overview->size ?? 0) > MAX_MESSAGE_BYTES) {
    imap_close($inbox);
    json_fail('Die Mail ist zu groß für diese Funktion (max. 60 MB).', 200);
}

// Welche Teile entfernen?
$attachmentParts = [];
foreach (list_leaf_parts($structure) as $leaf) {
    if ($leaf['attachment']) $attachmentParts[] = $leaf['num'];
}
if ($partsParam === 'all') {
    $remove = $attachmentParts;
} else {
    $remove = [];
    foreach (explode(',', $partsParam) as $p) {
        $p = validate_part_number(trim($p));
        if (!in_array($p, $attachmentParts, true)) {
            imap_close($inbox);
            json_fail("Teil {$p} ist kein Anhang dieser Mail.");
        }
        $remove[] = $p;
    }
    $remove = array_values(array_unique($remove));
}
if (!$remove) {
    imap_close($inbox);
    json_fail('Diese Mail hat keine Anhänge.', 200);
}

// Rohtext laden (FT_PEEK: nicht als gelesen markieren)
$hdr = imap_fetchheader($inbox, $uid, FT_UID);
// Manche Server liefern den Kopf ohne abschließende Leerzeile
if (!preg_match('/\r?\n\r?\n$/', $hdr)) {
    $hdr = rtrim($hdr, "\r\n") . "\r\n\r\n";
}
$raw = $hdr . imap_body($inbox, $uid, FT_UID | FT_PEEK);
$removed = [];
$newRaw = mime_strip($raw, $remove, $removed);

if (count($removed) !== count($remove)) {
    imap_close($inbox);
    json_fail('Die Mail konnte nicht sicher umgebaut werden – es wurde nichts verändert.', 200, [
        'details' => count($removed) . ' von ' . count($remove) . ' Anhängen gefunden',
    ]);
}

// Flags und Empfangsdatum übernehmen
$flags = [];
if (!empty($overview->seen)) $flags[] = '\\Seen';
if (!empty($overview->flagged)) $flags[] = '\\Flagged';
if (!empty($overview->answered)) $flags[] = '\\Answered';
if (!empty($overview->draft)) $flags[] = '\\Draft';
$ts = strtotime(trim((string) ($header->MailDate ?? '')));
$internalDate = $ts ? date('d-M-Y H:i:s O', $ts) : ''; // IMAP verlangt zweistelligen Tag

$status = imap_status($inbox, $folder, SA_UIDNEXT);
$expectedUid = $status ? (int) $status->uidnext : 0;

$ok = $internalDate !== ''
    ? @imap_append($inbox, $folder, $newRaw, implode(' ', $flags), $internalDate)
    : @imap_append($inbox, $folder, $newRaw, implode(' ', $flags));
if (!$ok) {
    $err = imap_last_error();
    imap_close($inbox);
    json_fail('Neue Version konnte nicht gespeichert werden – das Original ist unverändert. ' . $err, 200);
}

// Neue Mail wiederfinden, bevor das Original gelöscht wird
imap_check($inbox); // Ordnerstatus aktualisieren
$newUid = 0;
$messageId = trim((string) ($overview->message_id ?? ''));
$newSize = strlen($newRaw);
$oldSize = (int) $overview->size;
// c-client kann nicht nach Kopfzeilen suchen → alle Mails ab der erwarteten UID ansehen.
// Treffer: neue UID und gleiche Message-ID (ohne Message-ID: passende, kleinere Größe).
// (Die gemeldete Größe weicht je nach Server leicht von strlen() ab, daher keine exakte Prüfung.)
$best = null;
if ($expectedUid) {
    foreach (imap_fetch_overview($inbox, $expectedUid . ':*', FT_UID) ?: [] as $ov) {
        $candUid = (int) $ov->uid;
        if ($candUid < $expectedUid || $candUid === $uid) continue; // "N:*" liefert notfalls die letzte Mail
        if ($messageId !== '') {
            if (trim((string) ($ov->message_id ?? '')) !== $messageId) continue;
        } elseif ((int) $ov->size >= $oldSize || abs((int) $ov->size - $newSize) > max(1024, (int) ($newSize * 0.1))) {
            continue;
        }
        $diff = abs((int) $ov->size - $newSize);
        if ($best === null || $diff < $best[1]) {
            $best = [$ov, $diff];
        }
    }
}
if ($best) {
    $newUid = (int) $best[0]->uid;
    $newSize = (int) $best[0]->size;
}
if (!$newUid) {
    imap_close($inbox);
    json_fail('Die neue Version wurde gespeichert, ließ sich aber nicht eindeutig wiederfinden. '
        . 'Das Original wurde sicherheitshalber NICHT gelöscht.', 200);
}

// Original löschen
@imap_delete($inbox, (string) $uid, FT_UID);
@imap_expunge($inbox);
imap_close($inbox);

json_out([
    'success' => true,
    'oldUid'  => $uid,
    'newUid'  => $newUid,
    'oldSize' => $oldSize,
    'newSize' => $newSize,
    'saved'   => max(0, $oldSize - $newSize),
    'removed' => array_map(function ($r) { return ['name' => $r['name'], 'size' => $r['size']]; }, $removed),
]);
