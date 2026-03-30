<?php
/**
 * CSRF Token Endpoint
 *
 * Returns a fresh CSRF token for the frontend to use in POST requests.
 */
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');
echo json_encode(['token' => csrf_token()]);
