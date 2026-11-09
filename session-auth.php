<?php
/**
 * Session-based IMAP Credential Storage
 *
 * Die Zugangsdaten werden nach dem ersten Request in der Session gehalten, das Passwort
 * VERSCHLÜSSELT: Der Schlüssel liegt nur in einem eigenen HttpOnly-Cookie beim Besucher.
 * Wer die Session-Dateien auf dem Server liest, bekommt so keine Klartext-Passwörter.
 * Nach 30 Minuten Inaktivität werden die Zugangsdaten verworfen.
 */

require_once __DIR__ . '/csrf.php'; // startet Session
require_once __DIR__ . '/validate.php';

const IMAPSIZE_CRED_IDLE = 1800;
const IMAPSIZE_KEY_COOKIE = 'imapsize_k';

function cred_key(bool $create): ?string {
    if (!function_exists('sodium_crypto_secretbox')) {
        return null;
    }
    $raw = $_COOKIE[IMAPSIZE_KEY_COOKIE] ?? '';
    $key = is_string($raw) ? base64_decode($raw, true) : false;
    if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        return $key;
    }
    if (!$create) {
        return null;
    }
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    setcookie(IMAPSIZE_KEY_COOKIE, base64_encode($key), [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => imapsize_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $_COOKIE[IMAPSIZE_KEY_COOKIE] = base64_encode($key);
    return $key;
}

function session_store_credentials(array $p): void {
    // Neue Session-ID, sobald Zugangsdaten hinterlegt werden (Session-Fixation)
    session_regenerate_id(true);

    $key = cred_key(true);
    if ($key !== null) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $pass = 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($p['pass'], $nonce, $key));
    } else {
        $pass = $p['pass'];
    }

    $_SESSION['imap'] = [
        'server'  => $p['server'],
        'port'    => $p['port'],
        'user'    => $p['user'],
        'pass'    => $pass,
        'ssl'     => $p['ssl'],
        'mailbox' => $p['mailbox'],
        'seen'    => time(),
    ];
}

function session_get_credentials(): ?array {
    $c = $_SESSION['imap'] ?? null;
    if (!is_array($c) || empty($c['server']) || empty($c['user']) || empty($c['pass'])) {
        return null;
    }
    if (time() - (int) ($c['seen'] ?? 0) > IMAPSIZE_CRED_IDLE) {
        session_clear_credentials();
        return null;
    }

    $pass = $c['pass'];
    if (strncmp($pass, 'enc:', 4) === 0) {
        $key = cred_key(false);
        $blob = base64_decode(substr($pass, 4), true);
        if ($key === null || $blob === false || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );
        if ($plain === false) {
            return null;
        }
        $pass = $plain;
    }

    $_SESSION['imap']['seen'] = time();
    return [
        'server'  => $c['server'],
        'port'    => $c['port'],
        'user'    => $c['user'],
        'pass'    => $pass,
        'ssl'     => $c['ssl'],
        'mailbox' => $c['mailbox'],
    ];
}

/**
 * IMAP-Parameter: Werden Zugangsdaten mitgeschickt, gelten diese (z. B. Wechsel auf ein anderes
 * Postfach) und werden gespeichert; sonst die aus der Session.
 */
function get_imap_params(): array {
    if (!empty($_POST['server']) && !empty($_POST['user']) && !empty($_POST['pass'])) {
        $params = extract_imap_params();
        $stored = $_SESSION['imap'] ?? null;
        if (!is_array($stored) || $stored['mailbox'] !== $params['mailbox'] || $stored['user'] !== $params['user']) {
            session_store_credentials($params);
        } else {
            $_SESSION['imap']['seen'] = time();
        }
        return $params;
    }

    $creds = session_get_credentials();
    if ($creds) {
        return $creds;
    }

    json_fail('Sitzung abgelaufen. Bitte erneut anmelden.', 401);
}

function session_clear_credentials(): void {
    unset($_SESSION['imap']);
}
