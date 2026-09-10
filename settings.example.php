<?php
/** B-Metrics configuration. Keep this file private and never commit real credentials. */
declare(strict_types=1);

// Required: replace this value with a long, unique password (at least 12 characters).
$admin_password = 'CHANGE-ME-BEFORE-USE';

// Exact hostnames only: no scheme, path or wildcard. Add www/subdomains separately.
// For internationalized domains, use their ASCII/Punycode representation.
$allowed_domains = ['example.com', 'www.example.com'];

// Optional exact IPv4 and IPv6 addresses to exclude from analytics. Addresses
// are normalized before comparison; leave this array empty to disable exclusion.
$excluded_ip_addresses = [];

// Optional canonical installation URL, without trailing slash, e.g.:
// https://analytics.example.com/b-metrics
// Set this behind an HTTPS-terminating reverse proxy to enforce secure cookies.
$app_url = '';
$timezone = 'Europe/Rome';

// null automatically chooses a private sibling of the server's document root.
// If that parent is not writable, specify an existing writable directory.
// Never use chmod 777. PHP alone needs write access.
$storage_directory = null;
// null uses <storage_directory>/bmetrics.sqlite; otherwise use an absolute path
// inside the private storage directory. SQLite creates the database automatically.
$database_path = null;
$pdo_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
];

// Idle timeout is refreshed by activity. Absolute timeout is measured from login
// and also controls how long the browser retains the session cookie.
$session_idle_seconds = 1800;
$session_absolute_seconds = 28800;
$login_attempt_limit = 8;
$login_window_seconds = 900;
$tracking_hits_per_minute = 180;
$max_date_range_days = 366;
$log_max_bytes = 2 * 1024 * 1024;
$log_backup_count = 3;
// Forwarded IP headers are deliberately ignored. REMOTE_ADDR is authoritative.
