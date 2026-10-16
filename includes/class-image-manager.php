<?php
namespace Dualscan\CloudOS;
final class ImageManager {
    private DatabaseAdapter $database;
    private string $storage;
    private $lock = null;
    public function __construct(DatabaseAdapter $database, string $privateStorage) {
        if (PHP_INT_SIZE < 8 || !extension_loaded('openssl')) { throw new EngineException('64-bit PHP 8.1+ with OpenSSL is required.'); }
        $this->database = $database; $this->storage = SafeFiles::privateDirectory($privateStorage);
    }
    public function storageDirectory(): string { return $this->storage; }
    private function siteRoot(string $root, bool $create = false): string {
        if (is_link($root)) { throw new EngineException('The site root must not be a symbolic link.'); }
        if ($create && !is_dir($root) && !mkdir($root, 0755, true)) { throw new EngineException('Cannot create target site directory.'); }
        $real = realpath($root);
        if ($real === false || !is_dir($real) || !is_readable($real)) { throw new EngineException('The site root is unavailable.'); }
        if (SafeFiles::inside($this->storage, $real) || SafeFiles::inside($real, $this->storage)) { throw new EngineException('Private image storage and the WordPress document root must be separate.'); }
        return rtrim($real, '/');
    }
    private function acquire(): void {
        $this->lock = fopen($this->storage . '/engine.lock', 'c');
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) { if (is_resource($this->lock)) { fclose($this->lock); } $this->lock = null; throw new EngineException('Another image operation is already running.'); }
        chmod($this->storage . '/engine.lock', 0600);
    }
    private function release(): void { if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); } $this->lock = null; }
    private function stage(): string { return SafeFiles::privateDirectory($this->storage . '/stage-' . bin2hex(random_bytes(16))); }
    private static function notify(array $options, string $phase, int $done, int $total): void { if (isset($options['progress']) && is_callable($options['progress'])) { ($options['progress'])($phase, $done, $total); } }
    public function create(string $siteRoot, string $password, array $options = []): array {
        $root = $this->siteRoot($siteRoot); $this->acquire();
        try { return $this->createInternal($root, $password, $options); } finally { $this->release(); }
    }
    private function createInternal(string $root, string $password, array $options): array {
        $stage = $this->stage(); $output = null;
        try {
            self::notify($options, 'database', 0, 0);
            $db = $this->database->exportTo($stage . '/database', static function ($phase, $done, $total) use ($options): void { self::notify($options, $phase, $done, $total); });
            $this->database->validateFrom($stage . '/database', $db);
            $inventory = SafeFiles::inventory($root); $entries = []; $total = count($inventory); $done = 0; $bytes = 0;
            SafeFiles::privateDirectory($stage . '/site');
            foreach ($inventory as $relative => $item) {
                $entry = ['path' => 'site/' . $relative, 'kind' => $item['kind'], 'bytes' => $item['size'], 'mode' => $item['mode'], 'mtime' => $item['mtime']];
                if ($item['kind'] === 'directory') { SafeFiles::privateDirectory($stage . '/site/' . $relative); }
                else { $entry += SafeFiles::copyStable($root . '/' . $relative, $stage . '/site/' . $relative, $item); $bytes += $entry['bytes']; }
                if ($bytes > ImageCodec::MAX_BYTES) { throw new EngineException('Site exceeds the 1 TiB image limit.'); }
                $entries[] = $entry; self::notify($options, 'files', ++$done, $total);
            }
            if (SafeFiles::inventory($root) !== $inventory) { throw new EngineException('The site changed during backup. Stop background writers and try again.'); }
            foreach ($entries as $entry) { if ($entry['kind'] === 'file' && !hash_equals($entry['sha256'], (string) hash_file('sha256', $root . '/' . substr($entry['path'], 5)))) { throw new EngineException('File contents changed during backup.'); } }
            foreach (SafeFiles::inventory($stage . '/database') as $relative => $item) {
                if ($item['kind'] !== 'file') { throw new EngineException('Unexpected database stage layout.'); }
                $entries[] = ['path' => 'database/' . $relative, 'kind' => 'file', 'bytes' => $item['size'], 'sha256' => hash_file('sha256', $stage . '/database/' . $relative), 'mode' => 0600, 'mtime' => $item['mtime']];
            }
            usort($entries, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
            $manifest = ['formatVersion' => 1, 'product' => 'Dualscan Cloud OS Image Manager', 'createdAt' => gmdate('c'), 'deployment' => ApplianceDeployment::descriptor(), 'source' => ['siteUrl' => (string) ($options['siteUrl'] ?? ''), 'tablePrefix' => (string) ($options['tablePrefix'] ?? ''), 'database' => $db['identity'] ?? $this->database->identity()], 'scope' => ['allRootFiles' => true, 'allDatabaseBaseTables' => true, 'excludedPaths' => [], 'databaseSnapshot' => 'InnoDB consistent transaction', 'filesystemSnapshot' => 'Copied and rehashed; source membership and stat changes reject the job', 'crossResourceAtomicity' => 'Maintenance and stopped external writers are required for application-level consistency. No server configuration, DNS, mailboxes, grants or external roots are captured.'], 'database' => $db, 'entries' => $entries];
            $payload = $stage . '/payload.bin'; $this->writePayload($stage, $payload, $manifest);
            $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(8)); $output = $this->storage . '/' . $id . '.dualscan';
            self::notify($options, 'encrypting', 0, filesize($payload)); ImageCodec::encrypt($payload, $output, $password);
            self::notify($options, 'complete', 1, 1);
            return ['id' => $id, 'path' => $output, 'manifest' => $manifest, 'sha256' => hash_file('sha256', $output), 'bytes' => filesize($output)];
        } catch (\Throwable $e) { if ($output !== null) { @unlink($output); } throw $e; }
        finally { SafeFiles::remove($stage); }
    }
    private function writePayload(string $stage, string $payload, array $manifest): void {
        $text = SafeFiles::json($manifest); if (strlen($text) > 67108864) { throw new EngineException('Manifest exceeds the 64 MiB safety limit.'); }
        $out = fopen($payload, 'xb'); if (!$out) { throw new EngineException('Cannot create image payload.'); } chmod($payload, 0600);
        try {
            SafeFiles::writeAll($out, pack('N', strlen($text)) . $text);
            foreach ($manifest['entries'] as $entry) {
                if ($entry['kind'] !== 'file') { continue; }
                $in = fopen($stage . '/' . $entry['path'], 'rb'); if (!$in) { throw new EngineException('Cannot read staged file.'); }
                try { while (!feof($in)) { $chunk = fread($in, ImageCodec::CHUNK); if ($chunk === false) { throw new EngineException('Cannot assemble staged image.'); } SafeFiles::writeAll($out, $chunk); } } finally { fclose($in); }
            }
        } finally { fclose($out); }
    }
    private function decode(string $image, string $password, string $stage): array {
        $payload = $stage . '/payload.bin'; ImageCodec::decrypt($image, $payload, $password);
        $in = fopen($payload, 'rb'); if (!$in) { throw new EngineException('Cannot read authenticated payload.'); }
        try {
            $length = unpack('N', SafeFiles::readExactly($in, 4))[1]; if ($length < 2 || $length > 67108864) { throw new EngineException('Invalid manifest length.'); }
            try { $manifest = json_decode(SafeFiles::readExactly($in, $length), true, 128, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new EngineException('Invalid manifest JSON.', 0, $e); }
            if (!is_array($manifest) || ($manifest['formatVersion'] ?? null) !== 1 || ($manifest['product'] ?? '') !== 'Dualscan Cloud OS Image Manager' || !is_array($manifest['entries'] ?? null) || !array_is_list($manifest['entries']) || count($manifest['entries']) > 510000 || !is_array($manifest['database'] ?? null) || !is_array($manifest['source'] ?? null)) { throw new EngineException('Invalid image manifest.'); }
            $seen = []; $caseSeen = []; $files = []; $total = 4 + $length;
            foreach ($manifest['entries'] as $entry) {
                if (!is_array($entry) || !is_string($entry['path'] ?? null) || !in_array($entry['kind'] ?? '', ['file', 'directory'], true) || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0 || $entry['bytes'] > ImageCodec::MAX_BYTES || !is_int($entry['mode'] ?? null) || $entry['mode'] < 0 || $entry['mode'] > 0777 || !is_int($entry['mtime'] ?? null)) { throw new EngineException('Invalid image entry.'); }
                $path = SafeFiles::path($entry['path']);
                if ((!str_starts_with($path, 'site/') && !str_starts_with($path, 'database/')) || (str_starts_with($path, 'database/') && !preg_match('/^database\/table-[0-9]{6}\.jsonl$/D', $path))) { throw new EngineException('Invalid image entry domain.'); }
                $fold = SafeFiles::collisionKey($path);
                if (isset($seen[$path]) || isset($caseSeen[$fold])) { throw new EngineException('Duplicate or case-colliding image path.'); } $seen[$path] = $entry['kind']; $caseSeen[$fold] = true;
                if ($entry['kind'] === 'file') { if (!preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')) { throw new EngineException('Invalid image entry hash.'); } $files[$path] = true; $total += $entry['bytes']; }
                elseif ($entry['bytes'] !== 0) { throw new EngineException('Directory with file data is invalid.'); }
                if ($total > ImageCodec::MAX_BYTES) { throw new EngineException('Image manifest exceeds its size bound.'); }
            }
            foreach (array_keys($seen) as $path) { for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) { if (isset($files[$parent])) { throw new EngineException('A file path shadows an image directory.'); } } }
            if ($total !== filesize($payload)) { throw new EngineException('Image manifest and payload lengths disagree.'); }
            SafeFiles::privateDirectory($stage . '/site'); SafeFiles::privateDirectory($stage . '/database');
            foreach ($manifest['entries'] as $entry) {
                $path = $stage . '/' . $entry['path'];
                if ($entry['kind'] === 'directory') { SafeFiles::privateDirectory($path); continue; }
                SafeFiles::privateDirectory(dirname($path)); $out = fopen($path, 'xb'); if (!$out) { throw new EngineException('Cannot create validation stage file.'); } chmod($path, 0600); $hash = hash_init('sha256');
                try { for ($left = $entry['bytes']; $left > 0;) { $chunk = SafeFiles::readExactly($in, min(ImageCodec::CHUNK, $left)); SafeFiles::writeAll($out, $chunk); hash_update($hash, $chunk); $left -= strlen($chunk); } } finally { fclose($out); }
                if (!hash_equals($entry['sha256'], hash_final($hash))) { throw new EngineException('Authenticated manifest file hash mismatch.'); }
            }
            if (fread($in, 1) !== '') { throw new EngineException('Unexpected extra payload data.'); }
            $this->database->validateFrom($stage . '/database', $manifest['database']);
            $expectedDb = []; foreach ($manifest['database']['tables'] ?? [] as $table) { $expectedDb[] = 'database/' . $table['file']; } sort($expectedDb, SORT_STRING);
            $actualDb = []; foreach ($manifest['entries'] as $entry) { if (str_starts_with($entry['path'], 'database/')) { $actualDb[] = $entry['path']; } } sort($actualDb, SORT_STRING);
            if ($expectedDb !== $actualDb) { throw new EngineException('Database manifest has unreferenced or missing snapshots.'); }
            return $manifest;
        } finally { fclose($in); @unlink($payload); }
    }
    public function inspect(string $imagePath, string $password): array {
        $this->acquire(); $stage = $this->stage();
        try { return $this->decode($imagePath, $password, $stage); } finally { SafeFiles::remove($stage); $this->release(); }
    }
    public function restore(string $imagePath, string $password, string $targetRoot, array $options = []): array {
        $root = $this->siteRoot($targetRoot, true); if (!is_writable($root)) { throw new EngineException('Restore target is not writable.'); }
        $this->acquire(); $stage = $this->stage(); $rollbackStage = null; $rollback = null; $mutated = false;
        try {
            self::notify($options, 'validating', 0, 1); $manifest = $this->decode($imagePath, $password, $stage);
            foreach (['requireSiteUrl' => 'siteUrl', 'requireTablePrefix' => 'tablePrefix'] as $option => $field) { if (isset($options[$option]) && $options[$option] !== ($manifest['source'][$field] ?? null)) { throw new EngineException('Image ' . $field . ' does not match this site. Use a separately prepared migration environment.'); } }
            $preserve = $options['preservePaths'] ?? []; if (!is_array($preserve)) { throw new EngineException('Invalid restore preservation policy.'); }
            foreach ($preserve as $keep) { if (!is_string($keep)) { throw new EngineException('Invalid preserved path.'); } SafeFiles::path($keep); }
            $authoringRecovery = ($options['authoringRecovery'] ?? false) === true;
            $configPreserved = false; foreach ($preserve as $keep) { if ($keep === 'wp-config.php') { $configPreserved = true; } }
            $configPath = ($configPreserved ? $root : $stage . '/site') . '/wp-config.php';
            if (!$authoringRecovery && (!is_file($configPath) || is_link($configPath) || !is_readable($configPath))) { throw new EngineException('Sealed WordPress restore requires a regular readable wp-config.php inside the root.'); }
            $configBytes = is_file($configPath) && !is_link($configPath) && is_readable($configPath) ? file_get_contents($configPath) : '';
            if ($configBytes === false) { throw new EngineException('Cannot hold the target WordPress configuration.'); }
            $heldDeployment = $authoringRecovery ? ApplianceDeployment::prepareAuthoring($configBytes) : ApplianceDeployment::prepare($root, $configBytes);
            if (!$authoringRecovery && (!method_exists($this->database, 'prepareAppliancePolicy') || !method_exists($this->database, 'finalizeAppliance'))) { throw new EngineException('This database adapter does not support sealed WordPress deployment.'); }
            $principalPolicy = $authoringRecovery ? null : $this->database->prepareAppliancePolicy($stage . '/database', $manifest['database'], (string) ($manifest['source']['tablePrefix'] ?? ''));
            $rollbackPreserve = array_values(array_filter($preserve, static function (string $keep): bool {
                foreach (array_merge(ApplianceDeployment::policyPaths(), ['wp-config.php']) as $policyPath) { if ($keep === $policyPath || str_starts_with($policyPath, $keep . '/') || str_starts_with($keep, $policyPath . '/')) { return false; } }
                return true;
            }));
            // Source and destination are completely validated before the first public file or DB mutation.
            if (method_exists($this->database, 'preflightRestoreFrom')) { $this->database->preflightRestoreFrom($stage . '/database', $manifest['database']); }
            SafeFiles::inventory($root); self::notify($options, 'rollback-snapshot', 0, 1);
            $rollback = $this->createInternal($root, $password, ['siteUrl' => (string) ($options['currentSiteUrl'] ?? $manifest['source']['siteUrl'] ?? ''), 'tablePrefix' => (string) ($options['currentTablePrefix'] ?? $manifest['source']['tablePrefix'] ?? '')]);
            $rollbackStage = $this->stage(); $rollbackManifest = $this->decode($rollback['path'], $password, $rollbackStage);
            self::notify($options, 'restoring', 0, 2); $mutated = true;
            SafeFiles::clearTree($root, $preserve); SafeFiles::copyTree($stage . '/site', $root, $preserve);
            foreach (array_reverse($manifest['entries']) as $entry) { if (!str_starts_with($entry['path'], 'site/')) { continue; } $relative = substr($entry['path'], 5); $skip = false; foreach ($preserve as $keep) { if ($relative === $keep || str_starts_with($relative, $keep . '/')) { $skip = true; break; } } if (!$skip) { chmod($root . '/' . $relative, $entry['mode']); touch($root . '/' . $relative, $entry['mtime']); } }
            self::notify($options, 'restoring', 1, 2); $this->database->restoreFrom($stage . '/database', $manifest['database']);
            self::notify($options, 'deploying', 0, 1);
            $databasePolicy = $authoringRecovery ? null : $this->database->finalizeAppliance($principalPolicy);
            $deployment = ApplianceDeployment::apply($root, $heldDeployment, $authoringRecovery);
            if ($databasePolicy !== null) { $deployment['databasePolicy'] = $databasePolicy; }
            self::notify($options, 'complete', 2, 2);
            return ['restored' => true, 'manifest' => $manifest, 'deployment' => $deployment, 'preservedPaths' => $preserve, 'rollbackImage' => $rollback['path'], 'rollbackSha256' => $rollback['sha256']];
        } catch (\Throwable $error) {
            if ($mutated && $rollback !== null && $rollbackStage !== null) {
                try {
                    SafeFiles::clearTree($root, $rollbackPreserve); SafeFiles::copyTree($rollbackStage . '/site', $root, $rollbackPreserve);
                    foreach (array_reverse($rollbackManifest['entries']) as $entry) { if (!str_starts_with($entry['path'], 'site/')) { continue; } $relative = substr($entry['path'], 5); $skip = false; foreach ($rollbackPreserve as $keep) { if ($relative === $keep || str_starts_with($relative, $keep . '/')) { $skip = true; break; } } if (!$skip) { chmod($root . '/' . $relative, $entry['mode']); touch($root . '/' . $relative, $entry['mtime']); } }
                    $this->database->restoreFrom($rollbackStage . '/database', $rollbackManifest['database']);
                } catch (\Throwable $rollbackError) { throw new EngineException('Restore failed and automatic rollback also failed. Keep maintenance active and use the encrypted rollback image ' . basename($rollback['path']) . '. Original error: ' . $error->getMessage() . '; rollback: ' . $rollbackError->getMessage(), EngineException::ROLLBACK_FAILED, $error); }
                throw new EngineException('Restore failed; the previous files and database were restored. ' . $error->getMessage(), 0, $error);
            }
            throw $error;
        } finally { SafeFiles::remove($stage); if ($rollbackStage !== null) { SafeFiles::remove($rollbackStage); } $this->release(); }
    }
}
