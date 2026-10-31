<?php
declare(strict_types=1);
namespace Src\Core;

class Config
{
    /** Set to true only in local/demo environments (shows test login hints). */
    public const SHOW_DEMO_CREDENTIALS = false;

    /**
     * Local setup values from `config.local.php` (request cache).
     *
     * Supported keys:
     *  - data_dir        => string   (see getDataDir())
     *  - allow_install   => bool     (see isInstallerEnabled())
     *  - force_https     => bool     (see isHttps(); for hosts where the proxy
     *                                 signal is missing)
     *  - trusted_proxies => string[] (extra proxy IPs/CIDRs; loopback is
     *                                 always trusted so a local Plesk/nginx
     *                                 reverse proxy works out of the box)
     *
     * @var array<string,mixed>|null
     */
    private static ?array $localConfig = null;

    /** Test-only data directory override (see overrideDataDir()). */
    private static ?string $dataDirOverride = null;

    /** Loopback proxies are trusted by default (local Plesk/nginx setup). */
    private const DEFAULT_TRUSTED_PROXIES = ['127.0.0.1', '::1'];

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
        return (self::getLocalConfig()['allow_install'] ?? false) === true;
    }

    /**
     * Reads `config.local.php` once per request.
     *
     * @return array<string,mixed>
     */
    public static function getLocalConfig(): array
    {
        if (self::$localConfig !== null) {
            return self::$localConfig;
        }

        $localConfig = self::getBaseDir() . DIRECTORY_SEPARATOR . 'config.local.php';
        if (!is_file($localConfig)) {
            return self::$localConfig = [];
        }

        $cfg = include $localConfig;
        return self::$localConfig = is_array($cfg) ? $cfg : [];
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
     * Whether *this* request was served over TLS.
     *
     * Resolution order:
     *  1. `force_https` in config.local.php (explicit host override).
     *  2. Native signals: `$_SERVER['HTTPS']` / HTTPS port 443.
     *  3. `X-Forwarded-Proto` – only when REMOTE_ADDR is a trusted proxy.
     *     Trusting the header from arbitrary clients would let them force a
     *     Secure cookie on a plain-HTTP connection and lock themselves out.
     *
     * The result only controls cookie hardening and the HSTS header, never an
     * authorization decision.
     */
    public static function isHttps(): bool
    {
        $forced = self::getLocalConfig()['force_https'] ?? null;
        if (is_bool($forced)) {
            return $forced;
        }

        $https = $_SERVER['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        if (self::isTrustedProxy()) {
            $proto = self::headerValue('HTTP_X_FORWARDED_PROTO');
            if ($proto !== '' && strtolower(trim(explode(',', $proto)[0])) === 'https') {
                return true;
            }
        }

        return false;
    }

    /**
     * Client IP for rate limiting / audit context.
     *
     * Behind a reverse proxy REMOTE_ADDR is the proxy address, so every user
     * would share one bucket. When the direct peer is trusted, the
     * X-Forwarded-For chain is walked right-to-left: the rightmost addresses
     * are appended by our own proxies, the first non-trusted one is the real
     * client. A client-supplied value at the left of the header therefore
     * cannot spoof the result.
     */
    public static function getClientIp(): string
    {
        $remote = self::remoteAddr();
        if (!self::isTrustedProxy()) {
            return $remote !== '' ? $remote : '0.0.0.0';
        }

        $forwarded = self::headerValue('HTTP_X_FORWARDED_FOR');
        if ($forwarded !== '') {
            $parts = array_reverse(array_map('trim', explode(',', $forwarded)));
            foreach ($parts as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    continue;
                }
                if (!self::isTrustedIp($candidate)) {
                    return $candidate;
                }
            }
        }

        $realIp = self::headerValue('HTTP_X_REAL_IP');
        if (filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }

        return $remote !== '' ? $remote : '0.0.0.0';
    }

    /** Whether the direct peer (REMOTE_ADDR) is a trusted reverse proxy. */
    public static function isTrustedProxy(): bool
    {
        $remote = self::remoteAddr();
        return $remote !== '' && self::isTrustedIp($remote);
    }

    /**
     * Test isolation hook: point all JSON storage at a different directory.
     * Pass null to restore the normal resolution order. Deliberately checked
     * before config.local.php so a test run can never touch production data.
     */
    public static function overrideDataDir(?string $dir): void
    {
        self::$dataDirOverride = ($dir === null || $dir === '') ? null : rtrim($dir, "/\\");
    }

    /**
     * Directory holding the JSON data files.
     *
     * Resolution order (first hit wins):
     *  1. `overrideDataDir()` (tests only).
     *  2. `config.local.php` in the project root returning `['data_dir' => '...']`
     *     (server setup: points OUTSIDE the document root, e.g. `~/private/psychkg-data`,
     *     so data files are never reachable via HTTP – the web server ignores
     *     `data/.htaccess`/`web.config` on nginx).
     *  3. Environment variable `PSYCHKG_DATA_DIR`.
     *  4. Fallback: `<project>/data` (local development).
     */
    public static function getDataDir(): string
    {
        if (self::$dataDirOverride !== null) {
            return self::$dataDirOverride;
        }

        $configured = self::getLocalConfig()['data_dir'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
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

    private static function remoteAddr(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($remote) ? trim($remote) : '';
    }

    private static function headerValue(string $serverKey): string
    {
        $value = $_SERVER[$serverKey] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Checks an IP against the trusted list (exact address or CIDR).
     */
    private static function isTrustedIp(string $ip): bool
    {
        $configured = self::getLocalConfig()['trusted_proxies'] ?? [];
        $rules = self::DEFAULT_TRUSTED_PROXIES;
        if (is_array($configured)) {
            foreach ($configured as $rule) {
                if (is_string($rule) && $rule !== '') {
                    $rules[] = $rule;
                }
            }
        }

        foreach ($rules as $rule) {
            if (self::ipMatches($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    private static function ipMatches(string $ip, string $rule): bool
    {
        if ($rule === $ip) {
            return true;
        }
        if (!str_contains($rule, '/')) {
            return false;
        }

        [$subnet, $bitsRaw] = explode('/', $rule, 2);
        if (!preg_match('/^\d{1,3}$/', $bitsRaw)) {
            return false;
        }
        $bits = (int) $bitsRaw;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits > 0) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($ipBin[$wholeBytes]) & $mask) !== (ord($subnetBin[$wholeBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
