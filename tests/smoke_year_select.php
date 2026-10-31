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
 * HTTP-level test of the year selector in the schedule generator.
 * Requires a running instance with an admin account.
 *
 * Usage: php tests/smoke_year_select.php <base-url> <admin-user> <admin-pass>
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8899', '/');
$user = $argv[2] ?? 'admin';
$pass = $argv[3] ?? '';

if ($pass === '') {
    fwrite(STDERR, "Passwort fehlt.\n");
    exit(2);
}

$cookie = tempnam(sys_get_temp_dir(), 'yr_');
$pass_n = 0; $fail_n = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass_n, $fail_n;
    $ok ? $pass_n++ : $fail_n++;
    printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
}
function req(string $url, array $opts = []): array {
    global $cookie;
    $ch = curl_init($url);
    $d = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true,
          CURLOPT_FOLLOWLOCATION=>false, CURLOPT_COOKIEJAR=>$cookie,
          CURLOPT_COOKIEFILE=>$cookie, CURLOPT_TIMEOUT=>25];
    foreach (array_filter([getenv('CURL_CA_BUNDLE') ?: null,
        'C:/Windows/System32/curl-ca-bundle.crt',
        'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt']) as $ca) {
        if (is_file($ca)) { $d[CURLOPT_CAINFO] = $ca; break; }
    }
    curl_setopt_array($ch, $d + $opts);
    $raw = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status'=>$st, 'headers'=>substr((string)$raw,0,$hs),
            'body'=>substr((string)$raw,$hs)];
}
function location(array $r): string {
    foreach (preg_split('/\r?\n/', $r['headers']) as $h) {
        if (stripos($h, 'Location:') === 0) return trim(substr($h, 9));
    }
    return '';
}
function token(string $html): string {
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?? '';
}

echo "Jahresauswahl im Generator (HTTP)\n\n";

$r = req("$base/login.php");
$r = req("$base/login.php", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query(
    ['csrf_token'=>token($r['body']), 'username'=>$user, 'password'=>$pass])]);
if (!str_contains(location($r), 'dashboard.php')) {
    fwrite(STDERR, "Login fehlgeschlagen\n"); exit(1);
}

$r = req("$base/admin.php?tab=plan");
check('Admin-Seite lädt', $r['status'] === 200);

// --- the selector itself
check('Auswahlfeld "Planungsjahr" vorhanden',
    strpos($r['body'], 'name="year" id="plan_year"') !== false);

$currentYear = (int) date('Y');
preg_match_all('/<option value="(\d{4})"/', $r['body'], $om);
$years = array_map('intval', $om[1]);
check('Optionen reichen vom laufenden Jahr bis +5',
    in_array($currentYear, $years, true)
    && in_array($currentYear + 1, $years, true)
    && count(array_filter($years, fn($y) => $y >= $currentYear && $y <= $currentYear + 5)) === 6,
    implode(',', array_slice($years, 0, 8)));
check('laufendes Jahr ist vorbelegt',
    preg_match('/<option value="' . $currentYear . '" selected/', $r['body']) === 1);
check('Halbjahres-Labels zeigen das gewählte Jahr',
    substr_count($r['body'], '<span data-plan-year-label>') === 2
    && substr_count($r['body'], '<span data-plan-year-label>' . $currentYear . '</span>') === 2,
    substr_count($r['body'], '<span data-plan-year-label>') . ' Labels');

// year persisted via query param
$r = req("$base/admin.php?tab=plan&plan_year=" . ($currentYear + 1));
check('Jahreswahl über ?plan_year= vorbelegt',
    preg_match('/<option value="' . ($currentYear + 1) . '" selected/', $r['body']) === 1
    && strpos($r['body'], '>' . ($currentYear + 1) . '</span>') !== false);

// year rejected when out of range
$r = req("$base/admin.php?tab=plan&plan_year=1999");
check('Jahr ausserhalb des Bereichs wird ignoriert (Fallback laufendes Jahr)',
    preg_match('/<option value="' . $currentYear . '" selected/', $r['body']) === 1);

// --- actual generation for the NEXT year
echo "\nGenerierung fuer das kommende Jahr\n";
$r = req("$base/admin.php?tab=plan&plan_year=" . ($currentYear + 1));
$t = token($r['body']);
preg_match_all('/name="employees\[\]" value="([^"]+)"/', $r['body'], $em);
$employees = $em[1] ?? [];
check('Mitarbeiter im Formular vorhanden', count($employees) > 0, count($employees) . ' gefunden');

$r = req("$base/admin.php?tab=plan", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query([
    'csrf_token' => $t,
    'action'     => 'generate_schedule',
    'year'       => $currentYear + 1,
    'period'     => 'h1',
    'employees'  => $employees,
])]);
check('POST akzeptiert (Redirect)', $r['status'] === 302, 'HTTP ' . $r['status']);
$loc = location($r);
check('Redirect meldet das geplante Jahr', str_contains($loc, 'plan_year=' . ($currentYear + 1)), $loc);

$r = req(str_starts_with($loc, 'http') ? $loc : $base . '/' . ltrim($loc, '/'));
check('Erfolgsmeldung nennt das Jahr',
    str_contains($r['body'], '1. Halbjahr ' . ($currentYear + 1))
    || str_contains($r['body'], 'erfolgreich generiert'),
    (str_contains($r['body'], '1. Halbjahr ' . ($currentYear + 1)) ? 'mit Jahresangabe' : 'generisch'));

// --- out-of-range year must be refused server-side
echo "\nServer-seitige Pruefung\n";
$r = req("$base/admin.php?tab=plan");
$t = token($r['body']);
$r = req("$base/admin.php?tab=plan", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query([
    'csrf_token' => $t, 'action' => 'generate_schedule',
    'year' => 1999, 'period' => 'h1', 'employees' => $employees,
])]);
$loc = location($r);
check('Jahr 1999 wird abgelehnt', str_contains($loc, 'schedule_year_range'), $loc);

$r = req("$base/admin.php?tab=plan");
$t = token($r['body']);
$r = req("$base/admin.php?tab=plan", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query([
    'csrf_token' => $t, 'action' => 'generate_schedule',
    'year' => 'neunzehnhundert', 'period' => 'h1', 'employees' => $employees,
])]);
check('unsinniges Jahr wird abgelehnt',
    str_contains(location($r), 'schedule_year_invalid'), location($r));

@unlink($cookie);
echo "\n" . str_repeat('=', 58) . "\n";
printf("%d/%d bestanden, %d fehlgeschlagen\n", $pass_n, $pass_n + $fail_n, $fail_n);
echo str_repeat('=', 58) . "\n";
exit($fail_n === 0 ? 0 : 1);