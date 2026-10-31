<?php
// CLI-only: the test suite must never be executable over HTTP. On this host
// the document root is the project root, so without this guard a GET on
// /tests/run_tests.php would execute the suite for anonymous visitors.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
?>
<?php
/**
 * Authenticated smoke test: logs in and exercises the pages that only an
 * admin can reach, including the malformed parameters that used to 500.
 *
 * Usage:  php tests/smoke_auth.php [base-url] [admin-user] [admin-pass]
 * Get the dev credentials from: php tests/seed_dev.php
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8899', '/');
$user = $argv[2] ?? 'admin';
$pass = $argv[3] ?? '';
$cookie = tempnam(sys_get_temp_dir(), 'psychkg_auth_');

if ($pass === '') {
    fwrite(STDERR, "Passwort fehlt. Aufruf: php tests/smoke_auth.php <url> <user> <pass>\n");
    exit(2);
}

$pass_n = 0; $fail_n = 0;

function req(string $url, array $opts = []): array {
    global $cookie;
    $ch = curl_init($url);
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 30,
    ];
    // Some PHP builds ship curl without a CA bundle (errno 60 on every https
    // request). Point it at the system bundle so production hosts work.
    foreach (array_filter([
        getenv('CURL_CA_BUNDLE') ?: null,
        'C:/Windows/System32/curl-ca-bundle.crt',
        'C:/Windows/System32/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/mingw64/ssl/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
    ]) as $ca) {
        if (is_file($ca)) {
            $defaults[CURLOPT_CAINFO] = $ca;
            break;
        }
    }
    curl_setopt_array($ch, $defaults + $opts);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'status' => $status,
        'headers' => substr((string) $raw, 0, $hsize),
        'body' => substr((string) $raw, $hsize),
        'error' => $err,
    ];
}

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass_n, $fail_n;
    $ok ? $pass_n++ : $fail_n++;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

echo "Authentifizierter Smoke-Test gegen $base (user=$user)\n\n";

// ------------------------------------------------------------------- login
echo "Login\n";
$r = req("$base/login.php");
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
check('CSRF-Token im Login-Formular', $token !== '');

$r = req("$base/login.php", [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'csrf_token' => $token, 'username' => $user, 'password' => $pass,
    ]),
]);
$loggedIn = $r['status'] === 302 && stripos($r['headers'], 'Location: dashboard.php') !== false;
check('Login erfolgreich', $loggedIn, "HTTP {$r['status']}");
if (!$loggedIn) {
    fwrite(STDERR, "Login fehlgeschlagen - Abbruch.\n");
    exit(1);
}

// ------------------------------------------------------- authenticated pages
echo "\nAngemeldete Seiten\n";
foreach ([
    '/dashboard.php'   => 'Dashboard',
    '/marketplace.php' => 'Marktplatz',
    '/admin.php'       => 'Admin-Bereich',
] as $path => $label) {
    $r = req("$base$path");
    check("$label laedt", $r['status'] === 200, "HTTP {$r['status']}");
    check("$label ohne PHP-Fehler",
        stripos($r['body'], 'Fatal error') === false
        && stripos($r['body'], 'Uncaught') === false
        && stripos($r['body'], 'Warning:') === false
        && stripos($r['body'], 'Deprecated:') === false);
}

// ------------------------------------------------------------- admin tabs
echo "\nAdmin-Tabs (Grossschreibung / unbekannte Werte)\n";
foreach ([
    '/admin.php?tab=users'   => 'users (normal)',
    '/admin.php?tab=USERS'   => 'USERS (vorher komplett leere Seite)',
    '/admin.php?tab=Plan'    => 'Plan (vorher komplett leere Seite)',
    '/admin.php?tab=billing' => 'billing',
    '/admin.php?tab=audit'   => 'audit',
    '/admin.php?tab=system'  => 'system',
    '/admin.php?tab=nachweise' => 'nachweise',
    '/admin.php?tab=profile' => 'profile',
    '/admin.php?tab=trades'  => 'trades',
] as $path => $label) {
    $r = req("$base$path");
    // A blank admin page still returns HTTP 200, so inspect which panel is
    // NOT hidden. The old sanitiser turned ?tab=Plan into 'lan', matched no
    // panel and rendered a page with every tab-content hidden.
    preg_match_all(
        '#<div id="tab-([a-z]+)" role="tabpanel"[^>]*class="([^"]*)"#',
        $r['body'],
        $mm,
        PREG_SET_ORDER
    );
    $visible = [];
    foreach ($mm as $x) {
        if (!str_contains($x[2], 'hidden')) {
            $visible[] = $x[1];
        }
    }
    $expected = strtolower(explode('=', $path)[1]);
    check("$label: Panel '$expected' sichtbar",
        count($visible) === 1 && $visible[0] === $expected,
        'sichtbar=' . (implode(',', $visible) ?: 'KEINE'));
}

// --------------------------------------- malformed params on authed endpoints
echo "\nManipulierte Parameter (authentifiziert - hier schlugen 500er auf)\n";
$malformed = [
    '/dashboard.php?error[]=x',
    '/dashboard.php?error=<script>alert(1)</script>',
    '/dashboard.php?aq_date[]=x',
    '/dashboard.php?cal_month=99',
    '/dashboard.php?cal_month=-1',
    '/dashboard.php?cal_year=99999999',
    '/dashboard.php?cal_year[]=x',
    '/dashboard.php?aq_date=not-a-date',
    '/admin.php?start[]=x',
    '/admin.php?end[]=x',
    '/admin.php?tab[]=x',
    '/admin.php?fair_year[]=x',
    '/admin.php?fair_year=99999',
    '/admin.php?cal_month[]=x',
    '/admin.php?cal_year=0',
    '/admin.php?edit[]=x',
    '/export_schedule_pdf.php?start[]=x',
    '/export_schedule_pdf.php?start=notadate&end=notadate',
    '/export_schedule_pdf.php?month_from=99&month_to=6',
    '/export_schedule_pdf.php?year=99999999',
    '/export_schedule_pdf.php?month_from[]=1',
    '/export_pdf.php?start[]=x',
    '/export_pdf.php?start=xx&end=yy',
    '/export_ics.php',
];
foreach ($malformed as $path) {
    $r = req("$base$path");
    $clean = $r['status'] < 500
        && stripos($r['body'], 'Fatal error') === false
        && stripos($r['body'], 'Uncaught') === false;
    check("ohne 500: $path", $clean, "HTTP {$r['status']}");
}

// -------------------------------------------- export_schedule_pdf DoS check
echo "\nMonats-Detailausgabe (DoS-Check)\n";
$r = req("$base/export_schedule_pdf.php?month_from=99&month_to=6");
$tableCells = substr_count($r['body'], '<td');
check('max. 12 Monate gerendert (<= ~380 Zellen)', $tableCells > 0 && $tableCells < 500,
    "Zellen=$tableCells");
$r = req("$base/export_schedule_pdf.php?start=2026-07-01&end=2027-03-01");
check('Cross-Jahres-Zeitraum rendert ohne Fehler', $r['status'] === 200, "HTTP {$r['status']}");

// ------------------------------------------------- calendar table structure
echo "\nDashboard-Kalender (Tabellenstruktur)\n";
$r = req("$base/dashboard.php");
$oTr = substr_count($r['body'], '<tr>');
$cTr = substr_count($r['body'], '</tr>');
check('<tr> / </tr> balanciert', $oTr === $cTr, "open=$oTr close=$cTr");
check('Kalender ist ein <table>', strpos($r['body'], '<table class="hidden sm:table') !== false);

// ---------------------------------------------------------- accessibility
echo "\nBarrierefreiheit\n";
$r = req("$base/admin.php");
check('Dark-Mode-Button hat aria-pressed',
    strpos($r['body'], 'aria-pressed=') !== false);
check('Tab-Buttons haben aria-controls',
    substr_count($r['body'], 'aria-controls="tab-') === 8,
    'gefunden=' . substr_count($r['body'], 'aria-controls="tab-'));
check('Tab-Panels haben role=tabpanel',
    substr_count($r['body'], 'role="tabpanel"') === 8,
    'gefunden=' . substr_count($r['body'], 'role="tabpanel"'));
// Trigger a real error banner: post add_user without required fields, which
// redirects back with ?err=user_fields. admin.php renders no alert on a clean
// load, so the markup can only be asserted after provoking one.
$r = req("$base/admin.php");
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $r['body'], $tm);
$r = req("$base/admin.php", [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'csrf_token' => $tm[1] ?? '', 'action' => 'add_user',
        'new_username' => '', 'new_password' => '', 'new_name' => '',
    ]),
]);
check('Fehlerfall erzeugt Redirect mit err-Key',
    $r['status'] === 302 && strpos($r['headers'], 'err=user_fields') !== false,
    'HTTP ' . $r['status']);

$location = '';
foreach (preg_split('/\r?\n/', $r['headers']) as $h) {
    if (stripos($h, 'Location:') === 0) {
        $location = trim(substr($h, 9));
        break;
    }
}
// The app redirects with a relative Location (admin.php?tab=...).
if ($location !== '' && !preg_match('#^https?://#i', $location)) {
    $location = $base . '/' . ltrim($location, '/');
}
$r = req($location !== '' ? $location : "$base/admin.php");
check('Fehlermeldung hat role=alert', strpos($r['body'], 'role="alert"') !== false);
check('Alert ist als Live-Region markiert', strpos($r['body'], 'aria-live="polite"') !== false);
check('Fehlertext wird gerendert',
    strpos($r['body'], 'Bitte füllen Sie alle Pflichtfelder aus') !== false);

$r = req("$base/dashboard.php");
check('Logout ist ein POST-Formular mit CSRF',
    strpos($r['body'], 'action="logout.php" method="POST"') !== false
    && substr_count($r['body'], 'name="csrf_token"') >= 1);
check('kein <a href="logout.php"> mehr', strpos($r['body'], '<a href="logout.php"') === false);

// ------------------------------------------------------------- XSS probes
echo "\nXSS-Sonden\n";
$xss = [
    '/admin.php?error=<img src=x onerror=alert(1)>',
    '/dashboard.php?error="><script>alert(1)</script>',
];
foreach ($xss as $path) {
    $r = req("$base$path");
    $clean = stripos($r['body'], '<script>alert(1)</script>') === false
        && stripos($r['body'], 'onerror=alert') === false;
    check("kein injiziertes Script: $path", $clean);
}

// ------------------------------------------------------------------- logout
echo "\nLogout-Flow\n";
$r = req("$base/dashboard.php");
preg_match('/action="logout.php" method="POST"[^>]*>.*?name="csrf_token" value="([a-f0-9]+)"/s',
    $r['body'], $m);
$logoutToken = $m[1] ?? '';
check('CSRF-Token im Logout-Formular', $logoutToken !== '');
$r = req("$base/logout.php", [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $logoutToken]),
]);
check('Logout mit Token erfolgreich',
    $r['status'] === 302 && stripos($r['headers'], 'Location: login.php') !== false,
    "HTTP {$r['status']}");

$r = req("$base/dashboard.php");
check('Dashboard nach Logout gesperrt',
    $r['status'] === 302 && stripos($r['headers'], 'Location: login.php') !== false);

@unlink($cookie);
echo "\n" . str_repeat('=', 58) . "\n";
printf("%d/%d bestanden, %d fehlgeschlagen\n", $pass_n, $pass_n + $fail_n, $fail_n);
echo str_repeat('=', 58) . "\n";
exit($fail_n === 0 ? 0 : 1);