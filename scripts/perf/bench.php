<?php

/**
 * HTTP latency bench for the staff app (docs/PERFORMANCE.md).
 *
 *   php scripts/perf/bench.php [base_url] [runs] [login]
 *   php scripts/perf/bench.php http://127.0.0.1:8190 9 admin
 *
 * Logs in through the demo login (DEMO_LOGINS=true), then issues GET requests only.
 * For query counts start the server with PERF_DEBUG=true (local env): the PerfDebug
 * middleware adds X-Debug-Queries / X-Debug-Query-Ms headers.
 * Prints median and p90 latency (ms), response bytes, query count and query time.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8190', '/');
$runs = (int) ($argv[2] ?? 9);
$login = $argv[3] ?? 'admin';
$jar = tempnam(sys_get_temp_dir(), 'bench');

function req(string $method, string $url, array $headers, string $jar, ?string $body = null): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$respHeaders) {
            if (str_contains($h, ':')) {
                [$k, $v] = explode(':', $h, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }

            return strlen($h);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $t = microtime(true);
    $out = curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $out, 'ms' => $ms, 'h' => $respHeaders];
}

function xsrf(string $jar): string
{
    foreach (file($jar) as $line) {
        $p = explode("\t", trim($line));
        if (count($p) >= 7 && $p[5] === 'XSRF-TOKEN') {
            return urldecode($p[6]);
        }
    }

    return '';
}

function pct(array $a, float $p): float
{
    sort($a);

    return $a[min(count($a) - 1, (int) ceil($p * count($a)) - 1)];
}

// Login page (anonymous) also seeds the XSRF cookie.
$targets = [];
$r = req('GET', "$base/login", [], $jar);
if ($r['status'] !== 200) {
    fwrite(STDERR, "login page returned {$r['status']}\n");
    exit(1);
}
$r = req('POST', "$base/demo-login/$login", ['X-XSRF-TOKEN: '.xsrf($jar), 'Accept: text/html'], $jar, '');
if (! in_array($r['status'], [302, 303, 409], true)) {
    fwrite(STDERR, "demo login returned {$r['status']}\n");
    exit(1);
}

// First full load gives the Inertia asset version and the active hotel.
$r = req('GET', "$base/dashboard", [], $jar);
// Inertia v2+ embeds the page in <script data-page="app" type="application/json">; older versions use a data-page attribute.
preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $r['body'], $m);
$page = json_decode($m[1] ?? '{}', true) ?: [];
if (! $page && preg_match('/data-page="(\{[^"]+)"/', $r['body'], $m)) {
    $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true) ?: [];
}
if (! $page) {
    fwrite(STDERR, "could not read the Inertia page from /dashboard\n");
}
$version = $page['version'] ?? '';
$hotel = $page['props']['property']['id'] ?? null;
$x = xsrf($jar);
$inertia = ['X-Inertia: true', "X-Inertia-Version: $version", 'X-Requested-With: XMLHttpRequest', 'Accept: text/html, application/xhtml+xml', "X-XSRF-TOKEN: $x"];
// Like the browser client: once props loaded on the first visit are not requested again.
if (! empty($page['onceProps'])) {
    $inertia[] = 'X-Inertia-Except-Once-Props: '.implode(',', array_keys($page['onceProps']));
}
$api = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', "X-XSRF-TOKEN: $x"];
if ($hotel) {
    $api[] = "X-Hotel-Id: $hotel";
}
$today = date('Y-m-d');
$to = date('Y-m-d', strtotime('+14 days'));

$targets = [
    ['login page (anon)', '/login', [], true],
    ['dashboard full load', '/dashboard', [], false],
    ['inertia /dashboard', '/dashboard', $inertia, false],
    ['inertia /reservations', '/reservations', $inertia, false],
    ['inertia /billing/folios', '/billing/folios', $inertia, false],
    ['inertia /pos', '/pos', $inertia, false],
    ['inertia /finance', '/finance', $inertia, false],
    ['inertia /stock', '/stock', $inertia, false],
    ['inertia /hr', '/hr', $inertia, false],
    ['inertia /tasks', '/tasks', $inertia, false],
    ['api reservations', '/api/v1/reservations', $api, false],
    ['api folios', '/api/v1/folios', $api, false],
    ['api dashboard compose', '/api/v1/reports/dashboard/compose', $api, false],
    ['api tape chart', "/api/v1/reservations/tape-chart?from=$today&to=$to", $api, false],
    ['api housekeeping board', '/api/v1/housekeeping/board', $api, false],
    ['api stock balances', '/api/v1/stock/balances', $api, false],
    ['api journal entries', '/api/v1/accounting/entries', $api, false],
    ['api guests', '/api/v1/guests', $api, false],
    ['api payroll runs', '/api/v1/payroll/runs', $api, false],
];
if (isset($argv[4])) {
    $targets = array_values(array_filter($targets, fn ($t) => str_contains($t[0], $argv[4])));
}

printf("base %s  runs %d  login %s  hotel %s  load %s\n", $base, $runs, $login, $hotel ?? '-', function_exists('sys_getloadavg') ? implode(',', sys_getloadavg() ?: []) : 'n/a');
printf("%-26s %6s %9s %9s %9s %6s %8s\n", 'target', 'status', 'median', 'p90', 'bytes', 'q', 'q_ms');
$anonJar = tempnam(sys_get_temp_dir(), 'anon');
foreach ($targets as [$label, $path, $headers, $anon]) {
    $times = [];
    $last = null;
    if (in_array('X-Inertia: true', $headers, true)) {
        $headers = $inertia; // current asset version
    }
    $w = req('GET', $base.$path, $headers, $anon ? $anonJar : $jar); // warm-up
    if ($w['status'] === 409) {
        // The asset version changed (a build ran meanwhile): read it again from a full load.
        $f = req('GET', "$base/dashboard", [], $jar);
        preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $f['body'], $m);
        $version = (json_decode($m[1] ?? '{}', true) ?: [])['version'] ?? $version;
        $headers = array_map(fn ($h) => str_starts_with($h, 'X-Inertia-Version:') ? "X-Inertia-Version: $version" : $h, $headers);
        $inertia = array_map(fn ($h) => str_starts_with($h, 'X-Inertia-Version:') ? "X-Inertia-Version: $version" : $h, $inertia);
    }
    for ($i = 0; $i < $runs; $i++) {
        $last = req('GET', $base.$path, $headers, $anon ? $anonJar : $jar);
        $times[] = $last['ms'];
    }
    printf("%-26s %6d %8.1fms %8.1fms %9d %6s %8s\n", $label, $last['status'], pct($times, 0.5), pct($times, 0.9), strlen($last['body']), $last['h']['x-debug-queries'] ?? '-', $last['h']['x-debug-query-ms'] ?? '-');
}
@unlink($jar);
@unlink($anonJar);
