<?php
/** Fast real-MySQL adapter qualification; only the owned disposable databases are writable. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 3) { exit(2); }
$runtime = realpath($argv[1]);
if ($runtime === false || !str_contains($runtime, '/dualscan-cloud-os-image-manager-runtime/')) { throw new RuntimeException('Only the owned disposable runtime is allowed.'); }
require dirname(__DIR__, 2) . '/includes/engine-bootstrap.php';
use Dualscan\CloudOS\PdoDatabase;
function connectProbe(string $name, string $zone): PDO {
    if (!in_array($name, ['dualscan_source', 'dualscan_target'], true)) { throw new RuntimeException('Unexpected database.'); }
    $pdo = new PDO('mysql:host=127.0.0.1;port=33617;dbname=' . $name . ';charset=utf8mb4', 'dualscan_test', 'LocalOnlyFixture2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]);
    $pdo->exec("SET SESSION time_zone='" . $zone . "'");
    return $pdo;
}
function probeSnapshot(PDO $pdo): array {
    $zone = $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn(); $pdo->exec("SET SESSION time_zone='+00:00'");
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($tables); $result = [];
        foreach ($tables as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $columns = $pdo->query('SHOW FULL COLUMNS FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC);
            $select = implode(',', array_map(static fn($column) => 'CAST(`' . str_replace('`', '``', $column['Field']) . '` AS BINARY)', $columns));
            $rows = array_map('serialize', $pdo->query('SELECT ' . $select . ' FROM ' . $q)->fetchAll(PDO::FETCH_NUM)); sort($rows);
            $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
            $result[$table] = ['rows' => count($rows), 'rowRawBytesSha256' => hash('sha256', serialize($rows)), 'ddlSha256' => hash('sha256', $create[1])];
        }
        return $result;
    } finally { $pdo->exec('SET SESSION time_zone=' . $pdo->quote($zone)); }
}
$source = connectProbe('dualscan_source', '+05:30'); $target = connectProbe('dualscan_target', '-07:00');
$sourceAdapter = new PdoDatabase($source); $targetAdapter = new PdoDatabase($target);
$sourceMode = $source->query('SELECT @@SESSION.sql_mode')->fetchColumn(); $targetMode = $target->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$expected = probeSnapshot($source);
$directory = $runtime . '/tmp/database-probe-' . bin2hex(random_bytes(8));
$began = microtime(true);
try {
    $metadata = $sourceAdapter->exportTo($directory);
    $sourceAdapter->validateFrom($directory, $metadata);
    $targetAdapter->restoreFrom($directory, $metadata);
    if (probeSnapshot($target) !== $expected || probeSnapshot($source) !== $expected) { throw new RuntimeException('Actual stored column bytes, schemas or source data differ.'); }
    if ($source->query('SELECT @@SESSION.time_zone')->fetchColumn() !== '+05:30' || $target->query('SELECT @@SESSION.time_zone')->fetchColumn() !== '-07:00' || $source->query('SELECT @@SESSION.sql_mode')->fetchColumn() !== $sourceMode || $target->query('SELECT @@SESSION.sql_mode')->fetchColumn() !== $targetMode) { throw new RuntimeException('Source/target session settings were not restored.'); }
    $fixture = $target->query('SELECT id,HEX(latin_text) AS latin1TextRaw,HEX(latin_label) AS latin1VarcharRaw,unsigned_max,HEX(bits8) AS bits8,HEX(bit_one) AS bitOne,UNIX_TIMESTAMP(timestamp_value) AS timestampInstant,CAST(json_value AS CHAR CHARACTER SET utf8mb4) AS jsonValue FROM wp_dualscan_fixture ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $report = ['passed' => true, 'runtime' => 'Native PHP 8.2.0, real MySQL 5.7.39', 'scope' => 'All 14 tables of the disposable WordPress database; no public website/file changes', 'seconds' => round(microtime(true) - $began, 3), 'rowEncoding' => $metadata['rowEncoding'], 'allRawColumnBytesAndDdlMatched' => true, 'sourceUnchanged' => true, 'sourceTimeZoneRestored' => '+05:30', 'targetTimeZoneRestored' => '-07:00', 'sessionSqlModesRestored' => true, 'tables' => $expected, 'mixedTypeFixture' => $fixture];
    file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode(['passed' => true, 'tables' => count($expected), 'seconds' => $report['seconds'], 'report' => $argv[2]]), "\n";
} finally { Dualscan\CloudOS\SafeFiles::remove($directory); }
