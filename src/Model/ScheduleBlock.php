<?php
declare(strict_types=1);
namespace Src\Model;

use Src\Core\Config;

class ScheduleBlock
{
    private string $id;
    private int $startTimestamp;
    private int $endTimestamp;
    private string $assignedUserId;
    private string $originalUserId;
    private string $status; // 'assigned', 'trade_open', 'trade_pending'

    public function __construct(
        string $id,
        int $startTimestamp,
        int $endTimestamp,
        string $assignedUserId,
        string $originalUserId,
        string $status = 'assigned'
    ) {
        $this->id = $id;
        $this->startTimestamp = $startTimestamp;
        $this->endTimestamp = $endTimestamp;
        $this->assignedUserId = $assignedUserId;
        $this->originalUserId = $originalUserId;
        $this->status = $status;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStartTimestamp(): int
    {
        return $this->startTimestamp;
    }

    public function getEndTimestamp(): int
    {
        return $this->endTimestamp;
    }

    public function getAssignedUserId(): string
    {
        return $this->assignedUserId;
    }

    public function setAssignedUserId(string $userId): void
    {
        $this->assignedUserId = $userId;
    }

    public function setOriginalUserId(string $userId): void
    {
        $this->originalUserId = $userId;
    }

    public function getOriginalUserId(): string
    {
        return $this->originalUserId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getFormattedStart(): string
    {
        return self::formatTimestamp($this->startTimestamp);
    }

    /**
     * Angezeigtes Dienstende.
     *
     * Bewusst aus dem Duty-Start (Bereitschaftswechsels) als Wall-Clock-Zeit
     * berechnet (DutyStart + 7 Tage = naechster Wechsel; minus InnendienstDauer
     * = Bereitschaftsende) und NICHT aus $this->endTimestamp.
     * Gründe:
     *
     *  - Der gespeicherte Block ist ein 168h-Container (end_timestamp = DutyStart);
     *    die Bereitschaft endet am Ende des Innendienstes, danach folgt der
     *    Bereitschaftsdienst des naechsten Wechsels. Eine Ausgabe des Wechsel-
     *    zeitpunkts wären fachlich falsch.
     *  - Über die DST-Umstellung hinweg sind 160 reale Stunden wall-clock
     *    gesehen 161 Stunden. end_timestamp kann dadurch um 1 h zu früh sein
     *    (z. B. 07:00 statt 08:00). Die Wall-Clock-Rechnung liefert immer 08:00.
     *
     * Ausnahme: Fällt der abschließende Duty-Start auf einen NRW-Feiertag, gibt
     * es keinen Innendienst – die Bereitschaft läuft dann bis zum Wechselzeitpunkt.
     * In dem Fall wird das echte Container-Ende angezeigt.
     *
     * Für alle internen Berechnungen (Überlappungen, Intervalle, isPast,
     * findActiveBlockAt) bleibt $this->endTimestamp maßgeblich – nur die
     * Anzeige geht über diese Methode.
     */
    public function getFormattedEnd(): string
    {
        $tz = new \DateTimeZone(Config::getTimezone());
        $handoverEnd = \Src\Service\ScheduleService::weekEndTimestamp($this->startTimestamp);
        $innendienstSeconds = \Src\Service\ShiftService::getInnendienstEndSeconds() - \Src\Service\ShiftService::getInnendienstStartSeconds();
        $innHours = intdiv($innendienstSeconds, 3600);
        $innMinutes = intdiv($innendienstSeconds % 3600, 60);
        $nominalEnd = (new \DateTimeImmutable('@' . $handoverEnd))->setTimezone($tz);
        $nominalEnd = $nominalEnd->modify('-' . $innHours . ' hours');
        if ($innMinutes > 0) {
            $nominalEnd = $nominalEnd->modify('-' . $innMinutes . ' minutes');
        }

        // Holiday closing duty start: no Innendienst, on-call runs to the handover time.
        if ($this->endTimestamp > $nominalEnd->getTimestamp()
            && \Src\Service\ScheduleService::isNrwHoliday($this->endTimestamp - 1)) {
            return (new \DateTimeImmutable('@' . $this->endTimestamp))
                ->setTimezone($tz)
                ->format('d.m.Y H:i');
        }

        return $nominalEnd->format('d.m.Y H:i');
    }

    public static function formatTimestamp(int $timestamp): string
    {
        $dt = new \DateTimeImmutable('@' . $timestamp);
        return $dt->setTimezone(new \DateTimeZone(Config::getTimezone()))->format('d.m.Y H:i');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'start_timestamp' => $this->startTimestamp,
            'end_timestamp' => $this->endTimestamp,
            'assigned_user_id' => $this->assignedUserId,
            'original_user_id' => $this->originalUserId,
            'status' => $this->status
        ];
    }

    public static function fromArray(array $data): self
    {
        $assignedId = (string) ($data['assigned_user_id'] ?? '');

        return new self(
            (string) ($data['id'] ?? ''),
            (int) ($data['start_timestamp'] ?? 0),
            (int) ($data['end_timestamp'] ?? 0),
            $assignedId,
            (string) ($data['original_user_id'] ?? $assignedId),
            (string) ($data['status'] ?? 'assigned')
        );
    }
}