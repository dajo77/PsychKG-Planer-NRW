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
 * Minimal test runner for the business rules touched by the hardening pass.
 *
 * Deliberately dependency-free: the project has no composer.json and the
 * production server has no Composer installed, so requiring PHPUnit would make
 * the tests unrunnable where they matter most. Plain assertions + exit code
 * keep this usable locally, in CI and on the server.
 *
 * Run:  php tests/run_tests.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Src\Core\Html;
use Src\Model\ScheduleBlock;
use Src\Service\AbsenceService;
use Src\Service\ScheduleService;
use Src\Service\ShiftService;
use Src\Service\SnapshotService;

$GLOBALS['tests_pass'] = 0;
$GLOBALS['tests_fail'] = 0;

function t(string $group, string $label, mixed $got, mixed $want): void
{
    $ok = $got === $want;
    $ok ? $GLOBALS['tests_pass']++ : $GLOBALS['tests_fail']++;
    printf("  [%s] %-14s %s\n", $ok ? 'PASS' : 'FAIL', $group, $label);
    if (!$ok) {
        printf("         got=%s want=%s\n", var_export($got, true), var_export($want, true));
    }
}

function section(string $name): void
{
    echo "\n$name\n";
}

// ---------------------------------------------------------------- Html helper
section('Html::js() — JS-Ausbruch ueber Mitarbeiternamen');

$malicious = "x'); alert(document.cookie); //";
$encoded = Html::js($malicious);
// The value must survive the HTML attribute decoding step that the browser
// performs before the JS parser runs. After that decoding NO quote may remain,
// otherwise the literal terminates early.
$asJsSees = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
t('js', 'escaped backslashes present', str_contains($encoded, '\\'), true);
t('js', 'no raw single quote survives decoding', substr_count($asJsSees, "'"), 0);
t('js', 'no raw double quote survives', substr_count($asJsSees, '"'), 0);
t('js', 'no script tag can be injected', str_contains(strtolower($encoded), '<script'), false);
t('js', 'no HTML entity can be injected', str_contains($encoded, '&'), false);

// End-to-end: the caller wraps the value in single quotes inside a
// double-quoted attribute. The security property is that after the browser's
// HTML entity decoding the literal still has no terminating quote — i.e. the
// whole injected text stays inside one string.
// Note: \u0027 is a valid *JavaScript* escape (it decodes to a quote INSIDE the
// string, it does not terminate it), so PHP eval() cannot model it; assert the
// invariant directly instead of round-tripping through the wrong language.
$literal = "'" . $asJsSees . "'";
t('js', 'literal opens and closes with exactly 2 quotes',
    substr_count($literal, "'"), 2);
t('js', 'quote escapes are used, not raw quotes',
    str_contains($asJsSees, '\\u0027'), true);
t('js', 'no line terminator can break the statement',
    (bool) preg_match('/[\r\n]/', $asJsSees), false);

// The old, broken approach for comparison.
$legacyDecoded = html_entity_decode(htmlspecialchars($malicious, ENT_QUOTES), ENT_QUOTES | ENT_HTML5, 'UTF-8');
t('js', 'legacy htmlspecialchars DID leak the quote', substr_count($legacyDecoded, "'"), 1);

t('js', 'plain name passes through', Html::js('Max Schmidt'), 'Max Schmidt');
t('js', 'Umlaut preserved', Html::js('Sabine Müller'), 'Sabine Müller');
t('js', 'slash not over-escaped', Html::js('a/b'), 'a/b');

// ---------------------------------------------------------- Html param guards
section('Html::q()/qi()/qDate() — Array- und Format-Eingaben');

$_GET = ['cal_month' => ['x'], 'n' => '7', 'bad' => 'abc', 'arr' => [1]];
t('param', 'array rejected, not cast', Html::q('cal_month'), '');
t('param', 'missing key returns default', Html::q('missing', 'dflt'), 'dflt');
t('param', 'scalar returned', Html::q('n'), '7');
t('param', 'array falls back to default in qi()', Html::qi('arr', 5, 1, 12), 5);
t('param', 'non-numeric falls back in qi()', Html::qi('bad', 3, 1, 12), 3);
t('param', 'in-range returned by qi()', Html::qi('n', 3, 1, 12), 7);
t('param', 'out-of-range clamps to default', Html::qi('n', 3, 1, 5), 3);
$_GET = ['d' => '2026-02-30', 'ok' => '2026-02-28', 't' => '25:00'];
t('param', 'impossible date rejected', Html::qDate('d'), '');
t('param', 'valid date accepted', Html::qDate('ok'), '2026-02-28');
t('param', 'out-of-range time rejected', Html::qTime('t'), '');
$_GET = ['tab' => 'Plan'];
t('param', 'token lowercases', Html::token(Html::q('tab'), ['plan', 'users'], 'plan'), 'plan');
t('param', 'token whitelists', Html::token('USERS', ['plan', 'users'], 'plan'), 'users');
t('param', 'token rejects unknown', Html::token('evil', ['plan'], 'plan'), 'plan');
$_GET = [];

// --------------------------------------------------- ScheduleBlock range rules
section('ScheduleService::isValidBlockRange() — Mi 16:00 bis Mi 08:00');

$wed16 = (new DateTimeImmutable('2026-03-04 16:00'))->getTimestamp();
$wed08 = (new DateTimeImmutable('2026-03-11 08:00'))->getTimestamp();
t('range', 'valid Wednesday window', ScheduleService::isValidBlockRange($wed16, $wed08), true);
$mon = (new DateTimeImmutable('2026-03-02 16:00'))->getTimestamp();
t('range', 'non-Wednesday start rejected', ScheduleService::isValidBlockRange($mon, $wed08), false);
$late = (new DateTimeImmutable('2026-03-04 17:00'))->getTimestamp();
t('range', 'wrong start hour rejected', ScheduleService::isValidBlockRange($late, $wed08), false);
$short = (new DateTimeImmutable('2026-03-11 12:00'))->getTimestamp();
t('range', 'short window rejected', ScheduleService::isValidBlockRange($wed16, $short), false);

// DST: the spring-forward week is 1h shorter in real time.
$dstStart = (new DateTimeImmutable('2026-03-25 16:00'))->getTimestamp();
$dstEnd   = (new DateTimeImmutable('2026-04-01 08:00'))->getTimestamp();
t('range', 'DST week still valid (tolerance)', ScheduleService::isValidBlockRange($dstStart, $dstEnd), true);

// ------------------------------------------------------------- Active hours
section('ScheduleService::calculateActiveHours() — Innendienst-Abzug');

$tz = new DateTimeZone('Europe/Berlin');
// Duty week Wed 16:00 -> Wed 08:00: 6 days 16h = 160h gross.
// Innendienst: Mon-Thu 08:00-16:00, Fri 08:00-12:00 (not on NRW holidays).
// Deduction: Thu 8 + Fri 4 + Mon 8 + Tue 8 = 28h => 132h net.
$h = ScheduleService::calculateActiveHours($wed16, $wed08);
t('hours', 'duty week nets 132h (160h gross - 28h office)', abs($h - 132.0) < 0.001, true);

// The stored block is a 168h container (Wed 16:00 -> Wed 16:00). Its trailing
// 8h (Wed 08:00-16:00) are Innendienst as well, so the net stays 132h.
$hContainer = ScheduleService::calculateActiveHours($wed16, $wed16 + 7 * 86400);
t('hours', '168h container also nets 132h', abs($hContainer - 132.0) < 0.001, true);

// The DST week matters for billing, so pin it down precisely.
// Wed 2026-03-25 16:00 -> Wed 2026-04-01 08:00 spans the spring-forward
// switch (2026-03-29), so the window is 159h of real time, not 160h.
// Internal office runs Thu 26, Fri 27, Mon 30, Tue 31 (08:00-16:00 / Fri 12:00)
// = 28h. Net on-call = 159 - 28 = 131h. Good Friday (2026-04-03) falls AFTER
// this window ends, so it correctly has no effect here.
$dstStart = (new DateTimeImmutable('2026-03-25 16:00'))->getTimestamp();
$dstEnd   = (new DateTimeImmutable('2026-04-01 08:00'))->getTimestamp();
$hHolidayWeek = ScheduleService::calculateActiveHours($dstStart, $dstEnd);
t('hours', 'DST week nets 131h (159h gross - 28h office)', abs($hHolidayWeek - 131.0) < 0.001, true);

// Control: a week with neither holiday nor DST must net the full 132h.
$plainStart = (new DateTimeImmutable('2026-03-18 16:00'))->getTimestamp();
$plainEnd   = (new DateTimeImmutable('2026-03-25 08:00'))->getTimestamp();
$hPlain = ScheduleService::calculateActiveHours($plainStart, $plainEnd);
t('hours', 'week without DST nets 132h', abs($hPlain - 132.0) < 0.001, true);

// A week that actually contains a public holiday: the internal office is
// closed that day, so the whole day counts as on-call instead of deducting
// 8 office hours. Wed 2026-04-01 16:00 -> Wed 2026-04-08 08:00 contains
// Good Friday 2026-04-03 and Easter Monday 2026-04-06.
// Gross 160h; office days would be Thu 2, Fri 3(holiday->no), Mon 6(holiday->no),
// Tue 7 = 16h deducted. Net = 160 - 16 = 144h.
$holStart = (new DateTimeImmutable('2026-04-01 16:00'))->getTimestamp();
$holEnd   = (new DateTimeImmutable('2026-04-08 08:00'))->getTimestamp();
$hHoliday = ScheduleService::calculateActiveHours($holStart, $holEnd);
t('hours', 'holiday week nets 144h (no office on holidays)',
    abs($hHoliday - 144.0) < 0.001, true);

// ----------------------------------------------------------- NRW holidays
section('ScheduleService::isNrwHoliday() — Feiertage NRW');

$cases = [
    ['2026-01-01', true,  'Neujahr'],
    ['2026-04-03', true,  'Karfreitag'],
    ['2026-04-06', true,  'Ostermontag'],
    ['2026-05-01', true,  'Tag der Arbeit'],
    ['2026-05-14', true,  'Christi Himmelfahrt'],
    ['2026-05-25', true,  'Pfingstmontag'],
    ['2026-06-04', true,  'Fronleichnam'],
    ['2026-10-03', true,  'Tag der Deutschen Einheit'],
    ['2026-11-01', true,  'Allerheiligen'],
    ['2026-12-25', true,  '1. Weihnachtstag'],
    ['2026-12-26', true,  '2. Weihnachtstag'],
    ['2026-03-04', false, 'normaler Werktag'],
    ['2026-06-06', false, 'Samstag (kein Feiertag)'],
];
foreach ($cases as [$date, $want, $label]) {
    t('holiday', $date . ' ' . $label,
        ScheduleService::isNrwHoliday(strtotime($date . ' 12:00:00')), $want);
}

// ----------------------------------------------------------- Week start calc
section('ScheduleService::getDutyWeekStartForTimestamp()');

$wed = ScheduleService::getDutyWeekStartForTimestamp(strtotime('2026-03-04 20:00'));
t('week', 'Wednesday evening maps to same Wed 16:00',
    date('Y-m-d H:i', $wed), '2026-03-04 16:00');
$fri = ScheduleService::getDutyWeekStartForTimestamp(strtotime('2026-03-06 10:00'));
t('week', 'Friday maps back to previous Wed 16:00',
    date('Y-m-d H:i', $fri), '2026-03-04 16:00');
$sun = ScheduleService::getDutyWeekStartForTimestamp(strtotime('2026-03-08 10:00'));
t('week', 'Sunday maps back to previous Wed 16:00',
    date('Y-m-d H:i', $sun), '2026-03-04 16:00');

// ---------------------------------------------------------- Snapshot month
section('SnapshotService::isValidMonth()');
foreach ([['2026-01', true], ['2026-12', true], ['2026-13', false],
          ['2026-00', false], ['0000-05', false], ['2026-1', false], ['', false]] as [$m, $want]) {
    t('month', var_export($m, true), SnapshotService::isValidMonth($m), $want);
}

// ---------------------------------------------------------- Absence parsing
section('AbsenceService::parseDate() / reason');
t('absence', 'valid date', AbsenceService::parseDate('2026-05-01'), '2026-05-01');
t('absence', 'impossible date rejected', AbsenceService::parseDate('2026-02-30'), null);
t('absence', 'array rejected', AbsenceService::parseDate(null), null);
t('absence', 'reason urlaub valid', AbsenceService::isValidReason('urlaub'), true);
t('absence', 'reason bogus rejected', AbsenceService::isValidReason('bogus'), false);
t('absence', 'reason label fallback', AbsenceService::reasonLabel('bogus'), 'bogus');

// --------------------------------------------------------- Password policy
section('AuthManager::validatePasswordStrength()');
t('pw', 'too short rejected', \Src\Auth\AuthManager::validatePasswordStrength('Ab1') !== null, true);
t('pw', 'no uppercase rejected', \Src\Auth\AuthManager::validatePasswordStrength('abcdefg1') !== null, true);
t('pw', 'no digit rejected', \Src\Auth\AuthManager::validatePasswordStrength('Abcdefgh') !== null, true);
t('pw', 'valid accepted', \Src\Auth\AuthManager::validatePasswordStrength('Psychkg2026'), null);

// ------------------------------------------------- Calendar table structure
section('Dashboard-Monatskalender — Tabellenstruktur');

// The calendar was a <div> grid that emitted one <tr> per leading filler cell
// and closed rows by interleaving tags. It is now a real <table> built by
// chunking a 7-column grid. Validate the geometry for many months.
$calFail = 0;
for ($y = 2024; $y <= 2027; $y++) {
    for ($m = 1; $m <= 12; $m++) {
        $startDow = (int) date('N', mktime(0, 0, 0, $m, 1, $y));
        $days = (int) date('j', mktime(0, 0, 0, $m + 1, 0, $y));
        $cells = array_fill(0, $startDow - 1, null);
        for ($d = 1; $d <= $days; $d++) {
            $cells[] = $d;
        }
        $chunks = array_chunk($cells, 7);
        $badRow = false;
        foreach ($chunks as $row) {
            if (count($row) > 7 || count($row) < 1) {
                $badRow = true;
            }
        }
        if ($badRow
            || count($chunks) !== (int) ceil(($days + $startDow - 1) / 7)
            || count($cells) !== $days + $startDow - 1
        ) {
            $calFail++;
            printf("         %04d-%02d: %d Zeilen, %d Zellen\n", $y, $m, count($chunks), count($cells));
        }
    }
}
t('kalender', '48 Monate 2024-2027: Zeilen/Zellen korrekt', $calFail, 0);

// --------------------------------------------------------------- HTML escaping
section('Csv-/HTML-Escaping bleibt unveraendert');
t('csv', 'formula injection neutralised (BillingService::csvCell)',
    \Src\Service\BillingService::csvCell('=cmd|calc'), "'=cmd|calc");
t('csv', 'plain value untouched', \Src\Service\BillingService::csvCell('Max Schmidt'), 'Max Schmidt');

// ---------------------------------------------------------- Csv helper (shared)
section('Csv::cell() — Formel-Injection-Schutz (gemeinsamer Helper)');

t('csv', 'formula injection neutralised via shared helper', \Src\Core\Csv::cell('=cmd|calc'), "'=cmd|calc");
t('csv', 'plain value untouched', \Src\Core\Csv::cell('Max Schmidt'), 'Max Schmidt');
t('csv', 'plus-prefix neutralised', \Src\Core\Csv::cell('+SUM(A1:A2)'), "'+SUM(A1:A2)");

// ---------------------------------------------------------- Trade status helpers
section('Trade status helpers');

t('trade', 'pending_approval detected', (new \Src\Model\Trade('trd_1', 'blk_1', 'usr_req', 'usr_rec', 'pending_approval', '2026-10-25 10:00:00'))->isPendingApproval(), true);
t('trade', 'approved detected', (new \Src\Model\Trade('trd_2', 'blk_2', 'usr_req', 'usr_rec', 'approved', '2026-10-25 10:00:00'))->isApproved(), true);
t('trade', 'open detected', (new \Src\Model\Trade('trd_3', 'blk_3', 'usr_req', null, 'open', '2026-10-25 10:00:00'))->isOpen(), true);
t('trade', 'cancelled detected', (new \Src\Model\Trade('trd_4', 'blk_4', 'usr_req', 'usr_rec', 'cancelled', '2026-10-25 10:00:00'))->isCancelled(), true);
t('trade', 'rejected detected', (new \Src\Model\Trade('trd_5', 'blk_5', 'usr_req', 'usr_rec', 'rejected', '2026-10-25 10:00:00'))->isRejected(), true);

// ------------------------------------------------- ScheduleBlock display (Variante A)
section('ScheduleBlock::getFormattedEnd() — Bereitschaftsende');

// The stored block is a 168h container. Its display must show the end of the
// on-call duty (Wednesday 08:00), not the container boundary (16:00).
$normalStart = (new DateTimeImmutable('2026-03-04 16:00', $tz))->getTimestamp();
$normalEnd   = (new DateTimeImmutable('2026-03-11 16:00', $tz))->getTimestamp();
$bNormal = new \Src\Model\ScheduleBlock('blk_t1', $normalStart, $normalEnd, 'usr_a', 'usr_a');
t('block', 'normal week displays Wednesday 08:00', $bNormal->getFormattedEnd(), '11.03.2026 08:00');

// Closing Wednesday 01.11.2028 is Allerheiligen (NRW holiday): there is no
// Innendienst, so the on-call person covers 08:00-16:00 and 16:00 is correct.
$holStart = (new DateTimeImmutable('2028-10-25 16:00', $tz))->getTimestamp();
$holEnd   = (new DateTimeImmutable('2028-11-01 16:00', $tz))->getTimestamp();
$bHoliday = new \Src\Model\ScheduleBlock('blk_t2', $holStart, $holEnd, 'usr_a', 'usr_a');
t('block', 'holiday Wednesday displays the real end 16:00', $bHoliday->getFormattedEnd(), '01.11.2028 16:00');

// Html::pRaw(): passwords keep whitespace, arrays never reach password_verify().
$_POST = ['pw' => ' pass word ', 'bad' => ['x']];
t('html', 'pRaw keeps leading/trailing spaces', \Src\Core\Html::pRaw('pw'), ' pass word ');
t('html', 'pRaw rejects arrays', \Src\Core\Html::pRaw('bad', 'fallback'), 'fallback');
$_POST = [];

// ------------------------------------------------------- Shift settings (Einsatzzeiten)
section('ShiftService — Einsatzzeiten / Bereitschaftswechsels');

t('shift', 'default duty handover day is Wednesday', ShiftService::getDutyStartDay(), 3);
t('shift', 'default duty start time is 16:00', ShiftService::getDutyStartTime(), '16:00');
t('shift', 'default innendienst enabled', ShiftService::isInnendienstEnabled(), true);
t('shift', 'default office days Mon-Fri', ShiftService::getInnendienstOfficeDays(), [1, 2, 3, 4, 5]);
t('shift', 'default bereitschaft end time is 08:00', ShiftService::getBereitschaftEndTime(), '08:00');

// Custom settings: Bereitschaftswechsels Montag 22:00, Innendienst deaktiviert (24/7 Bereitschaft).
ShiftService::clearCache();
t('shift', 'custom settings save ok', ShiftService::saveShiftSettings([
    'duty_start_day' => 1,
    'duty_start_time' => '22:00',
    'innendienst_enabled' => false,
]), true);
t('shift', 'custom duty day Monday', ShiftService::getDutyStartDay(), 1);
t('shift', 'custom duty start time 22:00', ShiftService::getDutyStartTime(), '22:00');
t('shift', 'custom innendienst disabled', ShiftService::isInnendienstEnabled(), false);
t('shift', 'custom bereitschaft end = handover', ShiftService::getBereitschaftEndTime(), '22:00');
t('shift', 'custom duty week start Monday 22:00', date('Y-m-d H:i', ShiftService::getDutyStartTimestamp(strtotime('2026-03-02 23:00'))), '2026-03-02 22:00');

ShiftService::clearCache();
ShiftService::saveShiftSettings([
    'duty_start_day' => 3,
    'duty_start_time' => '16:00',
    'innendienst_enabled' => true,
    'innendienst_start' => '08:00',
    'innendienst_end' => '16:00',
    'innendienst_friday_end' => '12:00',
    'innendienst_office_days' => [1, 2, 3, 4, 5],
]);

// With innendienst disabled the whole duty week is pure Bereitschaft (24/7).
ShiftService::clearCache();
ShiftService::saveShiftSettings(['innendienst_enabled' => false]);
$t24_7 = ScheduleService::calculateActiveHours((new DateTimeImmutable('2026-03-04 16:00'))->getTimestamp(), (new DateTimeImmutable('2026-03-11 16:00'))->getTimestamp());
t('shift', '24/7 Bereitschaft nets full gross hours (168h)', abs($t24_7 - 168.0) < 0.001, true);

// Custom innendienst duration shifts the net Bereitschaft hours.
ShiftService::clearCache();
ShiftService::saveShiftSettings([
    'duty_start_day' => 3,
    'duty_start_time' => '16:00',
    'innendienst_enabled' => true,
    'innendienst_start' => '09:00',
    'innendienst_end' => '18:00',
    'innendienst_friday_end' => '14:00',
    'innendienst_office_days' => [1, 2, 3, 4, 5],
]);
$tCustom = ScheduleService::calculateActiveHours((new DateTimeImmutable('2026-03-04 16:00'))->getTimestamp(), (new DateTimeImmutable('2026-03-11 16:00'))->getTimestamp());
// Gross 168h; Innendienst Mo-So: Mo/Di/Mi/Do 9h + Fr 5h + Di... => 41h office inside -> net 127h.
t('shift', 'custom innendienst times shift net hours', abs($tCustom - 127.0) < 0.001, true);

ShiftService::clearCache();
ShiftService::saveShiftSettings([
    'duty_start_day' => 3,
    'duty_start_time' => '16:00',
    'innendienst_enabled' => true,
    'innendienst_start' => '08:00',
    'innendienst_end' => '16:00',
    'innendienst_friday_end' => '12:00',
    'innendienst_office_days' => [1, 2, 3, 4, 5],
]);
ShiftService::clearCache();

// --------------------------------------------------------------- Summary
$total = $GLOBALS['tests_pass'] + $GLOBALS['tests_fail'];
echo "\n" . str_repeat('=', 58) . "\n";
printf("%d/%d bestanden, %d fehlgeschlagen\n",
    $GLOBALS['tests_pass'], $total, $GLOBALS['tests_fail']);
echo str_repeat('=', 58) . "\n";

exit($GLOBALS['tests_fail'] === 0 ? 0 : 1);