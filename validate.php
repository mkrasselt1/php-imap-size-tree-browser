<?php
/**
 * Input Validation Helpers
 *
 * Provides sanitization and validation for user inputs
 * to prevent IMAP injection, header injection, and other attacks.
 */

/**
 * Validate and sanitize an IMAP server hostname.
 * Only allows alphanumeric characters, dots, hyphens, and colons.
 */
function validate_server(string $server): string {
    $server = trim($server);

    if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $server)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Ungültiger Servername. Nur Buchstaben, Zahlen, Punkte und Bindestriche erlaubt.']);
        exit;
    }

    if (strlen($server) > 253) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Servername zu lang.']);
        exit;
    }

    return $server;
}

/**
 * Validate and sanitize an IMAP port number.
 */
function validate_port(string $port): int {
    $port = (int) $port;

    if ($port < 1 || $port > 65535) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Ungültiger Port. Muss zwischen 1 und 65535 liegen.']);
        exit;
    }

    return $port;
}

/**
 * Sanitize a filename for use in Content-Disposition headers.
 * Removes path traversal, null bytes, and newlines.
 */
function sanitize_filename(string $filename): string {
    // Remove path components
    $filename = basename($filename);

    // Remove null bytes and control characters
    $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);

    // Remove characters that could break HTTP headers
    $filename = str_replace(['"', '\\', '/', "\r", "\n"], '', $filename);

    // Fallback if empty
    if (empty(trim($filename))) {
        $filename = 'attachment.bin';
    }

    return $filename;
}

/**
 * Build a safe IMAP mailbox connection string.
 */
function build_mailbox(string $server, int $port, bool $ssl): string {
    $server = validate_server($server);
    $port = validate_port((string) $port);

    return "{" . $server . ":" . $port . ($ssl ? "/ssl" : "") . "}";
}

/**
 * Extract and validate common IMAP connection parameters from POST.
 * Returns an associative array with validated values.
 */
function extract_imap_params(): array {
    $server = $_POST['server'] ?? '';
    $port   = $_POST['port'] ?? '993';
    $user   = $_POST['user'] ?? '';
    $pass   = $_POST['pass'] ?? '';
    $ssl    = in_array(strtolower($_POST['ssl'] ?? 'false'), ['true', 'on'], true);

    if (!$server || !$user || !$pass) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Fehlende Zugangsdaten']);
        exit;
    }

    $server = validate_server($server);
    $port = validate_port($port);
    $mailbox = build_mailbox($server, $port, $ssl);

    return [
        'server'  => $server,
        'port'    => $port,
        'user'    => $user,
        'pass'    => $pass,
        'ssl'     => $ssl,
        'mailbox' => $mailbox,
    ];
}
