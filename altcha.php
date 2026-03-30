<?php
/**
 * ALTCHA - Self-hosted Proof-of-Work Captcha
 *
 * Generates cryptographic challenges that clients must solve before
 * submitting requests. This prevents automated abuse without requiring
 * third-party services or API keys.
 *
 * Flow:
 * 1. Client requests a challenge from altcha-challenge.php
 * 2. Client solves the challenge (brute-force SHA-256 hashing)
 * 3. Client submits the solution with the form data
 * 4. Server verifies the solution before processing the request
 */

// HMAC key for signing challenges — change this to a random secret!
define('ALTCHA_HMAC_KEY', 'imapsize-altcha-secret-change-me-' . __DIR__);

// Difficulty: higher maxnumber = harder challenge = more client CPU time
// 50000 takes ~0.5-2 seconds on modern hardware
define('ALTCHA_MAX_NUMBER', 50000);

// Challenge expiration in seconds (5 minutes)
define('ALTCHA_EXPIRE_SECONDS', 300);

/**
 * Generate a new ALTCHA challenge.
 *
 * Returns an array with:
 * - algorithm: hash algorithm (SHA-256)
 * - challenge: the hash the client needs to find
 * - maxnumber: upper bound for brute-force search
 * - salt: random salt (includes expiration timestamp)
 * - signature: HMAC signature to verify authenticity
 */
function altcha_create_challenge(): array {
    $salt = bin2hex(random_bytes(12));
    $secretNumber = random_int(0, ALTCHA_MAX_NUMBER);

    // Include expiration timestamp in the salt
    $expires = time() + ALTCHA_EXPIRE_SECONDS;
    $saltWithExpiry = $salt . '?expires=' . $expires;

    // The challenge is the hash the client needs to reproduce
    $challenge = hash('sha256', $saltWithExpiry . $secretNumber);

    // Sign the challenge so we can verify it wasn't tampered with
    $signature = hash_hmac('sha256', $challenge, ALTCHA_HMAC_KEY);

    return [
        'algorithm' => 'SHA-256',
        'challenge' => $challenge,
        'maxnumber' => ALTCHA_MAX_NUMBER,
        'salt' => $saltWithExpiry,
        'signature' => $signature,
    ];
}

/**
 * Verify an ALTCHA solution submitted by the client.
 *
 * The client submits a base64-encoded JSON string in the 'altcha' field.
 *
 * Returns true if the solution is valid, false otherwise.
 */
function altcha_verify(string $payload): bool {
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return false;
    }

    $data = json_decode($decoded, true);
    if (!$data) {
        return false;
    }

    $algorithm = $data['algorithm'] ?? '';
    $challenge = $data['challenge'] ?? '';
    $number = $data['number'] ?? null;
    $salt = $data['salt'] ?? '';
    $signature = $data['signature'] ?? '';

    // Validate required fields
    if ($algorithm !== 'SHA-256' || $challenge === '' || $number === null || $salt === '' || $signature === '') {
        return false;
    }

    // Check expiration
    if (preg_match('/\?expires=(\d+)/', $salt, $matches)) {
        $expires = (int) $matches[1];
        if (time() > $expires) {
            return false; // Challenge expired
        }
    }

    // Verify the signature (proves the challenge came from our server)
    $expectedSignature = hash_hmac('sha256', $challenge, ALTCHA_HMAC_KEY);
    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }

    // Verify the solution (proves the client did the work)
    $expectedChallenge = hash('sha256', $salt . $number);
    if (!hash_equals($expectedChallenge, $challenge)) {
        return false;
    }

    return true;
}

/**
 * Enforce ALTCHA verification — send error and exit if invalid.
 */
function altcha_enforce(): void {
    $payload = $_POST['altcha'] ?? '';

    if (empty($payload)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'ALTCHA-Verifizierung fehlt. Bitte lösen Sie das Captcha.']);
        exit;
    }

    if (!altcha_verify($payload)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'ALTCHA-Verifizierung fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.']);
        exit;
    }
}
