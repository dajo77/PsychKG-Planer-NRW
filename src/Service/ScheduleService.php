<?php
declare(strict_types=1);
namespace Src\Service;

use Src\Auth\AuthManager;
use Src\Core\Config;
use Src\Storage\JsonStorage;
use Src\Model\ScheduleBlock;

class ScheduleService
{
    /** One duty week, 7 x 24 h. */
    private const WEEK_SECONDS = 7 * 24 * 3600;

    private JsonStorage $storage;

    /** @var ScheduleBlock[]|null Request cache */
    private static ?array $blocksCache = null;

    /** @var array<int, array{easter: int, good_friday: int, easter_monday: int, ascension: int, whit_monday: int, corpus_christi: int}> Easter date cache per year */
    private static array $easterCache = [];

    /** Resets the request cache (call after any write). */
    public static function clearCache(): void
    {
        self::$blocksCache = null;
    }

    public function __construct()
    {
        $this->storage = new JsonStorage(Config::getScheduleFilePath());
    }

    /**
     * Checks if a date is a public holiday in North Rhine-Westphalia (NRW).
     */
    public static function isNrwHoliday(int $timestamp): bool
    {
        $year = (int)date('Y', $timestamp);
        $dateStr = date('m-d', $timestamp);

        // Fixed-date public holidays in NRW
        $fixedHolidays = [
            '01-01', // Neujahr
            '05-01', // Tag der Arbeit
            '10-03', // Tag der Deutschen Einheit
            '11-01', // Allerheiligen
            '12-25', // 1. Weihnachtstag
            '12-26', // 2. Weihnachtstag
        ];

        if (in_array($dateStr, $fixedHolidays)) {
            return true;
        }

        // Easter-dependent holidays (cached per year)
        if (!isset(self::$easterCache[$year])) {
            $easterDays = easter_days($year);
            $easterSunday = strtotime("$year-03-21 +$easterDays days");
            self::$easterCache[$year] = [
                'good_friday'    => date('Y-m-d', strtotime('-2 days', $easterSunday)),
                'easter_monday'  => date('Y-m-d', strtotime('+1 day', $easterSunday)),
                'ascension'      => date('Y-m-d', strtotime('+39 days', $easterSunday)),
                'whit_monday'    => date('Y-m-d', strtotime('+50 days', $easterSunday)),
                'corpus_christi' => date('Y-m-d', strtotime('+60 days', $easterSunday)),
            ];
        }

        $holidays = self::$easterCache[$year];
        $currentDay = date('Y-m-d', $timestamp);

        return in_array($currentDay, $holidays, true);
    }

    /**
     * Splits a block into detailed intervals of either active on-call or internal office duty.
     * Takes weekends AND NRW public holidays into account (where the internal office is closed).
     *
     * Innendienst hours come from the shift settings (ShiftService). The duty
     * block itself is handover-based (Bereitschaftswechsels), so the configured
     * duty start time plays into the intervals only via the block boundaries.
     *
     * Innendienst hours:
     *  - Mo–Do: 08:00–16:00 (configurable via shift settings)
     *  - Fri:   08:00–12:00 (configurable via shift settings)
     *  - Sat, Sun and any NRW public holiday: no Innendienst → 24h Bereitschaft
     *  - innendienst_enabled = false → no Innendienst at all → 24h Bereitschaft
     */
    public static function calculateIntervals(int $startTs, int $endTs): array
    {
        $intervals = [];
        $currentTs = $startTs;

        $innendienstEnabled = ShiftService::isInnendienstEnabled();
        $officeDays = ShiftService::getInnendienstOfficeDays();
        $innendienstStart = ShiftService::getInnendienstStart();
        $innendienstEnd = ShiftService::getInnendienstEnd();
        $innendienstFridayEnd = ShiftService::getInnendienstFridayEnd();

        while ($currentTs < $endTs) {
            $currentDateStr = date('Y-m-d', $currentTs);
            $dayOfWeek = (int)date('N', $currentTs); // 1 = Monday, 7 = Sunday

            $isOfficeDay = $innendienstEnabled
                && in_array($dayOfWeek, $officeDays, true)
                && $dayOfWeek >= 1 && $dayOfWeek <= 5
                && !self::isNrwHoliday($currentTs);

            if (!$isOfficeDay) {
                // Weekend or Public Holiday (or Innendienst disabled): pure On-Call duty
                $nextDayStart = strtotime($currentDateStr . ' +1 day 00:00:00');
                $nextBoundary = min($nextDayStart, $endTs);
                $intervals[] = [
                    'type' => 'bereitschaft',
                    'start' => $currentTs,
                    'end' => $nextBoundary
                ];
                $currentTs = $nextBoundary;
                continue;
            }

            $officeStart = strtotime($currentDateStr . ' ' . $innendienstStart . ':00');
            $officeEnd = $dayOfWeek === 5
                ? strtotime($currentDateStr . ' ' . $innendienstFridayEnd . ':00')
                : strtotime($currentDateStr . ' ' . $innendienstEnd . ':00');

            // 1. Prior to internal office hours
            if ($currentTs < $officeStart) {
                $nextBoundary = min($officeStart, $endTs);
                $intervals[] = [
                    'type' => 'bereitschaft',
                    'start' => $currentTs,
                    'end' => $nextBoundary
                ];
                $currentTs = $nextBoundary;
            }
            // 2. During internal office hours
            elseif ($currentTs >= $officeStart && $currentTs < $officeEnd) {
                $nextBoundary = min($officeEnd, $endTs);
                $intervals[] = [
                    'type' => 'innendienst',
                    'start' => $currentTs,
                    'end' => $nextBoundary
                ];
                $currentTs = $nextBoundary;
            }
            // 3. After internal office hours
            else {
                $nextDayStart = strtotime($currentDateStr . ' +1 day 00:00:00');
                $nextBoundary = min($nextDayStart, $endTs);
                $intervals[] = [
                    'type' => 'bereitschaft',
                    'start' => $currentTs,
                    'end' => $nextBoundary
                ];
                $currentTs = $nextBoundary;
            }
        }

        return $intervals;
    }

    /**
     * Computes total net on-call active hours (excluding internal office hours).
     */
    public static function calculateActiveHours(int $startTs, int $endTs): float
    {
        $intervals = self::calculateIntervals($startTs, $endTs);
        $activeSeconds = 0;

        foreach ($intervals as $interval) {
            if ($interval['type'] === 'bereitschaft') {
                $activeSeconds += ($interval['end'] - $interval['start']);
            }
        }

        return $activeSeconds / 3600;
    }

    /**
     * Gibt die Intervalle des Blocks zurück, wobei benachbarte Intervalle
     * gleichen Typs zusammengefasst werden (z. B. eine Wochenend-Bereitschaft
     * von Fr 12:00 bis Mo 08:00 als EIN Fenster).
     */
    public static function getMergedIntervals(int $startTs, int $endTs): array
    {
        $intervals = self::calculateIntervals($startTs, $endTs);
        $merged = [];
        foreach ($intervals as $iv) {
            $last = end($merged);
            if ($last !== false && $last['type'] === $iv['type'] && $last['end'] === $iv['start']) {
                $merged[count($merged) - 1]['end'] = $iv['end'];
            } else {
                $merged[] = $iv;
            }
        }
        return $merged;
    }

    /**
     * Gibt das exakte Phasen-Fenster (Innendienst oder Bereitschaft) zurück,
     * das den Zeitpunkt $now enthält.
     * @return array{type: string, start: int, end: int}
     */
    public static function getPhaseAt(int $startTs, int $endTs, int $now): array
    {
        $merged = self::getMergedIntervals($startTs, $endTs);
        foreach ($merged as $iv) {
            if ($now >= $iv['start'] && $now < $iv['end']) {
                return $iv;
            }
        }
        // Fallback (sollte nicht passieren, wenn $now im Block liegt)
        return ['type' => 'bereitschaft', 'start' => $startTs, 'end' => $endTs];
    }

    /**
     * Validates a duty block's time range.
     *
     * The stored block is a duty-period container starting at the configured
     * Bereitschaftswechsels (duty start day/time). The on-call duty inside it
     * ends at the bereitschaft end; the trailing Innendienst window is deducted
     * by calculateActiveHours(). The container deliberately reaches the duty
     * start time so that when that day is a public holiday, the on-call person
     * covers the whole Innendienst window (holidays are 24h Bereitschaft).
     *
     * Legacy shape: 160h (6 days 16h), Mi 16:00 → Mi 08:00. Still accepted
     * so old blocks keep validating; repairCoverage() extends them to 168h.
     *
     * Both are tolerated within ±2h for DST transitions. The handover day and
     * time are taken from the shift settings (ShiftService).
     */
    public static function isValidBlockRange(int $startTs, int $endTs): bool
    {
        $dutyStartDay = ShiftService::getDutyStartDay();
        $dutyStartTime = ShiftService::getDutyStartTime();

        $startDay  = (int)date('N', $startTs);
        $endDay    = (int)date('N', $endTs);
        $startHour = (int)date('H', $startTs);
        $startMin  = (int)date('i', $startTs);
        $endHour   = (int)date('H', $endTs);
        $endMin    = (int)date('i', $endTs);

        // Start muss am konfigurierten Bereitschaftswechsels liegen (Wochentag + Uhrzeit).
        if ($startDay !== $dutyStartDay) {
            return false;
        }
        if ($startHour !== (int)substr($dutyStartTime, 0, 2) || $startMin !== (int)substr($dutyStartTime, 3, 2)) {
            return false;
        }

        $diffSeconds         = $endTs - $startTs;
        $currentShapeSeconds = 7 * 24 * 3600;             // 168h (kanonisch: dutyStart -> naechster DutyStart)
        $tolerance           = 7200; // ±2h for DST

        // Kanonisches Format: dutyStart -> naechster DutyStart (7 Tage).
        if ($endDay === $dutyStartDay && abs($diffSeconds - $currentShapeSeconds) <= $tolerance) {
            return true;
        }

        // Legacy-Format: Bereitschaftsende = naechster DutyStart - InnendienstDauer.
        // Bei den Defaults (Mittwoch 16:00, Innendienst 08:00-16:00) entspricht das 160h.
        if ($endDay === $dutyStartDay) {
            $innendienstSeconds = ShiftService::getInnendienstEndSeconds() - ShiftService::getInnendienstStartSeconds();
            $legacyEndTs = ShiftService::getDutyWeekEndTimestamp($startTs) - $innendienstSeconds;
            if (abs($endTs - $legacyEndTs) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets all schedule blocks (with request cache).
     * @return ScheduleBlock[]
     */
    public function getAllBlocks(): array
    {
        if (self::$blocksCache !== null) {
            return self::$blocksCache;
        }

        $data = $this->storage->read();
        $blocksData = $data['blocks'] ?? [];

        // Sort chronologically
        usort($blocksData, function ($a, $b) {
            return $a['start_timestamp'] <=> $b['start_timestamp'];
        });

        $blocks = [];
        foreach ($blocksData as $bData) {
            $blocks[] = ScheduleBlock::fromArray($bData);
        }

        self::$blocksCache = $blocks;
        return $blocks;
    }

    /**
     * Save a list of ScheduleBlock objects back to json.
     * Invalidates the request cache.
     * @param ScheduleBlock[] $blocks
     */
    public function saveBlocks(array $blocks): bool
    {
        self::clearCache();
        $blocksData = [];
        foreach ($blocks as $block) {
            $blocksData[] = $block->toArray();
        }
        return $this->storage->write(['blocks' => $blocksData]);
    }

    /**
     * Number of duty weeks needed to cover a period end-to-end.
     */
    public static function weeksToCover(int $startTs, int $endTs): int
    {
        if ($endTs <= $startTs) {
            return 0;
        }
        $weeks = 0;
        $cursor = $startTs;
        while ($cursor < $endTs) {
            $weeks++;
            $cursor = self::weekEndTimestamp($cursor);
            if ($weeks > 104) {
                break; // safety stop, cannot span more than two years
            }
        }
        return $weeks;
    }

    /**
     * End timestamp of the duty block starting at $startTs.
     *
     * Canonical shape: Mi 16:00 → Mi 16:00 (168h). Consecutive blocks meet
     * exactly at every Wednesday 16:00 — the weekly handover.
     * DST-safe: uses DateTime modify so wall-clock is preserved.
     */
    public static function weekEndTimestamp(int $startTs): int
    {
        $tz = new \DateTimeZone(Config::getTimezone());
        $dt = new \DateTime('@' . $startTs);
        $dt->setTimezone($tz);
        $dt->modify('+7 days');
        return $dt->getTimestamp();
    }

    /**
     * Next duty start (Bereitschaftswechsels) on or after $ts, based on the
     * shift settings (ShiftService: duty start day/time).
     */
    public static function nextDutyStartAfter(int $ts): int
    {
        $day = ShiftService::getDutyStartDay();
        $time = ShiftService::getDutyStartTime();
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(Config::getTimezone()));
        if ((int) $dt->format('N') !== $day) {
            $dt = $dt->modify('next ' . ShiftService::DAY_NAMES[$day]);
        }
        $dt = $dt->setTime((int)substr($time, 0, 2), (int)substr($time, 3, 2), 0);
        return $dt->getTimestamp();
    }

    /**
     * Brings every block back to the canonical 168h shape (Mi 16:00 → Mi 16:00).
     *
     * Each block's end is recomputed from its own start via weekEndTimestamp(),
     * so 160h legacy blocks are extended to 168h and previously mis-shaped
     * blocks are re-aligned. Assignments are never touched.
     *
     * @return array{checked:int, stitched:int, extended:int, first_ts:int, last_ts:int, gaps_before:int, written:bool}
     */
    public function repairCoverage(): array
    {
        return JsonStorage::synchronized(function (): array {
            $blocks = $this->getAllBlocks();
            if (empty($blocks)) {
                return [
                    'checked' => 0, 'stitched' => 0, 'extended' => 0,
                    'first_ts' => 0, 'last_ts' => 0, 'gaps_before' => 0,
                    'written' => true,
                ];
            }

            usort($blocks, fn ($a, $b) => $a->getStartTimestamp() <=> $b->getStartTimestamp());

            $stitched = 0;
            $extended = 0;
            $gapsBefore = 0;

            $count = count($blocks);
            for ($i = 0; $i < $count; $i++) {
                $block  = $blocks[$i];
                $oldEnd = $block->getEndTimestamp();

                // Canonical 168h boundary: exactly 7 days after this block's
                // own start = next Wednesday 16:00.
                $target = self::weekEndTimestamp($block->getStartTimestamp());

                if ($target > $oldEnd) {
                    $gapsBefore += (int) ($target - $oldEnd);
                }

                if ($oldEnd === $target) {
                    continue;
                }
                if ($oldEnd < $target) {
                    $stitched++;   // end moved later (e.g. 160h → 168h)
                } else {
                    $extended++;   // end moved earlier
                }

                $blocks[$i] = new ScheduleBlock(
                    $block->getId(),
                    $block->getStartTimestamp(),
                    $target,
                    $block->getAssignedUserId(),
                    $block->getOriginalUserId(),
                    $block->getStatus()
                );
            }

            self::clearCache();
            $ok = $this->storage->write([
                'blocks' => array_map(fn ($b) => $b->toArray(), $blocks),
            ]);

            return [
                'checked'     => $count,
                'stitched'    => $stitched,
                'extended'    => $extended,
                'first_ts'    => $blocks[0]->getStartTimestamp(),
                'last_ts'     => $blocks[$count - 1]->getEndTimestamp(),
                'gaps_before' => $gapsBefore,
                'written'     => $ok,
            ];
        });
    }

    /**
     * Repairs existing blocks and then extends the chain with fair
     * round-robin blocks until $targetEndTs is covered.
     *
     * Existing assignments are never touched: only block boundaries move.
     *
     * @param  array<int,string> $employeeIds duty-eligible user ids
     * @return array{checked:int,stitched:int,extended:int,added:int,written:bool,ok:bool}
     */
    public function repairCoverageAndExtend(array $employeeIds, int $targetEndTs = 0): array
    {
        $result = $this->repairCoverage();
        $result['added'] = 0;
        $result['ok'] = !empty($result['written']);

        if (empty($employeeIds) || $targetEndTs <= 0) {
            return $result;
        }

        $blocks = $this->getAllBlocks();
        if (empty($blocks)) {
            return $result;
        }

        $last = $blocks[count($blocks) - 1];
        $cursor = $last->getEndTimestamp();
        if ($cursor >= $targetEndTs) {
            return $result;
        }

        $weeks = (int) ceil(($targetEndTs - $cursor) / self::WEEK_SECONDS);
        if ($weeks > 104) {
            return $result;
        }

        $startDate = (new \DateTimeImmutable('@' . $cursor))
            ->setTimezone(new \DateTimeZone(Config::getTimezone()))
            ->format('Y-m-d');

        if ($this->generateWeeks($startDate, $weeks, $employeeIds, true)) {
            $result['added'] = $weeks;
            $result['ok'] = true;
        }

        return $result;
    }

    /**
     * Finds coverage gaps in the plan, for the health check.
     * With 168h blocks there should be no gaps at all.
     *
     * @return array<int, array{from:int, to:int, hours:float}>
     */
    public static function findCoverageGaps(): array
    {
        $blocks = self::getAllBlocksSorted();
        $gaps = [];
        for ($i = 1; $i < count($blocks); $i++) {
            $prevEnd  = $blocks[$i - 1]->getEndTimestamp();
            $curStart = $blocks[$i]->getStartTimestamp();
            if ($curStart > $prevEnd) {
                $gaps[] = [
                    'from'  => $prevEnd,
                    'to'    => $curStart,
                    'hours' => round(($curStart - $prevEnd) / 3600, 2),
                ];
            }
        }
        return $gaps;
    }

    /**
     * Whether the plan covers the given instant.
     */
    public static function isCovered(int $timestampTs): bool
    {
        foreach (self::getAllBlocksSorted() as $b) {
            if ($timestampTs >= $b->getStartTimestamp() && $timestampTs < $b->getEndTimestamp()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Blocks ordered chronologically, read straight from storage.
     * @return ScheduleBlock[]
     */
    private static function getAllBlocksSorted(): array
    {
        $storage = new JsonStorage(Config::getScheduleFilePath());
        $data = $storage->read();
        $raw = $data['blocks'] ?? [];
        usort($raw, fn ($a, $b) => ($a['start_timestamp'] ?? 0) <=> ($b['start_timestamp'] ?? 0));
        return array_map(fn ($b) => ScheduleBlock::fromArray($b), $raw);
    }

    /**
     * Generates upcoming Wednesday-to-Wednesday blocks starting from a specific date.
     * Starts on the Wednesday on or after $startDateStr at 16:00 and runs the
     * full 7 days until the next Wednesday 16:00.
     */
    public function generateWeeks(string $startDateStr, int $numberOfWeeks, array $userIds, bool $clearRange = false): bool
    {
        return JsonStorage::synchronized(function () use ($startDateStr, $numberOfWeeks, $userIds, $clearRange): bool {
        $auth = new AuthManager();
        $userIds = array_values(array_filter($userIds, fn (string $id) => $auth->isDutyEligibleUserId($id)));

        if (empty($userIds)) {
            return false;
        }
        if ($numberOfWeeks > 104) {
            return false;
        }

        try {
            $startDateTime = new \DateTime($startDateStr);
        } catch (\Exception $e) {
            return false;
        }
        $dutyStartDay = ShiftService::getDutyStartDay();
        $dutyStartTime = ShiftService::getDutyStartTime();
        if ((int) $startDateTime->format('N') !== $dutyStartDay) {
            $startDateTime->modify('next ' . ShiftService::DAY_NAMES[$dutyStartDay]);
        }
        $startDateTime->setTime((int) substr($dutyStartTime, 0, 2), (int) substr($dutyStartTime, 3, 2), 0);

        $currentDutyWeekStart = self::getDutyWeekStartForTimestamp(time());
        if ($startDateTime->getTimestamp() < $currentDutyWeekStart) {
            return false;
        }

        if ($numberOfWeeks <= 0) {
            $year = (int) $startDateTime->format('Y');
            $month = (int) $startDateTime->format('n');
            $periodStart = $startDateTime->getTimestamp();
            if ($month <= 6) {
                $periodEndTs = (new \DateTimeImmutable("{$year}-07-01 00:00:00"))
                    ->setTimezone(new \DateTimeZone(Config::getTimezone()))
                    ->getTimestamp();
            } else {
                $periodEndTs = (new \DateTimeImmutable("{$year}-12-31 23:59:59"))
                    ->setTimezone(new \DateTimeZone(Config::getTimezone()))
                    ->getTimestamp();
            }
            $numberOfWeeks = max(1, self::weeksToCover($periodStart, $periodEndTs));
        }

        $rangeEnd = clone $startDateTime;
        $rangeEnd->modify("+$numberOfWeeks weeks");
        $rangeEndTs = $rangeEnd->getTimestamp();

        $blocks = $this->getAllBlocks();
        $existingMap = [];
        $existingUserMap = [];
        foreach ($blocks as $b) {
            $existingMap[$b->getStartTimestamp()] = true;
            $existingUserMap[$b->getStartTimestamp()] = $b->getAssignedUserId();
        }

        if ($clearRange) {
            $rangeStartTs = $startDateTime->getTimestamp();
            $blocks = array_values(array_filter($blocks, function ($b) use ($rangeStartTs, $rangeEndTs) {
                return $b->getStartTimestamp() < $rangeStartTs || $b->getStartTimestamp() >= $rangeEndTs;
            }));
            $existingMap = [];
            $existingUserMap = [];
            foreach ($blocks as $b) {
                $existingMap[$b->getStartTimestamp()] = true;
                $existingUserMap[$b->getStartTimestamp()] = $b->getAssignedUserId();
            }
        }

        $userCount = count($userIds);
        $userIndex = 0;
        $added = 0;

        for ($i = 0; $i < $numberOfWeeks; $i++) {
            $weekStart = clone $startDateTime;
            $weekStart->modify("+$i weeks");

            // Full 168h duty week: Mi 16:00 → Mi 16:00.
            $weekEnd = clone $weekStart;
            $weekEnd->modify("+7 days");

            $startTimestamp = $weekStart->getTimestamp();
            $endTimestamp = $weekEnd->getTimestamp();

            if (!self::isValidBlockRange($startTimestamp, $endTimestamp)) {
                continue;
            }

            if (isset($existingMap[$startTimestamp])) {
                continue;
            }

            $absenceService = new AbsenceService();
            $attempts = 0;
            do {
                $userId = $userIds[$userIndex % $userCount];
                $userIndex++;
                $attempts++;
                $prevTs = strtotime('-1 week', $startTimestamp);
                $prevUser = $existingUserMap[$prevTs] ?? null;
                $absent = $absenceService->isAbsent($userId, $startTimestamp, $endTimestamp);
            } while (($prevUser === $userId || $absent) && $attempts <= $userCount * 2);

            $id = 'blk_' . date('Y_W', $startTimestamp) . '_' . bin2hex(random_bytes(16));

            $blocks[] = new ScheduleBlock(
                $id,
                $startTimestamp,
                $endTimestamp,
                $userId,
                $userId,
                'assigned'
            );
            $existingMap[$startTimestamp] = true;
            $existingUserMap[$startTimestamp] = $userId;
            $added++;
        }

        if ($added > 0) {
            return $this->saveBlocks($blocks);
        }

        return true;
        });
    }

    /**
     * Retrieve a specific schedule block by ID.
     */
    public function getBlockById(string $id): ?ScheduleBlock
    {
        foreach ($this->getAllBlocks() as $block) {
            if ($block->getId() === $id) {
                return $block;
            }
        }
        return null;
    }

    /**
     * Manually updates the assignment of a block.
     */
    public function assignUserToBlock(string $blockId, string $userId): bool
    {
        return JsonStorage::synchronized(function () use ($blockId, $userId) {
        $auth = new AuthManager();
        if (!$auth->isDutyEligibleUserId($userId)) {
            return false;
        }

        $blocks = $this->getAllBlocks();
        foreach ($blocks as $block) {
            if ($block->getId() === $blockId) {
                $block->setAssignedUserId($userId);
                $block->setOriginalUserId($userId);
                $block->setStatus('assigned');

                if (!$this->saveBlocks($blocks)) {
                    return false;
                }

                $tradeService = new TradeService();
                $tradeService->cancelTradesForBlock($blockId);
                return true;
            }
        }
        return false;
        });
    }

    /**
     * Blocks overlapping a timestamp range (by block start date).
     * @return ScheduleBlock[]
     */
    public function getBlocksInRange(int $startTimestamp, int $endTimestamp): array
    {
        return array_values(array_filter(
            $this->getAllBlocks(),
            fn (ScheduleBlock $block) => $block->getStartTimestamp() >= $startTimestamp
                && $block->getStartTimestamp() <= $endTimestamp
        ));
    }

    /**
     * Blocks that overlap any moment inside [start, end].
     * @return ScheduleBlock[]
     */
    public function getBlocksOverlappingRange(int $startTimestamp, int $endTimestamp): array
    {
        return array_values(array_filter(
            $this->getAllBlocks(),
            function (ScheduleBlock $block) use ($startTimestamp, $endTimestamp): bool {
                return $block->getStartTimestamp() <= $endTimestamp
                    && $block->getEndTimestamp() > $startTimestamp;
            }
        ));
    }

    /**
     * Whether a duty block covers any part of a calendar day (local date Y-m-d).
     */
    public static function blockOverlapsDate(int $blockStart, int $blockEnd, string $dateYmd): bool
    {
        $dayStart = strtotime($dateYmd . ' 00:00:00');
        $dayEnd = strtotime($dateYmd . ' 23:59:59');
        return $blockStart <= $dayEnd && $blockEnd > $dayStart;
    }

    /**
     * Builds daily duty marks for calendar export: [userId][Y-m-d] => true
     */
    public static function buildDailyDutyMatrix(array $blocks, array $dayKeys, array $employeeIds): array
    {
        $matrix = [];
        foreach ($employeeIds as $employeeId) {
            $matrix[$employeeId] = [];
            foreach ($dayKeys as $dayKey) {
                $matrix[$employeeId][$dayKey] = false;
            }
        }

        foreach ($blocks as $block) {
            $userId = $block->getAssignedUserId();
            if (!isset($matrix[$userId])) {
                continue;
            }
            foreach ($dayKeys as $dayKey) {
                if (self::blockOverlapsDate(
                    $block->getStartTimestamp(),
                    $block->getEndTimestamp(),
                    $dayKey
                )) {
                    $matrix[$userId][$dayKey] = true;
                }
            }
        }

        return $matrix;
    }

    /**
     * German month names for export titles.
     */
    public static function getGermanMonthNames(): array
    {
        return [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
        ];
    }

    /**
     * Day-of-month column CSS class (weekend / holiday).
     */
    public static function getDayColumnClass(string $dateYmd): string
    {
        $ts = strtotime($dateYmd . ' 12:00:00');
        if (self::isNrwHoliday($ts)) {
            return 'day-holiday';
        }
        $dow = (int) date('N', $ts);
        if ($dow >= 6) {
            return 'day-weekend';
        }
        return '';
    }

    /**
     * Duty start (Bereitschaftswechsels) that starts the duty week containing $timestamp.
     * Based on the shift settings (ShiftService: duty start day/time).
     */
    public static function getDutyWeekStartForTimestamp(int $timestamp): int
    {
        return ShiftService::getDutyStartTimestamp($timestamp);
    }

    /**
     * Returns the schedule block active at a given unix timestamp, if any.
     */
    public function findActiveBlockAt(int $timestamp): ?ScheduleBlock
    {
        foreach ($this->getAllBlocks() as $block) {
            if ($timestamp >= $block->getStartTimestamp() && $timestamp < $block->getEndTimestamp()) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Resolves whether the timestamp is in Bereitschaft or Innendienst for a duty block.
     */
    public static function resolveCurrentPhase(int $startTs, int $endTs, int $now): string
    {
        if ($now < $startTs || $now >= $endTs) {
            return 'bereitschaft';
        }

        foreach (self::calculateIntervals($startTs, $endTs) as $interval) {
            if ($now >= $interval['start'] && $now < $interval['end']) {
                return $interval['type'];
            }
        }

        return 'bereitschaft';
    }

    /**
     * Returns the Unix timestamp of the next phase TYPE change (Innendienst ↔ Bereitschaft)
     * within the given duty block.
     */
    public static function getNextPhaseChange(int $startTs, int $endTs, int $now): ?int
    {
        if ($now < $startTs) {
            return null;
        }

        $intervals = self::calculateIntervals($startTs, $endTs);

        $currentIdx = null;
        foreach ($intervals as $idx => $interval) {
            if ($now >= $interval['start'] && $now <= $interval['end']) {
                $currentIdx = $idx;
                break;
            }
        }

        if ($currentIdx === null) {
            return $intervals[0]['start'] > $now ? $intervals[0]['start'] : $endTs;
        }

        $currentType = $intervals[$currentIdx]['type'];

        for ($i = $currentIdx + 1; $i < count($intervals); $i++) {
            if ($intervals[$i]['type'] !== $currentType) {
                return $intervals[$i]['start'];
            }
        }

        return $endTs;
    }

    /**
     * Reassigns blocks held by admins or unknown users to eligible employees.
     */
    public function sanitizeIneligibleAssignments(): bool
    {
        return JsonStorage::synchronized(function (): bool {
        $auth = new AuthManager();
        $eligible = $auth->getDutyEligibleUsers();
        if (empty($eligible)) {
            return true;
        }

        $blocks = $this->getAllBlocks();
        $changed = false;
        $assignIndex = 0;

        foreach ($blocks as $block) {
            if ($auth->isDutyEligibleUserId($block->getAssignedUserId())) {
                continue;
            }

            $replacement = $eligible[$assignIndex % count($eligible)];
            $assignIndex++;
            $block->setAssignedUserId($replacement->getId());
            $block->setOriginalUserId($replacement->getId());
            $block->setStatus('assigned');
            $changed = true;
        }

        return $changed ? $this->saveBlocks($blocks) : true;
        });
    }

    /**
     * Ensures at least one duty block covers the current moment (extends plan if needed).
     */
    public function ensureScheduleCoversNow(): void
    {
        $now = time();
        if ($this->findActiveBlockAt($now) !== null) {
            return;
        }

        $auth = new AuthManager();
        $userIds = array_map(
            function ($user) { return $user->getId(); },
            $auth->getDutyEligibleUsers()
        );
        if (empty($userIds)) {
            return;
        }

        $blocks = $this->getAllBlocks();
        $tz = new \DateTimeZone(Config::getTimezone());

        if (empty($blocks)) {
            $weekStart = self::getDutyWeekStartForTimestamp($now);
            $startDateStr = (new \DateTimeImmutable('@' . $weekStart))
                ->setTimezone($tz)
                ->format('Y-m-d');
            $this->generateWeeks($startDateStr, 8, $userIds);
            return;
        }

        $lastBlock = $blocks[count($blocks) - 1];
        if ($now >= $lastBlock->getEndTimestamp()) {
            $extendFrom = (new \DateTimeImmutable('@' . $lastBlock->getStartTimestamp()))
                ->setTimezone($tz)
                ->modify('+1 week')
                ->format('Y-m-d');
            $this->generateWeeks($extendFrom, 12, $userIds);
            return;
        }

        if ($now < $blocks[0]->getStartTimestamp()) {
            $weekStart = self::getDutyWeekStartForTimestamp($now);
            $startDateStr = (new \DateTimeImmutable('@' . $weekStart))
                ->setTimezone($tz)
                ->format('Y-m-d');
            $this->generateWeeks($startDateStr, 8, $userIds);
            return;
        }

        $weekStart = self::getDutyWeekStartForTimestamp($now);
        $startDateStr = (new \DateTimeImmutable('@' . $weekStart))
            ->setTimezone($tz)
            ->format('Y-m-d');
        $this->generateWeeks($startDateStr, 8, $userIds);
    }

    /**
     * Replaces all references to a deleted user in blocks (assigned + original).
     */
    public function removeUserAssignments(string $userId): bool
    {
        return JsonStorage::synchronized(function () use ($userId): bool {
        $blocks = $this->getAllBlocks();

        $referenced = false;
        foreach ($blocks as $block) {
            if ($block->getAssignedUserId() === $userId || $block->getOriginalUserId() === $userId) {
                $referenced = true;
                break;
            }
        }
        if (!$referenced) {
            return true;
        }

        $auth = new AuthManager();
        $eligible = $auth->getDutyEligibleUsers();
        // If the deleted user was the last duty-eligible employee, there is no
        // replacement. Clear the assignment instead of dereferencing [0] (which
        // was a fatal error) – sanitizeIneligibleAssignments() fills the gap
        // once a new employee exists.
        $replacementId = !empty($eligible) ? $eligible[0]->getId() : '';
        $changed = false;

        foreach ($blocks as $block) {
            $touched = false;
            if ($block->getAssignedUserId() === $userId) {
                $block->setAssignedUserId($replacementId);
                if ($replacementId !== '') {
                    $block->setOriginalUserId($replacementId);
                    $block->setStatus('assigned');
                }
                $touched = true;
            }
            if ($block->getOriginalUserId() === $userId) {
                if ($block->getAssignedUserId() !== $userId) {
                    $block->setOriginalUserId($block->getAssignedUserId());
                }
                $touched = true;
            }
            if ($touched) {
                $changed = true;
            }
        }

        return $changed ? $this->saveBlocks($blocks) : true;
        });
    }

    /**
     * Clears all schedule blocks (useful for reset/re-generating).
     */
    public function clearAllBlocks(): bool
    {
        return JsonStorage::synchronized(function (): bool {
        $tradeService = new TradeService();
        if (!$tradeService->clearAllTrades()) {
            return false;
        }
        return $this->saveBlocks([]);
        });
    }
}