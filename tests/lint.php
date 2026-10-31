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
 * Syntax check for every PHP file in the project.
 * Run: php tests/lint.php   (or: composer lint)
 *
 * Complements the pre-commit hook's div-balance check, which only covers
 * staged files and only checks markup.
 */

$root = dirname(__DIR__);
$dirs = ['src', 'public', 'templates', 'tests', 'data'];

$files = [];
foreach ($dirs as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$failures = 0;
foreach ($files as $file) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failures++;
        echo "FAIL  " . str_replace($root . '/', '', $file) . "\n";
        foreach ($out as $line) {
            echo "      $line\n";
        }
    }
}

printf("%d Dateien geprueft, %d Fehler\n", count($files), $failures);
exit($failures === 0 ? 0 : 1);