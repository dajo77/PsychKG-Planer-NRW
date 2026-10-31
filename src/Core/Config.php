<?php
declare(strict_types=1);
namespace Src\Core;

class Config
{
    /** Set to true only in local/demo environments (shows test login hints). */
    public const SHOW_DEMO_CREDENTIALS = false;

    /**
     * Whether the app assumes it is served over TLS.
     *
     * The session cookie's Secure flag is derived from this instead of from
     * X-Forwarded-Proto: that header is client-settable unless the proxy is
     * known to overwrite it, so trusting it makes cookie hardening depend on
     * the proxy configuration.
     */
    public const ASSUME_HTTPS = true;

    /** Dark-mode default used for the initial server-rendered aria-pressed. */
    public static function isDarkModeDefault(): bool
    {
        return false;
    }

    /**
     * Hotline displayed on the dashboard while on-call duty is active.
     * Kept here (not inline in the markup) so it has exactly one place to edit.
     */
    public const DUTY_HOTLINE = '0176 13 80 21 27';

    /**
     * Whether install.php may run.
     *
     * The installer creates accounts without authentication. It must therefore
     * be opt-in: set ['allow_install' => true] in config.local.php on a local
     * development machine. Production has no such flag, so the endpoint stays
     * inert even if the file is ever deployed again.
     */
    public static function isInstallerEnabled(): bool
    {
        $localConfig = self::getBaseDir() . DIRECTORY_SEPARATOR . 'config.local.php';
        if (!is_file($localConfig)) {
            return false;
        }
        $cfg = include $localConfig;
        return is_array($cfg) && ($cfg['allow_install'] ?? false) === true;
    }

    public static function getBaseDir(): string
    {
        return dirname(dirname(__DIR__));
    }

    public static function getTimezone(): string
    {
        return 'Europe/Berlin';
    }

    /**
     * Directory holding the JSON data files.
     *
     * Resolution order (first hit wins):
     *  1. `config.local.php` in the project root returning `['data_dir' => '...']`
     *     (server setup: points OUTSIDE the document root, e.g. `~/private/psychkg-data`,
     *     so data files are never reachable via HTTP – the web server ignores
     *     `data/.htaccess`/`web.config` on nginx).
     *  2. Environment variable `PSYCHKG_DATA_DIR`.
     *  3. Fallback: `<project>/data` (local development).
     */
    public static function getDataDir(): string
    {
        $localConfig = self::getBaseDir() . DIRECTORY_SEPARATOR . 'config.local.php';
        if (is_file($localConfig)) {
            $cfg = include $localConfig;
            if (is_array($cfg) && !empty($cfg['data_dir']) && is_string($cfg['data_dir'])) {
                return $cfg['data_dir'];
            }
        }

        $env = getenv('PSYCHKG_DATA_DIR');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::getBaseDir() . DIRECTORY_SEPARATOR . 'data';
    }

    public static function getUsersFilePath(): string
    {
        return self::getDataDir() . DIRECTORY_SEPARATOR . 'users.json';
    }

    public static function getScheduleFilePath(): string
    {
        return self::getDataDir() . DIRECTORY_SEPARATOR . 'schedule.json';
    }

    public static function getTradesFilePath(): string
    {
        return self::getDataDir() . DIRECTORY_SEPARATOR . 'trades.json';
    }

    public static function getAuditFilePath(): string
    {
        return self::getDataDir() . DIRECTORY_SEPARATOR . 'audit.json';
    }

    public static function getAbsencesFilePath(): string
    {
        return self::getDataDir() . DIRECTORY_SEPARATOR . 'absences.json';
    }

    public static function getSnapshotsFilePath(): string
    {
        return self::getDataDir() . DIRECTORY_SEPARATOR . 'snapshots.json';
    }

    public static function getShiftSettingsFilePath(): string
    {
        return self::getDataDir() . DIRECTORY_SEPARATOR . 'settings.json';
    }
}
