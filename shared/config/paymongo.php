<?php

require_once __DIR__ . '/../helpers/env.php';
load_env();

// ── PayMongo credentials ─────────────────────────────────────────────────
// Read from the untracked .env file (see .env.example) rather than
// hardcoded here — keeps API keys out of git. Set PAYMONGO_SECRET_KEY /
// PAYMONGO_PUBLIC_KEY to your TEST keys locally (PayMongo Dashboard →
// Developers → API Keys); swap to the sk_live_.../pk_live_... pair only in
// the production .env once you're ready to accept real payments.
define('PAYMONGO_SECRET_KEY', $_ENV['PAYMONGO_SECRET_KEY'] ?? '');
define('PAYMONGO_PUBLIC_KEY', $_ENV['PAYMONGO_PUBLIC_KEY'] ?? '');
define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');

// Minimal curl-based client — PayMongo's REST API is simple enough that
// pulling in an HTTP client library (none is installed in this project;
// see composer.json) isn't worth it. Auth is HTTP Basic with the secret
// key as username and an empty password, per PayMongo's docs.
//
// Returns ['status' => int, 'body' => array] — callers check $status
// themselves (PayMongo uses standard HTTP status codes for errors) rather
// than this helper throwing, so a failed API call surfaces as a normal
// "something went wrong" response instead of an uncaught exception.
function paymongo_request(string $method, string $endpoint, array $payload = []): array
{
    $ch = curl_init(PAYMONGO_API_BASE . $endpoint);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    if ($method !== 'GET' && !empty($payload)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['data' => ['attributes' => $payload]]));
    }

    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('[paymongo_request] curl error: ' . $err);
        return ['status' => 0, 'body' => []];
    }

    $decoded = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}
