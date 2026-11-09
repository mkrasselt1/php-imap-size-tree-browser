<?php
/**
 * MIME-Helfer: Teile einer Mail auflisten (IMAP-Teilnummern) und Anhänge aus dem
 * Rohtext einer Mail entfernen.
 */

require_once __DIR__ . '/bootstrap.php';

const MIME_TYPES = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];

/** Bilder, die direkt im Browser angezeigt werden dürfen (kein SVG: könnte Skripte enthalten). */
const PREVIEW_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

function part_param($part, string $name): ?string {
    foreach (['dparameters', 'parameters'] as $prop) {
        if (!empty($part->$prop) && is_array($part->$prop)) {
            foreach ($part->$prop as $p) {
                $attr = strtolower($p->attribute);
                if ($attr === $name || $attr === $name . '*') return $p->value;
            }
        }
    }
    return null;
}

function part_mime($part): string {
    $type = MIME_TYPES[(int) ($part->type ?? 0)] ?? 'application';
    return strtolower($type . '/' . ($part->subtype ?? 'octet-stream'));
}

/** Inhalt eines Teils dekodieren (ohne Zeichensatz-Umwandlung). */
function decode_transfer(string $body, $part): string {
    $enc = (int) ($part->encoding ?? 0);
    if ($enc === 3) return (string) base64_decode($body);
    if ($enc === 4) return quoted_printable_decode($body);
    return $body;
}

/** Text-Teil dekodieren und in UTF-8 umwandeln. */
function decode_part(string $body, $part): string {
    return to_utf8(decode_transfer($body, $part), part_param($part, 'charset'));
}

/**
 * Alle Blatt-Teile einer Mail mit IMAP-Teilnummer ("1", "2.1", …).
 * Weitergeleitete Mails (message/rfc822) zählen als EIN Anhang.
 *
 * @return array<int, array{num:string, part:object, mime:string, attachment:bool, filename:?string, cid:?string}>
 */
function list_leaf_parts($structure): array {
    $out = [];
    $walk = function ($part, string $num) use (&$walk, &$out) {
        $mime = part_mime($part);
        if (!empty($part->parts) && strpos($mime, 'multipart/') === 0) {
            foreach ($part->parts as $i => $sub) {
                $walk($sub, $num === '' ? (string) ($i + 1) : $num . '.' . ($i + 1));
            }
            return;
        }
        $filename = part_param($part, 'filename') ?? part_param($part, 'name');
        $disposition = isset($part->disposition) ? strtolower($part->disposition) : '';
        $isMessage = (int) ($part->type ?? 0) === 2;
        if ($isMessage && !$filename) {
            $filename = 'weitergeleitete-mail.eml';
        }
        $out[] = [
            'num'        => $num === '' ? '1' : $num,
            'part'       => $part,
            'mime'       => $mime,
            'attachment' => $isMessage || $disposition === 'attachment' || ($filename !== null && $filename !== ''),
            'filename'   => $filename !== null ? (safe_imap_utf8($filename) ?: $filename) : null,
            'cid'        => !empty($part->id) ? trim($part->id, '<> ') : null,
        ];
    };
    $walk($structure, '');
    return $out;
}

// ---------------------------------------------------------------- Anhänge entfernen

function mime_eol(string $raw): string {
    return strpos($raw, "\r\n") !== false ? "\r\n" : "\n";
}

/** Kopf und Körper trennen (an der ersten Leerzeile). */
function mime_split(string $entity): array {
    if (preg_match('/\r?\n\r?\n/', $entity, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        return [substr($entity, 0, $pos), substr($entity, $pos + strlen($m[0][0]))];
    }
    return [$entity, ''];
}

/** Wert eines Kopffelds (Folgezeilen zusammengeführt). */
function mime_header(string $headers, string $name): ?string {
    $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $headers);
    if (preg_match('/^' . preg_quote($name, '/') . ':[ \t]*(.*)$/mi', $unfolded, $m)) {
        return trim($m[1]);
    }
    return null;
}

function mime_header_param(?string $value, string $param): ?string {
    if ($value === null) return null;
    if (preg_match('/(?:^|;)\s*' . preg_quote($param, '/') . '\s*=\s*("([^"]*)"|[^;\s]+)/i', $value, $m)) {
        return isset($m[2]) && $m[2] !== '' ? $m[2] : trim($m[1], '"');
    }
    return null;
}

/** Ersatz-Teil mit kurzem Hinweis anstelle des entfernten Anhangs. */
function mime_placeholder(string $name, int $bytes, string $eol): string {
    $text = "[Anhang entfernt: {$name} (" . format_bytes($bytes) . ") – am " . date('d.m.Y') . " mit IMAP Size Tree Browser]";
    return 'Content-Type: text/plain; charset=utf-8' . $eol
        . 'Content-Transfer-Encoding: base64' . $eol
        . 'Content-Disposition: inline' . $eol
        . 'X-Imapsize-Removed: ' . preg_replace('/[\r\n]+/', ' ', mb_encode_mimeheader($name, 'UTF-8', 'Q')) . $eol
        . $eol
        . rtrim(chunk_split(base64_encode($text), 76, $eol));
}

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    return $bytes . ' Bytes';
}

/** Ungefähre Originalgröße eines Teils (Base64 ist ca. 4/3 so groß). */
function mime_decoded_size(string $headers, string $body): int {
    $enc = strtolower((string) mime_header($headers, 'Content-Transfer-Encoding'));
    $len = strlen(preg_replace('/\s+/', '', $body));
    return $enc === 'base64' ? (int) floor($len * 3 / 4) : strlen($body);
}

function mime_part_name(string $headers): string {
    $name = mime_header_param(mime_header($headers, 'Content-Disposition'), 'filename')
        ?? mime_header_param(mime_header($headers, 'Content-Type'), 'name')
        ?? 'Anhang';
    $decoded = @mb_decode_mimeheader($name);
    return to_utf8($decoded !== false && $decoded !== '' ? $decoded : $name);
}

/**
 * Entfernt die angegebenen Teile (IMAP-Teilnummern) aus einer Mail und ersetzt sie durch einen Hinweis.
 * Alle übrigen Bytes (Kopfzeilen, Text, Signaturen, andere Teile) bleiben unverändert.
 *
 * @param string[] $remove
 * @param array    $removed  erhält [ ['num'=>..., 'name'=>..., 'size'=>...], ... ]
 */
function mime_strip(string $raw, array $remove, array &$removed): string {
    $eol = mime_eol($raw);
    [$headers, $body] = mime_split($raw);
    $sep = substr($raw, strlen($headers), strlen($raw) - strlen($headers) - strlen($body)); // Leerzeile(n)
    $ctype = (string) mime_header($headers, 'Content-Type');

    if (stripos($ctype, 'multipart/') === 0) {
        return $headers . $sep . mime_strip_multipart($body, $ctype, '', $remove, $removed, $eol);
    }

    // Einteilige Mail, deren gesamter Inhalt der Anhang ist (Teil "1")
    if (in_array('1', $remove, true)) {
        $removed[] = ['num' => '1', 'name' => mime_part_name($headers), 'size' => mime_decoded_size($headers, $body)];
        $kept = preg_replace('/^(Content-Type|Content-Transfer-Encoding|Content-Disposition|Content-ID|Content-Description):.*(\r?\n[ \t].*)*\r?\n?/mi', '', $headers);
        [$phHeaders, $phBody] = mime_split(mime_placeholder($removed[count($removed) - 1]['name'], $removed[count($removed) - 1]['size'], $eol));
        return rtrim($kept, "\r\n") . $eol . $phHeaders . $eol . $eol . $phBody . $eol;
    }
    return $raw;
}

function mime_strip_multipart(string $body, string $ctype, string $prefix, array $remove, array &$removed, string $eol): string {
    $boundary = mime_header_param($ctype, 'boundary');
    if ($boundary === null || $boundary === '') {
        return $body;
    }

    $lines = explode("\n", $body);
    $preamble = [];
    $parts = [];
    $current = null;
    $epilogue = [];
    $closed = false;
    $closeLine = '--' . $boundary . '--';

    foreach ($lines as $line) {
        $trim = rtrim($line, "\r \t");
        if ($closed) {
            $epilogue[] = $line;
        } elseif ($trim === '--' . $boundary . '--') {
            $closeLine = $line;
            if ($current !== null) $parts[] = $current;
            $current = null;
            $closed = true;
        } elseif ($trim === '--' . $boundary) {
            if ($current !== null) $parts[] = $current;
            $current = [];
        } elseif ($current === null) {
            $preamble[] = $line;
        } else {
            $current[] = $line;
        }
    }
    if (!$closed && $current !== null) {
        $parts[] = $current; // unvollständige Mail: letzten Teil trotzdem übernehmen
    }

    $out = $preamble ? implode("\n", $preamble) . "\n" : '';
    foreach ($parts as $i => $partLines) {
        $num = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
        $entity = implode("\n", $partLines);
        // Der Zeilenumbruch vor der nächsten Grenze gehört zur Grenze, nicht zum Teil
        $entity = preg_replace('/\r?$/', '', $entity, 1);

        if (in_array($num, $remove, true)) {
            [$h, $b] = mime_split($entity);
            $name = mime_part_name($h);
            $size = mime_decoded_size($h, $b);
            $removed[] = ['num' => $num, 'name' => $name, 'size' => $size];
            $entity = mime_placeholder($name, $size, $eol);
        } else {
            [$h, $b] = mime_split($entity);
            $childType = (string) mime_header($h, 'Content-Type');
            if (stripos($childType, 'multipart/') === 0) {
                $sep = substr($entity, strlen($h), strlen($entity) - strlen($h) - strlen($b));
                $entity = $h . $sep . mime_strip_multipart($b, $childType, $num, $remove, $removed, $eol);
            }
        }
        $out .= '--' . $boundary . $eol . $entity . $eol;
    }
    $out .= '--' . $boundary . '--';
    if ($epilogue) {
        $out .= "\n" . implode("\n", $epilogue);
    } else {
        $out .= $eol;
    }
    return $out;
}
