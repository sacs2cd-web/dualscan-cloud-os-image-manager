<?php
namespace Dualscan\CloudOS;
/** Server-owned secrets. The public plugin folder name supplies context, never entropy. */
final class ServerKeyring {
    public const MASTER_BYTES = 32;
    private string $storage;
    private string $directory;
    private string $folder;
    private string $keyPath;
    private array $publicRoots;
    public function __construct(string $privateStorage, string $packageFolder, array $publicRoots = []) {
        if (!function_exists('hash_hkdf')) { throw new EngineException('Server-managed encryption requires PHP HKDF support.'); }
        $this->folder = self::normalizeFolder($packageFolder); $this->publicRoots = [];
        foreach ($publicRoots as $publicRoot) {
            if (!is_string($publicRoot) || $publicRoot === '') { continue; }
            $real = realpath($publicRoot); if ($real !== false) { $this->publicRoots[] = rtrim($real, '/'); }
        }
        $this->assertNotPublic($privateStorage);
        $this->storage = SafeFiles::privateDirectory($privateStorage); $this->assertNotPublic($this->storage);
        $this->directory = $this->storage . '/keyring';
        if (is_link($this->directory)) { throw new EngineException('The server keyring directory must not be a symbolic link.'); }
        $this->assertNotPublic($this->directory);
        $this->directory = SafeFiles::privateDirectory($this->directory);
        $this->keyPath = $this->directory . '/' . $this->folder . '.key';
    }
    public static function normalizeFolder(string $folder): string {
        if (strlen($folder) < 1 || strlen($folder) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $folder) || str_contains($folder, '..')) { throw new EngineException('Use the installed plugin folder basename as the server key context. Paths and traversal are not accepted.'); }
        return strtolower($folder);
    }
    private function assertNotPublic(string $path): void {
        if ($path === '' || $path[0] !== '/' || preg_match('~(?:^|/)\.\.?(/|$)~', $path)) { throw new EngineException('Server key storage must use an absolute dedicated private path.'); }
        $ancestor = $path; $tail = [];
        while (!file_exists($ancestor) && !is_link($ancestor)) { $tail[] = basename($ancestor); $parent = dirname($ancestor); if ($parent === $ancestor) { break; } $ancestor = $parent; }
        $real = realpath($ancestor); if ($real === false) { throw new EngineException('Cannot resolve server key storage.'); }
        $resolved = rtrim($real, '/') . ($tail ? '/' . implode('/', array_reverse($tail)) : '');
        foreach ($this->publicRoots as $root) { if (SafeFiles::inside($resolved, $root)) { throw new EngineException('Server keys must stay outside every public document root.'); } }
    }
    private static function checkFile(string $path): void {
        clearstatcache(true, $path); $stat = lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600 || !is_readable($path)) { throw new EngineException('Server key files must be regular owner-only files with mode 600, not symbolic links.'); }
        if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) { throw new EngineException('Server key files must belong to the PHP worker user.'); }
    }
    private function locked(callable $operation) {
        $path = $this->directory . '/.keyring.lock';
        if (file_exists($path) || is_link($path)) { self::checkFile($path); }
        $previousUmask = umask(0077);
        try { $handle = @fopen($path, 'x+b'); } finally { umask($previousUmask); }
        if ($handle === false) { self::checkFile($path); $handle = fopen($path, 'r+b'); }
        if (!$handle) { throw new EngineException('Cannot open the private server keyring lock.'); }
        if (!flock($handle, LOCK_EX)) { fclose($handle); throw new EngineException('Cannot lock the private server keyring.'); }
        try { self::checkFile($path); return $operation(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    private static function wipe(string &$bytes): void { if ($bytes !== '' && function_exists('sodium_memzero')) { sodium_memzero($bytes); } else { $bytes = ''; } }
    private function readMaster(): string {
        if (!file_exists($this->keyPath) && !is_link($this->keyPath)) { throw new EngineException('This server has no key for plugin folder ' . $this->folder . '. Provision the matching private server key before opening an image.'); }
        self::checkFile($this->keyPath);
        if (filesize($this->keyPath) !== self::MASTER_BYTES) { throw new EngineException('The server key has an invalid length. Restore the matching 32-byte private key backup.'); }
        $bytes = file_get_contents($this->keyPath);
        if ($bytes === false || strlen($bytes) !== self::MASTER_BYTES) { throw new EngineException('Cannot read the private server key.'); }
        return $bytes;
    }
    private function derive(string $master): string {
        return bin2hex(hash_hkdf('sha256', $master, 32, "Dualscan server-folder encryption v1\0" . $this->folder, 'Dualscan Cloud OS Image Manager'));
    }
    private function identifier(string $master): string {
        return substr(hash_hmac('sha256', "Dualscan server-folder key identifier v1\0" . $this->folder, $master), 0, 32);
    }
    private static function writeSecret(string $path, string $master): void {
        if (file_exists($path) || is_link($path)) { throw new EngineException('The private key destination already exists; keys are never overwritten automatically.'); }
        $handle = fopen($path, 'xb'); if (!$handle) { throw new EngineException('Cannot create the private server key.'); }
        try {
            if (!chmod($path, 0600)) { throw new EngineException('Cannot protect the private server key.'); }
            SafeFiles::writeAll($handle, $master); if (!fflush($handle)) { throw new EngineException('Cannot flush the private server key.'); } if (function_exists('fsync')) { fsync($handle); }
        } catch (\Throwable $error) { fclose($handle); @unlink($path); throw $error; }
        fclose($handle); self::checkFile($path);
        if (!hash_equals(hash('sha256', $master), (string) hash_file('sha256', $path))) { @unlink($path); throw new EngineException('Private server key readback verification failed.'); }
    }
    public function resolveOrCreate(): string {
        return $this->locked(function (): string {
            if (!file_exists($this->keyPath) && !is_link($this->keyPath)) { $master = random_bytes(self::MASTER_BYTES); try { self::writeSecret($this->keyPath, $master); } finally { self::wipe($master); } }
            $master = $this->readMaster(); try { return $this->derive($master); } finally { self::wipe($master); }
        });
    }
    public function requireExisting(): string {
        return $this->locked(function (): string { $master = $this->readMaster(); try { return $this->derive($master); } finally { self::wipe($master); } });
    }
    public function status(): array {
        return $this->locked(function (): array {
            if (!file_exists($this->keyPath) && !is_link($this->keyPath)) { return ['folder' => $this->folder, 'state' => 'missing', 'keyId' => null, 'keyBytes' => self::MASTER_BYTES, 'derivation' => 'HKDF-SHA256']; }
            $master = $this->readMaster(); try { return ['folder' => $this->folder, 'state' => 'ready', 'keyId' => $this->identifier($master), 'keyBytes' => self::MASTER_BYTES, 'derivation' => 'HKDF-SHA256']; } finally { self::wipe($master); }
        });
    }
    public function importFrom(string $sourceKeyFile): array {
        $this->assertNotPublic($sourceKeyFile); self::checkFile($sourceKeyFile);
        if (filesize($sourceKeyFile) !== self::MASTER_BYTES) { throw new EngineException('Import requires a raw 32-byte private master key.'); }
        $master = file_get_contents($sourceKeyFile); if ($master === false || strlen($master) !== self::MASTER_BYTES) { throw new EngineException('Cannot read imported server key.'); }
        try {
            $this->locked(function () use ($master): void {
                if (file_exists($this->keyPath) || is_link($this->keyPath)) { $current = $this->readMaster(); try { if (!hash_equals($current, $master)) { throw new EngineException('A different server key already exists. Export and retain it before provisioning a new isolated keyring.'); } } finally { self::wipe($current); } }
                else { self::writeSecret($this->keyPath, $master); }
            });
        } finally { self::wipe($master); }
        return $this->status();
    }
    public function exportTo(string $destinationKeyFile): array {
        $this->assertNotPublic($destinationKeyFile); SafeFiles::privateDirectory(dirname($destinationKeyFile));
        return $this->locked(function () use ($destinationKeyFile): array {
            $master = $this->readMaster();
            try { self::writeSecret($destinationKeyFile, $master); return ['folder' => $this->folder, 'keyId' => $this->identifier($master), 'exported' => true, 'path' => $destinationKeyFile]; }
            finally { self::wipe($master); }
        });
    }
}
