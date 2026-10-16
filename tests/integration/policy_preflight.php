<?php
/** Focused native policy tests with staged-only capabilities and disposable FK fixtures. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 3) { exit(2); }
$runtime = realpath($argv[1]);
if ($runtime === false || !str_contains($runtime, '/dualscan-cloud-os-image-manager-runtime/')) { throw new RuntimeException('Only the owned disposable runtime is allowed.'); }
require dirname(__DIR__, 2) . '/includes/engine-bootstrap.php';
use Dualscan\CloudOS\PdoDatabase;
use Dualscan\CloudOS\SafeFiles;
function policyDb(string $name): PDO { return new PDO('mysql:host=127.0.0.1;port=33617;dbname=' . $name . ';charset=utf8mb4', 'dualscan_test', 'LocalOnlyFixture2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]); }
function expectRefused(callable $operation, string $name, array &$cases): void {
    try { $operation(); } catch (Throwable $e) { $cases[$name] = ['passed' => true, 'rejection' => $e->getMessage()]; return; }
    throw new RuntimeException('Expected safe refusal: ' . $name);
}
function editStagedTable(string $directory, array &$metadata, string $name, callable $edit): void {
    foreach ($metadata['tables'] as &$table) {
        if ($table['name'] !== $name) { continue; }
        $path = $directory . '/' . $table['file']; $output = '';
        foreach (file($path) as $line) {
            $raw = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $row = array_combine($table['columns'], array_map(static fn($v) => $v === null ? null : base64_decode($v, true), $raw));
            $row = $edit($row);
            $output .= json_encode(array_map(static fn($v) => $v === null ? null : base64_encode($v), array_values($row)), JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($path, $output); $table['bytes'] = strlen($output); $table['sha256'] = hash('sha256', $output); return;
    }
    throw new RuntimeException('Missing staged fixture table.');
}
$source = policyDb('dualscan_source'); $target = policyDb('dualscan_target');
$sourceAdapter = new PdoDatabase($source); $targetAdapter = new PdoDatabase($target);
$directory = $runtime . '/tmp/policy-probe-' . bin2hex(random_bytes(8));
$cases = []; $otherStages = [];
try {
    $metadata = $sourceAdapter->exportTo($directory);
    $baseline = $sourceAdapter->prepareAppliancePolicy($directory, $metadata, 'wp_');
    if ($baseline['removedAdministrativeUserIds'] !== ['1', '2']) { throw new RuntimeException('Ordinary editor must be retained in baseline.'); }
    $cases['ordinaryEditorRetained'] = ['passed' => true, 'removedIds' => $baseline['removedAdministrativeUserIds']];
    $originals = []; foreach ($metadata['tables'] as $table) { $originals[$table['file']] = file_get_contents($directory . '/' . $table['file']); }
    $custom = $metadata;
    editStagedTable($directory, $custom, 'wp_options', static function (array $row): array {
        if ($row['option_name'] === 'wp_user_roles') { $roles = unserialize($row['option_value'], ['allowed_classes' => false]); $roles['fixture_custom_manager'] = ['name' => 'Fixture manager', 'capabilities' => ['read' => true, 'manage_options' => true]]; $row['option_value'] = serialize($roles); }
        return $row;
    });
    editStagedTable($directory, $custom, 'wp_usermeta', static function (array $row): array { if ($row['user_id'] === '3' && $row['meta_key'] === 'wp_capabilities') { $row['meta_value'] = serialize(['fixture_custom_manager' => true]); } return $row; });
    $sourceAdapter->validateFrom($directory, $custom);
    $plan = $sourceAdapter->prepareAppliancePolicy($directory, $custom, 'wp_');
    if ($plan['removedAdministrativeUserIds'] !== ['1', '2', '3']) { throw new RuntimeException('Custom administrative role was not detected.'); }
    $cases['customRoleWithManageOptionsDetected'] = ['passed' => true, 'removedIds' => $plan['removedAdministrativeUserIds']];
    foreach ($originals as $file => $bytes) { file_put_contents($directory . '/' . $file, $bytes); }
    $direct = $metadata;
    editStagedTable($directory, $direct, 'wp_usermeta', static function (array $row): array { if ($row['user_id'] === '3' && $row['meta_key'] === 'wp_capabilities') { $row['meta_value'] = serialize(['editor' => true, 'install_plugins' => true]); } return $row; });
    $sourceAdapter->validateFrom($directory, $direct);
    $plan = $sourceAdapter->prepareAppliancePolicy($directory, $direct, 'wp_');
    if ($plan['removedAdministrativeUserIds'] !== ['1', '2', '3']) { throw new RuntimeException('Direct plugin-management capability was not detected.'); }
    $cases['directPluginEscalationCapabilityDetected'] = ['passed' => true, 'removedIds' => $plan['removedAdministrativeUserIds']];
    $target->exec('CREATE TABLE fixture_user_dependency (id INT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,payload VARBINARY(64),FOREIGN KEY(user_id) REFERENCES wp_users(ID) ON DELETE CASCADE) ENGINE=InnoDB');
    $target->exec("INSERT INTO fixture_user_dependency VALUES(1,3,UNHEX('6669787475726500ff'))");
    $expected = $target->query('SELECT id,user_id,HEX(payload) FROM fixture_user_dependency')->fetchAll(PDO::FETCH_NUM);
    $fkStage = $runtime . '/tmp/policy-fk-' . bin2hex(random_bytes(8)); $otherStages[] = $fkStage;
    $fkMetadata = $targetAdapter->exportTo($fkStage);
    expectRefused(static fn() => $targetAdapter->prepareAppliancePolicy($fkStage, $fkMetadata, 'wp_'), 'usersForeignKeyRejectedBeforeDeployment', $cases);
    expectRefused(static fn() => $targetAdapter->finalizeAppliance($baseline), 'usersForeignKeyRejectedBeforeAccountDeletion', $cases);
    if ($expected !== $target->query('SELECT id,user_id,HEX(payload) FROM fixture_user_dependency')->fetchAll(PDO::FETCH_NUM)) { throw new RuntimeException('Foreign-key fixture row changed.'); }
    $cases['foreignKeyPluginRowPreserved'] = ['passed' => true, 'payloadHex' => $expected[0][2]];
    $target->exec('DROP TABLE fixture_user_dependency');
    $target->exec('CREATE TABLE fixture_cross_dependency (id INT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,FOREIGN KEY(user_id) REFERENCES dualscan_source.wp_users(ID)) ENGINE=InnoDB');
    $target->exec('INSERT INTO fixture_cross_dependency VALUES(1,3)');
    foreach (['outbound' => $targetAdapter, 'inbound' => $sourceAdapter] as $direction => $adapter) {
        $stage = $runtime . '/tmp/policy-cross-' . bin2hex(random_bytes(8)); $otherStages[] = $stage;
        expectRefused(static fn() => $adapter->exportTo($stage), 'visibleCrossDatabaseForeignKeyRejected:' . $direction, $cases);
    }
    $report = ['passed' => true, 'scope' => 'Actual MySQL, staged-only roles/capabilities; disposable target FK tables, no source account changes', 'cases' => $cases, 'sourceDatabaseClassSha256' => hash_file('sha256', dirname(__DIR__, 2) . '/includes/class-logical-database.php')];
    file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode(['passed' => true, 'cases' => count($cases), 'report' => $argv[2]]), "\n";
} finally {
    $target->exec('DROP TABLE IF EXISTS fixture_user_dependency'); $target->exec('DROP TABLE IF EXISTS fixture_cross_dependency');
    SafeFiles::remove($directory); foreach ($otherStages as $stage) { SafeFiles::remove($stage); }
}
