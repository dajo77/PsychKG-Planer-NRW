<?php
namespace Src\Service;

use Src\Auth\AuthManager;
use Src\Core\Config;
use Src\Storage\JsonStorage;

/**
 * Sealed monthly duty-plan snapshots ("Nachweise").
 * A snapshot freezes the blocks of one calendar month together with a
 * SHA-256 hash, so the historical plan can later be proven unaltered
 * (e.g. for legal inquiries: who was on duty when).
 *
 * Snapshot shape: ['id', 'month' => YYYY-MM, 'created_at', 'created_by',
 *   'created_by_name', 'blocks' => [...], 'hash']
 */
class SnapshotService
{
    private JsonStorage $storage;
    private ScheduleService $scheduleService;

    public function __construct()
    {
        $this->storage = new JsonStorage(Config::getSnapshotsFilePath());
        $this->scheduleService = new ScheduleService();
    }

    /** @return array<int, array> newest first */
    public function getAll(): array
    {
        $data = $this->storage->read();
        $list = $data['snapshots'] ?? [];
        usort($list, fn ($a, $b) => strcmp($b['month'] ?? '', $a['month'] ?? ''));
        return $list;
    }

    public function getForMonth(string $month): ?array
    {
        foreach ($this->getAll() as $snap) {
            if (($snap['month'] ?? '') === $month) {
                return $snap;
            }
        }
        return null;
    }

    public static function isValidMonth(?string $input): bool
    {
        if ($input === null || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $input, $m)) {
            return false;
        }
        // The old regex allowed year 0000, which yields negative timestamps
        // from strtotime() and a meaningless "Nachweis" for a year that never
        // existed. Constrain to a plausible range.
        $year = (int) $m[1];
        return $year >= 2000 && $year <= 2100;
    }

    /**
     * Seals a month. Returns null on success or a machine-readable
     * error key ('bad_month', 'exists', 'save_failed').
     */
    public function create(string $month, string $actorId, string $actorName): ?string
    {
        if (!self::isValidMonth($month)) {
            return 'bad_month';
        }
        if ($this->getForMonth($month) !== null) {
            return 'exists';
        }

        [$year, $mon] = array_map('intval', explode('-', $month));
        $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon)))->format('t');
        $periodStart = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $mon));
        $periodEnd = strtotime(sprintf('%04d-%02d-%02d 23:59:59', $year, $mon, $daysInMonth));

        $auth = new AuthManager();
        $names = [];
        foreach ($auth->getAllUsers() as $u) {
            $names[$u->getId()] = $u->getName();
        }

        $blocks = [];
        foreach ($this->scheduleService->getBlocksOverlappingRange($periodStart, $periodEnd) as $block) {
            $blocks[] = [
                'id' => $block->getId(),
                'start' => date('Y-m-d H:i', $block->getStartTimestamp()),
                'end' => date('Y-m-d H:i', $block->getEndTimestamp()),
                'start_ts' => $block->getStartTimestamp(),
                'end_ts' => $block->getEndTimestamp(),
                'user_id' => $block->getAssignedUserId(),
                'user_name' => $names[$block->getAssignedUserId()] ?? 'Unbekannt',
                'status' => $block->getStatus(),
                'hours' => round(ScheduleService::calculateActiveHours(
                    max($block->getStartTimestamp(), $periodStart),
                    min($block->getEndTimestamp(), $periodEnd)
                ), 2),
            ];
        }
        usort($blocks, fn ($a, $b) => $a['start_ts'] <=> $b['start_ts']);

        $snapshot = [
            'id' => 'snp_' . str_replace('-', '', $month) . '_' . bin2hex(random_bytes(8)),
            'month' => $month,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $actorId,
            'created_by_name' => $actorName,
            'blocks' => $blocks,
            'hash' => '', // filled in below, once the sealed fields are final
        ];

        // Hash covers the month, the blocks AND the provenance fields. The
        // previous payload only covered month+blocks, so created_at,
        // created_by and created_by_name could be edited afterwards without
        // invalidating the checksum — a problem for a record whose purpose is
        // to serve as evidence of who sealed what, and when.
        $snapshot['hash'] = self::computeHash($snapshot);

        return JsonStorage::synchronized(function () use ($snapshot, $month) {
            $list = $this->getAll();
            // Re-check under the lock: two concurrent seals for the same
            // month must not both succeed.
            foreach ($list as $existing) {
                if (($existing['month'] ?? '') === $month) {
                    return 'exists';
                }
            }
            $list[] = $snapshot;
            return $this->storage->write(['snapshots' => $list]) ? null : 'save_failed';
        });
    }

    /**
     * Canonical hash of a snapshot's sealed content.
     * Covers month, creation provenance and blocks.
     */
    private static function computeHash(array $snapshot): string
    {
        $payload = [
            'month'           => $snapshot['month'] ?? '',
            'created_at'      => $snapshot['created_at'] ?? '',
            'created_by'      => $snapshot['created_by'] ?? '',
            'created_by_name' => $snapshot['created_by_name'] ?? '',
            'blocks'          => $snapshot['blocks'] ?? [],
        ];
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Recomputes the hash of a stored snapshot. True = unaltered.
     *
     * Snapshots sealed before the hash was extended to cover the provenance
     * fields only cover month+blocks. Those legacy records are still valid
     * evidence and must not suddenly be reported as manipulated, so the
     * legacy payload is accepted as a fallback. New snapshots always use the
     * stronger payload (see computeHash()).
     */
    public function verify(array $snapshot): bool
    {
        $stored = (string) ($snapshot['hash'] ?? '');
        if ($stored === '') {
            return false;
        }

        if (hash_equals(self::computeHash($snapshot), $stored)) {
            return true;
        }

        // Legacy scheme: month + blocks only.
        $legacy = [
            'month'  => $snapshot['month'] ?? '',
            'blocks' => $snapshot['blocks'] ?? [],
        ];
        $legacyHash = hash('sha256', json_encode($legacy, JSON_UNESCAPED_UNICODE));

        return hash_equals($legacyHash, $stored);
    }
}
