<?php
/** Password login and the administrative dashboard. UI text is deliberately English. */
declare(strict_types=1);
define('BMETRICS_ENTRY', true);
$GLOBALS['response_format'] = 'html';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';
$nonce = base64_encode(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=utf-8');
start_admin_session();
$errorMessage = '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
    header('Allow: GET, HEAD, POST'); fail(405, 'Method not allowed.');
}
if ($method === 'POST') {
    if (!valid_csrf($_POST['csrf_token'] ?? null)) {
        fail(403, 'Invalid form token. Reload the sign-in page and try again.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        $_SESSION = [];
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'],
            'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
        header('Location: index.php', true, 303); exit;
    }
    if ($action !== 'login') { fail(400, 'Unknown action.'); }
    if (!consume_rate_limit('login:' . client_ip(), $login_attempt_limit, $login_window_seconds)) {
        header('Retry-After: ' . $login_window_seconds);
        http_response_code(429);
        $errorMessage = 'Too many sign-in attempts. Please try again later.';
        app_log('warning', 'Login rate limit reached.');
    } else {
        $submitted = $_POST['password'] ?? '';
        if (is_string($submitted) && strlen($submitted) <= 4096 && hash_equals(hash('sha256', $admin_password), hash('sha256', $submitted))) {
            session_regenerate_id(true);
            $_SESSION = ['authenticated' => true, 'authenticated_at' => time(), 'last_activity' => time(),
                'password_version' => hash('sha256', $admin_password), 'csrf_token' => bin2hex(random_bytes(32))];
            app_log('info', 'Administrator signed in.');
            header('Location: index.php', true, 303); exit;
        }
        http_response_code(401);
        $errorMessage = 'The password is incorrect.';
        app_log('warning', 'Unsuccessful sign-in attempt.');
    }
}
$authenticated = !empty($_SESSION['authenticated']);
$csrfToken = $_SESSION['csrf_token'];
session_write_close();
// Create the schema on the first configured visit, including the login page.
$connection = database();
$domains = [];
if ($authenticated) {
    $domains = array_values(array_unique(array_merge($allowed_domains,
        $connection->query('SELECT DISTINCT domain FROM hits')->fetchAll(PDO::FETCH_COLUMN))));
    sort($domains, SORT_STRING);
}
$today = new DateTimeImmutable('today', $report_timezone);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <title><?= $authenticated ? 'Overview' : 'Sign in' ?> · B-Metrics</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="assets/app.css" rel="stylesheet">
<?php if ($authenticated): ?>
    <script nonce="<?= escape($nonce) ?>" id="app-config" type="application/json"><?= json_encode([
        'today' => $today->format('Y-m-d'), 'timezone' => $timezone, 'allowedDomains' => $allowed_domains,
        'appUrl' => rtrim($app_url, '/'), 'maxDays' => $max_date_range_days,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.js" integrity="sha384-sitkSLEO56SJYRloHWybrHos3JVvByQSlOZYJ1HiExytjxUlVOernDbpQcOgwtW8" crossorigin="anonymous" defer></script>
    <script src="assets/dashboard.js" defer></script>
<?php endif; ?>
</head>
<body class="<?= $authenticated ? 'dashboard-body' : 'login-body' ?>">
<?php if (!$authenticated): ?>
    <main class="login-shell">
        <a class="brand login-brand" href="index.php"><span class="brand-mark" aria-hidden="true">B</span>B-Metrics</a>
        <section class="login-card" aria-labelledby="login-title">
            <span class="eyebrow">YOUR ANALYTICS WORKSPACE</span>
            <h1 id="login-title">Welcome back.</h1>
            <p class="text-muted">Sign in to see how your websites are doing.</p>
            <?php if ($errorMessage !== ''): ?><div class="alert alert-danger" role="alert"><?= escape($errorMessage) ?></div><?php endif; ?>
            <form method="post" action="index.php">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                <label class="form-label" for="password">Dashboard password</label>
                <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required maxlength="4096" autofocus>
                <button class="btn btn-primary btn-lg login-submit" type="submit">Sign in <span aria-hidden="true">→</span></button>
            </form>
        </section>
        <p class="login-footnote">B-Metrics · Simple website analytics</p>
    </main>
<?php else: ?>
    <a href="#main" class="skip-link">Skip to content</a>
    <aside class="sidebar" aria-label="Workspace">
        <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">B</span>B-Metrics</a>
        <div class="sidebar-label">WORKSPACE</div>
        <nav aria-label="Main navigation"><a class="nav-item active" href="#main" aria-current="page"><span aria-hidden="true">▥</span> Overview</a><a class="nav-item" href="#tracking"><span aria-hidden="true">⌘</span> Tracking setup</a></nav>
        <div class="sidebar-bottom"><p>Every visit.<br>A clearer picture.</p><form method="post" action="index.php"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>"><button class="sign-out" type="submit">Sign out <span aria-hidden="true">↗</span></button></form></div>
    </aside>
    <main id="main" class="workspace">
        <header class="page-header"><div><span class="eyebrow">WEBSITE ANALYTICS</span><h1>Overview</h1><p class="text-muted">Your traffic, at a glance.</p></div><div class="timezone-label">Reporting timezone<br><strong><?= escape($timezone) ?></strong></div></header>
        <form id="filters" class="filter-bar" aria-label="Analytics filters">
            <div class="domain-field"><label for="domain" class="form-label">Website</label><select id="domain" name="domain" class="form-select"><?php foreach ($domains as $domain): ?><option value="<?= escape($domain) ?>"><?= escape($domain) ?></option><?php endforeach; ?></select></div>
            <div><label for="period" class="form-label">Date range</label><select id="period" class="form-select"><option value="today">Today</option><option value="7" selected>Last 7 days</option><option value="30">Last 30 days</option><option value="month">Last calendar month</option><option value="custom">Custom dates</option></select></div>
            <div><label for="start" class="form-label">From</label><input id="start" class="form-control" type="date" required max="<?= $today->format('Y-m-d') ?>"></div>
            <div><label for="end" class="form-label">To</label><input id="end" class="form-control" type="date" required max="<?= $today->format('Y-m-d') ?>"></div>
            <button id="refresh" type="submit" class="btn btn-outline-primary">Refresh</button>
        </form>
        <noscript><div class="alert alert-warning">JavaScript is required to load statistics and generate the tracking snippet.</div></noscript>
        <div id="status" class="status-line" role="status" aria-live="polite">Loading analytics…</div>
        <div id="error" class="alert alert-danger" role="alert" hidden></div>
        <section id="results" aria-label="Traffic statistics" aria-busy="true">
            <div class="metric-grid">
                <article class="metric-card"><div class="metric-label">Total pageviews<span class="metric-symbol" aria-hidden="true">↗</span></div><div class="metric-value" id="pageviews">—</div><p>Page loads in the selected period</p></article>
                <article class="metric-card"><div class="metric-label">Unique visitors<span class="metric-symbol" aria-hidden="true">◎</span></div><div class="metric-value" id="visitors">—</div><p>Distinct IP addresses across this period</p></article>
            </div>
            <section class="surface chart-panel" aria-labelledby="traffic-heading">
                <div class="panel-heading"><div><h2 id="traffic-heading">Traffic over time</h2><p id="range-label">Daily pageviews and visitors</p></div><div class="chart-key"><span class="key-views">Pageviews</span><span class="key-visitors">Visitors</span></div></div>
                <div id="empty-state" class="empty-state" hidden>No visits yet. Add the tracking snippet to your website, or choose another date range.</div>
                <div class="chart-wrap"><canvas id="traffic-chart" role="img" aria-label="Daily traffic chart. Exact values are available in the daily data table below."></canvas></div>
                <p id="chart-warning" class="chart-warning" hidden>The chart library could not load. You can still view the daily data below.</p>
                <details class="daily-details"><summary>View daily data</summary><div class="table-responsive"><table class="table"><thead><tr><th scope="col">Date</th><th scope="col">Pageviews</th><th scope="col">Visitors</th></tr></thead><tbody id="daily-body"></tbody></table></div></details>
            </section>
            <div class="table-grid">
                <?php foreach (['pages' => 'Top pages', 'referrers' => 'Top referrers', 'devices' => 'Devices', 'browsers' => 'Browsers'] as $key => $title): ?>
                <section class="surface table-panel"><div class="panel-heading"><h2><?= $title ?></h2><span class="subtle">By pageviews</span></div><div class="table-responsive"><table class="table ranking-table"><thead><tr><th scope="col"><?= $key === 'pages' ? 'Page URL' : ($key === 'referrers' ? 'Source URL' : rtrim($title, 's')) ?></th><th scope="col" class="text-end">Views</th><th scope="col" class="text-end">Share</th></tr></thead><tbody id="<?= $key ?>-body"><tr><td colspan="3" class="text-muted">Loading…</td></tr></tbody></table></div></section>
                <?php endforeach; ?>
            </div>
        </section>
        <section id="tracking" class="surface tracking-panel" aria-labelledby="tracking-title">
            <div class="panel-heading"><div><span class="eyebrow">CONNECT YOUR WEBSITE</span><h2 id="tracking-title">Tracking snippet</h2><p>Paste this once inside the &lt;head&gt; or &lt;body&gt; of each page you want to measure.</p></div><button id="copy-snippet" class="btn btn-primary" type="button">Copy snippet</button></div>
            <p id="snippet-notice" class="subtle"></p><label class="visually-hidden" for="snippet">Tracking code</label><textarea id="snippet" class="snippet-code" readonly rows="4" spellcheck="false"></textarea><div id="copy-status" role="status" aria-live="polite"></div>
        </section>
        <footer class="workspace-footer">B-Metrics<span>Unique visitors are estimates based on IP addresses. Automated traffic is included.</span></footer>
    </main>
<?php endif; ?>
</body>
</html>
