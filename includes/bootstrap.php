<?php
/** Shared bootstrap, configuration validation, logging, and HTTP primitives. */
declare(strict_types=1);

if (!defined('BMETRICS_ENTRY')) {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
umask(0077);
$GLOBALS['request_id'] = bin2hex(random_bytes(8));
$GLOBALS['log_directory'] = null;
$GLOBALS['response_format'] = $GLOBALS['response_format'] ?? 'json';

/** Write bounded JSON lines under an exclusive lock; fall back to PHP's error log. */
function app_log(string $level, string $message, array $context = []): void
{
    static $active = false;
    if ($active) {
        return;
    }
    $active = true;
    try {
        $record = json_encode([
            'timestamp' => gmdate('c'), 'level' => $level,
            'request_id' => $GLOBALS['request_id'],
            'message' => substr($message, 0, 2000), 'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $directory = $GLOBALS['log_directory'];
        $written = false;
        if (is_string($directory)) {
            $lock = @fopen($directory . '/log.lock', 'c');
            if ($lock !== false) {
                if (@flock($lock, LOCK_EX)) {
                    $file = $directory . '/application.log';
                    clearstatcache(true, $file);
                    if (is_file($file) && filesize($file) >= ($GLOBALS['log_max_bytes'] ?? 2097152)) {
                        $backups = $GLOBALS['log_backup_count'] ?? 3;
                        for ($index = $backups; $index >= 1; --$index) {
                            $source = $index === 1 ? $file : $file . '.' . ($index - 1);
                            if (is_file($source)) {
                                @rename($source, $file . '.' . $index);
                            }
                        }
                    }
                    $written = @file_put_contents($file, $record . PHP_EOL, FILE_APPEND) !== false;
                    @flock($lock, LOCK_UN);
                }
                fclose($lock);
            }
        }
        if (!$written) {
            error_log('[B-Metrics] ' . $record);
        }
    } catch (Throwable $error) {
        error_log('[B-Metrics] Logging failed. Request ' . $GLOBALS['request_id']);
    } finally {
        $active = false;
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    app_log('warning', $message, ['file' => basename($file), 'line' => $line]);
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(static function (Throwable $error): void {
    app_log('error', $error->getMessage(), [
        'exception' => get_class($error), 'file' => basename($error->getFile()),
        'line' => $error->getLine(),
    ]);
    fail(503, 'The service is temporarily unavailable. Check server configuration and logs.');
});
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log('critical', $error['message'], ['file' => basename($error['file']), 'line' => $error['line']]);
        if (!headers_sent()) {
            fail(503, 'The service is temporarily unavailable.');
        }
    }
});

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message): never
{
    if (($GLOBALS['response_format'] ?? '') === 'html') {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>B-Metrics</title><h1>B-Metrics</h1><p>'
            . escape($message) . '</p><p>Request ID: ' . escape($GLOBALS['request_id']) . '</p></html>';
        exit;
    }
    json_response(['error' => $message, 'request_id' => $GLOBALS['request_id']], $status);
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Normalize exact ASCII hostnames without treating suffixes as trusted domains. */
function normalize_host(string $host): string
{
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || strlen($host) > 253 ||
        !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        throw new InvalidArgumentException('Invalid domain name. Use an ASCII hostname.');
    }
    return $host;
}

/** Parse an HTTP URL, rejecting credentials, malformed UTF-8 and control characters. */
function parse_http_url(string $url, bool $originOnly = false): ?array
{
    if ($url === '' || strlen($url) > 4096 || !preg_match('//u', $url) ||
        preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
        !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
        isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    if ($originOnly && (isset($parts['query']) || isset($parts['fragment']) ||
        (isset($parts['path']) && $parts['path'] !== ''))) {
        return null;
    }
    try {
        $parts['host'] = normalize_host($parts['host']);
    } catch (InvalidArgumentException $error) {
        return null;
    }
    return $parts;
}

function is_within(string $path, string $directory): bool
{
    $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
    $path = str_replace('\\', '/', $path);
    if (PHP_OS_FAMILY === 'Windows') {
        $directory = strtolower($directory);
        $path = strtolower($path);
    }
    return $path === rtrim($directory, '/') || str_starts_with($path, $directory);
}

require dirname(__DIR__) . '/settings.php';
if (PHP_VERSION_ID < 80100 || !extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('PHP 8.1+ with PDO SQLite is required.');
}
if (!is_string($admin_password) || strlen($admin_password) < 12 || $admin_password === 'CHANGE-ME-BEFORE-USE') {
    fail(503, 'Setup required: set a unique password of at least 12 characters in settings.php.');
}
if (!is_array($allowed_domains) || $allowed_domains === []) {
    throw new RuntimeException('Configure at least one allowed domain in settings.php.');
}
$allowed_domains = array_values(array_unique(array_map('normalize_host', $allowed_domains)));
$report_timezone = new DateTimeZone($timezone);
if ($app_url !== '' && (parse_http_url($app_url) === null ||
    isset(parse_http_url($app_url)['query']) || isset(parse_http_url($app_url)['fragment']))) {
    throw new RuntimeException('Invalid canonical app URL.');
}
foreach (['session_idle_seconds', 'session_absolute_seconds', 'login_attempt_limit',
    'login_window_seconds', 'tracking_hits_per_minute', 'max_date_range_days',
    'log_max_bytes', 'log_backup_count'] as $setting) {
    if (!is_int($$setting) || $$setting < 1) {
        throw new RuntimeException('Invalid numeric setting: ' . $setting);
    }
}

// Resolve the actual document root, including symlinks, before creating private files.
$document_root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: realpath(dirname(__DIR__));
$default_storage = dirname($document_root) . '/b-metrics-private-' . substr(hash('sha256', dirname(__DIR__)), 0, 12);
$storage_directory = $storage_directory ?? $default_storage;
if (!is_string($storage_directory) || $storage_directory === '' ||
    !preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $storage_directory)) {
    throw new RuntimeException('Storage directory must be an absolute path.');
}
$storage_parent = realpath(dirname($storage_directory));
$prospective_storage = $storage_parent === false ? '' : $storage_parent . '/' . basename($storage_directory);
if ($prospective_storage === '') {
    throw new RuntimeException('Missing storage folder.');
}
// Another first-run request may create the directory between these checks.
if (!is_dir($storage_directory) && !@mkdir($storage_directory, 0700, true) && !is_dir($storage_directory)) {
    throw new RuntimeException('Cannot create private storage directory.');
}
$storage_directory = realpath($storage_directory);
if ($storage_directory === false || !is_writable($storage_directory)) {
    throw new RuntimeException('Private storage must be writable.');
}
$GLOBALS['log_directory'] = $storage_directory;
$database_path = $database_path ?? $storage_directory . '/bmetrics.sqlite';
$database_parent = realpath(dirname($database_path));
if ($database_parent === false || !is_within($database_parent, $storage_directory) ||
    (file_exists($database_path) && !is_within((string) realpath($database_path), $storage_directory))) {
    throw new RuntimeException('The database must be located inside private storage.');
}
require __DIR__ . '/database.php';
