<?php
/**
 * ALTCHA - Self-hosted Proof-of-Work Captcha
 *
 * Ablauf:
 * 1. Client holt eine Challenge von altcha-challenge.php
 * 2. Client löst sie (SHA-256 durchprobieren)
 * 3. Client schickt die Lösung mit dem Request
 * 4. Server prüft Signatur, Ablaufzeit, Lösung – und dass sie noch nie benutzt wurde
 */

require_once __DIR__ . '/bootstrap.php';

// 50000 dauert auf aktueller Hardware ca. 0,5–2 Sekunden
define('ALTCHA_MAX_NUMBER', 50000);
// Gültigkeit einer Challenge in Sekunden
define('ALTCHA_EXPIRE_SECONDS', 300);

/**
 * HMAC-Schlüssel: aus IMAPSIZE_ALTCHA_KEY, sonst einmalig zufällig erzeugt und außerhalb des
 * Webroots gespeichert. (Früher: fester, erratbarer Schlüssel → Challenges fälschbar.)
 */
function altcha_hmac_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    $env = getenv('IMAPSIZE_ALTCHA_KEY');
    if ($env && strlen($env) >= 32) {
        return $key = $env;
    }
    $file = imapsize_data_dir() . '/altcha.key';
    $stored = @file_get_contents($file);
    if (is_string($stored) && strlen($stored) === 64) {
        return $key = $stored;
    }
    $new = bin2hex(random_bytes(32));
    if (@file_put_contents($file, $new, LOCK_EX) !== false) {
        @chmod($file, 0600);
        return $key = $new;
    }
    // Datenverzeichnis nicht beschreibbar (z. B. open_basedir): stabiler Schlüssel aus
    // serverinternen Werten, die von außen nicht bekannt sind – besser wäre IMAPSIZE_ALTCHA_KEY.
    error_log('imapsize: Datenverzeichnis nicht beschreibbar (' . imapsize_data_dir() . '), bitte IMAPSIZE_ALTCHA_KEY oder IMAPSIZE_DATA_DIR setzen');
    $st = @stat(__FILE__) ?: [];
    return $key = hash('sha256', implode('|', [php_uname(), __FILE__, $st['ino'] ?? '', $st['ctime'] ?? '', ini_get('session.save_path')]));
}

function altcha_create_challenge(): array {
    $salt = bin2hex(random_bytes(12));
    $secretNumber = random_int(0, ALTCHA_MAX_NUMBER);
    $saltWithExpiry = $salt . '?expires=' . (time() + ALTCHA_EXPIRE_SECONDS);

    $challenge = hash('sha256', $saltWithExpiry . $secretNumber);
    $signature = hash_hmac('sha256', $challenge, altcha_hmac_key());

    return [
        'algorithm' => 'SHA-256',
        'challenge' => $challenge,
        'maxnumber' => ALTCHA_MAX_NUMBER,
        'salt'      => $saltWithExpiry,
        'signature' => $signature,
    ];
}

/** Jede gelöste Challenge darf nur einmal verwendet werden (Replay-Schutz). */
function altcha_mark_used(string $challenge): bool {
    $dir = imapsize_data_dir('altcha-used');

    // Gelegentlich aufräumen: Einträge älter als die Gültigkeit sind wertlos
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (@filemtime($f) < time() - ALTCHA_EXPIRE_SECONDS - 60) {
                @unlink($f);
            }
        }
    }

    // fopen 'x' schlägt fehl, wenn die Datei schon existiert → atomar
    $fh = @fopen($dir . '/' . $challenge, 'x');
    if ($fh === false) {
        return false;
    }
    fclose($fh);
    return true;
}

function altcha_verify(string $payload): bool {
    $decoded = base64_decode($payload, true);
    if ($decoded === false) return false;

    $data = json_decode($decoded, true);
    if (!is_array($data)) return false;

    $algorithm = $data['algorithm'] ?? '';
    $challenge = $data['challenge'] ?? '';
    $number    = $data['number'] ?? null;
    $salt      = $data['salt'] ?? '';
    $signature = $data['signature'] ?? '';

    if ($algorithm !== 'SHA-256' || !is_string($challenge) || !preg_match('/^[a-f0-9]{64}$/', $challenge)
        || !is_int($number) || $number < 0 || $number > ALTCHA_MAX_NUMBER
        || !is_string($salt) || !is_string($signature) || $signature === '') {
        return false;
    }

    // Ablaufzeit ist Pflicht
    if (!preg_match('/\?expires=(\d+)$/', $salt, $m) || time() > (int) $m[1]) {
        return false;
    }

    if (!hash_equals(hash_hmac('sha256', $challenge, altcha_hmac_key()), $signature)) {
        return false;
    }
    if (!hash_equals(hash('sha256', $salt . $number), $challenge)) {
        return false;
    }

    return altcha_mark_used($challenge);
}

function altcha_enforce(): void {
    $payload = $_POST['altcha'] ?? '';

    if (!is_string($payload) || $payload === '') {
        json_fail('ALTCHA-Verifizierung fehlt. Bitte lösen Sie das Captcha.', 403);
    }
    if (strlen($payload) > 2000 || !altcha_verify($payload)) {
        json_fail('ALTCHA-Verifizierung fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.', 403);
    }
}
