<?php
declare(strict_types=1);
namespace Src\Storage;

class JsonStorage
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Reads and decodes JSON from the file, applying a shared read lock.
     * If the main file is missing or corrupt, falls back to the `.bak`
     * copy written by the last successful write() (crash recovery).
     */
    public function read(): array
    {
        $data = $this->readFile($this->filePath);
        if ($data !== null) {
            return $data;
        }

        $backup = $this->filePath . '.bak';
        if (is_file($backup)) {
            error_log('JsonStorage: main file unusable, falling back to backup (' . $this->filePath . ')');
            $data = $this->readFile($backup);
            if ($data !== null) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Reads one file. Returns null when missing, unreadable or invalid.
     */
    private function readFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        // Open file in read mode
        $fp = fopen($path, 'r');
        if (!$fp) {
            return null;
        }

        // Apply a shared lock (multiple clients can read simultaneously)
        flock($fp, LOCK_SH);

        // Size the read from the OPEN HANDLE, not from the path. write() commits
        // via rename(), which swaps the directory entry while our descriptor
        // still refers to the previous inode — filesize($path) would then report
        // the NEW file's size and fread() would cut the OLD one short, producing
        // invalid JSON and a silent fallback to the .bak copy (stale data).
        // fstat() always describes the inode this handle actually reads.
        $stat = fstat($fp);
        $filesize = (int) ($stat['size'] ?? 0);
        $content = '';
        if ($filesize > 0) {
            $content = stream_get_contents($fp);
        }

        // Release lock and close
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JsonStorage read error (' . $path . '): ' . json_last_error_msg());
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Encodes and writes data atomically: temp file + rename, so a crash
     * can never leave a truncated file behind. The previous version is
     * kept as `.bak` (used by read() as crash fallback).
     */
    public function write(array $data): bool
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonContent === false) {
            return false;
        }

        $tmp = $this->filePath . '.tmp.' . getmypid();
        $fp = fopen($tmp, 'wb');
        if (!$fp) {
            return false;
        }

        flock($fp, LOCK_EX);
        $result = fwrite($fp, $jsonContent);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($result === false) {
            @unlink($tmp);
            return false;
        }

        // Keep previous version as crash fallback before swapping.
        if (is_file($this->filePath)) {
            @copy($this->filePath, $this->filePath . '.bak');
        }

        if (!rename($tmp, $this->filePath)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /** @var resource|null Shared lock handle for synchronized() (re-entrant). */
    private static $lockHandle = null;

    private static int $lockDepth = 0;

    /**
     * Runs $fn while holding one exclusive, cross-request lock for the
     * whole data directory. Use for read-modify-write sequences (trade
     * state changes, schedule updates) so parallel requests serialize
     * instead of overwriting each other. Re-entrant: nested calls within
     * the same request do not deadlock.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function synchronized(callable $fn): mixed
    {        $lockPath = \Src\Core\Config::getDataDir() . DIRECTORY_SEPARATOR . '.lock';
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (self::$lockHandle === null) {
            $fp = @fopen($lockPath, 'c');
            if ($fp === false) {
                error_log('JsonStorage: cannot open lock file, running unlocked (' . $lockPath . ')');
                return $fn();
            }
            self::$lockHandle = $fp;
        }

        self::$lockDepth++;
        $outer = self::$lockDepth === 1;
        if ($outer) {
            flock(self::$lockHandle, LOCK_EX);
        }
        try {
            return $fn();
        } finally {
            self::$lockDepth--;
            if ($outer) {
                flock(self::$lockHandle, LOCK_UN);
            }
        }
    }

    /**
     * Releases the shared lock handle. Only for process teardown/tests: on
     * Windows an open handle keeps the `.lock` file (and its directory) alive.
     */
    public static function closeLock(): void
    {
        if (self::$lockHandle !== null) {
            if (is_resource(self::$lockHandle)) {
                flock(self::$lockHandle, LOCK_UN);
                fclose(self::$lockHandle);
            }
            self::$lockHandle = null;
        }
        self::$lockDepth = 0;
    }
}
