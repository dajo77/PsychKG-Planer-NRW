<?php
declare(strict_types=1);
namespace Src\Auth;

use Src\Core\Config;
use Src\Storage\JsonStorage;
use Src\Model\User;

class AuthManager
{
    private JsonStorage $storage;

    /** Session inactivity timeout in seconds (30 minutes). */
    private const SESSION_LIFETIME = 1800;

    public function __construct()
    {
        $this->storage = new JsonStorage(Config::getUsersFilePath());
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Always evaluate the idle timeout, even when the entry point started
        // the session itself (admin.php did that before).
        $this->checkSessionExpiry();
        $this->refreshSessionActivity();
    }

    /**
     * Checks if the session has expired due to inactivity and destroys it.
     */
    private function checkSessionExpiry(): void
    {
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        if (is_int($lastActivity) && $lastActivity > 0
            && (time() - $lastActivity) > self::SESSION_LIFETIME
        ) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
                session_start();
                // New id for the fresh (empty) session: no fixation window.
                session_regenerate_id(true);
            }
        }
    }

    /**
     * Refreshes the last-activity timestamp.
     */
    private function refreshSessionActivity(): void
    {
        $_SESSION['last_activity'] = time();
    }

    /**
     * Rotates the session id after a credential change.
     *
     * The old token must die, but the user should stay logged in: destroying
     * the whole session made the following redirect land on the login page and
     * discarded the "Passwort geändert" confirmation. Regenerating the id
     * invalidates the old token while keeping the authenticated state; the
     * CSRF token is re-issued because it is bound to the old session.
     */
    private function rotateSessionAfterCredentialChange(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['last_activity'] = time();
    }

    /**
     * Timing-equalising dummy hash: unknown usernames must not be detectable
     * via response time (no password_verify() against a missing user).
     */
    private const DUMMY_HASH = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

    /**
     * Attempts to log in a user with username and password.
     */
    public function login(string $username, string $password): bool
    {
        $data = $this->storage->read();
        $usersData = $data['users'] ?? [];

        foreach ($usersData as $userArr) {
            if (strcasecmp($userArr['username'], $username) === 0) {
                if (password_verify($password, $userArr['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userArr['id'];
                    $_SESSION['user_name'] = $userArr['name'];
                    $_SESSION['user_role'] = User::normalizeRole($userArr['role'] ?? 'mitarbeiter');
                    return true;
                }
            }
        }

        // Unknown username: run a dummy hash so the timing does not reveal
        // whether the account exists (username enumeration via timing).
        password_verify('invalidsessiondummyvalue', self::DUMMY_HASH);

        return false;
    }

    /**
     * Logs out the current user by destroying the session.
     */
    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Gets the currently logged-in user, if any.
     */
    public function getCurrentUser(): ?User
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $userId = $_SESSION['user_id'];
        $data = $this->storage->read();
        $usersData = $data['users'] ?? [];

        foreach ($usersData as $userArr) {
            if ($userArr['id'] === $userId) {
                return User::fromArray($userArr);
            }
        }

        return null;
    }

    /**
     * Checks if a user is logged in.
     */
    public function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * Checks if the logged in user is an admin.
     */
    public function isAdmin(): bool
    {
        $user = $this->getCurrentUser();
        return $user !== null && $user->isAdmin();
    }

    /**
     * Enforces login and redirects to login page if unauthorized.
     */
    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Returns the logged-in user or ends the session if the account no longer exists.
     */
    public function getRequiredUser(): User
    {
        $this->requireLogin();
        $user = $this->getCurrentUser();
        if ($user === null) {
            $this->logout();
            header('Location: login.php?msg=session_invalid');
            exit;
        }
        return $user;
    }

    /**
     * Users eligible for on-call duty (role mitarbeiter only).
     * @return User[]
     */
    public function getDutyEligibleUsers(): array
    {
        return array_values(array_filter($this->getAllUsers(), fn (User $u) => !$u->isAdmin()));
    }

    public function isDutyEligibleUserId(string $userId): bool
    {
        foreach ($this->getDutyEligibleUsers() as $user) {
            if ($user->getId() === $userId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enforces admin privileges.
     */
    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: dashboard.php?error=unauthorized');
            exit;
        }
    }

    /**
     * Number of users with the admin role.
     * Policy: exactly one administrator exists (single-admin setup).
     */
    public function countAdmins(): int
    {
        $count = 0;
        foreach ($this->getAllUsers() as $user) {
            if ($user->isAdmin()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Helper to retrieve all users in the system (useful for drop downs, etc.).
     * @return User[]
     */
    public function getAllUsers(): array
    {
        $data = $this->storage->read();
        $usersData = $data['users'] ?? [];
        $users = [];
        foreach ($usersData as $userArr) {
            $users[] = User::fromArray($userArr);
        }
        return $users;
    }

    /**
     * Adds a new user to the system.
     * Enforces the single-admin policy: a second administrator is rejected.
     *
     * The whole read-modify-write runs under the cross-request file lock, so
     * two concurrent admin actions cannot overwrite each other's user list.
     */
    public function addUser(string $username, string $password, string $name, string $role, string $phone = '', string $email = ''): bool
    {
        return JsonStorage::synchronized(function () use ($username, $password, $name, $role, $phone, $email) {
            $data = $this->storage->read();
            $usersData = $data['users'] ?? [];

            // Check for duplicate username (case-insensitive)
            foreach ($usersData as $existing) {
                if (strcasecmp($existing['username'] ?? '', $username) === 0) {
                    return false;
                }
            }

            if (User::normalizeRole($role) === 'admin') {
                foreach ($usersData as $existing) {
                    if (User::normalizeRole($existing['role'] ?? 'mitarbeiter') === 'admin') {
                        return false; // exactly one admin allowed
                    }
                }
            }

            $newUser = [
                'id' => 'usr_' . bin2hex(random_bytes(16)),
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $name,
                'role' => User::normalizeRole($role),
                'phone' => $phone,
                'email' => $email,
            ];

            $usersData[] = $newUser;
            return $this->storage->write(['users' => $usersData]);
        });
    }

    /**
     * Updates an existing user. If $newPassword is null, the password remains unchanged.
     * Enforces the single-admin policy: the resulting admin count must stay exactly one
     * (no demoting the administrator, no promoting a second one).
     */
    public function updateUser(string $userId, string $name, string $username, string $role, ?string $newPassword = null, string $phone = '', string $email = '', bool $updateContact = false): bool
    {
        return JsonStorage::synchronized(function () use ($userId, $name, $username, $role, $newPassword, $phone, $email, $updateContact) {
        $data = $this->storage->read();
        $usersData = $data['users'] ?? [];

        $targetIndex = null;
        foreach ($usersData as $i => $userArr) {
            if ($userArr['id'] === $userId) {
                $targetIndex = $i;
                break;
            }
        }
        if ($targetIndex === null) {
            return false;
        }

        // Check for duplicate username (case-insensitive), excluding self
        foreach ($usersData as $other) {
            if ($other['id'] !== $userId && strcasecmp($other['username'] ?? '', $username) === 0) {
                return false;
            }
        }

        // Single-admin policy: simulate the change and require exactly one admin.
        $resultingAdmins = 0;
        foreach ($usersData as $i => $userArr) {
            $r = ($i === $targetIndex) ? $role : ($userArr['role'] ?? 'mitarbeiter');
            if (User::normalizeRole($r) === 'admin') {
                $resultingAdmins++;
            }
        }
        if ($resultingAdmins !== 1) {
            return false;
        }

        $usersData[$targetIndex]['name'] = $name;
        $usersData[$targetIndex]['username'] = $username;
        $usersData[$targetIndex]['role'] = User::normalizeRole($role);
        if ($updateContact) {
            $usersData[$targetIndex]['phone'] = $phone;
            $usersData[$targetIndex]['email'] = $email;
        }

        if ($newPassword !== null && $newPassword !== '') {
            $usersData[$targetIndex]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        return $this->storage->write(['users' => $usersData]);
        });
    }

    /**
     * Validates password strength: min 8 chars, 1 uppercase, 1 digit.
     * @return string|null Error message or null if valid.
     */
    public static function validatePasswordStrength(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if (!preg_match('/[A-ZÄÖÜ]/', $password)) {
            return 'Das Passwort muss mindestens einen Großbuchstaben enthalten.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Das Passwort muss mindestens eine Ziffer enthalten.';
        }
        return null;
    }

    /**
     * Changes a user's password after verifying the current password.
     */
    public function changePassword(string $userId, string $currentPassword, string $newPassword): bool
    {
        $strengthError = self::validatePasswordStrength($newPassword);
        if ($strengthError !== null) {
            return false;
        }

        return JsonStorage::synchronized(function () use ($userId, $currentPassword, $newPassword) {
        $data = $this->storage->read();
        $usersData = $data['users'] ?? [];

        $newHash = null;
        $found = false;
        foreach ($usersData as $i => $userArr) {
            if ($userArr['id'] === $userId) {
                if (!password_verify($currentPassword, $userArr['password'] ?? '')) {
                    return false;
                }
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $usersData[$i]['password'] = $newHash;
                $found = true;
                break;
            }
        }
        if (!$found || $newHash === null) {
            return false;
        }

        if (!$this->storage->write(['users' => $usersData])) {
            return false;
        }

        // The old session token must not survive the password change, but the
        // login itself must (otherwise the success message is lost and the
        // user is bounced to the login page).
        $this->rotateSessionAfterCredentialChange();

        return true;
        });
    }

    /**
     * Deletes a user by ID. Prevents deleting the currently logged-in user
     * and the administrator (single-admin policy) for safety.
     */
    public function deleteUser(string $userId): bool
    {
        // Prevent self-deletion
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userId) {
            return false;
        }

        return JsonStorage::synchronized(function () use ($userId) {
        $data = $this->storage->read();
        $usersData = $data['users'] ?? [];

        foreach ($usersData as $userArr) {
            if ($userArr['id'] === $userId
                && User::normalizeRole($userArr['role'] ?? 'mitarbeiter') === 'admin'
            ) {
                return false; // the single administrator must stay
            }
        }

        $filtered = array_values(array_filter($usersData, function ($userArr) use ($userId) {
            return ($userArr['id'] ?? '') !== $userId;
        }));

        // If nothing was removed, the user didn't exist
        if (count($filtered) === count($usersData)) {
            return false;
        }

        return $this->storage->write(['users' => $filtered]);
        });
    }
}
