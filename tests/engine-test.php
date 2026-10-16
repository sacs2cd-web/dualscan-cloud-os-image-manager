<?php
require_once dirname(__DIR__) . '/includes/engine-bootstrap.php';
use Dualscan\CloudOS\DatabaseAdapter;
use Dualscan\CloudOS\EngineException;
use Dualscan\CloudOS\ImageCodec;
use Dualscan\CloudOS\ImageManager;
use Dualscan\CloudOS\LogicalDatabase;
use Dualscan\CloudOS\SafeFiles;
final class FixtureDatabase implements DatabaseAdapter {
    public string $value = 'source database';
    public bool $failOnce = false;
    public bool $failAlways = false;
    public int $sealedPolicies = 0;
    public function identity(): array { return ['database' => 'fixture', 'serverVersion' => 'test']; }
    public function prepareAppliancePolicy(string $directory, array $metadata, string $prefix): array { return ['fixture' => true]; }
    public function finalizeAppliance(array $plan): array { $this->sealedPolicies++; return ['mode' => 'fixture-sealed-principal-policy']; }

    public function exportTo(string $directory, ?callable $progress = null): array {
        SafeFiles::privateDirectory($directory); $path = $directory . '/table-000001.jsonl'; file_put_contents($path, $this->value); chmod($path, 0600);
        return ['version' => 1, 'identity' => $this->identity(), 'tables' => [['file' => 'table-000001.jsonl', 'bytes' => strlen($this->value), 'sha256' => hash('sha256', $this->value)]]];
    }
    public function validateFrom(string $directory, array $metadata): void {
        if (($metadata['version'] ?? null) !== 1 || count($metadata['tables'] ?? []) !== 1 || ($metadata['tables'][0]['file'] ?? '') !== 'table-000001.jsonl') { throw new EngineException('Invalid fixture database.'); }
        $path = $directory . '/table-000001.jsonl'; if (!is_file($path) || !hash_equals($metadata['tables'][0]['sha256'], hash_file('sha256', $path))) { throw new EngineException('Fixture database hash mismatch.'); }
    }
    public function restoreFrom(string $directory, array $metadata): void { $this->validateFrom($directory, $metadata); $this->value = file_get_contents($directory . '/table-000001.jsonl'); if ($this->failOnce || $this->failAlways) { $this->failOnce = false; throw new EngineException('Injected database restore failure.'); } }
}
final class SchemaFixtureDatabase extends LogicalDatabase {
    protected function rows(string $sql): array { return []; }
    protected function execute(string $sql): void {}
}
final class VerifiedRowsFixtureDatabase extends LogicalDatabase {
    public array $values = [];
    protected function rows(string $sql): array {
        if (preg_match('/^SELECT .+ FROM `fixture` LIMIT ([0-9]+),1$/D', $sql, $matches)) { $index = (int) $matches[1]; return array_key_exists($index, $this->values) ? [['v' => $this->values[$index]]] : []; }
        if (str_starts_with($sql, 'SHOW CREATE TABLE')) { return [['Table' => 'fixture', 'Create Table' => 'CREATE TABLE `fixture` (`v` text) ENGINE=InnoDB']]; }
        if ($sql === 'SHOW FULL TABLES') { return [['Tables_in_fixture' => 'fixture', 'Table_type' => 'BASE TABLE']]; }
        throw new RuntimeException('Unexpected fixture query: ' . $sql);
    }
    protected function execute(string $sql): void {}
}
$base = sys_get_temp_dir() . '/dualscan-engine-' . bin2hex(random_bytes(8)); mkdir($base, 0700);
$tests = []; $password = 'Synthetic image password 2026';
$assert = static function ($condition, $message = 'Assertion failed'): void { if (!$condition) { throw new RuntimeException($message); } };
$test = static function (string $name, callable $body) use (&$tests): void { $start = microtime(true); $body(); $tests[] = ['name' => $name, 'passed' => true, 'seconds' => round(microtime(true) - $start, 3)]; fwrite(STDERR, 'PASS ' . $name . "\n"); };
try {
    $source = $base . '/source'; $target = $base . '/target'; $storage = $base . '/private'; mkdir($source); mkdir($target); mkdir($source . '/empty'); mkdir($source . '/wp-content'); mkdir($source . '/wp-content/uploads');
    file_put_contents($source . '/.htaccess', 'private dot file'); file_put_contents($source . '/wp-config.php', '<?php /* synthetic */'); file_put_contents($source . '/wp-content/uploads/élève-画像.bin', str_repeat("\x00\x01\xff\xfe hello", 250000));
    $db = new FixtureDatabase(); $manager = new ImageManager($db, $storage); $created = null;
    $test('complete filesystem + binary database roundtrip', function () use ($manager, $source, $target, $password, $db, $assert, &$created): void {
        $created = $manager->create($source, $password, ['siteUrl' => 'https://fixture.invalid', 'tablePrefix' => 'wp_']);
        $assert(pathinfo($created['path'], PATHINFO_EXTENSION) === 'dualscan'); $assert((fileperms($created['path']) & 0777) === 0600);
        $manifest = $manager->inspect($created['path'], $password); $assert(count($manifest['entries']) === 7);
        file_put_contents($target . '/old.txt', 'old'); $db->value = 'before restore';
        $result = $manager->restore($created['path'], $password, $target, ['authoringRecovery' => true]); $assert($result['restored']); $assert(is_file($result['rollbackImage'])); $assert(!file_exists($target . '/old.txt'));
        $assert($db->value === 'source database'); $assert(is_dir($target . '/empty')); $assert(hash_file('sha256', $target . '/wp-content/uploads/élève-画像.bin') === hash_file('sha256', $source . '/wp-content/uploads/élève-画像.bin')); $assert(file_get_contents($target . '/.htaccess') === 'private dot file');
    });
    $test('preserved target wp-config.php', function () use ($manager, $created, $password, $target, $assert): void { file_put_contents($target . '/wp-config.php', 'destination database credentials'); $result = $manager->restore($created['path'], $password, $target, ['authoringRecovery' => true, 'preservePaths' => ['wp-config.php']]); $assert(file_get_contents($target . '/wp-config.php') === 'destination database credentials'); $assert($result['preservedPaths'] === ['wp-config.php']); });
    $test('URL and prefix mismatch cannot mutate target', function () use ($manager, $created, $password, $target, $assert, $db): void { $old = $db->value; foreach (['requireSiteUrl' => 'https://other.invalid', 'requireTablePrefix' => 'other_'] as $key => $value) { try { $manager->restore($created['path'], $password, $target, ['authoringRecovery' => true, $key => $value]); throw new RuntimeException('Mismatch accepted'); } catch (EngineException $e) {} } $assert($db->value === $old); $assert(file_get_contents($target . '/wp-config.php') === 'destination database credentials'); });
    $test('rollback after post-database failure restores original database and files', function () use ($manager, $created, $password, $target, $assert, $db): void { file_put_contents($target . '/rollback-original.bin', "\x00private previous bytes\xff"); $db->value = 'previous target database'; $db->failOnce = true; try { $manager->restore($created['path'], $password, $target, ['authoringRecovery' => true]); throw new RuntimeException('Injected failure not observed'); } catch (EngineException $e) { $assert(str_contains($e->getMessage(), 'previous files and database were restored')); } $assert($db->value === 'previous target database'); $assert(file_get_contents($target . '/rollback-original.bin') === "\x00private previous bytes\xff"); $assert(file_get_contents($target . '/wp-config.php') === 'destination database credentials'); });
    $test('controlled rollback keeps preserved config and live maintenance guard', function () use ($manager, $created, $password, $target, $assert, $db): void {
        file_put_contents($target . '/wp-config.php', 'preserved target configuration'); $db->value = 'before guarded rollback'; $db->failOnce = true;
        try { $manager->restore($created['path'], $password, $target, ['authoringRecovery' => true, 'preservePaths' => ['wp-config.php', '.maintenance'], 'progress' => function ($phase, $done) use ($target): void { if ($phase === 'restoring' && $done === 0) { file_put_contents($target . '/.maintenance', 'live maintenance guard'); } }]); throw new RuntimeException('Injected failure not observed'); }
        catch (EngineException $e) { $assert($e->getCode() !== EngineException::ROLLBACK_FAILED); }
        $assert(file_get_contents($target . '/.maintenance') === 'live maintenance guard'); $assert(file_get_contents($target . '/wp-config.php') === 'preserved target configuration'); $assert($db->value === 'before guarded rollback'); unlink($target . '/.maintenance');
        file_put_contents($target . '/wp-config.php', 'destination database credentials');
    });
    $test('failed rollback returns stable error code and leaves maintenance guard', function () use ($manager, $created, $password, $target, $assert, $db): void {
        $db->failAlways = true;
        try { $manager->restore($created['path'], $password, $target, ['authoringRecovery' => true, 'preservePaths' => ['.maintenance'], 'progress' => function ($phase, $done) use ($target): void { if ($phase === 'restoring' && $done === 0) { file_put_contents($target . '/.maintenance', 'manual recovery guard'); } }]); throw new RuntimeException('Injected rollback failure not observed'); }
        catch (EngineException $e) { $assert($e->getCode() === EngineException::ROLLBACK_FAILED); $assert(str_contains($e->getMessage(), 'encrypted rollback image')); }
        finally { $db->failAlways = false; }
        $assert(file_get_contents($target . '/.maintenance') === 'manual recovery guard'); unlink($target . '/.maintenance');
    });
    $bytes = file_get_contents($created['path']); $headerLength = unpack('N', substr($bytes, strlen(ImageCodec::MAGIC), 4))[1]; $chunkStart = strlen(ImageCodec::MAGIC) + 4 + $headerLength;
    $variants = ['wrong password' => [$bytes, 'Not the actual password'], 'ciphertext tamper' => [substr_replace($bytes, chr(ord($bytes[$chunkStart + 20]) ^ 1), $chunkStart + 20, 1), $password], 'tag tamper' => [substr_replace($bytes, chr(ord($bytes[$chunkStart + 4 + ImageCodec::CHUNK]) ^ 1), $chunkStart + 4 + ImageCodec::CHUNK, 1), $password], 'truncated image' => [substr($bytes, 0, -1), $password], 'appended bytes' => [$bytes . 'extra', $password], 'header version tamper' => [substr_replace($bytes, '9', strpos($bytes, '"version":1') + 10, 1), $password], 'oversized clear header' => [ImageCodec::MAGIC . pack('N', 4097) . str_repeat('x', 4097), $password]];
    foreach ($variants as $name => [$variant, $variantPassword]) {
        $test($name . ' rejected without public mutation', function () use ($manager, $variant, $variantPassword, $base, $target, $assert, $db): void { $path = $base . '/bad.dualscan'; file_put_contents($path, $variant); $before = $db->value; $oldFile = file_get_contents($target . '/wp-config.php'); try { $manager->restore($path, $variantPassword, $target, ['authoringRecovery' => true]); throw new RuntimeException('Damaged image accepted'); } catch (EngineException $e) {} $assert($db->value === $before); $assert(file_get_contents($target . '/wp-config.php') === $oldFile); });
    }
    $manifest = $manager->inspect($created['path'], $password);
    $malicious = static function (array $modified, string $data = '') use ($base, $password): string { $text = SafeFiles::json($modified); file_put_contents($base . '/forged.bin', pack('N', strlen($text)) . $text . $data); @unlink($base . '/forged.dualscan'); ImageCodec::encrypt($base . '/forged.bin', $base . '/forged.dualscan', $password); return $base . '/forged.dualscan'; };
    foreach (['site/../../escape.php', '/absolute.php', 'site/C:\\escape', 'site//empty', "site/bad\x00name"] as $path) { $test('authenticated unsafe path rejected ' . bin2hex($path), function () use ($manifest, $path, $malicious, $manager, $password): void { $m = $manifest; $m['entries'] = [['path' => $path, 'kind' => 'directory', 'bytes' => 0, 'mode' => 0755, 'mtime' => 1]]; try { $manager->inspect($malicious($m), $password); throw new RuntimeException('Unsafe path accepted'); } catch (EngineException $e) {} }); }
    $test('authenticated duplicate entries rejected', function () use ($manifest, $malicious, $manager, $password): void { $m = $manifest; $entry = ['path' => 'site/same', 'kind' => 'directory', 'bytes' => 0, 'mode' => 0755, 'mtime' => 1]; $m['entries'] = [$entry, $entry]; try { $manager->inspect($malicious($m), $password); throw new RuntimeException('Duplicate accepted'); } catch (EngineException $e) {} });
    $test('authenticated file shadows directory rejected', function () use ($manifest, $malicious, $manager, $password): void { $m = $manifest; $m['entries'] = [['path' => 'site/parent', 'kind' => 'file', 'bytes' => 0, 'sha256' => hash('sha256', ''), 'mode' => 0600, 'mtime' => 1], ['path' => 'site/parent/child', 'kind' => 'directory', 'bytes' => 0, 'mode' => 0700, 'mtime' => 1]]; try { $manager->inspect($malicious($m), $password); throw new RuntimeException('Shadow accepted'); } catch (EngineException $e) {} });
    $test('authenticated file hash mismatch rejected', function () use ($manifest, $malicious, $manager, $password): void { $m = $manifest; $m['entries'] = [['path' => 'site/one', 'kind' => 'file', 'bytes' => 1, 'sha256' => hash('sha256', 'b'), 'mode' => 0600, 'mtime' => 1]]; try { $manager->inspect($malicious($m, 'a'), $password); throw new RuntimeException('Bad hash accepted'); } catch (EngineException $e) {} });
    $test('authenticated oversized payload declaration rejected', function () use ($manifest, $malicious, $manager, $password): void { $m = $manifest; $m['entries'] = [['path' => 'site/huge', 'kind' => 'file', 'bytes' => ImageCodec::MAX_BYTES, 'sha256' => str_repeat('a', 64), 'mode' => 0600, 'mtime' => 1]]; try { $manager->inspect($malicious($m), $password); throw new RuntimeException('Oversized accepted'); } catch (EngineException $e) {} });
    $test('source symbolic link rejected', function () use ($source, $manager, $password): void { symlink('/etc/passwd', $source . '/linked'); try { $manager->create($source, $password); throw new RuntimeException('Symlink accepted'); } catch (EngineException $e) {} finally { unlink($source . '/linked'); } });
    $test('unreadable source fails explicitly', function () use ($source, $manager, $password): void { file_put_contents($source . '/unreadable', 'unreadable'); chmod($source . '/unreadable', 0000); try { $manager->create($source, $password); throw new RuntimeException('Unreadable source accepted'); } catch (EngineException $e) {} finally { chmod($source . '/unreadable', 0600); unlink($source . '/unreadable'); } });
    $test('source changes during backup reject image', function () use ($source, $manager, $password): void { $changed = false; try { $manager->create($source, $password, ['progress' => function ($phase) use ($source, &$changed): void { if ($phase === 'files' && !$changed) { $changed = true; file_put_contents($source . '/mutation.txt', 'new file during snapshot'); } }]); throw new RuntimeException('Mutating source accepted'); } catch (EngineException $e) {} finally { @unlink($source . '/mutation.txt'); } });
    $test('private staging inside web root rejected', function () use ($source, $password, $db): void { $manager = new ImageManager($db, $source . '/unsafe-private'); try { $manager->create($source, $password); throw new RuntimeException('Public storage accepted'); } catch (EngineException $e) {} finally { SafeFiles::remove($source . '/unsafe-private'); } });
    $test('existing public directory permissions never changed', function () use ($base, $assert): void { $path = $base . '/not-private'; mkdir($path, 0755); try { SafeFiles::privateDirectory($path); throw new RuntimeException('Public directory accepted'); } catch (EngineException $e) {} clearstatcache(true, $path); $assert((fileperms($path) & 0777) === 0755); });
    $test('portable table schema accepted by validation positive control', function () use ($base): void { file_put_contents($base . '/table-000001.jsonl', ''); $adapter = new SchemaFixtureDatabase(); $adapter->validateFrom($base, ['version' => 1, 'rowEncoding' => 'raw-column-bytes-utc-v1', 'tables' => [['name' => 'fixture', 'createSql' => 'CREATE TABLE `fixture` (`v` text) ENGINE=InnoDB', 'columns' => ['v'], 'columnTypes' => ['text'], 'file' => 'table-000001.jsonl', 'rows' => 0, 'bytes' => 0, 'sha256' => hash('sha256', '')]]]); });
    foreach (["CREATE TABLE `fixture` (`v` text) ENGINE=MyISAM", "CREATE TABLE `fixture` (`v` text) ENGINE=InnoDB; DROP TABLE `other`", "CREATE TABLE `fixture` (`v` text) ENGINE=InnoDB DATA DIRECTORY='/tmp'", "CREATE TABLE `fixture` (`v` text) ENGINE=InnoDB /* executable comment */", "CREATE TABLE `fixture` (`v` text) ENGINE=InnoDB AS SELECT * FROM `secret`"] as $sql) {
        $test('unsafe database schema rejected ' . substr(hash('sha256', $sql), 0, 10), function () use ($sql, $base): void { $adapter = new SchemaFixtureDatabase(); $meta = ['version' => 1, 'rowEncoding' => 'raw-column-bytes-utc-v1', 'tables' => [['name' => 'fixture', 'createSql' => $sql, 'columns' => ['v'], 'columnTypes' => ['text'], 'file' => 'table-000001.jsonl', 'rows' => 0, 'bytes' => 0, 'sha256' => hash('sha256', '')]]]; try { $adapter->validateFrom($base, $meta); throw new RuntimeException('Unsafe SQL schema accepted'); } catch (EngineException $e) {} });
    }
    foreach ([['expected' => ['a', 'b'], 'actual' => ['b', 'a'], 'pass' => true, 'name' => 'row verification accepts reordered exact rows'], ['expected' => ['a', 'a'], 'actual' => ['b', 'b'], 'pass' => false, 'name' => 'row verification rejects duplicate-pair XOR cancellation'], ['expected' => ['1'], 'actual' => ['49'], 'pass' => false, 'name' => 'row verification rejects numeric value coercion']] as $case) {
        $test($case['name'], function () use ($case, $base): void {
            $directory = $base . '/verify-rows'; if (!is_dir($directory)) { mkdir($directory, 0700); } $data = ''; foreach ($case['expected'] as $value) { $data .= json_encode([base64_encode($value)]) . "\n"; } file_put_contents($directory . '/table-000001.jsonl', $data);
            $table = ['name' => 'fixture', 'file' => 'table-000001.jsonl', 'columns' => ['v'], 'createSql' => 'CREATE TABLE `fixture` (`v` text) ENGINE=InnoDB'];
            $adapter = new VerifiedRowsFixtureDatabase(); $adapter->values = $case['actual']; $method = new ReflectionMethod(LogicalDatabase::class, 'verifyRestoredRows'); $method->setAccessible(true);
            try { $method->invoke($adapter, $directory, ['tables' => [$table]]); if (!$case['pass']) { throw new RuntimeException('Bad restored rows accepted'); } } catch (EngineException $e) { if ($case['pass']) { throw $e; } }
        });
    }
    $test('default restore seals only the restored copy and declares exact three policy files', function () use ($manager, $created, $password, $base, $source, $assert, $db): void {
        $target = $base . '/sealed-default'; mkdir($target); $sourceConfig = file_get_contents($source . '/wp-config.php'); $beforePolicies = $db->sealedPolicies;
        $result = $manager->restore($created['path'], $password, $target);
        $assert($result['deployment']['mode'] === 'sealed-public-runtime'); $assert(count($result['deployment']['files']) === 3); $assert($db->sealedPolicies === $beforePolicies + 1);
        foreach ($result['deployment']['files'] as $file) { $assert(hash_file('sha256', $target . '/' . $file['path']) === $file['sha256']); }
        $assert(str_contains(file_get_contents($target . '/wp-config.php'), "require_once __DIR__ . '/wp-content/mu-plugins/dualscan-appliance.php'"));
        $assert(file_get_contents($source . '/wp-config.php') === $sourceConfig); $assert(!file_exists($source . '/wp-content/mu-plugins/dualscan-appliance.php'));
    });
    $test('post-seal failure fully recovers unsealed authoring target even with preserved policy paths', function () use ($manager, $created, $password, $base, $assert, $db): void {
        $target = $base . '/authoring-rollback'; mkdir($target); $config = "<?php /* previous authoring credentials */"; file_put_contents($target . '/wp-config.php', $config); chmod($target . '/wp-config.php', 0600); file_put_contents($target . '/previous.txt', 'previous authoring file'); $db->value = 'previous authoring DB';
        try { $manager->restore($created['path'], $password, $target, ['preservePaths' => ['wp-config.php', 'wp-content/mu-plugins/dualscan-appliance.php'], 'progress' => static function ($phase): void { if ($phase === 'complete') { throw new EngineException('Injected post-seal failure'); } }]); throw new RuntimeException('Post-seal failure missing'); } catch (EngineException $e) { $assert($e->getCode() !== EngineException::ROLLBACK_FAILED); }
        $assert(file_get_contents($target . '/wp-config.php') === $config); $assert(file_get_contents($target . '/previous.txt') === 'previous authoring file'); $assert(!file_exists($target . '/wp-content/mu-plugins/dualscan-appliance.php')); $assert($db->value === 'previous authoring DB');
    });
    $test('strict-types config remains valid and owner-only mode never widened', function () use ($base, $assert): void {
        $target = $base . '/strict-config'; mkdir($target); file_put_contents($target . '/wp-config.php', "<?php declare(strict_types=1);\n/* original body */"); chmod($target . '/wp-config.php', 0600);
        $prepared = Dualscan\CloudOS\ApplianceDeployment::prepare($target, file_get_contents($target . '/wp-config.php'));
        $result = Dualscan\CloudOS\ApplianceDeployment::apply($target, $prepared); $config = file_get_contents($target . '/wp-config.php');
        $assert(strpos($config, 'declare(strict_types=1);') < strpos($config, 'require_once')); $assert((fileperms($target . '/wp-config.php') & 0777) === 0600);
        $authoring = Dualscan\CloudOS\ApplianceDeployment::prepareAuthoring($config); Dualscan\CloudOS\ApplianceDeployment::apply($target, $authoring, true);
        $assert(!str_contains(file_get_contents($target . '/wp-config.php'), 'DUALSCAN_APPLIANCE_BOOTSTRAP')); $assert(!file_exists($target . '/wp-content/mu-plugins/dualscan-appliance.php')); $assert(str_contains(file_get_contents($target . '/wp-config.php'), 'original body'));
    });
    $test('unsafe config prefixes and namespaces are rejected before deployment', function () use ($base): void {
        foreach (["\xef\xbb\xbf<?php echo 'bom';", "leading output<?php", "<?php namespace Untrusted;", "<?php declare(ticks=1) { }"] as $config) { try { Dualscan\CloudOS\ApplianceDeployment::prepare($base, $config); throw new RuntimeException('Unsafe config accepted'); } catch (EngineException $e) {} }
    });
    $test('all private plaintext stages removed', function () use ($storage, $assert): void { $assert(glob($storage . '/stage-*') === []); });
    echo json_encode(['passed' => count($tests), 'failed' => 0, 'tests' => $tests, 'fixture' => 'Synthetic binary/UTF-8/dotfile/empty-directory source; fake DB unit adapter. Separate real MySQL integration covers SQL semantics.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} finally { SafeFiles::remove($base); }
