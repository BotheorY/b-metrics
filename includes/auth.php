<?php
/** Session authentication and CSRF checks for the administrative surface only. */
declare(strict_types=1);
if (!defined('BMETRICS_ENTRY')) { http_response_code(404); exit; }

function start_admin_session(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        str_starts_with(strtolower($GLOBALS['app_url']), 'https://');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) $GLOBALS['session_absolute_seconds']);
    session_name('bmetrics_' . substr(hash('sha256', dirname(__DIR__)), 0, 12));
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $now = time();
    if (!empty($_SESSION['authenticated']) && (
        $now - (int) ($_SESSION['last_activity'] ?? 0) > $GLOBALS['session_idle_seconds'] ||
        $now - (int) ($_SESSION['authenticated_at'] ?? 0) > $GLOBALS['session_absolute_seconds'] ||
        !hash_equals(hash('sha256', $GLOBALS['admin_password']), (string) ($_SESSION['password_version'] ?? ''))
    )) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!empty($_SESSION['authenticated'])) {
        $_SESSION['last_activity'] = $now;
    }
    $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
}

function require_authentication(): void
{
    if (empty($_SESSION['authenticated'])) {
        fail(401, 'Your session has expired. Please sign in again.');
    }
}

function valid_csrf(mixed $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}
