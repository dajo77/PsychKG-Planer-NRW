<?php
declare(strict_types=1);

namespace Src\Security;

use Src\Logging\Logger;

/**
 * Dateibasiertes Login-Throttling (IP- und Benutzername-bezogen).
 *
 * Bewusst ohne APCu/Redis und ohne Datenbank: Die Anwendung läuft auf
 * einfachen Webhostern, auf denen diese Erweiterungen oft fehlen. Die
 * Zählerdateien liegen in sys_get_temp_dir() und enthalten ausschließlich
 * Unix-Timestamps sowie einen SHA-256-Hash der Kennung – keine Passwörter,
 * keine Klarnamen und keine Session-IDs. Ein Angreifer mit Leserechten auf
 * das Temp-Verzeichnis erfährt damit nur, dass (und wann) Versuche stattfanden.
 *
 * Alle Lese-/Schreibzugriffe laufen unter flock(), damit parallele Requests
 * keine Versuche verlieren. Alte Dateien werden probabilistisch (1 % der
 * Aufrufe) bzw. explizit über prune() entfernt.
 */
final class LoginThrottle
{
    private const FILE_PREFIX = 'psychkg_login_';

    /** Schwelle, ab der ein Muster als verdächtig geloggt wird. */
    private const SUSPICIOUS_THRESHOLD = 5;

    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $dir = $storageDir ?? sys_get_temp_dir();
        $this->storageDir = rtrim($dir, "/\\");
    }

    /**
     * Prüft den aktuellen Zählerstand, ohne einen Versuch zu erfassen.
     *
     * @return array{0:int,1:bool} [Anzahl im Zeitfenster, Limit erreicht]
     */
    public function check(string $scope, string $identifier, int $max, int $window): array
    {
        $path = $this->filePath($scope, $identifier);
        $attempts = $this->readAttempts($path, $window);
        $count = count($attempts);

        return [$count, $count >= $max];
    }

    /**
     * Erfasst einen fehlgeschlagenen Versuch und liefert den neuen Stand.
     *
     * @return array{0:int,1:bool} [Anzahl inkl. dieses Versuchs, Limit erreicht]
     */
    public function recordFailure(string $scope, string $identifier, int $max, int $window): array
    {
        $path = $this->filePath($scope, $identifier);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return [0, false];
        }

        flock($handle, LOCK_EX);
        $attempts = $this->readFromHandle($handle, $window);
        $attempts[] = time();

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($attempts));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $count = count($attempts);
        $limited = $count >= $max;

        // Monitoring ohne externen Dienst: verdächtige Muster landen in der
        // Logdatei. Die Kennung wird nur gehasht protokolliert (Datensparsamkeit).
        if ($count >= self::SUSPICIOUS_THRESHOLD) {
            Logger::warning('Suspicious login pattern', [
                'scope' => $this->normalizeScope($scope),
                'identifier_hash' => substr(hash('sha256', $identifier), 0, 16),
                'attempts' => $count,
                'window_seconds' => $window,
            ]);
        }

        return [$count, $limited];
    }

    /**
     * Setzt den Zähler nach erfolgreichem Login zurück.
     */
    public function reset(string $scope, string $identifier): void
    {
        $path = $this->filePath($scope, $identifier);
        if (!is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            @unlink($path);
            return;
        }

        flock($handle, LOCK_EX);
        ftruncate($handle, 0);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Entfernt Zählerdateien, die älter als $maxAgeSeconds sind.
     *
     * @return int Anzahl gelöschter Dateien
     */
    public function prune(int $maxAgeSeconds = 86400): int
    {
        $cutoff = time() - max(0, $maxAgeSeconds);
        $deleted = 0;

        foreach (glob($this->storageDir . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Ruft prune() probabilistisch auf (Standard: 1 % der Requests), damit
     * keine Cron-Job-Infrastruktur nötig ist. Für Cron/Tests ist prune()
     * weiterhin direkt aufrufbar.
     */
    public function maybePrune(int $probabilityPercent = 1, int $maxAgeSeconds = 86400): void
    {
        $probability = max(0, min(100, $probabilityPercent));
        if ($probability === 0) {
            return;
        }

        try {
            if (random_int(1, 100) <= $probability) {
                $this->prune($maxAgeSeconds);
            }
        } catch (\Throwable) {
            // Monitoring darf den Login nie blockieren.
        }
    }

    private function filePath(string $scope, string $identifier): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . self::FILE_PREFIX
            . $this->normalizeScope($scope) . '_'
            . hash('sha256', $identifier);
    }

    private function normalizeScope(string $scope): string
    {
        $normalized = preg_replace('/[^a-z0-9_]/', '', strtolower($scope));

        return $normalized === '' ? 'default' : $normalized;
    }

    /**
     * @return int[]
     */
    private function readAttempts(string $path, int $window): array
    {
        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        flock($handle, LOCK_SH);
        $attempts = $this->readFromHandle($handle, $window);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $attempts;
    }

    /**
     * @param resource $handle
     * @return int[]
     */
    private function readFromHandle($handle, int $window): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $list = json_decode($raw === false || $raw === '' ? '[]' : $raw, true);
        if (!is_array($list)) {
            return [];
        }

        $now = time();
        $attempts = [];
        foreach ($list as $timestamp) {
            if (is_int($timestamp) && $timestamp > $now - $window) {
                $attempts[] = $timestamp;
            }
        }

        return $attempts;
    }
}
