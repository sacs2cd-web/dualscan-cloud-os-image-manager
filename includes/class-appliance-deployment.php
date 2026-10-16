<?php
namespace Dualscan\CloudOS;
final class ApplianceDeployment {
    public const POLICY_VERSION = 1;
    public const GUARD_PATH = 'wp-content/mu-plugins/dualscan-appliance.php';
    public const POLICY_PATH = 'wp-content/mu-plugins/dualscan-appliance-policy.json';
    public static function descriptor(): array {
        return ['policyVersion' => self::POLICY_VERSION, 'defaultMode' => 'sealed-public-runtime', 'guardSource' => 'trusted restoring manager', 'administrativePrincipals' => 'removed on sealed restore', 'allWebAuthentication' => false, 'wordpressWebAdministration' => false, 'wordpressWebLogin' => false, 'wordpressWebApis' => false, 'publicMethods' => ['GET', 'HEAD', 'POST'], 'hostFrontControllerRequired' => true];
    }
    /** Hold trusted bytes before the target can overwrite the manager's package. */
    public static function prepare(string $root, string $configBytes): array {
        $path = dirname(__DIR__) . '/runtime/dualscan-appliance-guard.php';
        if (!is_file($path) || is_link($path) || !is_readable($path)) { throw new EngineException('The trusted appliance runtime policy is missing.'); }
        $guard = file_get_contents($path);
        if ($guard === false || strlen($guard) < 1000 || strlen($guard) > 65536 || !str_starts_with($guard, '<?php')) { throw new EngineException('The trusted appliance runtime policy is invalid.'); }
        $policy = SafeFiles::json(['schemaVersion' => 1, 'product' => 'Dualscan Cloud OS Image Manager', 'mode' => 'sealed-public-runtime', 'policyVersion' => self::POLICY_VERSION, 'guardSha256' => hash('sha256', $guard), 'administration' => 'offline host CLI only', 'publicMethods' => ['GET', 'HEAD', 'POST'], 'hostFrontControllerRequired' => true]) . "\n";
        return ['guard' => $guard, 'policy' => $policy, 'config' => self::patchConfig($root, $configBytes), 'originalConfigSha256' => hash('sha256', $configBytes)];
    }
    public static function prepareAuthoring(string $configBytes): array {
        return ['config' => self::stripBootstrap($configBytes), 'originalConfigSha256' => hash('sha256', $configBytes)];
    }
    private static function stripBootstrap(string $bytes): string {
        $begin = '/* DUALSCAN_APPLIANCE_BOOTSTRAP_V1_BEGIN */'; $end = '/* DUALSCAN_APPLIANCE_BOOTSTRAP_V1_END */';
        $tokens = token_get_all($bytes); $offset = 0; $start = null; $ranges = [];
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_COMMENT && $text === $begin) { if ($start !== null) { throw new EngineException('Invalid previous appliance bootstrap markers.'); } $start = $offset; }
            if (is_array($token) && $token[0] === T_COMMENT && $text === $end) { if ($start === null) { throw new EngineException('Invalid previous appliance bootstrap markers.'); } $ranges[] = [$start, $offset + strlen($text)]; $start = null; }
            $offset += strlen($text);
        }
        if ($start !== null) { throw new EngineException('Unterminated previous appliance bootstrap.'); }
        foreach (array_reverse($ranges) as [$start, $finish]) { $bytes = substr($bytes, 0, $start) . substr($bytes, $finish); }
        return $bytes;
    }
    private static function patchConfig(string $root, string $bytes): string {
        if (strlen($bytes) > 2097152) { throw new EngineException('wp-config.php exceeds the supported 2 MiB limit.'); }
        $bytes = self::stripBootstrap($bytes);
        $begin = '/* DUALSCAN_APPLIANCE_BOOTSTRAP_V1_BEGIN */'; $end = '/* DUALSCAN_APPLIANCE_BOOTSTRAP_V1_END */';
        $tokens = token_get_all($bytes);
        if (!$tokens || !is_array($tokens[0]) || $tokens[0][0] !== T_OPEN_TAG || !str_starts_with($bytes, '<?php')) { throw new EngineException('Sealed deployment requires wp-config.php to begin with a PHP opening tag, without a BOM or leading output.'); }
        foreach ($tokens as $token) {
            if (!is_array($token)) { continue; }
            if ($token[0] === T_NAMESPACE) { throw new EngineException('Namespaced wp-config.php layouts require a dedicated deployment adapter.'); }
            if (in_array($token[0], [T_STRING, T_CONSTANT_ENCAPSED_STRING], true) && in_array(strtoupper(trim($token[1], "'\"")), ['CUSTOM_USER_TABLE', 'CUSTOM_USER_META_TABLE'], true)) { throw new EngineException('Custom WordPress principal tables require a dedicated sealed deployment adapter.'); }
        }
        $offset = strlen($tokens[0][1]); $insert = $offset; $index = 1;
        while ($index < count($tokens)) {
            while ($index < count($tokens) && is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $offset += strlen($tokens[$index][1]); $index++; }
            if ($index >= count($tokens) || !is_array($tokens[$index]) || $tokens[$index][0] !== T_DECLARE) { break; }
            $declaration = '';
            while ($index < count($tokens)) { $token = $tokens[$index++]; $text = is_array($token) ? $token[1] : $token; $declaration .= $text; $offset += strlen($text); if ($text === ';') { break; } if ($text === '{' || $text === '?>') { throw new EngineException('Block-style PHP declarations in wp-config.php are unsupported.'); } }
            if (!preg_match('/^declare\s*\(\s*strict_types\s*=\s*[01]\s*\)\s*;$/D', trim($declaration))) { throw new EngineException('Only a leading strict_types declaration is supported in wp-config.php.'); }
            $insert = $offset;
        }
        $bootstrap = "\n" . $begin . "\nrequire_once " . "__DIR__ . " . var_export('/' . self::GUARD_PATH, true) . ";\n" . $end . "\n";
        return substr($bytes, 0, $insert) . $bootstrap . substr($bytes, $insert);
    }
    public static function policyPaths(): array { return [self::GUARD_PATH, self::POLICY_PATH]; }
    private static function atomicWrite(string $root, string $relative, string $bytes): void {
        $path = $root . '/' . $relative; $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0755, true)) { throw new EngineException('Cannot install the sealed runtime directory.'); }
        for ($cursor = $parent; $cursor !== $root; $cursor = dirname($cursor)) { if (is_link($cursor) || !SafeFiles::inside($cursor, $root)) { throw new EngineException('Unsafe appliance policy directory.'); } }
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new EngineException('The appliance policy path is not a regular file.'); }
        $mode = $relative === 'wp-config.php' ? (is_file($path) ? fileperms($path) & 0777 : 0600) : 0644;
        $temporary = $parent . '/.dualscan-policy-' . bin2hex(random_bytes(12)) . '.part'; $handle = fopen($temporary, 'xb');
        if (!$handle) { throw new EngineException('Cannot create appliance policy output.'); } chmod($temporary, 0600);
        try { SafeFiles::writeAll($handle, $bytes); if (!fflush($handle)) { throw new EngineException('Cannot flush appliance policy.'); } if (function_exists('fsync')) { fsync($handle); } }
        catch (\Throwable $error) { fclose($handle); @unlink($temporary); throw $error; }
        fclose($handle);
        if (!chmod($temporary, $mode) || !rename($temporary, $path)) { @unlink($temporary); throw new EngineException('Cannot activate the sealed appliance policy.'); }
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) !== strlen($bytes) || !hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $path))) { throw new EngineException('Installed appliance policy verification failed.'); }
        if (function_exists('opcache_invalidate') && str_ends_with($relative, '.php')) { opcache_invalidate($path, true); }
    }
    public static function apply(string $root, array $prepared, bool $authoringRecovery = false): array {
        if ($authoringRecovery) {
            self::remove($root); $files = [];
            if (is_string($prepared['config'] ?? null) && hash('sha256', $prepared['config']) !== ($prepared['originalConfigSha256'] ?? null)) { self::atomicWrite($root, 'wp-config.php', $prepared['config']); $files[] = ['path' => 'wp-config.php', 'sha256' => hash('sha256', $prepared['config']), 'bytes' => strlen($prepared['config']), 'originalSha256' => $prepared['originalConfigSha256']]; }
            return ['mode' => 'authoring-recovery', 'policyVersion' => self::POLICY_VERSION, 'files' => $files, 'removedPolicyPaths' => self::policyPaths()];
        }
        if (!is_string($prepared['guard'] ?? null) || !is_string($prepared['policy'] ?? null) || !is_string($prepared['config'] ?? null)) { throw new EngineException('Missing held appliance policy bytes.'); }
        self::atomicWrite($root, self::GUARD_PATH, $prepared['guard']); self::atomicWrite($root, self::POLICY_PATH, $prepared['policy']); self::atomicWrite($root, 'wp-config.php', $prepared['config']);
        $files = [];
        foreach ([self::GUARD_PATH => $prepared['guard'], self::POLICY_PATH => $prepared['policy'], 'wp-config.php' => $prepared['config']] as $relative => $bytes) { $file = ['path' => $relative, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]; if ($relative === 'wp-config.php') { $file['originalSha256'] = $prepared['originalConfigSha256']; } $files[] = $file; }
        return ['mode' => 'sealed-public-runtime', 'policyVersion' => self::POLICY_VERSION, 'files' => $files];
    }
    /** Never infer an unseal request from imported content, a WP option or HTTP. */
    public static function remove(string $root): void {
        foreach (self::policyPaths() as $relative) {
            $path = $root . '/' . $relative;
            if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new EngineException('Cannot safely replace an appliance policy path.'); }
            if (is_file($path) && !unlink($path)) { throw new EngineException('Cannot remove the prior appliance policy.'); }
            if (function_exists('opcache_invalidate') && str_ends_with($relative, '.php')) { opcache_invalidate($path, true); }
        }
    }
}
