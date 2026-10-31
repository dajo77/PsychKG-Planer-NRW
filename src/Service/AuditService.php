<?php
declare(strict_types=1);
namespace Src\Service;

use Src\Auth\AuthManager;
use Src\Core\Csv;
use Src\Core\Config;
use Src\Storage\JsonStorage;
use Src\Model\AuditEntry;

class AuditService
{
    private JsonStorage $storage;

    /** Hard cap for the audit log (oldest entries are trimmed). */
    public const LIMIT = 1000;

    public function __construct()
    {
        $this->storage = new JsonStorage(Config::getAuditFilePath());
    }

    /**
     * Logs an audit entry.
     */
    public function log(string $action, string $targetType, string $targetId, array $details = []): void
    {
        JsonStorage::synchronized(function () use ($action, $targetType, $targetId, $details) {
        $auth = new AuthManager();
        $user = $auth->getCurrentUser();

        $entry = new AuditEntry(
            'aud_' . bin2hex(random_bytes(16)),
            time(),
            $user?->getId() ?? 'system',
            $user?->getName() ?? 'System',
            $action,
            $targetType,
            $targetId,
            $details
        );

        $data = $this->storage->read();
        $entries = $data['entries'] ?? [];
        $entries[] = $entry->toArray();

        if (count($entries) > self::LIMIT) {
            $entries = array_slice($entries, -self::LIMIT);
        }

        $this->storage->write(['entries' => $entries]);
        });
    }

    /**
     * Returns the most recent audit entries.
     * @return AuditEntry[]
     */
    public function getRecent(int $limit = 100): array
    {
        $data = $this->storage->read();
        $entriesData = $data['entries'] ?? [];

        $entriesData = array_reverse($entriesData);
        $entriesData = array_slice($entriesData, 0, $limit);

        $entries = [];
        foreach ($entriesData as $eData) {
            $entries[] = AuditEntry::fromArray($eData);
        }
        return $entries;
    }

    /**
     * Returns audit entries for a specific user.
     * @return AuditEntry[]
     */
    public function getByUser(string $userId): array
    {
        $data = $this->storage->read();
        $entriesData = $data['entries'] ?? [];

        $filtered = array_filter($entriesData, fn(array $e) => ($e['user_id'] ?? '') === $userId);
        $filtered = array_reverse($filtered);

        $entries = [];
        foreach ($filtered as $eData) {
            $entries[] = AuditEntry::fromArray($eData);
        }
        return $entries;
    }

    /**
     * Returns audit entries within a timestamp range.
     * @return AuditEntry[]
     */
    public function getByDateRange(int $startTs, int $endTs): array
    {
        $data = $this->storage->read();
        $entriesData = $data['entries'] ?? [];

        $filtered = array_filter($entriesData, function(array $e) use ($startTs, $endTs) {
            $ts = (int) ($e['timestamp'] ?? 0);
            return $ts >= $startTs && $ts <= $endTs;
        });
        $filtered = array_reverse($filtered);

        $entries = [];
        foreach ($filtered as $eData) {
            $entries[] = AuditEntry::fromArray($eData);
        }
        return $entries;
    }

    /**
     * Exports audit entries as CSV string.
     */
    public function exportToCsv(array $entries): string
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, ['Zeitpunkt', 'Mitarbeiter', 'Aktion', 'Ziel-Typ', 'Ziel-ID', 'Details'], ';');

        foreach ($entries as $entry) {
            fputcsv($output, [
                date('d.m.Y H:i', $entry->getTimestamp()),
                Csv::cell($entry->getUserName()),
                Csv::cell($entry->getActionLabel()),
                $entry->getTargetType(),
                $entry->getTargetId(),
                Csv::cell($entry->getDetailsSummary()),
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }
}
