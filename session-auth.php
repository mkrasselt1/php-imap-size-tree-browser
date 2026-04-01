<?php
/**
 * Session-based IMAP Credential Storage
 *
 * Stores IMAP credentials in the PHP session after first login.
 * Subsequent requests only need the session cookie, no credentials in POST.
 */

require_once __DIR__ . '/csrf.php'; // starts session

/**
 * Store IMAP credentials in the session.
 */
function session_store_credentials(string $server, int $port, string $user, string $pass, bool $ssl): void {
    $_SESSION['imap_server'] = $server;
    $_SESSION['imap_port'] = $port;
    $_SESSION['imap_user'] = $user;
    $_SESSION['imap_pass'] = $pass;
    $_SESSION['imap_ssl'] = $ssl;
    $_SESSION['imap_mailbox'] = "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";
}

/**
 * Get IMAP credentials from session.
 * Returns null if not stored yet.
 */
function session_get_credentials(): ?array {
    if (empty($_SESSION['imap_server']) || empty($_SESSION['imap_user']) || empty($_SESSION['imap_pass'])) {
        return null;
    }

    return [
        'server'  => $_SESSION['imap_server'],
        'port'    => $_SESSION['imap_port'],
        'user'    => $_SESSION['imap_user'],
        'pass'    => $_SESSION['imap_pass'],
        'ssl'     => $_SESSION['imap_ssl'],
        'mailbox' => $_SESSION['imap_mailbox'],
    ];
}

/**
 * Get IMAP params: prefer session, fall back to POST (and store in session).
 * This way the first request sends credentials, subsequent ones use the session.
 */
function get_imap_params(): array {
    // Try session first
    $creds = session_get_credentials();
    if ($creds) {
        return $creds;
    }

    // Fall back to POST params (first login)
    require_once __DIR__ . '/validate.php';
    $params = extract_imap_params();

    // Store in session for future requests
    session_store_credentials(
        $params['server'],
        $params['port'],
        $params['user'],
        $params['pass'],
        $params['ssl']
    );

    return $params;
}

/**
 * Clear stored credentials (logout).
 */
function session_clear_credentials(): void {
    unset($_SESSION['imap_server'], $_SESSION['imap_port'],
          $_SESSION['imap_user'], $_SESSION['imap_pass'],
          $_SESSION['imap_ssl'], $_SESSION['imap_mailbox']);
}
