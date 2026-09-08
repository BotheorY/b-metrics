<?php
/** Lazy SQLite connection, transactional migrations, and persistent rate limiting. */
declare(strict_types=1);
if (!defined('BMETRICS_ENTRY')) { http_response_code(404); exit; }

function database(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $options = $GLOBALS['pdo_options'];
    // Essential safety options cannot be overridden by optional tuning.
    $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
    $connection = new PDO('sqlite:' . $GLOBALS['database_path'], null, null, $options);
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA journal_mode = WAL');
    $connection->exec('PRAGMA synchronous = NORMAL');
    $version = (int) $connection->query('PRAGMA user_version')->fetchColumn();
    if ($version > 1) {
        throw new RuntimeException('The database schema is newer than this application.');
    }
    if ($version === 0) {
        // Serialize first-run setup across concurrent requests; DDL contains no user data.
        $connection->exec('BEGIN IMMEDIATE');
        try {
            $connection->exec('CREATE TABLE IF NOT EXISTS hits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain TEXT NOT NULL,
                page_url TEXT NOT NULL,
                referrer TEXT NOT NULL DEFAULT \'\',
                user_agent TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                language TEXT NOT NULL DEFAULT \'\',
                screen_width INTEGER NOT NULL DEFAULT 0,
                screen_height INTEGER NOT NULL DEFAULT 0,
                device TEXT NOT NULL,
                browser TEXT NOT NULL,
                event_id TEXT NOT NULL,
                UNIQUE(domain, event_id)
            )');
            $connection->exec('CREATE INDEX IF NOT EXISTS hits_domain_time ON hits(domain, timestamp)');
            $connection->exec('CREATE INDEX IF NOT EXISTS hits_domain_ip_time ON hits(domain, ip_address, timestamp)');
            $connection->exec('CREATE TABLE IF NOT EXISTS rate_limits (
                bucket_key TEXT PRIMARY KEY,
                hits INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )');
            $connection->exec('CREATE INDEX IF NOT EXISTS rate_limits_expiry ON rate_limits(expires_at)');
            $connection->exec('PRAGMA user_version = 1');
            $connection->exec('COMMIT');
        } catch (Throwable $error) {
            $connection->exec('ROLLBACK');
            throw $error;
        }
    }
    return $connection;
}

/** Atomic fixed-window limiter. Keys are hashes; neither raw IPs nor passwords are logged. */
function consume_rate_limit(string $key, int $limit, int $window): bool
{
    $connection = database();
    $now = time();
    $key = hash('sha256', $key);
    $connection->exec('BEGIN IMMEDIATE');
    try {
        $query = $connection->prepare('SELECT hits, expires_at FROM rate_limits WHERE bucket_key = :key');
        $query->execute(['key' => $key]);
        $row = $query->fetch();
        $allowed = !$row || (int) $row['expires_at'] <= $now || (int) $row['hits'] < $limit;
        if ($allowed) {
            $newWindow = !$row || (int) $row['expires_at'] <= $now;
            $query = $connection->prepare('INSERT INTO rate_limits (bucket_key, hits, expires_at)
                VALUES (:key, :hits, :expiry) ON CONFLICT(bucket_key) DO UPDATE
                SET hits = excluded.hits, expires_at = excluded.expires_at');
            $query->execute(['key' => $key, 'hits' => $newWindow ? 1 : (int) $row['hits'] + 1,
                'expiry' => $newWindow ? $now + $window : (int) $row['expires_at']]);
        }
        // Bounded cleanup on each request avoids unbounded expired-key accumulation.
        $query = $connection->prepare('DELETE FROM rate_limits WHERE bucket_key IN
            (SELECT bucket_key FROM rate_limits WHERE expires_at <= :now LIMIT 100)');
        $query->execute(['now' => $now]);
        $connection->exec('COMMIT');
        return $allowed;
    } catch (Throwable $error) {
        $connection->exec('ROLLBACK');
        throw $error;
    }
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('The server did not provide a valid client IP address.');
    }
    // Normalize equivalent IPv6 representations before counting distinct visitors.
    return (string) inet_ntop(inet_pton($ip));
}
