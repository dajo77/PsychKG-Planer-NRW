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
 * Replays the exact 2026 production situation and proves the repair closes the
 * year boundary without ever booking one person for two weeks in a row.
 *
 * Production before the repair:
 *   26 blocks, 01.07.2026 16:00 -> 30.12.2026 08:00
 *   = Wednesday 16:00 -> Wednesday 08:00 (160h), so every Wednesday had an
 *     8h hole (08:00-16:00) and 31.12. plus the turn of the year were uncovered.
 *
 * Runs against an isolated temp data directory; the real schedule is never
 * touched.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Src\Service\ScheduleService;

if (PHP_SAPI === 'cli') {
    @ini_set('session.use_cookies', '0');
    @ini_set('session.cache_limiter', '');
}

$pass = 0; $fail = 0;
function t(string $l, bool $ok, string $d = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
}

$tz = new DateTimeZone('Europe/Berlin');

// ----------------------------------------------------------------- fixture
$tmp = sys_get_temp_dir() . '/psychkg_yearedge_' . getmypid();
@mkdir($tmp, 0777, true);

$blocks = [];
$first = (new DateTimeImmutable('2026-07-01 16:00', $tz))->getTimestamp();
for ($i = 0; $i < 26; $i++) {
    $s = (clone (new DateTimeImmutable("@$first")))->setTimezone($tz)->modify("+$i weeks");
    $e = $s->modify('+7 days')->setTime(8, 0, 0);
    $blocks[] = [
        'id' => 'blk_' . $i,
        'assigned_user_id' => 'usr_' . chr(97 + ($i % 3)),
        'original_user_id' => 'usr_' . chr(97 + ($i % 3)),
        'start_timestamp' => $s->getTimestamp(),
        'end_timestamp' => $e->getTimestamp(),
        'note' => '',
    ];
}
file_put_contents("$tmp/schedule.json", json_encode(['blocks' => $blocks]));
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

$svc = new ScheduleService();
echo "Jahreswechsel-Reparatur (Produktionsszenario 2026)\n\n";

// ------------------------------------------------------- 1. Ausgangslage
echo "1. Ausgangslage\n";
$all = $svc->getAllBlocks();
t('26 Bloecke eingelesen', count($all) === 26, count($all) . ' Bloecke');

$firstBlock = $all[0];
$lastBlock = $all[count($all) - 1];
t('Kette beginnt 01.07.2026 16:00',
    $firstBlock->getStartTimestamp() === (int) strtotime('2026-07-01 16:00'),
    date('d.m.Y H:i', $firstBlock->getStartTimestamp()));
t('Kette endet 30.12.2026 08:00',
    $lastBlock->getEndTimestamp() === (int) strtotime('2026-12-30 08:00'),
    date('d.m.Y H:i', $lastBlock->getEndTimestamp()));

$gapsBefore = ScheduleService::findCoverageGaps();
t('25 interne Luecken vorhanden (Mi 08:00-16:00)', count($gapsBefore) === 25,
    count($gapsBefore) . ' Luecken');

t('31.12.2026 12:00 ist unbesetzt',
    ScheduleService::isCovered((int) strtotime('2026-12-31 12:00')) === false);
t('01.01.2027 00:00 ist unbesetzt',
    ScheduleService::isCovered((int) strtotime('2027-01-01 00:00')) === false);
// Die Luecke sitzt am ENDE jedes legacy Blocks, also Mi 08:00-16:00 des
// Folge-Mittwochs (08.07.), nicht am 06.07. (das liegt noch im Block).
t('Mittwoch 08.07.2026 10:00 ist unbesetzt',
    ScheduleService::isCovered((int) strtotime('2026-07-08 10:00')) === false);
t('Mittwoch 08.07.2026 07:00 ist noch besetzt',
    ScheduleService::isCovered((int) strtotime('2026-07-08 07:00')) === true);

// -------------------------------------------------------- 2. Reparatur
echo "\n2. repairCoverage() allein (nur Angleichung)\n";
$result = $svc->repairCoverage();
t('Reparatur hat geschrieben', !empty($result['written']), json_encode([
    'checked' => $result['checked'], 'stitched' => $result['stitched'],
    'extended' => $result['extended'],
]));

$after = $svc->getAllBlocks();
t('weiterhin 26 Bloecke (kein Block erfunden/verloren)', count($after) === 26,
    count($after) . ' Bloecke');

$gapsAfter = ScheduleService::findCoverageGaps();
t('keine internen Luecken mehr', count($gapsAfter) === 0,
    count($gapsAfter) . ' Luecken');

$chainOk = true;
$maxSpan = 0;
foreach ($after as $i => $b) {
    $span = ($b->getEndTimestamp() - $b->getStartTimestamp()) / 3600;
    $maxSpan = max($maxSpan, $span);
    if ($i < count($after) - 1 && $b->getEndTimestamp() !== $after[$i + 1]->getStartTimestamp()) {
        $chainOk = false;
    }
}
t('Kette lueckenlos (Ende == naechster Start)', $chainOk);
// 168h nominal; in der Woche der Zeitumstellung (25.10.2026) sind es real
// 169h, weil die Uhr eine Stunde zurueckgestellt wird. isValidBlockRange
// akzeptiert das per Toleranz.
t('kein Block laenger als 168h nominal (+1h DST)', $maxSpan <= 169, "max {$maxSpan}h");
t('alle Bloecke sind gueltige Zeitbereiche',
    count(array_filter($after, fn ($b) => !ScheduleService::isValidBlockRange(
        $b->getStartTimestamp(), $b->getEndTimestamp()))) === 0);

$lastAfter = $after[count($after) - 1];
t('letzter Block endet jetzt 30.12.2026 16:00',
    $lastAfter->getEndTimestamp() === (int) strtotime('2026-12-30 16:00'),
    date('d.m.Y H:i', $lastAfter->getEndTimestamp()));

// Nur 8h Aufstockung, nicht 7 Tage: derselbe Kollege darf nicht 14 Tage
// durchgehend Dienst haben.
$lastSpan = ($lastAfter->getEndTimestamp() - $lastAfter->getStartTimestamp()) / 3600;
t('letzter Block ist 168h (nicht 14 Tage)', $lastSpan === 168, "{$lastSpan}h");
t('nach repairCoverage() allein ist der Jahreswechsel noch offen',
    ScheduleService::isCovered((int) strtotime('2026-12-31 12:00')) === false);

// ------------------------------------- 3. Reparatur inkl. fairer Fortsetzung
echo "\n3. repairCoverageAndExtend() bis 31.12. (+1 Woche)\n";
$target = ScheduleService::nextDutyStartAfter(
    (int) strtotime('2027-01-01 00:00:00'));
t('Ziel: erster Mittwoch 16:00 nach dem 1.1.',
    $target === (int) strtotime('2027-01-06 16:00'), date('d.m.Y H:i', $target));
$ext = $svc->repairCoverageAndExtend(['usr_a', 'usr_b', 'usr_c'], $target);
t('Fortsetzung erzeugt (1 Woche)', $ext['added'] === 1,
    'added=' . $ext['added']);

$final = $svc->getAllBlocks();
$newBlock = $final[count($final) - 1];
t('jetzt 27 Bloecke', count($final) === 27, count($final) . ' Bloecke');
t('neuer Block beginnt 30.12.2026 16:00',
    $newBlock->getStartTimestamp() === (int) strtotime('2026-12-30 16:00'),
    date('d.m.Y H:i', $newBlock->getStartTimestamp()));
t('neuer Block endet 06.01.2027 16:00',
    $newBlock->getEndTimestamp() === (int) strtotime('2027-01-06 16:00'),
    date('d.m.Y H:i', $newBlock->getEndTimestamp()));
t('neuer Block ist 168h',
    ($newBlock->getEndTimestamp() - $newBlock->getStartTimestamp()) / 3600 === 168);

// ------------------------------------------------------------ 4. Ergebnis
echo "\n4. Ergebnispruefung\n";
t('keine internen Luecken', count(ScheduleService::findCoverageGaps()) === 0);
t('31.12.2026 00:00 besetzt', ScheduleService::isCovered((int) strtotime('2026-12-31 00:00')));
t('31.12.2026 12:00 besetzt', ScheduleService::isCovered((int) strtotime('2026-12-31 12:00')));
t('31.12.2026 23:59 besetzt', ScheduleService::isCovered((int) strtotime('2026-12-31 23:59')));
t('01.01.2027 00:00 besetzt', ScheduleService::isCovered((int) strtotime('2027-01-01 00:00')));
t('01.01.2027 12:00 besetzt', ScheduleService::isCovered((int) strtotime('2027-01-01 12:00')));
t('06.01.2027 10:00 besetzt', ScheduleService::isCovered((int) strtotime('2027-01-06 10:00')));

// Jeder Tag des Jahres 2026 muss belegt sein. Der 01.07. ist ein bekannter
// Sonderfall: der Produktionsplan beginnt erst um 16:00, die 0-16 Uhr waren
// schon vorher unbesetzt und sind nicht Teil dieser Reparatur.
t('01.07.2026 vor Planbeginn 16:00 unbesetzt (Altbestand, bekannt)',
    ScheduleService::isCovered((int) strtotime('2026-07-01 00:00')) === false);

$uncoveredDays = [];
for ($ts = (int) strtotime('2026-07-02 00:00'); $ts < strtotime('2027-01-01 00:00'); $ts += 86400) {
    if (!ScheduleService::isCovered($ts)) {
        $uncoveredDays[] = date('d.m.Y', $ts);
    }
}
t('jeder Tag 02.07.-31.12.2026 belegt', count($uncoveredDays) === 0,
    implode(', ', $uncoveredDays));

// Gerechtigkeit: keine Person zwei Wochen in Folge.
$seq = array_map(fn ($b) => $b->getAssignedUserId(), $final);
$backToBack = 0;
for ($i = 1; $i < count($seq); $i++) {
    if ($seq[$i] === $seq[$i - 1]) {
        $backToBack++;
    }
}
t('niemand zwei Wochen in Folge eingeteilt', $backToBack === 0, $backToBack . ' Faelle');

// Billierbare Bereitschaftszeit darf sich durch die Umstellung nicht aendern:
// ein legacy 160h-Block (Mi 16:00 -> Mi 08:00) und der neue 168h-Block
// (Mi 16:00 -> Mi 16:00) muessen denselben Netto-Wert liefern.
$legacyWeek = ScheduleService::calculateActiveHours(
    (int) strtotime('2026-12-23 16:00'), (int) strtotime('2026-12-30 08:00'));
$newWeek = ScheduleService::calculateActiveHours(
    (int) strtotime('2026-12-30 16:00'), (int) strtotime('2027-01-06 16:00'));
t('Netto-Stunden: legacy 160h == neu 168h', abs($legacyWeek - $newWeek) < 0.01,
    number_format($legacyWeek, 2, ',', '.') . ' h vs ' . number_format($newWeek, 2, ',', '.') . ' h');

printf("\n%d/%d bestanden, %d fehlgeschlagen\n", $pass, $pass + $fail, $fail);
exit($fail > 0 ? 1 : 0);