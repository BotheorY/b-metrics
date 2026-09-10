<?php
/** Session authentication and CSRF checks for the administrative surface only. */
declare(strict_types=1);
if (!defined('BMETRICS_ENTRY')) { http_response_code(404); exit; }

function start_admin_session(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        str_starts_with(strtolower($GLOBALS['app_url']), 'https://');
    // A shared PHP session directory can be garbage-collected by another site
    // using a shorter gc_maxlifetime. Keep this application's sessions in its
    // private storage so the configured lifetime is authoritative.
    $sessionDirectory = $GLOBALS['storage_directory'] . '/sessions-' .
        substr(hash('sha256', dirname(__DIR__)), 0, 12);
    if (!is_dir($sessionDirectory) &&
        !@mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('Cannot create the private session directory.');
    }
    $sessionDirectory = realpath($sessionDirectory);
    if ($sessionDirectory === false ||
        !is_within($sessionDirectory, $GLOBALS['storage_directory']) ||
        !is_writable($sessionDirectory)) {
        throw new RuntimeException('Private session storage must be writable.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.save_handler', 'files');
    ini_set('session.save_path', $sessionDirectory);
    ini_set('session.gc_maxlifetime', (string) $GLOBALS['session_absolute_seconds']);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_name('bmetrics_' . substr(hash('sha256', dirname(__DIR__)), 0, 12));
    session_set_cookie_params(['lifetime' => $GLOBALS['session_absolute_seconds'], 'path' => '/', 'secure' => $secure,
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
