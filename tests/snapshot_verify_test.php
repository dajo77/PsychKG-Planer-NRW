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
// Verify SnapshotService backwards compatibility: a legacy snapshot (hash over
// month+blocks only) must still verify, and a tampered one must not.
require_once __DIR__ . '/../src/bootstrap.php';

use Src\Service\SnapshotService;

$blocks = [
    ['id' => 'blk_1', 'start' => '2026-03-01 08:00', 'end' => '2026-03-08 08:00',
     'start_ts' => 1772352000, 'end_ts' => 1772956800,
     'user_id' => 'usr_a', 'user_name' => 'Max Mustermann', 'status' => 'assigned', 'hours' => 128.0],
];

// 1) legacy snapshot, as produced by the previous code
$legacy = [
    'id' => 'snp_202601_legacy', 'month' => '2026-01',
    'created_at' => '2026-01-05 10:00:00',
    'created_by' => 'usr_admin', 'created_by_name' => 'Admin',
    'blocks' => $blocks,
    'hash' => hash('sha256', json_encode(['month' => '2026-01', 'blocks' => $blocks], JSON_UNESCAPED_UNICODE)),
];
$svc = new SnapshotService();

// 2) new-format snapshot with provenance in the hash
$new = [
    'id' => 'snp_202602_new', 'month' => '2026-02',
    'created_at' => '2026-02-05 10:00:00',
    'created_by' => 'usr_admin', 'created_by_name' => 'Admin',
    'blocks' => $blocks,
    'hash' => '',
];
$new['hash'] = hash('sha256', json_encode([
    'month' => $new['month'], 'created_at' => $new['created_at'],
    'created_by' => $new['created_by'], 'created_by_name' => $new['created_by_name'],
    'blocks' => $new['blocks'],
], JSON_UNESCAPED_UNICODE));

$cases = [
    'legacy snapshot (old hash scheme)'        => [$svc->verify($legacy), true],
    'new snapshot (provenance covered)'         => [$svc->verify($new), true],
];
// tampering
$badNew = $new; $badNew['created_by_name'] = 'Jemand Anderes';
$cases['new snapshot, creator tampered']       = [$svc->verify($badNew), false];
$badBlocks = $new; $badBlocks['blocks'][0]['user_id'] = 'usr_b';
$cases['new snapshot, block tampered']         = [$svc->verify($badBlocks), false];
$legacyBad = $legacy; $legacyBad['blocks'][0]['hours'] = 999.0;
$cases['legacy snapshot, block tampered']     = [$svc->verify($legacyBad), false];
$legacyName = $legacy; $legacyName['created_by_name'] = 'Jemand';
$cases['legacy snapshot, name edited (out of hash scope)'] = [$svc->verify($legacyName), true];
$cases['empty hash']                          = [$svc->verify(['month' => '2026-01']), false];

$fail = 0;
foreach ($cases as $label => [$got, $want]) {
    $ok = $got === $want;
    if (!$ok) { $fail++; }
    printf("%-52s got=%-5s want=%-5s %s\n", $label,
        var_export($got, true), var_export($want, true), $ok ? 'OK' : '*** FAIL ***');
}

// month validation
foreach ([['2026-01', true], ['0000-05', false], ['2101-01', false], ['2026-13', false], ['2026-00', false]] as [$m, $want]) {
    $got = SnapshotService::isValidMonth($m);
    if ($got !== $want) { $fail++; }
    printf("%-52s got=%-5s want=%-5s %s\n", "isValidMonth($m)", var_export($got, true),
        var_export($want, true), $got === $want ? 'OK' : '*** FAIL ***');
}

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);