#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/engine-bootstrap.php';
use Dualscan\CloudOS\ServerKeyring;
try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['status', 'init', 'import', 'export'], true)) { throw new RuntimeException("Usage: php bin/keyring.php status | init | import PRIVATE_KEY_FILE | export NEW_PRIVATE_KEY_FILE\nSet DUALSCAN_PRIVATE_STORAGE and DUALSCAN_PUBLIC_ROOT. DUALSCAN_PLUGIN_FOLDER defaults to the installed package folder.\nExport writes a raw 32-byte mode-600 key to a dedicated mode-700 directory. Treat it as a secret; transfer it separately from .dualscan images. Existing keys are never replaced automatically."); }
    $required = static function (string $name): string { $value = getenv($name); if ($value === false || $value === '') { throw new RuntimeException('Missing environment setting: ' . $name); } return $value; };
    $root = realpath($required('DUALSCAN_PUBLIC_ROOT'));
    if ($root === false || !is_dir($root)) { throw new RuntimeException('DUALSCAN_PUBLIC_ROOT must identify the existing public document root.'); }
    $keyring = new ServerKeyring($required('DUALSCAN_PRIVATE_STORAGE'), (string) (getenv('DUALSCAN_PLUGIN_FOLDER') ?: basename(dirname(__DIR__))), [$root, dirname(__DIR__)]);
    if ($command === 'init') { $keyring->resolveOrCreate(); $result = $keyring->status(); }
    elseif ($command === 'status') { $result = $keyring->status(); }
    elseif ($command === 'import') { if (!isset($argv[2])) { throw new RuntimeException('Provide a private raw key file to import.'); } $result = $keyring->importFrom($argv[2]); }
    else { if (!isset($argv[2])) { throw new RuntimeException('Provide a new private key-backup file path.'); } $result = $keyring->exportTo($argv[2]); }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) { fwrite(STDERR, json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n"); exit(1); }
