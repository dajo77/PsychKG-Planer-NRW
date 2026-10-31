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
 * Regression test for multi-year schedule planning.
 *
 * The generator used to be hard-wired to date('Y'), so planning the following
 * year was impossible. These tests pin down that:
 *   - a future year produces valid, fair blocks entirely within that year,
 *   - generating one year does not disturb another,
 *   - clearRange only replaces the targeted window,
 *   - a past start date is refused instead of rolling forward silently.
 *
 * Runs against an isolated temp data directory; the real schedule is never
 * touched.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Src\Service\ScheduleService;

// AuthManager starts a session for its duty-eligibility checks. Under CLI that
// is pointless and PHP warns once output has begun. Suppress just that case.
if (PHP_SAPI === 'cli') {
    @ini_set('session.use_cookies', '0');
    @ini_set('session.cache_limiter', '');
}

$pass = 0; $fail = 0;
function t(string $l, bool $ok, string $d = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
}

// ------------------------------------------------------------ isolated fixture
$tmp = sys_get_temp_dir() . '/psychkg_yearplan_' . getmypid();
@mkdir($tmp, 0777, true);
file_put_contents("$tmp/schedule.json", json_encode(['blocks' => []]));
file_put_contents("$tmp/absences.json", json_encode(['absences' => []]));
file_put_contents("$tmp/trades.json", json_encode(['trades' => []]));
file_put_contents("$tmp/users.json", json_encode(['users' => [
    ['id' => 'usr_a', 'username' => 'a', 'password' => password_hash('X', PASSWORD_DEFAULT),
     'name' => 'A', 'role' => 'mitarbeiter', 'phone' => '', 'email' => ''],
    ['id' => 'usr_b', 'username' => 'b', 'password' => password_hash('X', PASSWORD_DEFAULT),
     'name' => 'B', 'role' => 'mitarbeiter', 'phone' => '', 'email' => ''],
    ['id' => 'usr_c', 'username' => 'c', 'password' => password_hash('X', PASSWORD_DEFAULT),
     'name' => 'C', 'role' => 'mitarbeiter', 'phone' => '', 'email' => ''],
]]));

$restore = getenv('PSYCHKG_DATA_DIR');
putenv('PSYCHKG_DATA_DIR=' . $tmp);
register_shutdown_function(static function () use ($tmp, $restore) {
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
    $restore === false ? putenv('PSYCHKG_DATA_DIR') : putenv('PSYCHKG_DATA_DIR=' . $restore);
});

$currentYear = (int) date('Y');
$nextYear = $currentYear + 1;
$ids = ['usr_a', 'usr_b', 'usr_c'];

echo "Mehrjaehrige Planung\n\n";

// ---------------------------------------------------- H1 of the coming year
$svc = new ScheduleService();
t('H1 des kommenden Jahres generiert',
    $svc->generateWeeks($nextYear . '-01-01', 26, $ids, true));

$blocks = (new ScheduleService())->getAllBlocks();
t('Blöcke erzeugt', count($blocks) === 26, count($blocks) . ' Blöcke');

$outside = 0; $allValid = true; $allWed = true; $starts = []; $allGapless = true;
foreach ($blocks as $i => $b) {
    if ((int) date('Y', $b->getStartTimestamp()) !== $nextYear
        || (int) date('Y', $b->getEndTimestamp()) !== $nextYear) {
        $outside++;
    }
    if (!ScheduleService::isValidBlockRange($b->getStartTimestamp(), $b->getEndTimestamp())) {
        $allValid = false;
    }
    if ((int) date('N', $b->getStartTimestamp()) !== 3
        || (int) date('H', $b->getStartTimestamp()) !== 16
        || (int) date('N', $b->getEndTimestamp()) !== 3
        || (int) date('H', $b->getEndTimestamp()) !== 16) {
        $allWed = false;
    }
    // Gapless: each block must end exactly where the next one starts.
    if ($i < count($blocks) - 1) {
        $nextStart = $blocks[$i + 1]->getStartTimestamp();
        if ($b->getEndTimestamp() !== $nextStart) {
            $allGapless = false;
        }
    }
    $starts[$b->getStartTimestamp()] = true;
}
t('alle Blöcke liegen im gewählten Jahr', $outside === 0, $outside . ' ausserhalb');
t('alle Blöcke gültiger Zeitbereich', $allValid);
t('Start und Ende jeweils Mi 16:00 (7-Tage-Woche)', $allWed);
t('Kette lückenlos: Ende == nächster Start', $allGapless);
t('keine doppelten Startzeiten', count($starts) === count($blocks));

// ---------------------------------------------------------------- fairness
$perUser = [];
foreach ($blocks as $b) {
    $perUser[$b->getAssignedUserId()] = ($perUser[$b->getAssignedUserId()] ?? 0) + 1;
}
t('Rotation verteilt auf alle drei Mitarbeiter', count($perUser) === 3, json_encode($perUser));
t('Rotationsdifferenz hoechstens 1 Block',
    (max($perUser) - min($perUser)) <= 1, 'Differenz=' . (max($perUser) - min($perUser)));

// ------------------------------------------------------- plausible net hours
$hours = array_map(
    fn ($b) => ScheduleService::calculateActiveHours($b->getStartTimestamp(), $b->getEndTimestamp()),
    $blocks
);
t('Netto-Stunden je Dienst plausibel',
    min($hours) >= 100 && max($hours) <= 145,
    sprintf('min=%.1f max=%.1f', min($hours), max($hours)));

// ------------------------------------------- a second year must not disturb
$beforeCount = count((new ScheduleService())->getAllBlocks());
$svc->generateWeeks(($currentYear + 2) . '-01-01', 26, $ids, true);
$after = (new ScheduleService())->getAllBlocks();
$nextYearBlocks = array_filter(
    $after,
    fn ($b) => (int) date('Y', $b->getStartTimestamp()) === $nextYear
);
t('Blöcke des ersten Jahres bleiben erhalten',
    count($nextYearBlocks) === $beforeCount, count($nextYearBlocks) . ' von ' . $beforeCount);
t('beide Jahre nebeneinander vorhanden',
    count($after) === $beforeCount * 2, count($after) . ' Blöcke gesamt');

// --------------------------------------------- clearRange stays in its window
// Regenerating H1 of the next year must not remove the H1 of the year after.
$svc->generateWeeks($nextYear . '-01-01', 26, $ids, true);
$after = (new ScheduleService())->getAllBlocks();
$byYearStart = [];
foreach ($after as $b) {
    $y = (int) date('Y', $b->getStartTimestamp());
    $byYearStart[$y] = ($byYearStart[$y] ?? 0) + 1;
}
ksort($byYearStart);
t('clearRange ersetzt nur den Zielzeitraum',
    ($byYearStart[$nextYear] ?? 0) === 26 && ($byYearStart[$currentYear + 2] ?? 0) === 26,
    json_encode($byYearStart));

// ------------------------------------------------------ guards at the limits
echo "\nGrenzen\n";
$before3 = count((new ScheduleService())->getAllBlocks());
t('Vergangenheitsdatum wird abgelehnt',
    $svc->generateWeeks(($currentYear - 5) . '-01-01', 26, $ids, true) === false);
t('keine Bloecke durch Vergangenheitsdatum entstanden',
    count((new ScheduleService())->getAllBlocks()) === $before3);

// $numberOfWeeks <= 0 means "cover the half-year automatically". Positive
// values are still validated.
t('Wochenzahl 0 = automatische Halbjahrabdeckung akzeptiert',
    $svc->generateWeeks(($currentYear + 3) . '-01-01', 0, $ids, true) !== false);
t('negative Wochenzahl wird wie 0 behandelt (automatisch)',
    $svc->generateWeeks(($currentYear + 4) . '-01-01', -5, $ids, true) !== false);
t('Unplausibel grosse Wochenzahl abgelehnt (>104)',
    $svc->generateWeeks(($currentYear + 5) . '-01-01', 500, $ids, true) === false);
t('Ungültiges Datumsformat abgelehnt',
    $svc->generateWeeks('kein-datum', 26, $ids, true) === false);
t('ohne diensttaugliche Mitarbeiter abgelehnt',
    $svc->generateWeeks(($currentYear + 3) . '-01-01', 26, [], true) === false);

// H2 of the coming year must also work (the other half of the request).
t('H2 des kommenden Jahres generiert',
    $svc->generateWeeks($nextYear . '-07-01', 26, $ids, true));
$all = (new ScheduleService())->getAllBlocks();
$h2 = array_filter($all, static function ($b) use ($nextYear) {
    $s = (int) date('Y', $b->getStartTimestamp());
    return $s === $nextYear && (int) date('m', $b->getStartTimestamp()) >= 7;
});
t('H2-Block erzeugt', count($h2) >= 24, count($h2) . ' Bloecke ab Juli');

echo "\n" . str_repeat('=', 58) . "\n";
printf("%d/%d bestanden, %d fehlgeschlagen\n", $pass, $pass + $fail, $fail);
echo str_repeat('=', 58) . "\n";
exit($fail === 0 ? 0 : 1);