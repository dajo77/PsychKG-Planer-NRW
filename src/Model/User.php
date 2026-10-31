<?php
declare(strict_types=1);
namespace Src\Model;

class User
{
    private string $id;
    private string $username;
    private string $passwordHash;
    private string $name;
    private string $role; // 'admin' | 'mitarbeiter'
    private string $phone; // dienstliche Erreichbarkeit (optional)
    private string $email; // optional, z.B. für künftige Benachrichtigungen

    public function __construct(string $id, string $username, string $passwordHash, string $name, string $role, string $phone = '', string $email = '')
    {
        $this->id = $id;
        $this->username = $username;
        $this->passwordHash = $passwordHash;
        $this->name = $name;
        $this->role = $role;
        $this->phone = $phone;
        $this->email = $email;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    private function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isAdmin(): bool
    {
        return self::normalizeRole($this->role) === 'admin';
    }

    /**
     * Normalizes role strings from storage (legacy values included).
     */
    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        $adminRoles = ['admin', 'administrator', 'dienststellenleitung', 'dienststellenleiter'];
        return in_array($role, $adminRoles, true) ? 'admin' : 'mitarbeiter';
    }

    public function isDutyEligible(): bool
    {
        return !$this->isAdmin();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'password' => $this->passwordHash,
            'name' => $this->name,
            'role' => $this->role,
            'phone' => $this->phone,
            'email' => $this->email,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['username'] ?? ''),
            (string) ($data['password'] ?? ''),
            (string) ($data['name'] ?? ''),
            self::normalizeRole((string) ($data['role'] ?? 'mitarbeiter')),
            (string) ($data['phone'] ?? ''),
            (string) ($data['email'] ?? '')
        );
    }
}
