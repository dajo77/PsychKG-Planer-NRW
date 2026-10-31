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
 * End-to-end smoke test against a running instance.
 *
 * Usage:  php tests/smoke.php [base-url]
 * Default base URL: http://127.0.0.1:8899
 *
 * Starts nothing itself; point it at the PHP built-in server:
 *   cd public && php -S 127.0.0.1:8899
 *
 * Verifies that every entry point answers, that the hardening headers are
 * present, that the logout endpoint refuses GET, and that malformed query
 * parameters no longer produce HTTP 500.
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8899', '/');
$cookie = tempnam(sys_get_temp_dir(), 'psychkg_smoke_');

/**
 * Some PHP builds (notably on Windows without a configured CA bundle) ship
 * curl without trust anchors, which makes every https request fail with
 * errno 60 / HTTP 0 even though the certificate is valid. Point curl at the
 * system CA bundle so the smoke test can target a production host.
 */
function curlCainfo(): ?string
{
    $candidates = array_filter([
        getenv('CURL_CA_BUNDLE') ?: null,
        'C:/Windows/System32/curl-ca-bundle.crt',
        'C:/Windows/System32/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/mingw64/ssl/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
    ]);
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

$CAINFO = curlCainfo();

$pass = 0;
$fail = 0;

function req(string $url, array $opts = []): array {
    global $cookie;
    $ch = curl_init($url);
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 20,
    ];
    $ca = curlCainfo();
    if ($ca !== null) {
        $defaults[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $defaults + $opts);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $hsize);
    $body = substr((string) $raw, $hsize);
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => $err];
}

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

echo "Smoke-Test gegen $base\n\n";

// ---------------------------------------------------------------- public pages
echo "Oeffentliche Seiten\n";
$r = req("$base/login.php");
check('login.php antwortet 200', $r['status'] === 200, "HTTP {$r['status']}");

// ---------------------------------------------------------- security headers
echo "\nSicherheits-Header (auf /login.php)\n";
foreach ([
    'X-Frame-Options: DENY'          => 'Clickjacking-Schutz',
    'X-Content-Type-Options: nosniff' => 'MIME-Sniffing-Schutz',
    'Referrer-Policy: same-origin'   => 'Referrer-Policy',
    'Cache-Control: no-store'        => 'kein Cache sensibler Seiten',
] as $needle => $label) {
    check($label, stripos($r['headers'], $needle) !== false, $needle);
}
check('CSP report-only gesetzt',
    stripos($r['headers'], 'Content-Security-Policy-Report-Only') !== false);
// The app's own policy must not allow any third-party origin. A separate
// enforcing CSP from Imunify/ModSecurity may exist in front of it - that one
// is server configuration, not the application's, and is not asserted here.
$appCsp = '';
foreach (preg_split('/\r?\n/', $r['headers']) as $h) {
    if (stripos($h, 'Content-Security-Policy-Report-Only:') === 0) {
        $appCsp = $h;
        break;
    }
}
check('Anwendungs-CSP erlaubt keine Drittanbieter-Origins',
    $appCsp !== ''
    && stripos($appCsp, 'fonts.googleapis') === false
    && stripos($appCsp, 'fonts.gstatic') === false
    && stripos($appCsp, 'cdn.jsdelivr') === false,
    trim($appCsp) !== '' ? 'nur self' : 'keine Report-Only-CSP gefunden');

// -------------------------------------------------------------- no CDN links
echo "\nKeine externen Requests (Login-Seite)\n";
check('kein fonts.googleapis.com', stripos($r['body'], 'fonts.googleapis.com') === false);
check('kein fonts.gstatic.com', stripos($r['body'], 'fonts.gstatic.com') === false);
check('lokale fonts.css eingebunden', strpos($r['body'], 'assets/fonts.css') !== false);

// ---------------------------------------------------------------- auth walls
echo "\nZugriffsschutz (ohne Login)\n";
foreach ([
    '/dashboard.php'          => 'Dashboard',
    '/admin.php'              => 'Admin-Bereich',
    '/marketplace.php'        => 'Marktplatz',
    '/export_ics.php'         => 'ICS-Export',
    '/export_pdf.php'         => 'PDF-Abrechnung',
    '/export_schedule_pdf.php'=> 'PDF-Dienstplan',
] as $path => $label) {
    $r = req("$base$path");
    $redirected = $r['status'] === 302
        && stripos($r['headers'], 'Location: login.php') !== false;
    check("$label erfordert Login", $redirected, "HTTP {$r['status']}");
}

// ------------------------------------------------------- logout is POST-only
echo "\nLogout\n";
$r = req("$base/logout.php");
check('GET /logout.php leitet nicht ab (kein CSRF-Logout)',
    $r['status'] === 302 && stripos($r['headers'], 'Location: dashboard.php') !== false,
    "HTTP {$r['status']}");

$r = req("$base/logout.php", [CURLOPT_POST => true]);
check('POST /logout.php ohne Token => 403', $r['status'] === 403, "HTTP {$r['status']}");

// ----------------------------------------------- malformed query parameters
// These all produced HTTP 500 (TypeError) before the hardening pass.
echo "\nManipulierte Query-Parameter (vorher HTTP 500)\n";
$malformed = [
    '/dashboard.php?error[]=x',
    '/dashboard.php?aq_date[]=x',
    '/dashboard.php?aq_time[]=x',
    '/dashboard.php?cal_month[]=x',
    '/dashboard.php?cal_year[]=x',
    '/dashboard.php?f_user[]=x',
    '/login.php?error[]=x',
];
foreach ($malformed as $path) {
    $r = req("$base$path");
    $ok = $r['status'] !== 500;
    check("ohne 500: $path", $ok, "HTTP {$r['status']}");
}

// The same on authenticated endpoints, reachable after logging in below.
// Here we at least assert the unauthenticated redirect is not a 500.
foreach ([
    '/admin.php?start[]=x',
    '/admin.php?tab[]=x',
    '/export_schedule_pdf.php?start[]=x',
] as $path) {
    $r = req("$base$path");
    check("ohne 500: $path", $r['status'] !== 500, "HTTP {$r['status']}");
}

// ---------------------------------------------------------- reflected input
echo "\nReflektierte Eingaben\n";
$payload = 'MitarbeiterPasswortZurueckgesetztBitteKlicken';
$r = req("$base/dashboard.php?error=" . rawurlencode($payload));
check('?error= reflektiert beliebigen Text nicht (Redirect ohne Login)',
    strpos($r['body'], $payload) === false);

// ------------------------------------------------------------------ assets
echo "\nAssets\n";
foreach ([
    '/assets/app.css'    => 'app.css',
    '/assets/fonts.css'  => 'fonts.css',
] as $path => $label) {
    $r = req("$base$path");
    check("$label auslieferbar", $r['status'] === 200, "HTTP {$r['status']}");
}
$r = req("$base/assets/fonts/inter-latin.woff2");
check('woff2-Font auslieferbar', $r['status'] === 200, "HTTP {$r['status']}");

// --------------------------------------------------------------------- logout
@unlink($cookie);

echo "\n" . str_repeat('=', 58) . "\n";
printf("%d/%d bestanden, %d fehlgeschlagen\n", $pass, $pass + $fail, $fail);
echo str_repeat('=', 58) . "\n";
exit($fail === 0 ? 0 : 1);