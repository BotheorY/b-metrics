<?php
/** Cookieless tracking collector. POST JSON (text/plain or application/json). */
declare(strict_types=1);
define('BMETRICS_ENTRY', true);
require __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin, Referer');

// An explicit, invalid Origin must never be rescued by a valid Referer.
$hasOrigin = array_key_exists('HTTP_ORIGIN', $_SERVER);
$source = $hasOrigin ? $_SERVER['HTTP_ORIGIN'] : ($_SERVER['HTTP_REFERER'] ?? '');
$sourceParts = parse_http_url($source, $hasOrigin);
if ($sourceParts === null || !in_array($sourceParts['host'], $allowed_domains, true)) {
    fail(403, 'Origin is not allowed.');
}
$domain = $sourceParts['host'];
if ($hasOrigin) {
    header('Access-Control-Allow-Origin: ' . $source);
}
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    if (strtoupper($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(405, 'Only POST tracking is supported.');
    }
    $requestedHeaders = strtolower($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '');
    foreach (array_filter(array_map('trim', explode(',', $requestedHeaders))) as $requestedHeader) {
        if ($requestedHeader !== 'content-type') {
            fail(403, 'Requested header is not allowed.');
        }
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    fail(405, 'Only POST tracking is supported.');
}
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if (!in_array($contentType, ['text/plain', 'application/json'], true)) {
    fail(415, 'Send a JSON object as text/plain or application/json.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    fail(413, 'Payload is too large.');
}
$raw = file_get_contents('php://input', false, null, 0, 16385);
if ($raw === false || strlen($raw) > 16384) {
    fail(413, 'Payload is too large.');
}
try {
    $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fail(400, 'Invalid JSON payload.');
}
if (!is_array($payload) || array_is_list($payload)) {
    fail(400, 'A JSON object is required.');
}

function payload_string(array $payload, string $key, int $maximum, string $default = ''): string
{
    $value = $payload[$key] ?? $default;
    if (!is_string($value) || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
        fail(400, 'Invalid field: ' . $key);
    }
    return $value;
}
$pageUrl = payload_string($payload, 'url', 4096);
$pageParts = parse_http_url($pageUrl);
if ($pageParts === null || $pageParts['host'] !== $domain) {
    fail(400, 'Page URL must belong to the requesting domain.');
}
$referrer = payload_string($payload, 'referrer', 4096);
if ($referrer !== '' && parse_http_url($referrer) === null) {
    fail(400, 'Referrer must be an HTTP or HTTPS URL.');
}
$language = payload_string($payload, 'language', 64);
$eventId = payload_string($payload, 'event_id', 100);
if ($eventId === '') {
    $eventId = bin2hex(random_bytes(16));
} elseif (!preg_match('/^[a-zA-Z0-9_-]{16,100}$/D', $eventId)) {
    fail(400, 'Invalid event identifier.');
}
$dimensions = [];
foreach (['screen_width', 'screen_height'] as $key) {
    $value = $payload[$key] ?? 0;
    if (!is_int($value) || $value < 0 || $value > 32768) {
        fail(400, 'Invalid field: ' . $key);
    }
    $dimensions[$key] = $value;
}
$ipAddress = client_ip();
// Return the standard success response without persisting excluded traffic.
// Validation above still applies so this endpoint behaves consistently for all clients.
if (in_array($ipAddress, $excluded_ip_addresses, true)) {
    http_response_code(204);
    exit;
}
if (!consume_rate_limit('track:' . $domain . ':' . $ipAddress, $tracking_hits_per_minute, 60)) {
    header('Retry-After: 60');
    fail(429, 'Tracking rate limit exceeded.');
}
$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1024);
// Replace malformed header bytes without breaking JSON statistics or logs.
$userAgent = json_decode(json_encode($userAgent, JSON_INVALID_UTF8_SUBSTITUTE), true);
require __DIR__ . '/includes/user_agent.php';
[$device, $browser] = classify_user_agent($userAgent);
$query = database()->prepare('INSERT INTO hits
    (domain, page_url, referrer, user_agent, ip_address, timestamp, language,
     screen_width, screen_height, device, browser, event_id)
    VALUES (:domain, :url, :referrer, :agent, :ip, :timestamp, :language,
     :width, :height, :device, :browser, :event_id)
    ON CONFLICT(domain, event_id) DO NOTHING');
$query->execute(['domain' => $domain, 'url' => $pageUrl, 'referrer' => $referrer,
    'agent' => $userAgent, 'ip' => $ipAddress, 'timestamp' => time(), 'language' => $language,
    'width' => $dimensions['screen_width'], 'height' => $dimensions['screen_height'],
    'device' => $device, 'browser' => $browser, 'event_id' => $eventId]);
http_response_code(204);
