<?php
/**
 * Gemeinsamer Einstieg für alle PHP-Endpunkte:
 * sichere Session-Cookies, Sicherheits-Header, JSON-Ausgabe, Datenverzeichnis, UTF-8-Helfer.
 */

if (defined('IMAPSIZE_BOOTSTRAPPED')) {
    return;
}
define('IMAPSIZE_BOOTSTRAPPED', true);

// ---------------------------------------------------------------- Sicherheits-Header
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header_remove('X-Powered-By');

// ---------------------------------------------------------------- Session
function imapsize_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('imapsize_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => imapsize_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ---------------------------------------------------------------- Datenverzeichnis (außerhalb des Webroots)
/**
 * Scan-Zwischenergebnisse (enthalten Betreffzeilen!) und ALTCHA-Daten liegen NICHT im Webroot,
 * sonst wären sie per URL abrufbar. Überschreibbar mit der Umgebungsvariable IMAPSIZE_DATA_DIR.
 */
function imapsize_data_dir(string $sub = ''): string {
    $base = getenv('IMAPSIZE_DATA_DIR') ?: (rtrim(sys_get_temp_dir(), '/\\') . '/imapsize-' . substr(hash('sha256', __DIR__), 0, 12));
    $dir = $sub === '' ? $base : $base . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

// ---------------------------------------------------------------- JSON
function json_out($data, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    // INVALID_UTF8_SUBSTITUTE: Mails in ISO-8859-1 o. ä. dürfen die Antwort nicht leeren
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function json_fail(string $message, int $status = 400, array $extra = []): void {
    json_out(array_merge(['success' => false, 'error' => $message], $extra), $status);
    exit;
}

// ---------------------------------------------------------------- IMAP-Helfer
function imapsize_timeouts(int $seconds = 30): void {
    imap_timeout(IMAP_OPENTIMEOUT, $seconds);
    imap_timeout(IMAP_READTIMEOUT, $seconds);
    imap_timeout(IMAP_WRITETIMEOUT, $seconds);
    imap_timeout(IMAP_CLOSETIMEOUT, 15);
}

/** Robuste UTF-8-Dekodierung für Ordnernamen (Modified UTF-7) und MIME-Header. */
function safe_imap_utf8($text) {
    if ($text === null || $text === '') return $text;

    if (strpos($text, '&') !== false && strpos($text, '-') !== false) {
        $decoded = @mb_convert_encoding($text, 'UTF-8', 'UTF7-IMAP');
        if ($decoded !== false && trim($decoded) !== '') {
            return $decoded;
        }
    }

    if (function_exists('mb_decode_mimeheader') && strpos($text, '=?') !== false) {
        $decoded = @mb_decode_mimeheader($text);
        if ($decoded !== false && trim($decoded) !== '') {
            return to_utf8($decoded);
        }
    }

    $decoded = @imap_utf8($text);
    if ($decoded !== false && trim($decoded) !== '') {
        return to_utf8($decoded);
    }

    return to_utf8($text);
}

/** Text in UTF-8 umwandeln (Zeichensatz aus dem MIME-Teil, sonst raten). */
function to_utf8(string $text, ?string $charset = null): string {
    if ($text === '') return $text;
    $charset = $charset ? strtoupper(trim($charset)) : null;
    if ($charset && $charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        if ($converted !== false) return $converted;
    }
    if (mb_check_encoding($text, 'UTF-8')) return $text;
    return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
}
