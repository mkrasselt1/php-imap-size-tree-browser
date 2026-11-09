<?php
/**
 * Input Validation Helpers
 *
 * Validierung aller Benutzereingaben gegen IMAP-Injection, SSRF und Header-Injection.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Erlaubte IMAP-Ports. Standard: 143 (IMAP/STARTTLS) und 993 (IMAPS).
 * Überschreibbar mit IMAPSIZE_ALLOWED_PORTS="143,993,1143".
 */
function allowed_ports(): array {
    $env = getenv('IMAPSIZE_ALLOWED_PORTS');
    if ($env) {
        return array_values(array_filter(array_map('intval', explode(',', $env))));
    }
    return [143, 993];
}

/** Ist die IP öffentlich (keine privaten, Loopback-, Link-Local- oder reservierten Bereiche)? */
function is_public_ip(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Hostnamen prüfen. Der Server baut im Auftrag beliebiger Besucher Verbindungen auf –
 * ohne diese Prüfung ließe sich damit das interne Netz des Webservers abtasten (SSRF).
 * Für Selbst-Hosting im LAN: IMAPSIZE_ALLOW_PRIVATE=1.
 */
function validate_server(string $server): string {
    $server = strtolower(trim($server));

    if ($server === '' || strlen($server) > 253 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $server)) {
        json_fail('Ungültiger Servername. Nur Buchstaben, Zahlen, Punkte und Bindestriche erlaubt.');
    }

    if (getenv('IMAPSIZE_ALLOW_PRIVATE') === '1') {
        return $server;
    }

    $ips = [];
    if (filter_var($server, FILTER_VALIDATE_IP)) {
        $ips[] = $server;
    } else {
        $ips = @gethostbynamel($server) ?: [];
        $aaaa = @dns_get_record($server, DNS_AAAA) ?: [];
        foreach ($aaaa as $rec) {
            if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
        }
    }
    if (!$ips) {
        json_fail('Server nicht gefunden (DNS).');
    }
    foreach ($ips as $ip) {
        if (!is_public_ip($ip)) {
            json_fail('Dieser Server ist nicht erlaubt (interne Adresse).', 403);
        }
    }

    return $server;
}

function validate_port($port): int {
    $port = (int) $port;
    if (!in_array($port, allowed_ports(), true)) {
        json_fail('Ungültiger Port. Erlaubt: ' . implode(', ', allowed_ports()) . '.');
    }
    return $port;
}

/** UID muss eine positive Ganzzahl sein. */
function validate_uid($uid): int {
    if (!is_scalar($uid) || !preg_match('/^[1-9][0-9]{0,9}$/', (string) $uid)) {
        json_fail('Ungültige Mail-ID.');
    }
    return (int) $uid;
}

/**
 * Ordner-Parameter prüfen: Er muss zum angemeldeten Postfach gehören, also mit genau dem
 * geprüften "{server:port/flags}" beginnen. Sonst könnte ein Request eine Verbindung zu einem
 * beliebigen anderen Server (mit den gespeicherten Zugangsdaten!) erzwingen.
 */
function validate_folder($folder, string $mailbox): string {
    if (!is_string($folder) || $folder === '' || strlen($folder) > 1000) {
        json_fail('Fehlender oder ungültiger Ordner.');
    }
    if (strncmp($folder, $mailbox, strlen($mailbox)) !== 0) {
        json_fail('Ordner gehört nicht zum angemeldeten Postfach.', 403);
    }
    $name = substr($folder, strlen($mailbox));
    if ($name === '' || strpbrk($name, "{}\r\n\0") !== false) {
        json_fail('Ungültiger Ordnername.');
    }
    return $folder;
}

/** MIME-Teilnummer wie "2" oder "2.1.3". */
function validate_part_number($part): string {
    if (!is_scalar($part) || !preg_match('/^[1-9][0-9]{0,3}(\.[1-9][0-9]{0,3}){0,9}$/', (string) $part)) {
        json_fail('Ungültige Anhangsnummer.');
    }
    return (string) $part;
}

/**
 * Dateinamen für Content-Disposition entschärfen.
 */
function sanitize_filename(string $filename): string {
    $filename = basename(str_replace('\\', '/', $filename));
    $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
    $filename = str_replace(['"', '\\', '/', "\r", "\n", ';'], '', $filename);
    if (trim($filename) === '' || $filename === '.' || $filename === '..') {
        $filename = 'attachment.bin';
    }
    return mb_substr($filename, 0, 200);
}

/** Mailbox-String aus geprüften Werten bauen. */
function build_mailbox(string $server, int $port, bool $ssl): string {
    return '{' . $server . ':' . $port . ($ssl ? '/ssl' : '') . '}';
}

/**
 * IMAP-Verbindungsparameter aus dem POST lesen und prüfen.
 */
function extract_imap_params(): array {
    $server = $_POST['server'] ?? '';
    $port   = $_POST['port'] ?? '993';
    $user   = $_POST['user'] ?? '';
    $pass   = $_POST['pass'] ?? '';
    $ssl    = in_array(strtolower((string) ($_POST['ssl'] ?? 'false')), ['true', 'on', '1'], true);

    if (!is_string($server) || !is_string($user) || !is_string($pass) || $server === '' || $user === '' || $pass === '') {
        json_fail('Fehlende Zugangsdaten');
    }
    if (strlen($user) > 320 || strlen($pass) > 1024 || strpbrk($user, "\r\n\0") !== false) {
        json_fail('Ungültige Zugangsdaten');
    }

    $server = validate_server($server);
    $port = validate_port($port);

    return [
        'server'  => $server,
        'port'    => $port,
        'user'    => $user,
        'pass'    => $pass,
        'ssl'     => $ssl,
        'mailbox' => build_mailbox($server, $port, $ssl),
    ];
}
