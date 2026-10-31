<?php
declare(strict_types=1);
namespace Src\Service;

use Src\Core\Config;
use Src\Storage\JsonStorage;

/**
 * Einsatzzeiten / Bereitschaftseinstellungen der Einsatzgemeinschaft.
 *
 * Zentraler Einstieg für alle Schicht-Abhängigkeiten der Bereitschaftsplanung:
 * - Wann beginnt die nächste Bereitschaftsdienst-Periode (Bereitschaftswechsel)?
 * - Gibt es Innendienst-Zeiten (und an welchen Wochentagen, mit welchen Zeiten)?
 * - Wie lange gilt der Bereitschaftsdienst sonst (z. B. 24/7)?
 *
 * Werden über die Admin-Konsole gespeichert, landen sie als JSON unter
 * <dataDir>/settings.json (Key "shift"). Fehlt die Datei, gelten die
 * NRW-Standardwerte – damit ein frischer Stand exakt das bisherige
 * Verhalten abbildet (Bereitschaftswechsel Mittwoch 16:00, Innendienst
 * Mo–Do 08:00–16:00 / Fr 08:00–12:00).
 */
class ShiftService
{
    private const DEFAULTS = [
        'duty_start_day' => 3,        // 1=Mo .. 7=So (3=Mittwoch) – Wochentag des Bereitschaftswechsels
        'duty_start_time' => '16:00', // HH:MM – Beginn der Bereitschaft (Bereitschaftswechsel)
        'innendienst_enabled' => true, // Innendienst-Zeiten aktiv?
        'innendienst_office_days' => [1, 2, 3, 4, 5], // Mo–Fr als Innendienst-Tage
        'innendienst_start' => '08:00', // HH:MM – Beginn Innendienst an Office-Tagen
        'innendienst_end' => '16:00',   // HH:MM – Ende Innendienst (an Office-Tagen)
        'innendienst_friday_end' => '12:00', // HH:MM – Ende Innendienst am Freitag
    ];

    /** @var array|null Request cache */
    private static ?array $settings = null;

    public const DAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    private const DAY_LABELS = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
        6 => 'Samstag',
        7 => 'Sonntag',
    ];

    public static function clearCache(): void
    {
        self::$settings = null;
    }

    /**
     * @return array{duty_start_day:int,duty_start_time:string,innendienst_enabled:bool,innendienst_office_days:int[],innendienst_start:string,innendienst_end:string,innendienst_friday_end:string}
     */
    public static function getShiftSettings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $storage = new JsonStorage(Config::getShiftSettingsFilePath());
        $data = $storage->read();
        self::$settings = self::normalizeSettings((array)($data['shift'] ?? []));
        return self::$settings;
    }

    /**
     * @param array<string, mixed> $raw
     * @return bool true bei Erfolg
     */
    public static function saveShiftSettings(array $raw): bool
    {
        self::clearCache();
        $storage = new JsonStorage(Config::getShiftSettingsFilePath());
        return $storage->write(['shift' => self::normalizeSettings($raw)]);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{ok:bool,errors:string[],settings:array}
     */
    public static function validateShiftSettings(array $raw): array
    {
        $errors = [];
        $settings = self::DEFAULTS;

        if (array_key_exists('duty_start_day', $raw)) {
            $day = (int)($raw['duty_start_day'] ?? 0);
            if ($day < 1 || $day > 7) {
                $errors[] = 'Bereitschaftswechsel-Wochentag muss zwischen 1 (Montag) und 7 (Sonntag) liegen.';
            } else {
                $settings['duty_start_day'] = $day;
            }
        }

        if (array_key_exists('duty_start_time', $raw)) {
            $time = trim((string)($raw['duty_start_time'] ?? ''));
            if (!self::isValidTime($time)) {
                $errors[] = 'Bereitschaftswechsel-Zeit muss ein gueltiges HH:MM sein (z. B. 16:00).';
            } else {
                $settings['duty_start_time'] = $time;
            }
        }

        if (array_key_exists('innendienst_enabled', $raw)) {
            $settings['innendienst_enabled'] = (bool)$raw['innendienst_enabled'];
        }

        if (array_key_exists('innendienst_office_days', $raw) && is_array($raw['innendienst_office_days'])) {
            $days = [];
            foreach ($raw['innendienst_office_days'] as $d) {
                if (is_int($d)) {
                    $val = $d;
                } elseif (is_string($d) && ctype_digit($d)) {
                    $val = (int)$d;
                } else {
                    continue;
                }
                if ($val >= 1 && $val <= 7) {
                    $days[] = $val;
                }
            }
            if (empty($days)) {
                $errors[] = 'Mindestens ein Innendienst-Tag muss ausgewaehlt sein (oder Innendienst deaktivieren).';
            } else {
                $settings['innendienst_office_days'] = array_values(array_unique($days));
            }
        }

        if (array_key_exists('innendienst_start', $raw)) {
            $time = trim((string)($raw['innendienst_start'] ?? ''));
            if (!self::isValidTime($time)) {
                $errors[] = 'Innendienst-Startzeit muss ein gueltiges HH:MM sein.';
            } else {
                $settings['innendienst_start'] = $time;
            }
        }

        if (array_key_exists('innendienst_end', $raw)) {
            $time = trim((string)($raw['innendienst_end'] ?? ''));
            if (!self::isValidTime($time)) {
                $errors[] = 'Innendienst-Endzeit muss ein gueltiges HH:MM sein.';
            } else {
                $settings['innendienst_end'] = $time;
            }
        }

        if (array_key_exists('innendienst_friday_end', $raw)) {
            $time = trim((string)($raw['innendienst_friday_end'] ?? ''));
            if (!self::isValidTime($time)) {
                $errors[] = 'Freitags-Ende des Innendienstes muss ein gueltiges HH:MM sein.';
            } else {
                $settings['innendienst_friday_end'] = $time;
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors, 'settings' => $settings];
        }

        return ['ok' => true, 'errors' => [], 'settings' => $settings];
    }

    public static function isInnendienstEnabled(): bool
    {
        return (bool)self::getShiftSettings()['innendienst_enabled'];
    }

    /** @return int[] */
    public static function getInnendienstOfficeDays(): array
    {
        $days = self::getShiftSettings()['innendienst_office_days'];
        return array_values(array_map('intval', (array)$days));
    }

    public static function getInnendienstStart(): string
    {
        return (string)self::getShiftSettings()['innendienst_start'];
    }

    public static function getInnendienstEnd(): string
    {
        return (string)self::getShiftSettings()['innendienst_end'];
    }

    public static function getInnendienstFridayEnd(): string
    {
        return (string)self::getShiftSettings()['innendienst_friday_end'];
    }

    /**
     * Bereitschaftsende (Uhrzeit) = Bereitschaftswechsels - Innendienstdauer.
     * Bei deaktiviertem Innendienst entspricht es dem Bereitschaftswechsels.
     */
    public static function getBereitschaftEndTime(): string
    {
        if (!self::isInnendienstEnabled()) {
            return self::getDutyStartTime();
        }
        $innendienstSeconds = self::getInnendienstEndSeconds() - self::getInnendienstStartSeconds();
        $seconds = self::timeToSeconds(self::getDutyStartTime()) - $innendienstSeconds;
        $seconds = max(0, $seconds);
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv(($seconds % 3600), 60));
    }

    public static function getDutyStartDay(): int
    {
        return (int)self::getShiftSettings()['duty_start_day'];
    }

    public static function getDutyStartTime(): string
    {
        return (string)self::getShiftSettings()['duty_start_time'];
    }

    public static function getInnendienstStartSeconds(): int
    {
        return self::timeToSeconds(self::getInnendienstStart());
    }

    public static function getInnendienstEndSeconds(): int
    {
        return self::timeToSeconds(self::getInnendienstEnd());
    }

    private static function timeToSeconds(string $time): int
    {
        return ((int)substr($time, 0, 2)) * 3600 + ((int)substr($time, 3, 2)) * 60;
    }

    /**
     * Unix-Timestamp des Bereitschaftswechsels (Bereitschaftsbeginn), der zu
     * einem Zeitpunkt $ts gehoert: die staubeligste Wechsel-Uhrzeit <= $ts.
     */
    public static function getDutyStartTimestamp(int $ts): int
    {
        $day = self::getDutyStartDay();
        $time = self::getDutyStartTime();
        $candidate = self::dayTimeToTimestamp($ts, $day, $time);
        if ($candidate > $ts) {
            $candidate = self::shiftDays($candidate, -7);
        }
        return $candidate;
    }

    /**
     * Unix-Timestamp des naechsten Bereitschaftswechsels (Bereitschaftsbeginn)
     * auf oder nach $ts.
     */
    public static function getNextDutyStartTimestamp(int $ts): int
    {
        $day = self::getDutyStartDay();
        $time = self::getDutyStartTime();
        $candidate = self::dayTimeToTimestamp($ts, $day, $time);
        while ($candidate < $ts) {
            $candidate = self::shiftDays($candidate, 7);
        }
        return $candidate;
    }

    /**
     * Unix-Timestamp des naechsten Bereitschaftswechsels (Bereitschaftsbeginn)
     * auf oder nach $ts.
     *
     * (Oeffentlich, fuer den ScheduleService.)
     */
    public static function nextDutyStartAfter(int $ts): int
    {
        return self::getNextDutyStartTimestamp($ts);
    }

    /**
     * Ende einer Dienstzeitperiode (Bereitschaftswechsels + 7 Tage, wall clock).
     */
    public static function getDutyWeekEndTimestamp(int $startTs): int
    {
        $tz = new \DateTimeZone(Config::getTimezone());
        $dt = (new \DateTimeImmutable('@' . $startTs))->setTimezone($tz);
        $dt = $dt->modify('+7 days');
        return $dt->getTimestamp();
    }

    public static function getDayName(int $dayOfWeek): string
    {
        return self::DAY_LABELS[$dayOfWeek] ?? (string)$dayOfWeek;
    }

    public static function dayTimeToTimestamp(int $ts, int $dayOfWeek, string $time): int
    {
        $tz = new \DateTimeZone(Config::getTimezone());
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $dt = $dt->modify('next ' . self::DAY_NAMES[$dayOfWeek]);
        $dt = $dt->setTime((int)substr($time, 0, 2), (int)substr($time, 3, 2), 0);
        return $dt->getTimestamp();
    }

    public static function shiftDays(int $ts, int $days): int
    {
        $tz = new \DateTimeZone(Config::getTimezone());
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $dt = $dt->modify(($days >= 0 ? '+' : '-') . abs($days) . ' days');
        return $dt->getTimestamp();
    }

    public static function isValidTime(string $time): bool
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return false;
        }
        return true;
    }

    private static function normalizeSettings(array $raw): array
    {
        $settings = self::DEFAULTS;

        if (array_key_exists('duty_start_day', $raw)) {
            $day = (int)($raw['duty_start_day'] ?? 0);
            if ($day >= 1 && $day <= 7) {
                $settings['duty_start_day'] = $day;
            }
        }
        if (array_key_exists('duty_start_time', $raw)) {
            $time = trim((string)($raw['duty_start_time'] ?? ''));
            if (self::isValidTime($time)) {
                $settings['duty_start_time'] = $time;
            }
        }
        if (array_key_exists('innendienst_enabled', $raw)) {
            $settings['innendienst_enabled'] = (bool)$raw['innendienst_enabled'];
        }
        if (array_key_exists('innendienst_office_days', $raw) && is_array($raw['innendienst_office_days'])) {
            $days = [];
            foreach ($raw['innendienst_office_days'] as $d) {
                $val = is_int($d) ? $d : (is_string($d) && ctype_digit($d) ? (int)$d : 0);
                if ($val >= 1 && $val <= 7) {
                    $days[] = $val;
                }
            }
            if (!empty($days)) {
                $settings['innendienst_office_days'] = array_values(array_unique($days));
            }
        }
        if (array_key_exists('innendienst_start', $raw)) {
            $time = trim((string)($raw['innendienst_start'] ?? ''));
            if (self::isValidTime($time)) {
                $settings['innendienst_start'] = $time;
            }
        }
        if (array_key_exists('innendienst_end', $raw)) {
            $time = trim((string)($raw['innendienst_end'] ?? ''));
            if (self::isValidTime($time)) {
                $settings['innendienst_end'] = $time;
            }
        }
        if (array_key_exists('innendienst_friday_end', $raw)) {
            $time = trim((string)($raw['innendienst_friday_end'] ?? ''));
            if (self::isValidTime($time)) {
                $settings['innendienst_friday_end'] = $time;
            }
        }

        return $settings;
    }
}
