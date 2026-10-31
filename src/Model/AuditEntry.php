<?php
namespace Src\Model;

class AuditEntry
{
    private string $id;
    private int $timestamp;
    private string $userId;
    private string $userName;
    private string $action;
    private string $targetType;
    private string $targetId;
    private array $details;

    public const USER_CREATED = 'user_created';
    public const USER_DELETED = 'user_deleted';
    public const USER_UPDATED = 'user_updated';
    public const PASSWORD_CHANGED = 'password_changed';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGIN_SUCCESS = 'login_success';
    public const BLOCK_REASSIGNED = 'block_reassigned';
    public const TRADE_OFFERED = 'trade_offered';
    public const TRADE_ACCEPTED = 'trade_accepted';
    public const TRADE_APPROVED = 'trade_approved';
    public const TRADE_REJECTED = 'trade_rejected';
    public const TRADE_CANCELLED = 'trade_cancelled';
    public const SCHEDULE_RESET = 'schedule_reset';
    public const SCHEDULE_GENERATED = 'schedule_generated';
    public const SCHEDULE_REPAIRED = 'schedule_repaired';
    public const ABSENCE_ADDED = 'absence_added';
    public const ABSENCE_DELETED = 'absence_deleted';
    public const SNAPSHOT_CREATED = 'snapshot_created';

    private const ACTION_LABELS = [
        self::USER_CREATED => 'Benutzer angelegt',
        self::USER_DELETED => 'Benutzer gelöscht',
        self::USER_UPDATED => 'Benutzer geändert',
        self::PASSWORD_CHANGED => 'Passwort geändert',
        self::LOGIN_FAILED => 'Login fehlgeschlagen',
        self::LOGIN_SUCCESS => 'Login erfolgreich',
        self::BLOCK_REASSIGNED => 'Block neu zugeordnet',
        self::TRADE_OFFERED => 'Tausch angeboten',
        self::TRADE_ACCEPTED => 'Tausch angenommen',
        self::TRADE_APPROVED => 'Tausch genehmigt',
        self::TRADE_REJECTED => 'Tausch abgelehnt',
        self::TRADE_CANCELLED => 'Tausch storniert',
        self::SCHEDULE_RESET => 'Dienstplan zurückgesetzt',
        self::SCHEDULE_GENERATED => 'Dienstplan generiert',
        self::SCHEDULE_REPAIRED => 'Dienstplan auf lückenlose Abdeckung gebracht',
        self::ABSENCE_ADDED => 'Abwesenheit eingetragen',
        self::ABSENCE_DELETED => 'Abwesenheit gelöscht',
        self::SNAPSHOT_CREATED => 'Monat versiegelt',
    ];

    public function __construct(
        string $id,
        int $timestamp,
        string $userId,
        string $userName,
        string $action,
        string $targetType,
        string $targetId,
        array $details = []
    ) {
        $this->id = $id;
        $this->timestamp = $timestamp;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->details = $details;
    }

    public function getId(): string { return $this->id; }
    public function getTimestamp(): int { return $this->timestamp; }
    public function getUserId(): string { return $this->userId; }
    public function getUserName(): string { return $this->userName; }
    public function getAction(): string { return $this->action; }
    public function getTargetType(): string { return $this->targetType; }
    public function getTargetId(): string { return $this->targetId; }
    public function getDetails(): array { return $this->details; }

    public function getActionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function getDetailsSummary(): string
    {
        $parts = [];
        foreach ($this->details as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = $key . ': ' . $value;
        }
        return $parts ? implode(', ', $parts) : '—';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'action' => $this->action,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'details' => $this->details,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (int) ($data['timestamp'] ?? 0),
            (string) ($data['user_id'] ?? ''),
            (string) ($data['user_name'] ?? ''),
            (string) ($data['action'] ?? ''),
            (string) ($data['target_type'] ?? ''),
            (string) ($data['target_id'] ?? ''),
            (array) ($data['details'] ?? [])
        );
    }
}
