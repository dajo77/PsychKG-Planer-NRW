<?php
// CLI-only: the test suite must never be executable over HTTP. On this host
// the document root is the project root, so without this guard a GET on
// /tests/run_tests.php would execute the suite for anonymous visitors.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
?>
<?php
/**
 * Seed a local development instance with known accounts, so the smoke test can
 * log in and exercise the authenticated pages. Never run against real data.
 *
 * Usage: php tests/seed_dev.php
 */
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Core\Config;
use Src\Core\Seeder;
use Src\Storage\JsonStorage;

$dataDir = Config::getDataDir();
if (str_contains($dataDir, 'psychkg-data') && !str_contains($dataDir, 'opencode')) {
    // Guard: refuse to touch what looks like the production data directory.
    fwrite(STDERR, "ABBRUCH: Data dir looks like production ($dataDir)\n");
    exit(1);
}

$usersFile = Config::getUsersFilePath();
$usersStorage = new JsonStorage($usersFile);
$existing = $usersStorage->read();
if (!empty($existing['users'])) {
    fwrite(STDERR, "users.json already populated - refusing to overwrite.\n");
    exit(1);
}

$pw = Seeder::seedWithRandomPasswords();
foreach ($pw as $u) {
    printf("%-14s %s  (%s)\n", $u['username'], $u['password'], $u['role']);
}
echo "\nDatenverzeichnis: $dataDir\n";