<?php
namespace Src\Service;

use Src\Core\Config;
use Src\Storage\JsonStorage;

/**
 * Manages employee absences (vacation, sick leave, other).
 * The rotation (ScheduleService::generateWeeks) skips absent employees.
 *
 * Entry shape: ['id', 'user_id', 'start' => Y-m-d, 'end' => Y-m-d,
 *               'reason' => urlaub|krank|sonstiges, 'created_at' => Y-m-d H:i:s]
 */
class AbsenceService
{
    public const REASONS = [
        'urlaub' => 'Urlaub',
        'krank' => 'Krank',
        'sonstiges' => 'Sonstiges',
    ];

    private JsonStorage $storage;

    /**
     * Request-scoped cache of the absence list.
     *
     * ScheduleService::generateWeeks() asks isAbsent() repeatedly (up to
     * userCount*2 times per week, over 26 weeks), and each call previously
     * re-opened, re-read, re-decoded and re-sorted absences.json. Caching the
     * list removes that I/O entirely; add()/delete() invalidate the cache.
     *
     * @var array<int, array>|null
     */
    private ?array $cache = null;

    public function __construct()
    {
        $this->storage = new JsonStorage(Config::getAbsencesFilePath());
    }

    /** @return array<int, array> */
    public function getAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $data = $this->storage->read();
        $list = $data['absences'] ?? [];
        usort($list, fn ($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));
        $this->cache = $list;
        return $list;
    }

    /** @return array<int, array> */
    public function getForUser(string $userId): array
    {
        return array_values(array_filter(
            $this->getAll(),
            fn (array $a) => ($a['user_id'] ?? '') === $userId
        ));
    }

    public static function isValidReason(string $reason): bool
    {
        return isset(self::REASONS[$reason]);
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASONS[$reason] ?? $reason;
    }

    /** Strict YYYY-MM-DD check, returns null when invalid. */
    public static function parseDate(?string $input): ?string
    {
        if ($input === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        return ($dt !== false && $dt->format('Y-m-d') === $input) ? $input : null;
    }

    /**
     * Adds an absence. Returns null on success or a machine-readable
     * error key ('need_user', 'bad_date', 'bad_range', 'bad_reason', 'save_failed').
     */
    public function add(string $userId, string $start, string $end, string $reason): ?string
    {
        if ($userId === '') {
            return 'need_user';
        }
        if (self::parseDate($start) === null || self::parseDate($end) === null) {
            return 'bad_date';
        }
        if ($start > $end) {
            return 'bad_range';
        }
        if (!self::isValidReason($reason)) {
            return 'bad_reason';
        }

        // Read-modify-write must be atomic: the read happens inside the same
        // lock as the write, otherwise parallel adds can be lost.
        $result = JsonStorage::synchronized(function (): ?string {
            $list = $this->getAll();
            $list[] = [
                'id' => 'abs_' . bin2hex(random_bytes(16)),
                'user_id' => $userId,
                'start' => $start,
                'end' => $end,
                'reason' => $reason,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $ok = $this->storage->write(['absences' => $list]);
            $this->cache = null;
            return $ok ? null : 'save_failed';
        });

        return $result;
    }

    public function delete(string $absenceId): bool
    {
        // Same atomicity requirement for read-modify-write.
        $result = JsonStorage::synchronized(function () use ($absenceId): bool {
            $list = $this->getAll();
            $filtered = array_values(array_filter(
                $list,
                fn (array $a) => ($a['id'] ?? '') !== $absenceId
            ));
            if (count($filtered) === count($list)) {
                return false;
            }
            $ok = $this->storage->write(['absences' => $filtered]);
            $this->cache = null;
            return $ok;
        });

        return $result;
    }

    /**
     * Whether the user is absent at any moment inside [startTs, endTs].
     * Absence days count whole calendar days (start 00:00 – end 23:59).
     */
    public function isAbsent(string $userId, int $startTs, int $endTs): bool
    {
        foreach ($this->getForUser($userId) as $absence) {
            $absStart = strtotime(($absence['start'] ?? '') . ' 00:00:00');
            $absEnd = strtotime(($absence['end'] ?? '') . ' 23:59:59');
            if ($absStart === false || $absEnd === false) {
                continue;
            }
            if ($absStart <= $endTs && $absEnd >= $startTs) {
                return true;
            }
        }
        return false;
    }
}
