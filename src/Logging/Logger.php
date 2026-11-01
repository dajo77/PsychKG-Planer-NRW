<?php
declare(strict_types=1);

namespace Src\Logging;

use Src\Core\Config;

/**
 * Minimaler, PSR-3-ähnlicher Datei-Logger.
 *
 * Bewusst ohne externe Abhängigkeit (kein Monolog): Die Anwendung muss auch
 * auf Servern ohne Composer lauffähig bleiben. Die Signatur orientiert sich an
 * PSR-3, damit ein späterer Wechsel auf eine vollwertige Implementierung ohne
 * Änderungen an den Aufrufstellen möglich ist.
 *
 * Logdateien liegen unter storage/logs/app-YYYY-MM-DD.log (Tagesrotation).
 * Das Verzeichnis wird bei Bedarf angelegt und enthält .htaccess/index.php,
 * damit es bei einem Document Root auf Projektwurzel nicht per HTTP abrufbar
 * ist. Dort dürfen niemals Passwörter, Session-IDs oder vollständige
 * personenbezogene Datensätze landen – Aufrufer übergeben nur Metadaten.
 */
final class Logger
{
    /** PSR-3 Level als Ordnungswerte (kleiner = ausführlicher). */
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /** Test-Override für das Logverzeichnis (verhindert Schreiben ins Repo). */
    private static ?string $logDirOverride = null;

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::log('notice', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /**
     * Test-Hook: eigenes Logverzeichnis setzen (null = Normalfall).
     */
    public static function setLogDir(?string $dir): void
    {
        self::$logDirOverride = ($dir === null || $dir === '') ? null : rtrim($dir, "/\\");
    }

    public static function getLogDir(): string
    {
        if (self::$logDirOverride !== null) {
            return self::$logDirOverride;
        }

        return Config::getBaseDir() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    }

    /**
     * Schwelle aus APP_ENV:
     *  - dev/development/local => alles ab debug
     *  - sonst (prod)          => ab warning
     * Optional per APP_LOG_LEVEL überschreibbar.
     */
    private static function threshold(): int
    {
        $explicit = getenv('APP_LOG_LEVEL');
        if (is_string($explicit) && isset(self::LEVELS[strtolower($explicit)])) {
            return self::LEVELS[strtolower($explicit)];
        }

        $env = strtolower((string) (getenv('APP_ENV') ?: 'prod'));
        return in_array($env, ['dev', 'development', 'local'], true)
            ? self::LEVELS['debug']
            : self::LEVELS['warning'];
    }

    private static function log(string $level, string $message, array $context): void
    {
        $level = strtolower($level);
        if (!isset(self::LEVELS[$level]) || self::LEVELS[$level] < self::threshold()) {
            return;
        }

        $line = sprintf(
            '[%s] %s: %s%s',
            date('c'),
            strtoupper($level),
            self::interpolate($message, $context),
            $context === [] ? '' : ' ' . self::encodeContext($context)
        );

        $dir = self::getLogDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // Letzte Rettung: PHP-eigenes Log, damit der Fehler nicht verloren geht.
            error_log('PsychKG Logger: cannot create log dir ' . $dir . ' – ' . $line);
            return;
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';
        $written = @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('PsychKG Logger: cannot write ' . $file . ' – ' . $line);
            return;
        }

        // Logs enthalten Betriebsmetadaten; nur der Dienstbenutzer soll lesen.
        @chmod($file, 0640);
    }

    /**
     * PSR-3-Platzhalter ({key}) ersetzen. Werte werden stringifiziert, aber
     * nicht rekursiv expandiert (kein sprintf, damit %-Zeichen sicher bleiben).
     */
    private static function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return $replace === [] ? $message : strtr($message, $replace);
    }

    private static function encodeContext(array $context): string
    {
        $json = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{}' : $json;
    }
}
