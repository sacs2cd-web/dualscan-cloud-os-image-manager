<?php
/** Native MySQL + full, disposable WordPress tree integration test. Never point this at a live site. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: php roundtrip.php DISPOSABLE_RUNTIME_ROOT REPORT_JSON\n");
    exit(2);
}
$runtime = realpath($argv[1]);
if ($runtime === false || !str_contains($runtime, '/dualscan-cloud-os-image-manager-runtime/')) {
    throw new RuntimeException('Only the isolated research runtime is allowed.');
}
require dirname(__DIR__, 2) . '/includes/engine-bootstrap.php';
require __DIR__ . '/sealed_database_audit.php';
use Dualscan\CloudOS\ImageManager;
use Dualscan\CloudOS\PdoDatabase;


function check(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
function db(string $name): PDO {
    check(in_array($name, ['dualscan_source', 'dualscan_target'], true), 'Unexpected database name.');
    $pdo = new PDO('mysql:host=127.0.0.1;port=33617;dbname=' . $name . ';charset=utf8mb4', 'dualscan_test', 'LocalOnlyFixture2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET SESSION time_zone='" . ($name === 'dualscan_source' ? '+05:30' : '-07:00') . "'");
    return $pdo;
}
function databaseSnapshot(PDO $pdo): array {
    $originalZone = $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
    $pdo->exec("SET SESSION time_zone='+00:00'");
    $result = [];
    try {
    foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $item) {
        $name = $item[0];
        $q = '`' . str_replace('`', '``', $name) . '`';
        $rows = $pdo->query('SELECT * FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(static fn($row) => serialize($row), $rows);
        sort($encoded, SORT_STRING);
        $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
        $result[$name] = ['rows' => count($rows), 'contentSha256' => hash('sha256', serialize($encoded)), 'createSqlSha256' => hash('sha256', $create[1])];
    }
    ksort($result);
    return $result;
    } finally { $pdo->exec('SET SESSION time_zone=' . $pdo->quote($originalZone)); }
}
function treeSnapshot(string $root): array {
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($relative === 'wp-config.php') { continue; }
        $result[$relative] = $file->isDir() ? ['kind' => 'directory'] : ['kind' => 'file', 'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
    }
    ksort($result);
    return $result;
}
function configDefinitions(string $path): array {
    $text = file_get_contents($path);
    preg_match_all('/\\bdefine\\(\\s*([\'"])([^\'"]+)\\1\\s*,(.*?)\\);/s', $text, $matches, PREG_SET_ORDER);
    $definitions = [];
    foreach ($matches as $match) { $definitions[$match[2]] = trim($match[3]); }
    preg_match('/\\$table_prefix\\s*=\\s*([^;]+);/', $text, $prefix);
    $definitions['$table_prefix'] = $prefix[1] ?? null;
    ksort($definitions);
    return $definitions;
}
function rejected(callable $operation, string $case, array &$cases): void {
    try { $operation(); } catch (Throwable $e) {
        $cases[$case] = ['passed' => true, 'rejection' => $e->getMessage()];
        return;
    }
    throw new RuntimeException($case . ' unexpectedly succeeded.');
}

$sourceRoot = $runtime . '/sites/source';
$targetRoot = $runtime . '/sites/target';
$storage = $runtime . '/images';
$source = db('dualscan_source');
$target = db('dualscan_target');
$sourceSqlMode = $source->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$targetSqlMode = $target->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$sourceTimeZone = $source->query('SELECT @@SESSION.time_zone')->fetchColumn();
$targetTimeZone = $target->query('SELECT @@SESSION.time_zone')->fetchColumn();
$sourceManager = new ImageManager(new PdoDatabase($source), $storage);
$targetManager = new ImageManager(new PdoDatabase($target), $storage);
$password = 'Roundtrip-Test-Password-2026';
$cases = [];
$start = microtime(true);
$sourceFiles = treeSnapshot($sourceRoot);
$sourceData = databaseSnapshot($source);
$sourcePolicyState = captureApplianceDatabaseState($source);
$sourceStorageBytes = $source->query('SELECT id,HEX(latin_text) AS latin_text,HEX(latin_label) AS latin_label,HEX(CAST(label AS BINARY)) AS utf8_label,HEX(payload) AS binary_payload,unsigned_max,HEX(bits8) AS bits8,HEX(bit_one) AS bit_one FROM wp_dualscan_fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$sourceTimestampInstants = $source->query('SELECT id,UNIX_TIMESTAMP(timestamp_value) AS instant,CAST(json_value AS CHAR CHARACTER SET utf8mb4) AS json_value FROM wp_dualscan_fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$sourceConfig = hash_file('sha256', $sourceRoot . '/wp-config.php');
$targetConfig = hash_file('sha256', $targetRoot . '/wp-config.php');
$targetConfigDefinitions = configDefinitions($targetRoot . '/wp-config.php');
$image = $sourceManager->create($sourceRoot, $password, ['siteUrl' => 'http://127.0.0.1:8767', 'tablePrefix' => 'wp_']);
check(is_array($image) && is_file($image['path']), 'create() did not produce a real file.');
check(pathinfo($image['path'], PATHINFO_EXTENSION) === 'dualscan', 'Wrong archive extension.');
check(treeSnapshot($sourceRoot) === $sourceFiles, 'Source files changed during backup.');
check(databaseSnapshot($source) === $sourceData, 'Source database changed during backup.');
check($source->query('SELECT @@SESSION.time_zone')->fetchColumn() === $sourceTimeZone, 'Backup changed original source connection time zone.');
$cases['fullWordPressBackup'] = ['passed' => true, 'fileAndDirectoryEntries' => count($sourceFiles), 'databaseTables' => count($sourceData), 'imageBytes' => filesize($image['path']), 'imageSha256' => hash_file('sha256', $image['path'])];
$manifest = $sourceManager->inspect($image['path'], $password);
$cases['authenticatedInspect'] = ['passed' => true, 'manifestKeys' => array_keys($manifest)];

// A target fixture is deliberately altered before restore.
file_put_contents($targetRoot . '/custom-root-data.json', 'mutated target data');
if (!is_dir($targetRoot . '/wp-content/uploads')) { mkdir($targetRoot . '/wp-content/uploads', 0755, true); }
file_put_contents($targetRoot . '/wp-content/uploads/empty-file', 'mutated empty file');
$target->exec('DROP TABLE IF EXISTS wp_dualscan_fixture');
$target->exec('CREATE TABLE wp_dualscan_fixture (id INT PRIMARY KEY, label TEXT) ENGINE=InnoDB');
$target->exec("INSERT INTO wp_dualscan_fixture VALUES (1,'mutated target database')");
// Fail only after actual account removal, guard installation and wp-config prepending.
file_put_contents($targetRoot . '/pre-restore-target-only.txt', 'Target-only file must return on rollback.');
$target->exec('DROP TABLE IF EXISTS rollback_target_only');
$target->exec('CREATE TABLE rollback_target_only (id INT PRIMARY KEY, value TEXT) ENGINE=InnoDB');
$target->exec("INSERT INTO rollback_target_only VALUES (7,'Restore must recover this target-only table.')");
$rollbackBeforeFiles = treeSnapshot($targetRoot);
$rollbackBeforeData = databaseSnapshot($target);
$rollbackBeforeConfig = hash_file('sha256', $targetRoot . '/wp-config.php');
$sealWasApplied = false;
rejected(function () use ($targetManager, $image, $password, $targetRoot, &$sealWasApplied): void {
    $targetManager->restore($image['path'], $password, $targetRoot, ['preservePaths' => ['wp-config.php'], 'progress' => static function (string $phase, int $done, int $total) use (&$sealWasApplied): void {
        if ($phase === 'complete' && $done === 2) { $sealWasApplied = true; throw new RuntimeException('Injected failure after completed account removal, guard installation and config bootstrap.'); }
    }]);
}, 'postSealingFailureRestoresOriginalAuthoringState', $cases);
check($sealWasApplied, 'Late-failure injection did not reach the fully applied seal.');
check(treeSnapshot($targetRoot) === $rollbackBeforeFiles && databaseSnapshot($target) === $rollbackBeforeData, 'Rollback failed to recover exact original target data.');
check(hash_file('sha256', $targetRoot . '/wp-config.php') === $rollbackBeforeConfig, 'Rollback left a sealed config bootstrap behind.');
check($target->query('SELECT @@SESSION.sql_mode')->fetchColumn() === $targetSqlMode && $target->query('SELECT @@SESSION.time_zone')->fetchColumn() === $targetTimeZone, 'Rollback did not restore connection settings.');
$cases['postSealingFailureRestoresOriginalAuthoringState'] += ['failureAfterActualAccountDeletionAndGuardInstallation' => true, 'allOriginalTargetFilesAndRowsRecovered' => true, 'targetOnlyFileAndTableRecovered' => true, 'originalWpConfigByteExact' => true, 'noNewGuardFilesRemain' => true];

$restoredResult = $targetManager->restore($image['path'], $password, $targetRoot, ['requireSiteUrl' => 'http://127.0.0.1:8767', 'requireTablePrefix' => 'wp_', 'preservePaths' => ['wp-config.php']]);
$restoredFiles = treeSnapshot($targetRoot);
foreach ($sourceFiles as $path => $expected) {
    check(isset($restoredFiles[$path]) && $restoredFiles[$path] === $expected, 'File/directory restore mismatch: ' . $path);
}
$deployment = $restoredResult['deployment'] ?? [];
check(($deployment['mode'] ?? '') === 'sealed-public-runtime' && ($deployment['policyVersion'] ?? null) === 1, 'Restore did not apply the default sealed deployment.');
$guardPaths = array_column($deployment['files'] ?? [], 'path');
sort($guardPaths);
check($guardPaths === ['wp-config.php', 'wp-content/mu-plugins/dualscan-appliance-policy.json', 'wp-content/mu-plugins/dualscan-appliance.php'], 'Unexpected runtime guard/bootstrap file inventory.');
foreach ($deployment['files'] as $guard) {
    check(is_file($targetRoot . '/' . $guard['path']) && filesize($targetRoot . '/' . $guard['path']) === $guard['bytes'] && hash_file('sha256', $targetRoot . '/' . $guard['path']) === $guard['sha256'], 'Sealed guard hash does not match the declared deployed file.');
}
$expectedPaths = array_unique(array_merge(array_keys($sourceFiles), array_values(array_filter($guardPaths, static fn($p) => $p !== 'wp-config.php'))));
sort($expectedPaths);
$actualPaths = array_keys($restoredFiles); sort($actualPaths);
check($actualPaths === $expectedPaths, 'Restore added files other than the two declared sealed guard files.');
$restoredData = databaseSnapshot($target);
check(array_keys($restoredData) === array_keys($sourceData), 'Database table inventory changed after deployment.');
$policyTables = ['wp_users', 'wp_usermeta', 'wp_posts', 'wp_comments', 'wp_links'];
foreach ($sourceData as $table => $snapshot) {
    if (!in_array($table, $policyTables, true)) { check($restoredData[$table] === $snapshot, 'Unrelated database table changed: ' . $table); }
}
$databasePolicyProof = assertApplianceDatabaseState($target, $sourcePolicyState, $deployment['databasePolicy'] ?? []);
check($target->query('SELECT id,HEX(latin_text) AS latin_text,HEX(latin_label) AS latin_label,HEX(CAST(label AS BINARY)) AS utf8_label,HEX(payload) AS binary_payload,unsigned_max,HEX(bits8) AS bits8,HEX(bit_one) AS bit_one FROM wp_dualscan_fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $sourceStorageBytes, 'Mixed Latin-1/UTF-8/binary column storage bytes changed.');
check($target->query('SELECT id,UNIX_TIMESTAMP(timestamp_value) AS instant,CAST(json_value AS CHAR CHARACTER SET utf8mb4) AS json_value FROM wp_dualscan_fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $sourceTimestampInstants, 'JSON value or timestamp instant changed between time zones.');
check(configDefinitions($targetRoot . '/wp-config.php') === $targetConfigDefinitions, 'Target DB/salts/configuration definitions changed during bootstrap insertion.');
check(hash_file('sha256', $sourceRoot . '/wp-config.php') === $sourceConfig, 'Original source configuration changed.');
check($target->query('SELECT @@SESSION.sql_mode')->fetchColumn() === $targetSqlMode, 'Target connection SQL mode was not restored.');
check($target->query('SELECT @@SESSION.time_zone')->fetchColumn() === $targetTimeZone, 'Target connection time zone was not restored.');
$cases['restoreFullTreeAndDatabase'] = ['passed' => true, 'allSourceFileBytesMatched' => true, 'allSourceDirectoriesMatched' => true, 'unaffectedTablesRowsAndDdlMatched' => true, 'onlyDeclaredAccountPolicyRowsChanged' => true, 'nonPrefixedPluginTableMatched' => true, 'targetWpConfigConnectionSettingsPreserved' => true, 'binaryUtf8NullDecimalSerializedRowsMatched' => true, 'mixedLatin1Utf8BinaryRawColumnBytesMatched' => true, 'latin1FixtureRawHex' => $sourceStorageBytes[0]['latin_text'], 'sessionSqlModeRestored' => true, 'targetOriginalSqlMode' => $targetSqlMode];
$cases['restoreFullTreeAndDatabase'] += ['jsonUnicodeNullNumbersAndEscapesMatched' => true, 'timestampInstantsAcrossDifferentSessionZonesMatched' => true, 'sourceSessionTimeZoneRestored' => $sourceTimeZone, 'targetSessionTimeZoneRestored' => $targetTimeZone, 'timestampFixtureUnixTime' => $sourceTimestampInstants[0]['instant']];
$cases['restoreFullTreeAndDatabase'] += ['defaultSealedDeploymentMode' => $deployment['mode'], 'onlyDeclaredGuardFilesAdded' => true, 'guardFiles' => $deployment['files']];
$cases['restoreFullTreeAndDatabase']['accountRemovalPolicy'] = $databasePolicyProof;

$beforeFiles = treeSnapshot($targetRoot);
$beforeData = databaseSnapshot($target);
rejected(static fn() => $targetManager->restore($image['path'], 'Incorrect-Password-2026', $targetRoot, ['preservePaths' => ['wp-config.php']]), 'wrongPasswordBeforeTargetMutation', $cases);
check(treeSnapshot($targetRoot) === $beforeFiles && databaseSnapshot($target) === $beforeData, 'Wrong password changed target.');
$tampered = $runtime . '/images/tampered.dualscan';
copy($image['path'], $tampered);
$f = fopen($tampered, 'r+b');
fseek($f, -33, SEEK_END); $byte = fread($f, 1); fseek($f, -1, SEEK_CUR); fwrite($f, chr(ord($byte) ^ 1)); fclose($f);
rejected(static fn() => $targetManager->restore($tampered, $password, $targetRoot, ['preservePaths' => ['wp-config.php']]), 'tamperBeforeTargetMutation', $cases);
check(treeSnapshot($targetRoot) === $beforeFiles && databaseSnapshot($target) === $beforeData, 'Tampered image changed target.');
unlink($tampered);

$truncated = $runtime . '/images/truncated.dualscan';
copy($image['path'], $truncated);
$f = fopen($truncated, 'r+b'); ftruncate($f, filesize($truncated) - 1); fclose($f);
rejected(static fn() => $targetManager->restore($truncated, $password, $targetRoot, ['preservePaths' => ['wp-config.php']]), 'truncationBeforeTargetMutation', $cases);
check(treeSnapshot($targetRoot) === $beforeFiles && databaseSnapshot($target) === $beforeData, 'Truncated image changed target.');
unlink($truncated);

$source->exec('CREATE TABLE unsupported_myisam (id INT) ENGINE=MyISAM');
try { rejected(static fn() => $sourceManager->create($sourceRoot, $password), 'unsupportedMyIsamRejected', $cases); }
finally { $source->exec('DROP TABLE unsupported_myisam'); }
$source->exec('CREATE TABLE unsupported_generated (id INT, generated_value INT GENERATED ALWAYS AS (id + 1) STORED) ENGINE=InnoDB');
try { rejected(static fn() => $sourceManager->create($sourceRoot, $password), 'unsupportedGeneratedColumnRejected', $cases); }
finally { $source->exec('DROP TABLE unsupported_generated'); }
$source->exec('CREATE VIEW unsupported_view AS SELECT 1 AS id');
try { rejected(static fn() => $sourceManager->create($sourceRoot, $password), 'unsupportedViewRejected', $cases); }
finally { $source->exec('DROP VIEW unsupported_view'); }
symlink($runtime . '/wordpress.tar.gz', $sourceRoot . '/external-fixture-symlink');
try { rejected(static fn() => $sourceManager->create($sourceRoot, $password), 'symlinkRejected', $cases); }
finally { unlink($sourceRoot . '/external-fixture-symlink'); }
$cases['sourceIntactAfterNegativeCases'] = ['passed' => treeSnapshot($sourceRoot) === $sourceFiles && databaseSnapshot($source) === $sourceData];
check($cases['sourceIntactAfterNegativeCases']['passed'], 'A negative case changed source.');
$report = ['passed' => true, 'runtime' => 'Native PHP 8.2.0 + MySQL 5.7.39 + real WordPress 7.1.3', 'scope' => 'Synthetic disposable local installations only', 'seconds' => round(microtime(true) - $start, 3), 'cases' => $cases, 'databaseSnapshot' => $sourceData, 'sourceFileSnapshotSha256' => hash('sha256', json_encode($sourceFiles)), 'imageManifest' => $manifest];
file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode(['passed' => true, 'cases' => count($cases), 'seconds' => $report['seconds'], 'imageBytes' => filesize($image['path']), 'report' => $argv[2]], JSON_UNESCAPED_SLASHES), "\n";
