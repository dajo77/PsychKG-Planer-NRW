<?php
declare(strict_types=1);
namespace Src\Core;

use Src\Model\User;
use Src\Storage\JsonStorage;
use Src\Service\ScheduleService;

class Seeder
{
    /**
     * Generates a secure random password.
     */
    private static function generatePassword(): string
    {
        $length = 12;
        $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $pw = '';
        // Ensure at least 1 uppercase and 1 digit
        $pw .= $upper[random_int(0, strlen($upper) - 1)];
        $pw .= $digits[random_int(0, strlen($digits) - 1)];
        for ($i = 2; $i < $length; $i++) {
            $pw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        // Shuffle to avoid predictable positions
        return str_shuffle($pw);
    }

    /**
     * Seeds the database with random passwords and returns them for display.
     * @return array{username: string, password: string, role: string}[]
     */
    public static function seedWithRandomPasswords(): array
    {
        return JsonStorage::synchronized(function (): array {
        $usersFile = Config::getUsersFilePath();
        $usersStorage = new JsonStorage($usersFile);
        $usersData = $usersStorage->read();

        $passwords = [];
        if (empty($usersData) || empty($usersData['users'])) {
            $entries = [
                ['id' => 'usr_admin', 'username' => 'admin', 'name' => 'Alexander Admin (Dienststellenleitung)', 'role' => 'admin'],
                ['id' => 'usr_schmidt', 'username' => 'm.schmidt', 'name' => 'Max Schmidt', 'role' => 'mitarbeiter'],
                ['id' => 'usr_mueller', 'username' => 's.mueller', 'name' => 'Sabine Müller', 'role' => 'mitarbeiter'],
                ['id' => 'usr_dohne', 'username' => 'j.dohne', 'name' => 'Jürgen Dohne', 'role' => 'mitarbeiter'],
            ];
            $users = [];
            foreach ($entries as $entry) {
                $pw = self::generatePassword();
                $users[] = [
                    'id' => $entry['id'],
                    'username' => $entry['username'],
                    'password' => password_hash($pw, PASSWORD_DEFAULT),
                    'name' => $entry['name'],
                    'role' => $entry['role'],
                ];
                $passwords[] = [
                    'username' => $entry['username'],
                    'password' => $pw,
                    'role' => $entry['role'],
                ];
            }
            if (!$usersStorage->write(['users' => $users])) {
                throw new \RuntimeException('Konnte users.json nicht schreiben (Schreibrechte prüfen).');
            }
        }

        // Initialize trades file
        $tradesFile = Config::getTradesFilePath();
        if (!file_exists($tradesFile)) {
            $tradesStorage = new JsonStorage($tradesFile);
            if (!$tradesStorage->write(['trades' => []])) {
                throw new \RuntimeException('Konnte trades.json nicht anlegen (Schreibrechte prüfen).');
            }
        }

        // Initialize schedule
        self::seedSchedule();

        return $passwords;
        });
    }

    /**
     * Seeds schedule data if empty (called by seedWithRandomPasswords and standalone).
     */
    private static function seedSchedule(): void
    {
        $scheduleFile = Config::getScheduleFilePath();
        $scheduleStorage = new JsonStorage($scheduleFile);
        $scheduleData = $scheduleStorage->read();

        if (empty($scheduleData) || empty($scheduleData['blocks'])) {
            $scheduleService = new ScheduleService();
            $tz = new \DateTimeZone(Config::getTimezone());
            $now = new \DateTime('now', $tz);
            $weekStartTs = ScheduleService::getDutyWeekStartForTimestamp($now->getTimestamp());
            $startDateStr = (new \DateTimeImmutable('@' . $weekStartTs))
                ->setTimezone($tz)
                ->format('Y-m-d');

            $userIds = ['usr_schmidt', 'usr_mueller', 'usr_dohne'];
            $scheduleService->generateWeeks($startDateStr, 8, $userIds);
        }

        $scheduleService = new ScheduleService();
        $scheduleService->sanitizeIneligibleAssignments();
        $scheduleService->ensureScheduleCoversNow();
    }

    public static function seed(): void
    {
        self::seedWithRandomPasswords();
    }
}
