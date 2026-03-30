<?php
/**
 * CSRF Token Management
 *
 * Generates and validates CSRF tokens using PHP sessions.
 * Include this file in all PHP endpoints that accept POST requests.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate a new CSRF token and store it in the session.
 */
function csrf_generate(): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

/**
 * Get the current CSRF token, or generate one if none exists.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        return csrf_generate();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token from a POST request.
 * Tokens expire after 2 hours.
 */
function csrf_validate(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    // Check token age (2 hours max)
    $tokenAge = time() - ($_SESSION['csrf_token_time'] ?? 0);
    if ($tokenAge > 7200) {
        csrf_generate(); // Regenerate expired token
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Enforce CSRF validation — send error and exit if invalid.
 */
function csrf_enforce(): void {
    if (!csrf_validate()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Ungültiges CSRF-Token. Bitte laden Sie die Seite neu.']);
        exit;
    }
}
