<?php
/** Authenticated JSON statistics. All date boundaries use the configured timezone. */
declare(strict_types=1);
define('BMETRICS_ENTRY', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    fail(405, 'Only GET is supported.');
}
start_admin_session();
require_authentication();
session_write_close(); // Parallel fetches must not serialize on the session lock.

function query_parameter(string $name): string
{
    $value = $_GET[$name] ?? '';
    if (!is_string($value)) { fail(400, 'Invalid query parameter: ' . $name); }
    return $value;
}
$domain = query_parameter('domain');
$connection = database();
$knownDomains = $connection->query('SELECT DISTINCT domain FROM hits')->fetchAll(PDO::FETCH_COLUMN);
$domains = array_values(array_unique(array_merge($allowed_domains, $knownDomains)));
if (!in_array($domain, $domains, true)) {
    fail(400, 'Select a known domain.');
}
function parse_report_date(string $input): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, $GLOBALS['report_timezone']);
    if (!$date || $date->format('Y-m-d') !== $input) {
        fail(400, 'Dates must be valid calendar dates in YYYY-MM-DD format.');
    }
    return $date;
}
$startDate = parse_report_date(query_parameter('start'));
$endDate = parse_report_date(query_parameter('end'));
$today = new DateTimeImmutable('today', $report_timezone);
if ($startDate > $endDate || $endDate > $today || (int) $startDate->diff($endDate)->days >= $max_date_range_days) {
    fail(400, 'Choose an ordered date range of at most ' . $max_date_range_days . ' days, ending today or earlier.');
}
$start = $startDate->getTimestamp();
$end = $endDate->modify('+1 day')->getTimestamp();
$parameters = ['domain' => $domain, 'start' => $start, 'end' => $end];
$where = 'domain = :domain AND timestamp >= :start AND timestamp < :end';

// A read transaction provides one consistent snapshot across cards, chart and tables.
$connection->beginTransaction();
try {
    $query = $connection->prepare('SELECT COUNT(*) AS pageviews, COUNT(DISTINCT ip_address) AS visitors FROM hits WHERE ' . $where);
    $query->execute($parameters);
    $totals = $query->fetch();
    $series = [];
    // Boundaries are computed in PHP: SQLite localtime cannot honor arbitrary IANA zones or DST reliably.
    $query = $connection->prepare('SELECT COUNT(*) AS pageviews, COUNT(DISTINCT ip_address) AS visitors FROM hits WHERE ' . $where);
    for ($date = $startDate; $date <= $endDate; $date = $date->modify('+1 day')) {
        $query->execute(['domain' => $domain, 'start' => $date->getTimestamp(), 'end' => $date->modify('+1 day')->getTimestamp()]);
        $row = $query->fetch();
        $series[] = ['date' => $date->format('Y-m-d'), 'pageviews' => (int) $row['pageviews'], 'visitors' => (int) $row['visitors']];
    }
    $tables = [];
    // Column names come only from this internal map, never from request input.
    foreach (['pages' => 'page_url', 'referrers' => 'referrer', 'devices' => 'device', 'browsers' => 'browser'] as $key => $column) {
        $query = $connection->prepare('SELECT ' . $column . ' AS label, COUNT(*) AS views FROM hits WHERE ' . $where .
            ' GROUP BY ' . $column . ' ORDER BY views DESC, label ASC LIMIT 10');
        $query->execute($parameters);
        $tables[$key] = array_map(static function (array $row): array {
            return ['label' => $row['label'] === '' ? 'Direct / unknown' : $row['label'], 'views' => (int) $row['views']];
        }, $query->fetchAll());
    }
    $connection->commit();
} catch (Throwable $error) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    throw $error;
}
json_response(['domain' => $domain, 'start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'),
    'timezone' => $timezone, 'totals' => ['pageviews' => (int) $totals['pageviews'], 'visitors' => (int) $totals['visitors']],
    'series' => $series, 'tables' => $tables]);
