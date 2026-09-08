# B-Metrics

A small, self-hosted website analytics application.

## Contents

- [Requirements](#requirements)
- [Install](#install)
  - [Apache](#apache)
  - [Content Security Policy on monitored websites](#content-security-policy-on-monitored-websites)
- [Dashboard](#dashboard)
- [Collector contract](#collector-contract)
  - [Tracker behavior](#tracker-behavior)
- [Authentication and data protection](#authentication-and-data-protection)
- [Database and logs](#database-and-logs)
- [Files](#files)
- [Troubleshooting](#troubleshooting)
- [License](#license)
- [Implementation references](#implementation-references)

## Requirements

- PHP **8.1 or later**, with PDO, **pdo_sqlite**, sessions, JSON and standard PHP functions enabled.
- A PHP-capable web server, HTTPS for production, and a writable storage directory whose parent already exists. The application does not require this directory to be outside the public document root, but using a location that the web server cannot serve is strongly recommended.
- SQLite on a local filesystem with working file locks; do not place it on an NFS/network share.
- A browser with modern JavaScript support. Bootstrap 5.3.3 CSS and Chart.js 4.4.8 are loaded from jsDelivr using pinned versions and SHA-384 integrity checks.

**No Composer, framework, React, Vue, Angular, Node.js, npm, bundler, or build step is used or required.**

## Install

1. Extract the archive and upload the contents of `b-metrics/` into your PHP site's desired directory, for example `public_html/b-metrics/`. Upload `.htaccess` files too if using Apache.
2. Copy `settings.example.php` to `settings.php`, then edit the new `settings.php` file:
   ```php
   $admin_password = 'replace-with-your-own-long-unique-password';
   $allowed_domains = ['example.com', 'www.example.com', 'another-site.it'];
   $app_url = 'https://analytics.example.com/b-metrics';
   $timezone = 'Europe/Rome';
   ```
   The password is stored **in clear text**, intentionally matching the specification. The supplied placeholder is rejected: both the dashboard and collector return a setup response until it is changed. The password must be at least 12 characters. Keep configuration files and backups private.
3. Leave `$storage_directory = null` for the default storage location. For a document root such as `/home/account/public_html`, the app chooses `/home/account/b-metrics-private-<installation-hash>/`. A custom value must be an absolute path, its parent directory must already exist, and the resulting storage directory must be writable by PHP. For production, choose a location that the web server cannot serve:
   ```php
   $storage_directory = '/home/account/private-b-metrics';
   $database_path = null; // /home/account/private-b-metrics/bmetrics.sqlite
   ```
   You can instead set `$database_path` to a filename whose parent directory resolves **inside the storage directory**; use an absolute path for predictable deployment configuration. The database parent directory must exist and resolve within `$storage_directory`; an existing database file must resolve there too. The application may create the storage directory, but not its parent. It does not prohibit storage within the public document root, so do not use a web-accessible location for logs or database files. Prefer directory mode 0700 and files 0600 with the PHP process as owner. Do not use 0777.
4. Open `https://analytics.example.com/b-metrics/`. The database, indexes and rate-limit table are created automatically on the first configured visit. No SQL import or installer page is needed.
5. Sign in. Select a website and use **Tracking snippet → Copy snippet**. Paste that snippet once in each monitored page's `<head>` or `<body>`.
6. Visit a monitored page, then refresh the dashboard for **Today**. The tracker must be allowed by that website's Content Security Policy and by the visitor's browser/content-blocker settings.

An example generated snippet is:

```html
<script async src="https://analytics.example.com/b-metrics/assets/tracker.js"></script>
```

The same snippet works on every whitelisted website: the actual page URL determines its domain. The installation may be at the web root or in a subdirectory. Configuring `$app_url` is especially useful when the dashboard has multiple aliases or sits behind an HTTPS-terminating proxy. Its scheme also controls the session cookie's Secure flag. Enforce HTTPS and redirect HTTP at the web server; the application does not trust forwarded-protocol headers or perform redirects from untrusted Host headers.

### Apache

The provided Apache 2.4 `.htaccess` rules deny configuration, internal code, and common backups, and disable directory indexes. The host must allow the relevant overrides. If it returns a 500 because `Options` or `Require` overrides are prohibited, ask the host to place equivalent rules in its server configuration; do not expose private data to work around it.

After deployment, verify that direct requests to `/settings.php`, `/includes/`, and backup filenames cannot disclose source code. If storage is under a web-served directory, ensure the server explicitly denies access to its database, logs, lock file and backups; an unserved storage location is safer.

### Content Security Policy on monitored websites

If a monitored site uses CSP, allow the analytics installation's origin in its existing `script-src` and `connect-src` directives. For example, extend those directives with `https://analytics.example.com`. Merge this into the existing policy; do not replace it or add broad wildcards. If the site only allows nonce-based scripts, give the snippet the site's own current nonce.

The dashboard has its own restrictive CSP, frame protection and no-store responses. Only the pinned CDN assets and same-origin application assets are needed. No Bootstrap JavaScript bundle is necessary.

## Dashboard

- **Website:** union of configured hosts and hosts with historical data. Removing a host from the whitelist stops new collection; its historical statistics remain available.
- **Date range:** Today, Last 7 days, Last 30 days, Last calendar month, or custom dates. Last 7/30 days include today. Both date fields are inclusive, with a configurable default limit of 366 calendar days. Future dates and reversed intervals are rejected.
- **Total pageviews:** accepted, deduplicated hits in that domain and interval.
- **Unique visitors:** distinct IP addresses across the **entire interval**. This is not the sum of daily unique visitors. Shared networks and changing addresses make it an estimate, not a count of identified people.
- **Traffic over time:** daily pageviews and distinct daily IPs; empty days are filled with zero. An accessible daily table exposes exact values and remains available if Chart.js cannot load.
- **Top pages / Top referrers:** top 10 exact full URLs by pageviews. An empty referrer is displayed as `Direct / unknown`. Query strings and fragments are not aggregated away.
- **Devices / Browsers:** top categories based on lightweight server-side user-agent heuristics. Bots are included and labeled when recognizable. Reduced/spoofed user agents can be misclassified; an iPad using a desktop user agent may appear as Desktop.

Filter changes use fetch and replace the results without a page reload. In-flight requests are aborted when superseded. Network and server errors are shown without presenting stale statistics as current. A 15-second client timeout reports an error; use **Refresh** to try again. Statistics refresh on filter changes or **Refresh**, not by background polling. Reporting dates use the configured IANA timezone, including 23/25-hour daylight-saving days. Reload a long-open dashboard after midnight to advance its **Today** reference.

## Collector contract

`track.php` accepts **POST** requests with a JSON object, sent as either `text/plain;charset=UTF-8` (preferred to avoid preflight) or `application/json`. **OPTIONS** supports CORS preflight. GET is intentionally unsupported and returns 405.

```json
{
  "url": "https://example.com/articles/welcome",
  "referrer": "https://search.example/",
  "language": "en-GB",
  "screen_width": 1920,
  "screen_height": 1080,
  "event_id": "6d2dfcb74fbf4d5eb9b0f06b1d963a70"
}
```

- `Origin` is required if present and must identify an exact whitelisted hostname; an invalid or `null` Origin is **never** rescued by Referer.
- If Origin is absent, the HTTP **Referer header** is used. This header describes the sending website and is separate from the JSON `referrer`, which describes how a visitor reached that page.
- Only HTTP/HTTPS origins are accepted. Ports and scheme do not create separate domains. Host case and trailing DNS dots are normalized. `www.example.com` and `example.com` are separate hosts; subdomains are not implicitly trusted. Use ASCII/Punycode for internationalized hosts.
- The page URL must have the same normalized host as the accepted source. A client-supplied `domain`, timestamp, IP or user agent does not override the server's values.
- The stored IP comes exclusively from `REMOTE_ADDR`, normalized with `inet_pton`/`inet_ntop`. Forwarded-IP headers are ignored. Configure trusted-proxy IP restoration at the web server if applicable; otherwise proxy users may share the proxy IP and its rate limit.
- JSON body limit: 16 KiB. URL and referrer: 4096 bytes each. User agent: at most 1024 bytes. Language: at most 64 bytes. Screen dimensions: integer 0–32768. Unknown screen dimensions default to zero. Referrer may be empty; nonempty referrers must be HTTP/HTTPS URLs without embedded credentials. Invalid fields reject the whole event.
- `event_id` is optional for direct API use (server-generated when absent). Supplied IDs require 16–100 ASCII letters, digits, hyphens or underscores. The generated tracker supplies a random ID. A unique `(domain, event_id)` constraint prevents duplicate storage, although retries still consume rate-limit capacity.
- Success or an already-stored event: **204**. Invalid payload: **400**. Untrusted source: **403**. Unsupported method: **405**. Too large: **413**. Unsupported media type: **415**. Throttled: **429** with `Retry-After`. Setup/database/server error: **503**, with a request ID.
- Default tracking limit: 180 attempts/minute for each IP/domain pair. The implementation uses an atomic SQLite fixed-window counter. Expired counters are cleaned in bounded batches.

**CORS is a browser rule, not proof that a remote sender owns a website.** A non-browser client can forge Origin/Referer. The allowlist prevents ordinary unauthorized browser-origin collection, but it cannot make a public analytics collector immune to fabricated traffic. Per-IP throttling limits basic abuse; no browser-visible secret would solve this.

### Tracker behavior

The tiny external script is asynchronous, does not use cookies or local storage, and never blocks page navigation. It sends one event per document load, avoids duplicate installation of the same snippet, and waits for prerender activation. It uses fetch with `credentials: 'omit'`, `keepalive: true`, and a plain-text JSON body. Beacon is a fallback only when fetch is unavailable; the server never uses collector cookies. Sending failures are intentionally silent, with no automatic retries.

This essential version counts normal page loads. It does **not** hook SPA routing, count bfcache restores as new page loads, identify sessions, or implement events, conversion goals, bounce rate, geolocation, campaign attribution, realtime streaming or cross-device identity. A page URL from a disallowed host, blocked scripts, restrictive CSP, missing source headers or browser blocking can cause a visit to be absent.

## Authentication and data protection

The admin password is compared using constant-time comparison of fixed-size digests. Sessions use cookie-only strict mode, HttpOnly, SameSite=Strict, and Secure when HTTPS is detected or configured. Successful login regenerates the identifier. Login and logout require CSRF tokens; logout is POST only. Default idle timeout is 30 minutes; absolute lifetime is 8 hours. A password change invalidates existing authenticated sessions. Default login limit is eight attempts per IP in a 15-minute fixed window, including successful attempts; it persists across sessions.

Dashboard statistics require authentication and return no-store JSON. Session locks are released before expensive read queries. Data values are bound through prepared statements. Dynamic SQL identifiers come only from internal fixed maps. The DOM displays untrusted strings using `textContent`, and PHP escapes HTML and embedded configuration.

As requested, the application stores **full IP addresses, full page URLs, referrers, user agents, language and screen dimensions**. It does not anonymize them or automatically delete historical hits. Take these facts into account when deciding what to monitor and how to configure the site's notices, collection controls, backups and retention. No legal-compliance certification is implied. If collection must wait for a consent decision, insert/load the snippet only when the site's consent manager permits it; B-Metrics itself is not a consent manager.

## Database and logs

`includes/database.php` lazily opens PDO SQLite and applies schema version 1 inside a write transaction. WAL mode, a 5-second busy timeout and an indexed domain/time filter support moderate concurrent collection. SQLite serializes writes; this is a lightweight application, not a high-volume distributed analytics service. Stats use a read transaction to keep cards, series and tables on one snapshot.

`hits` contains `id`, `domain`, `page_url`, `referrer`, `user_agent`, `ip_address`, `timestamp` (server Unix seconds), `language`, `screen_width`, `screen_height`, `device`, `browser`, and `event_id`. Indexes cover domain/time, domain/IP/time and event deduplication. `rate_limits` contains hashed keys, counters and expiries. Schema version is recorded with `PRAGMA user_version`; newer schemas are rejected rather than silently downgraded.

Structured JSON logs are stored as `<storage_directory>/application.log`. Entries contain UTC timestamp, severity, request ID, message and bounded error context. Warning/exception handlers capture application failures while client errors remain sanitized. The logger uses an exclusive lock and rotates at 2 MiB, retaining three backups by default; if writing fails, it falls back to PHP's configured error log. It does not deliberately log passwords, full request bodies, tracked URLs or raw IPs. Rejected collector input is not logged per event to avoid log amplification. PHP parse/startup failures that occur before bootstrap executes rely on the server's PHP log; configure `display_errors=Off` in production as well.

To back up a live WAL database, use SQLite's online backup facility or the SQLite CLI `.backup` command. Do not copy only the main `.sqlite` file while writes are active: committed data may still be in its WAL file. Alternatively, stop writes and use a consistent backup of the storage directory. Keep configuration and database backups in a location the web server cannot serve. Log retention is automatic; analytics retention is an administrator policy and must be implemented separately if desired.

## Files

| File | Purpose |
| --- | --- |
| `settings.example.php` | Configuration template for the password, exact hostname allowlist, PDO/storage setup, timezone and limits; copy it to the ignored local `settings.php` file before use. |
| `includes/bootstrap.php` | Configuration validation, storage/database-path checks, logging/error handlers, HTTP and escaping helpers. |
| `includes/database.php` | PDO connection, transactional schema, indexes and atomic rate limiter. |
| `includes/auth.php` | Session lifecycle, authentication and CSRF helpers. |
| `includes/user_agent.php` | Small device/browser classifier. |
| `track.php` | Cross-origin collection endpoint and payload validation. |
| `index.php` | Login/logout and responsive English dashboard HTML. |
| `stats.php` | Authenticated, date-aware statistics JSON endpoint. |
| `assets/dashboard.js` | Fetch, filters, safe tables, Chart.js and snippet generation. |
| `assets/tracker.js` | Asynchronous website tracking script. |
| `assets/app.css` | Dashboard/login styling and mobile/tablet layouts. |
| `assets/favicon.svg` | App icon. |
| `.htaccess` and directory `.htaccess` files | Apache defense-in-depth access restrictions. |
| `LICENSE` | MIT License terms. |
| `README.md` | Technical reference. |

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Setup-required message | Replace the placeholder password in `settings.php`. |
| 503 with request ID | Match the ID in the application log in `$storage_directory` or the PHP error log; check PDO SQLite and storage-directory permissions. |
| 403 on a monitored page | Exact hostname (including `www`), Origin/Referer headers, CSP, and whether the HTML snippet points to the intended installation. |
| 400 for a hit | Inspect the response JSON; check URL host, field types/lengths, non-HTTP referrers and event ID format. |
| No graph but tables work | CDN connectivity or CSP/SRI errors; use the daily data table. |
| Counts look lower than expected | Content blockers, restrictive referrer policies, same-IP visitors, request limits, monitored page coverage and timezone. |
| All visits have one IP | Reverse proxy setup; B-Metrics deliberately ignores untrusted forwarded headers. |
| Locked database / 503 during traffic spikes | Correct filesystem locks, local disk, permissions, write contention and hosting limits. |
| Clipboard copy unavailable | Use the automatically selected snippet and Ctrl+C / Command+C; deploy over HTTPS. |

## License

This project is licensed under the [MIT License](LICENSE). Copyright (c) 2026 Botheory Labs.

## Implementation references

- [PHP session security settings](https://www.php.net/manual/en/session.security.ini.php)
- [PHP session cookie parameters](https://www.php.net/manual/en/function.session-set-cookie-params.php)
- [Bootstrap CDN setup and integrity attributes](https://getbootstrap.com/docs/5.3/getting-started/introduction/)
- [Chart.js standalone script integration](https://www.chartjs.org/docs/latest/getting-started/integration.html)
