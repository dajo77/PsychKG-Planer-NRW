<?php
namespace Src\Service;

use Src\Auth\AuthManager;
use Src\Core\Csv;

class BillingService
{
    private ScheduleService $scheduleService;
    private AuthManager $authManager;

    public function __construct()
    {
        $this->scheduleService = new ScheduleService();
        $this->authManager = new AuthManager();
    }

    /**
     * Aggregates shift statistics for all users within a given timestamp range.
     * Looks at who EFFECTIVELY held the shift (assigned_user_id).
     */
    public function generateSummary(int $startTimestamp, int $endTimestamp): array
    {
        $blocks = $this->scheduleService->getAllBlocks();
        $users = $this->authManager->getAllUsers();

        $summary = [];
        foreach ($users as $user) {
            if ($user->isAdmin()) {
                continue; // Admins typically do not hold shifts
            }
            $summary[$user->getId()] = [
                'user' => $user,
                'blocks_count' => 0,
                'total_hours' => 0.0,
                'details' => []
            ];
        }

        foreach ($blocks as $block) {
            // Check if the block overlaps with the period range
            $blockOverlaps = $block->getStartTimestamp() <= $endTimestamp
                && $block->getEndTimestamp() > $startTimestamp;
            if ($blockOverlaps) {
                $assignedId = $block->getAssignedUserId();
                
                if (isset($summary[$assignedId])) {
                    // Clip block to billing range to avoid counting hours outside the period
                    $clippedStart = max($block->getStartTimestamp(), $startTimestamp);
                    $clippedEnd = min($block->getEndTimestamp(), $endTimestamp);
                    $activeHours = ScheduleService::calculateActiveHours($clippedStart, $clippedEnd);

                    $summary[$assignedId]['blocks_count']++;
                    $summary[$assignedId]['total_hours'] += $activeHours;
                    $summary[$assignedId]['details'][] = [
                        'block_id' => $block->getId(),
                        'start' => $block->getFormattedStart(),
                        'end' => $block->getFormattedEnd(),
                        'start_ts' => $block->getStartTimestamp(),
                        'end_ts' => $block->getEndTimestamp(),
                        'hours' => $activeHours
                    ];
                }
            }
        }

        // Sort by total hours descending
        uasort($summary, function ($a, $b) {
            return $b['total_hours'] <=> $a['total_hours'];
        });

        return $summary;
    }

    /**
     * Year fairness stats per employee: blocks, net hours, distinct duty days,
     * thereof weekend days and NRW-holiday days. For the annual load comparison.
     * @return array<string, array{user: \Src\Model\User, blocks: int, hours: float, days: int, weekend_days: int, holiday_days: int, weekday_counts: array<int, int}}>     */
    public function generateYearStats(int $year): array
    {
        $yearStart = strtotime(sprintf('%04d-01-01 00:00:00', $year));
        $yearEnd = strtotime(sprintf('%04d-12-31 23:59:59', $year));

        $stats = [];
        foreach ($this->authManager->getAllUsers() as $user) {
            if ($user->isAdmin()) {
                continue;
            }
            $stats[$user->getId()] = [
                'user' => $user,
                'blocks' => 0,
                'hours' => 0.0,
                'days' => 0,
                'weekend_days' => 0,
                'holiday_days' => 0,
                'weekday_counts' => [0, 0, 0, 0, 0, 0, 0],
            ];
        }

        // Distinct duty days per user (a day counts once even if blocks touch it).
        $userDays = [];
        foreach ($this->scheduleService->getAllBlocks() as $block) {
            if ($block->getStartTimestamp() > $yearEnd || $block->getEndTimestamp() <= $yearStart) {
                continue;
            }
            $uid = $block->getAssignedUserId();
            if (!isset($stats[$uid])) {
                continue;
            }
            $stats[$uid]['blocks']++;
            $stats[$uid]['hours'] += ScheduleService::calculateActiveHours(
                max($block->getStartTimestamp(), $yearStart),
                min($block->getEndTimestamp(), $yearEnd)
            );

            $dayTs = strtotime(date('Y-m-d', max($block->getStartTimestamp(), $yearStart)) . ' 12:00:00');
            $blockEnd = min($block->getEndTimestamp(), $yearEnd);
            while ($dayTs < $blockEnd) {
                $userDays[$uid][date('Y-m-d', $dayTs)] = true;
                $dayTs = strtotime('+1 day', $dayTs);
            }
        }

        foreach ($userDays as $uid => $days) {
            $stats[$uid]['days'] = count($days);
            foreach (array_keys($days) as $dayKey) {
                $ts = strtotime($dayKey . ' 12:00:00');
                $dow = (int) date('N', $ts);
                if ($dow >= 6) {
                    $stats[$uid]['weekend_days']++;
                } elseif (ScheduleService::isNrwHoliday($ts)) {
                    $stats[$uid]['holiday_days']++;
                }
                $stats[$uid]['weekday_counts'][$dow - 1] = ($stats[$uid]['weekday_counts'][$dow - 1] ?? 0) + 1;
            }
        }

        uasort($stats, fn ($a, $b) => $b['hours'] <=> $a['hours']);
        return $stats;
    }

    /**
    /**
     * True if any employee has at least one block in the summary.
     */
    public static function summaryHasActivity(array $summary): bool
    {
        foreach ($summary as $data) {
            if (($data['blocks_count'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parses a YYYY-MM-DD date range from user input (e.g. `?start=&end=`).
     * Invalid or missing values fall back to the defaults; inverted ranges
     * are swapped. Never throws – safe against tampered query parameters.
     * @return array{int, int} [startTimestamp, endTimestamp]
     */
    public static function parseBillingRange(
        ?string $startInput,
        ?string $endInput,
        string $defaultStart = '-4 weeks',
        string $defaultEnd = 'today'
    ): array {
        $startTs = self::parseDateInput($startInput, '00:00:00')
            ?? strtotime($defaultStart . ' 00:00:00');
        $endTs = self::parseDateInput($endInput, '23:59:59')
            ?? strtotime($defaultEnd . ' 23:59:59');

        if ($startTs === false) {
            $startTs = strtotime('-4 weeks 00:00:00');
        }
        if ($endTs === false) {
            $endTs = time();
        }
        if ($startTs > $endTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }
        return [(int) $startTs, (int) $endTs];
    }

    /**
     * Strict YYYY-MM-DD parser, returns null on any deviation.
     */
    private static function parseDateInput(?string $input, string $timeSuffix): ?int
    {
        if ($input === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if ($dt === false || $dt->format('Y-m-d') !== $input) {
            return null; // e.g. month 13 or Feb 30
        }
        $ts = strtotime($input . ' ' . $timeSuffix);
        return $ts === false ? null : $ts;
    }

    /**
     * Neutralizes CSV formula injection (OWASP): cells starting with
     * = + - @ (or tab/CR) are prefixed with a single quote so Excel
     * treats them as text. Applied to all user-controlled text cells.
     */
    public static function csvCell(string $value): string
    {
        return Csv::cell($value);
    }

    /**
     * Generates a CSV string from summary data.
     */
    public function exportToCsv(array $summaryData, string $periodLabel): string
    {
        $output = fopen('php://temp', 'r+');
        
        // UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Headers
        fputcsv($output, [
            'Abrechnungszeitraum', 
            $periodLabel
        ], ';');
        
        fputcsv($output, [], ';'); // Empty line

        fputcsv($output, [
            'Mitarbeiter-ID',
            'Name',
            'Benutzername',
            'Anzahl Dienste',
            'Gesamt Bereitschaftsstunden (Netto)'
        ], ';');

        foreach ($summaryData as $userId => $data) {
            $user = $data['user'];
            fputcsv($output, [
                $user->getId(),
                self::csvCell($user->getName()),
                self::csvCell($user->getUsername()),
                $data['blocks_count'],
                number_format($data['total_hours'], 2, ',', '')
            ], ';');
        }

        fputcsv($output, [], ';'); // Empty line
        fputcsv($output, ['Dienstplan-Details nach Mitarbeiter'], ';');
        
        fputcsv($output, [
            'Mitarbeiter',
            'Dienst-ID',
            'Beginn',
            'Ende',
            'Stunden (Netto)'
        ], ';');

        foreach ($summaryData as $userId => $data) {
            $user = $data['user'];
            foreach ($data['details'] as $detail) {
                fputcsv($output, [
                    self::csvCell($user->getName()),
                    $detail['block_id'],
                    $detail['start'],
                    $detail['end'],
                    number_format($detail['hours'], 2, ',', '')
                ], ';');
            }
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }
}
