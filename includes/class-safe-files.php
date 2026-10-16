<?php
namespace Dualscan\CloudOS;
final class SafeFiles {
    public static function json(array $value): string {
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException $e) { throw new EngineException('A file name or database value cannot be represented safely.', 0, $e); }
    }
    public static function path(string $path): string {
        if ($path === '' || strlen($path) > 4096 || preg_match('/[\\\\\x00-\x1f\x7f:]/', $path) || $path[0] === '/') {
            throw new EngineException('Unsafe image path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new EngineException('Unsafe image path segment.');
            }
        }
        if (!preg_match('//u', $path)) { throw new EngineException('Non-UTF-8 file names are unsupported.'); }
        return $path;
    }
    public static function collisionKey(string $path): string {
        if (class_exists('Normalizer')) { $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C); if ($normalized !== false) { $path = $normalized; } }
        return function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
    }
    public static function privateDirectory(string $directory): string {
        if (is_link($directory)) { throw new EngineException('Private storage must not be a symbolic link.'); }
        $created = !is_dir($directory);
        if ($created && !@mkdir($directory, 0700, true) && !is_dir($directory)) { throw new EngineException('Cannot create private working directory.'); }
        $real = realpath($directory);
        if ($real === false || $real === '/' || !is_writable($real)) { throw new EngineException('Private storage is not writable or is an unsafe filesystem root.'); }
        clearstatcache(true, $real);
        if ((fileperms($real) & 0077) !== 0) { throw new EngineException('Private storage must already have owner-only permissions (chmod 700 on a dedicated directory). Existing directories are never re-permissioned automatically.'); }
        if (function_exists('posix_geteuid') && fileowner($real) !== posix_geteuid()) { throw new EngineException('Private storage must belong to the PHP worker user.'); }
        return rtrim($real, '/');
    }
    public static function inside(string $path, string $root): bool { return $path === $root || str_starts_with($path, rtrim($root, '/') . '/'); }
    public static function writeAll($handle, string $data): void {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) { throw new EngineException('Write failed; check available disk space.'); }
            $offset += $written;
        }
    }
    public static function readExactly($handle, int $length): string {
        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $part = fread($handle, $length - strlen($data));
            if ($part === false) { throw new EngineException('Image read failed.'); }
            $data .= $part;
        }
        if (strlen($data) !== $length) { throw new EngineException('Image is truncated.'); }
        return $data;
    }
    public static function remove(string $path): void {
        if (is_link($path) || is_file($path)) { if (!is_link($path) && function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); } if (!unlink($path)) { throw new EngineException('Cannot remove a staged file.'); } return; }
        if (!is_dir($path)) { return; }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) { self::remove($item->getPathname()); }
        if (!rmdir($path)) { throw new EngineException('Cannot remove a staging directory.'); }
    }
    public static function inventory(string $root, array $excluded = []): array {
        $entries = []; $collisions = [];
        $walk = function (string $directory, string $relative) use (&$walk, &$entries, &$collisions, $excluded): void {
            $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $item) {
                $name = ($relative === '' ? '' : $relative . '/') . $item->getFilename();
                self::path($name);
                foreach ($excluded as $exclude) { if ($name === $exclude || str_starts_with($name, $exclude . '/')) { continue 2; } }
                $fold = self::collisionKey($name); if (isset($collisions[$fold])) { throw new EngineException('Case or Unicode-normalization colliding paths cannot be made portable: ' . $name); } $collisions[$fold] = true;
                if ($item->isLink()) { throw new EngineException('Symbolic links require an explicit external-root migration plan: ' . $name); }
                $stat = lstat($item->getPathname());
                if ($stat === false || !is_readable($item->getPathname())) { throw new EngineException('Unreadable site item: ' . $name); }
                if (!$item->isDir() && !$item->isFile()) { throw new EngineException('Special filesystem objects are unsupported: ' . $name); }
                $entries[$name] = ['kind' => $item->isDir() ? 'directory' : 'file', 'size' => $item->isDir() ? 0 : $stat['size'], 'mode' => $stat['mode'] & 0777, 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'], 'ino' => $stat['ino'], 'dev' => $stat['dev']];
                if (count($entries) > 500000) { throw new EngineException('Site exceeds the 500,000-item safety limit.'); }
                if ($item->isDir()) { $walk($item->getPathname(), $name); }
            }
        };
        $walk($root, ''); ksort($entries, SORT_STRING); return $entries;
    }
    public static function copyStable(string $source, string $target, array $before): array {
        $parent = dirname($target); if (!is_dir($parent) && !mkdir($parent, 0700, true)) { throw new EngineException('Cannot stage a directory.'); }
        $in = fopen($source, 'rb'); $out = fopen($target, 'xb');
        if ($in === false || $out === false) { throw new EngineException('Cannot stage a source file.'); }
        chmod($target, 0600); $hash = hash_init('sha256'); $bytes = 0;
        try {
            while (!feof($in)) { $chunk = fread($in, 1048576); if ($chunk === false) { throw new EngineException('Source file read failed.'); } hash_update($hash, $chunk); self::writeAll($out, $chunk); $bytes += strlen($chunk); }
        } finally { fclose($in); fclose($out); }
        clearstatcache(true, $source); $after = lstat($source);
        foreach (['size', 'mtime', 'ctime', 'ino', 'dev'] as $key) { if ($after === false || $after[$key] !== $before[$key]) { throw new EngineException('Site changed during backup: ' . basename($source)); } }
        if ($bytes !== $before['size']) { throw new EngineException('Source size changed during backup.'); }
        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }
    public static function copyTree(string $source, string $target, array $preserve = []): void {
        $items = self::inventory($source, $preserve);
        foreach ($items as $relative => $item) {
            $destination = $target . '/' . $relative;
            if ($item['kind'] === 'directory') { if (!is_dir($destination) && !mkdir($destination, 0700, true)) { throw new EngineException('Cannot restore a directory.'); } }
            else { self::copyStable($source . '/' . $relative, $destination, $item); if (function_exists('opcache_invalidate')) { @opcache_invalidate($destination, true); } }
        }
        foreach (array_reverse($items, true) as $relative => $item) { chmod($target . '/' . $relative, $item['mode']); touch($target . '/' . $relative, $item['mtime']); }
    }
    public static function clearTree(string $root, array $preserve = []): void {
        $walk = function (string $directory, string $relative) use (&$walk, $preserve): void {
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
                $name = ($relative === '' ? '' : $relative . '/') . $item->getFilename();
                foreach ($preserve as $keep) { if ($name === $keep || str_starts_with($name, $keep . '/')) { continue 2; } }
                $containsKeep = false; foreach ($preserve as $keep) { if (str_starts_with($keep, $name . '/')) { $containsKeep = true; break; } }
                if ($containsKeep && $item->isDir() && !$item->isLink()) { $walk($item->getPathname(), $name); }
                else { self::remove($item->getPathname()); }
            }
        }; $walk($root, '');
    }
}
