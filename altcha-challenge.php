<?php
/**
 * ALTCHA Challenge Endpoint
 *
 * Returns a new proof-of-work challenge for the ALTCHA widget.
 * The widget calls this endpoint automatically via the challengeurl attribute.
 */
require_once __DIR__ . '/altcha.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(altcha_create_challenge());
