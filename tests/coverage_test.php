<?php
// CLI-only: the test suite must never be executable over HTTP. On this host
// the document root is the project root, so without this guard a GET on
// /tests/run_tests.php would execute the suite for anonymous visitors.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
?>
﻿<?php
/**
 * Proves continuous 24/7 coverage across a chain of duty blocks.
 *
 * A duty block must span Wednesday 16:00 -> Wednesday 16:00 so that
 * consecutive blocks meet exactly. Any instant that is not inside a block is
 * a hole where the system reports "no active duty".
 */
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Service\ScheduleService;

$pass = 0; $fail = 0;
function t(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
}

$tz = new DateTimeZone('Europe/Berlin');

echo "24/7-Abdeckung: Blockketten ohne Luecken\n\n";

// Build a chain of N weeks the way generateWeeks does, then probe every hour.
echo "Gegenprobe: alte 160h-Logik (Mi 16:00 -> Mi 08:00)\n";
$legacy = [];
$start = (new DateTimeImmutable('2026-07-01 16:00', $tz))->getTimestamp();
for ($i = 0; $i < 26; $i++) {
    $s = (clone (new DateTimeImmutable("@$start")))->setTimezone($tz)->modify("+$i weeks");
    $e = $s->modify('+7 days')->setTime(8, 0, 0);
    $legacy[] = [$s->getTimestamp(), $e->getTimestamp()];
}
$legacyHoles = 0;
for ($i = 1; $i < count($legacy); $i++) {
    if ($legacy[$i][0] > $legacy[$i - 1][1]) { $legacyHoles++; }
}
t('alte Logik hat Luecken (Nachweis des Ausgangsproblems)', $legacyHoles === 25, $legacyHoles . ' Luecken');

echo "\nNeue Logik: weekEndTimestamp()\n";
$new = [];
for ($i = 0; $i < 26; $i++) {
    $s = (clone (new DateTimeImmutable("@$start")))->setTimezone($tz)->modify("+$i weeks");
    $new[] = [$s->getTimestamp(), ScheduleService::weekEndTimestamp($s->getTimestamp())];
}
$newHoles = 0;
for ($i = 1; $i < count($new); $i++) {
    if ($new[$i][0] !== $new[$i - 1][1]) { $newHoles++; }
}
t('neue Logik: Kette lueckenlos (Ende == naechster Start)', $newHoles === 0, $newHoles . ' Luecken');

$sum = 0;
foreach ($new as [$s, $e]) { $sum += ($e - $s); }
t('Summe der Blocklaengen entspricht der Gesamtspanne',
    $sum === $new[count($new) - 1][1] - $new[0][0],
    $sum . 's');

echo "\nStuechprobe: jeder 15-Minuten-Schritt in der Kette\n";
$uncovered = [];
for ($ts = $new[0][0]; $ts < $new[count($new) - 1][1]; $ts += 900) {
    $found = false;
    foreach ($new as [$s, $e]) {
        if ($ts >= $s && $ts < $e) { $found = true; break; }
    }
    if (!$found) { $uncovered[] = $ts; }
}
t('kein ungedeckter 15-Min-Schritt', count($uncovered) === 0, count($uncovered) . ' Luecken');

echo "\nNetto-Stunden bleiben identisch\n";
$h160 = ScheduleService::calculateActiveHours($start, (clone (new DateTimeImmutable("@$start")))->setTimezone($tz)->modify('+7 days')->setTime(8, 0, 0)->getTimestamp());
$h168 = ScheduleService::calculateActiveHours($start, ScheduleService::weekEndTimestamp($start));
t('160h- und 168h-Block ergeben dieselben Netto-Stunden',
    abs($h160 - $h168) < 0.001, sprintf('%.2f vs %.2f', $h160, $h168));
t('Netto-Stunden = 132h pro normaler Woche', abs($h168 - 132.0) < 0.001, sprintf('%.2f', $h168));

echo "\nisValidBlockRange(): beide Formen akzeptiert\n";
$wed16 = (new DateTimeImmutable('2026-07-01 16:00', $tz))->getTimestamp();
t('168h-Block gueltig', ScheduleService::isValidBlockRange($wed16, ScheduleService::weekEndTimestamp($wed16)));
t('160h-Legacy-Format weiterhin gueltig',
    ScheduleService::isValidBlockRange($wed16, (new DateTimeImmutable('2026-07-08 08:00', $tz))->getTimestamp()));
t('falscher Starttag abgelehnt',
    !ScheduleService::isValidBlockRange((new DateTimeImmutable('2026-06-30 16:00', $tz))->getTimestamp(), ScheduleService::weekEndTimestamp($wed16)));
t('DST-Woche im Maerz gueltig (Toleranz)',
    ScheduleService::isValidBlockRange(
        (new DateTimeImmutable('2026-03-25 16:00', $tz))->getTimestamp(),
        ScheduleService::weekEndTimestamp((new DateTimeImmutable('2026-03-25 16:00', $tz))->getTimestamp())
    ));
t('DST-Woche im Oktober gueltig (Toleranz)',
    ScheduleService::isValidBlockRange(
        (new DateTimeImmutable('2026-10-21 16:00', $tz))->getTimestamp(),
        ScheduleService::weekEndTimestamp((new DateTimeImmutable('2026-10-21 16:00', $tz))->getTimestamp())
    ));
t('160h-Differenz deutlich ausserhalb der Toleranz abgelehnt',
    !ScheduleService::isValidBlockRange($wed16, (new DateTimeImmutable('2026-07-10 08:00', $tz))->getTimestamp()));

echo "\nweeksToCover(): benoetigte Wochenzahl je Halbjahr\n";
foreach ([2026, 2027, 2028] as $y) {
    foreach ([1, 7] as $startMonth) {
        $s = new DateTimeImmutable(sprintf('%d-%02d-01', $y, $startMonth), $tz);
        if ($s->format('N') !== '3') { $s = $s->modify('next Wednesday'); }
        $s = $s->setTime(16, 0, 0);
        $end = $startMonth <= 6
            ? (new DateTimeImmutable("$y-07-01 00:00:00", $tz))->getTimestamp()
            : (new DateTimeImmutable("$y-12-31 23:59:59", $tz))->getTimestamp();
        $w = ScheduleService::weeksToCover($s->getTimestamp(), $end);
        $covered = $s->getTimestamp();
        for ($i = 0; $i < $w; $i++) { $covered = ScheduleService::weekEndTimestamp($covered); }
        t(sprintf('H%d %d: %d Wochen decken bis Halbjahrende', $startMonth <= 6 ? 1 : 2, $y, $w),
            $covered >= $end,
            'End: ' . (new DateTimeImmutable("@$covered"))->setTimezone($tz)->format('d.m.Y H:i')
            . ' vs ' . (new DateTimeImmutable("@$end"))->setTimezone($tz)->format('d.m.Y H:i'));
    }
}

echo "\n" . str_repeat('=', 58) . "\n";
printf("%d/%d bestanden, %d fehlgeschlagen\n", $pass, $pass + $fail, $fail);
echo str_repeat('=', 58) . "\n";
exit($fail === 0 ? 0 : 1);