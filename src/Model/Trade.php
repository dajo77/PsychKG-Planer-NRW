<?php
namespace Src\Model;

class Trade
{
    private string $id;
    private string $blockId;
    private string $requesterId;
    private ?string $receiverId;
    private string $status; // 'open', 'pending_approval', 'approved', 'rejected'
    private string $createdAt;
    private ?string $acceptedAt;
    private ?string $resolvedAt;

    public function __construct(
        string $id,
        string $blockId,
        string $requesterId,
        ?string $receiverId,
        string $status,
        string $createdAt,
        ?string $acceptedAt = null,
        ?string $resolvedAt = null
    ) {
        $this->id = $id;
        $this->blockId = $blockId;
        $this->requesterId = $requesterId;
        $this->receiverId = $receiverId;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->acceptedAt = $acceptedAt;
        $this->resolvedAt = $resolvedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBlockId(): string
    {
        return $this->blockId;
    }

    public function getRequesterId(): string
    {
        return $this->requesterId;
    }

    public function getReceiverId(): ?string
    {
        return $this->receiverId;
    }

    public function setReceiverId(?string $receiverId): void
    {
        $this->receiverId = $receiverId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?string
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?string $acceptedAt): void
    {
        $this->acceptedAt = $acceptedAt;
    }

    public function getResolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?string $resolvedAt): void
    {
        $this->resolvedAt = $resolvedAt;
    }

    public function isApprover(string $userId): bool
    {
        return $this->receiverId === $userId;
    }

    public function isOwner(string $userId): bool
    {
        return $this->requesterId === $userId;
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function __toString(): string
    {
        $receiver = $this->receiverId ?? 'open';
        return "Trade#{$this->id} [{$this->status}] {$this->requesterId} -> {$receiver}";
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'block_id' => $this->blockId,
            'requester_id' => $this->requesterId,
            'receiver_id' => $this->receiverId,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'accepted_at' => $this->acceptedAt,
            'resolved_at' => $this->resolvedAt
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['block_id'] ?? ''),
            (string) ($data['requester_id'] ?? ''),
            isset($data['receiver_id']) ? (string) $data['receiver_id'] : null,
            (string) ($data['status'] ?? 'open'),
            (string) ($data['created_at'] ?? date('Y-m-d H:i:s')),
            isset($data['accepted_at']) ? (string) $data['accepted_at'] : null,
            isset($data['resolved_at']) ? (string) $data['resolved_at'] : null
        );
    }
}
