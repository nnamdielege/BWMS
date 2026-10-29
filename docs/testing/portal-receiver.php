<?php

/**
 * Stand-in for the portal's inbound API, for local testing (docs/TESTING.md, flow D).
 * Receives what BWMS sends the portal (stock pushes, dispatched / short_shipped
 * events), checks the signature, prints each call and answers 200.
 *
 *   set PORTAL_RECEIVER_SECRET to the same value as PORTAL_OUTBOUND_SECRET, then:
 *   php -S 127.0.0.1:8099 docs/testing/portal-receiver.php
 *
 * and in .env: PORTAL_BASE_URL=http://127.0.0.1:8099
 */
$body = file_get_contents('php://input');
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
$secret = (string) getenv('PORTAL_RECEIVER_SECRET');
$timestamp = $headers['x-bwms-timestamp'] ?? '';
$given = strtolower($headers['x-bwms-signature'] ?? '');

$signature = match (true) {
    $secret === '' => 'not checked (PORTAL_RECEIVER_SECRET unset)',
    $given === '' => 'MISSING',
    hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $given) => 'valid',
    default => 'INVALID',
};

$json = json_decode($body, true);
file_put_contents('php://stderr', sprintf(
    "\n[%s] %s %s\n  key %s · signature %s · Idempotency-Key %s\n  %s\n",
    date('H:i:s'),
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
    $headers['x-bwms-key-id'] ?? '-',
    $signature,
    $headers['idempotency-key'] ?? '-',
    $json === null ? $body : str_replace("\n", "\n  ", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
));

header('Content-Type: application/json');
http_response_code($signature === 'INVALID' || $signature === 'MISSING' ? 401 : 200);
echo json_encode(['ok' => $signature !== 'INVALID' && $signature !== 'MISSING']);
