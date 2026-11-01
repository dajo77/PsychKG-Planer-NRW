<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Auth\AuthManager;
use Src\Core\Html;
use Src\Model\User;
use Src\Service\ScheduleService;
use Src\Service\ShiftService;

$auth = new AuthManager();
// Team-wide roster export: admin only (contains colleagues' duty data).
$auth->requireAdmin();

$currentUser = $auth->getRequiredUser();
$scheduleService = new ScheduleService();

$monthNames = ScheduleService::getGermanMonthNames();

/**
 * Resolves the requested month window from validated Y-m-d bounds.
 *
 * Both inputs were previously handed straight to `new DateTimeImmutable()`,
 * which throws DateMalformedStringException on any unparseable string — an
 * unauthenticated-style 500 reachable by every logged-in user, since the
 * dashboard links straight to this page. Empty/invalid input now falls back to
 * the current half-year instead.
 *
 * @return array{year: int, month_start: int, month_end: int}
 */
function resolveExportMonthRange(string $startInput, string $endInput): array
{
    $default = static function (): array {
        $now = time();
        $month = (int) date('n', $now);
        return [
            'year'        => (int) date('Y', $now),
            'month_start' => $month <= 6 ? 1 : 7,
            'month_end'   => $month <= 6 ? 6 : 12,
        ];
    };

    if ($startInput === '' || $endInput === '') {
        return $default();
    }

    $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $startInput);
    $to   = \DateTimeImmutable::createFromFormat('!Y-m-d', $endInput);
    if ($from === false || $to === false
        || $from->format('Y-m-d') !== $startInput
        || $to->format('Y-m-d') !== $endInput
    ) {
        return $default();
    }

    $yearStart = (int) $from->format('Y');
    $yearEnd   = (int) $to->format('Y');
    if ($yearStart === $yearEnd) {
        return [
            'year'        => $yearStart,
            'month_start' => (int) $from->format('n'),
            'month_end'   => (int) $to->format('n'),
        ];
    }

    // Cross-year range: start year plus the end month offset by 12.
    if ($yearEnd !== $yearStart + 1) {
        return $default(); // more than a year apart — not a supported export
    }

    return [
        'year'        => $yearStart,
        'month_start' => (int) $from->format('n'),
        'month_end'   => 12 + (int) $to->format('n'),
    ];
}

function displaySurname(string $fullName): string
{
    $parts = preg_split('/\s+/u', trim($fullName));
    if (!$parts || $parts[0] === '') {
        return $fullName;
    }
    return (string) end($parts);
}

// Range from validated query parameters. month_from/month_to/year were
// previously raw casts: ?month_from=99&month_to=6 passed the individual bounds,
// got swapped afterwards and then drove a loop over 94 months (~2900 table
// cells), before sprintf('%04d-%02d-01', $year, 99) threw as well.
$range = resolveExportMonthRange(Html::qDate('start'), Html::qDate('end'));

$year       = Html::qi('year', $range['year'], 2000, 2100);
// A cross-year window legitimately runs past 12, so the upper bound here is
// 24 (12 months of the start year plus the end month), not 12.
$monthStart = Html::qi('month_from', $range['month_start'], 1, 24);
$monthEnd   = Html::qi('month_to', $range['month_end'], 1, 24);

if ($monthStart > $monthEnd) {
    [$monthStart, $monthEnd] = [$monthEnd, $monthStart];
}
// Never render more than 12 calendar months.
$monthEnd = min($monthEnd, $monthStart + 11);

$employees = $auth->getDutyEligibleUsers();
usort($employees, static function (User $a, User $b) {
    return strcasecmp(displaySurname($a->getName()), displaySurname($b->getName()));
});

$employeeIds = array_map(function (User $u) { return $u->getId(); }, $employees);

$months = [];
$dayKeys = [];
for ($m = $monthStart; $m <= $monthEnd; $m++) {
    // Normalise the possibly cross-year month offset (m may exceed 12).
    $actualMonth = (($m - 1) % 12) + 1;
    $actualYear = $year + intdiv($m - 1, 12);
    $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $actualYear, $actualMonth)))->format('t');
    $days = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateKey = sprintf('%04d-%02d-%02d', $actualYear, $actualMonth, $d);
        $days[] = [
            'key' => $dateKey,
            'day' => $d,
            'class' => ScheduleService::getDayColumnClass($dateKey),
        ];
        $dayKeys[] = $dateKey;
    }
    $months[] = [
        'number' => $actualMonth,
        'label' => $monthNames[$actualMonth],
        'days' => $days,
    ];
}

// Period bounds are derived from the first/last normalised month, not from
// $monthStart/$monthEnd directly: for a cross-year window those raw values can
// exceed 12, which both strtotime() and DateTimeImmutable() reject.
$first = $months[array_key_first($months)];
$last = $months[array_key_last($months)];
$firstTs = strtotime($first['days'][0]['key'] . ' 00:00:00');
$lastKey = end($last['days'])['key'];
$periodStartTs = $firstTs !== false ? $firstTs : time();
$periodEndTs = strtotime($lastKey . ' 23:59:59');
if ($periodEndTs === false || $periodEndTs < $periodStartTs) {
    $periodEndTs = $periodStartTs;
}

$blocks = $scheduleService->getBlocksOverlappingRange($periodStartTs, $periodEndTs);
$matrix = ScheduleService::buildDailyDutyMatrix($blocks, $dayKeys, $employeeIds);

// Derive the title from the normalised months: for a cross-year window the raw
// $monthEnd can be > 12 and would index $monthNames out of bounds.
$titleYearFrom = (int) substr($first['days'][0]['key'], 0, 4);
$titleYearTo   = (int) substr($lastKey, 0, 4);
$documentTitle = ($titleYearFrom === $titleYearTo)
    ? sprintf('PsychKG-Einsatzplan - %s - %s %d', $first['label'], $last['label'], $titleYearFrom)
    : sprintf('PsychKG-Einsatzplan - %s %d - %s %d', $first['label'], $titleYearFrom, $last['label'], $titleYearTo);

/** @var array<int, array<int, array>> $monthPairs */
$monthPairs = [];
for ($i = 0; $i < count($months); $i += 2) {
    $monthPairs[] = array_slice($months, $i, 2);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($documentTitle) ?></title>
    <link rel="stylesheet" href="assets/css/export_schedule_pdf.css">
</head>
<body>

    <div class="action-bar">
        <button class="btn btn-primary" type="button" id="print-btn">Drucken / Als PDF speichern</button>
        <a href="dashboard.php" class="btn">Zurück zum Dashboard</a>
        <?php if ($currentUser->isAdmin()): ?>
            <a href="admin.php" class="btn">Admin-Bereich</a>
        <?php endif; ?>
    </div>

    <div class="print-sheet">
        <h1 class="doc-title"><?= htmlspecialchars($documentTitle) ?></h1>

        <?php foreach ($monthPairs as $pair): ?>
            <div class="calendar-section">
                <table class="calendar-grid">
                    <tr>
                        <th class="label-head"></th>
                        <?php foreach ($pair as $month): ?>
                            <th class="month-head" colspan="<?= count($month['days']) ?>">
                                <?= htmlspecialchars($month['label']) ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="label-head">Mitarbeiter</th>
                        <?php foreach ($pair as $month): ?>
                            <?php foreach ($month['days'] as $day): ?>
                                <th class="day-head <?= htmlspecialchars($day['class']) ?>"><?= $day['day'] ?></th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td class="employee-name" colspan="<?= 1 + array_sum(array_map(function ($m) { return count($m['days']); }, $pair)) ?>">
                                Keine Mitarbeiter im System hinterlegt.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <?php $userId = $employee->getId(); ?>
                            <tr>
                                <td class="employee-name"><?= htmlspecialchars(displaySurname($employee->getName())) ?></td>
                                <?php foreach ($pair as $month): ?>
                                    <?php foreach ($month['days'] as $day): ?>
                                        <?php
                                        $dayKey = $day['key'];
                                        $hasDuty = $matrix[$userId][$dayKey] ?? false;
                                        ?>
                                        <td class="<?= htmlspecialchars($day['class']) ?>">
                                            <?php if ($hasDuty): ?>
                                                <span class="mark">x</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="legend">
            <span class="legend-item">
                <span class="legend-swatch weekend"></span>
                Wochenende
            </span>
            <span class="legend-item">
                <span class="legend-swatch holiday"></span>
                Feiertag
            </span>
        </div>

        <div class="meta-line">
            Erstellt am <?= date('d.m.Y, H:i') ?> Uhr · <?= htmlspecialchars($currentUser->getName()) ?>
            · Bereitschaft bis Dienstende (<?= ShiftService::getDayName(ShiftService::getDutyStartDay()) ?> <?= ShiftService::getBereitschaftEndTime() ?>) · Innendienst <?= ShiftService::getInnendienstStart() ?>–<?= ShiftService::getInnendienstEnd() ?>, Fr <?= ShiftService::getInnendienstFridayEnd() ?>
        </div>
    </div>

    <script src="assets/js/print.js" nonce="<?= CSP_NONCE ?>"></script>
</body>
</html>
