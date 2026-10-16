#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/engine-bootstrap.php';
use Dualscan\CloudOS\ImageManager;
use Dualscan\CloudOS\PdoDatabase;
use Dualscan\CloudOS\ServerKeyring;
try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['backup', 'inspect', 'restore'], true)) { throw new RuntimeException("Usage: php bin/dualscan.php backup SITE_ROOT | inspect IMAGE | restore IMAGE TARGET_ROOT --confirm-replace [--preserve-config] [--authoring-recovery]\nServer-folder key mode is the default; --server-folder-key makes it explicit. Legacy offline recovery only: --password-mode.\nSet DUALSCAN_DB_DSN, DUALSCAN_DB_USER, DUALSCAN_DB_PASSWORD, DUALSCAN_PRIVATE_STORAGE and DUALSCAN_PUBLIC_ROOT; optional DUALSCAN_PLUGIN_FOLDER, DUALSCAN_SERVER_KEY_FILE, DUALSCAN_SITE_URL and DUALSCAN_TABLE_PREFIX."); }
    $required = static function (string $name): string { $value = getenv($name); if ($value === false || $value === '') { throw new RuntimeException('Missing environment setting: ' . $name); } return $value; };
    $passwordMode = in_array('--password-mode', $argv, true);
    if ($passwordMode && in_array('--server-folder-key', $argv, true)) { throw new RuntimeException('Choose either server-folder key mode or explicit legacy password mode.'); }
    $storage = $required('DUALSCAN_PRIVATE_STORAGE');
    if ($passwordMode) { $password = $required('DUALSCAN_IMAGE_PASSWORD'); }
    else {
        $publicRoot = realpath($required('DUALSCAN_PUBLIC_ROOT'));
        if ($publicRoot === false || !is_dir($publicRoot)) { throw new RuntimeException('DUALSCAN_PUBLIC_ROOT must identify the existing public document root.'); }
        $publicRoots = [$publicRoot, dirname(__DIR__)];
        if ($command === 'backup' && isset($argv[2])) { $publicRoots[] = $argv[2]; }
        if ($command === 'restore' && isset($argv[3])) { $publicRoots[] = $argv[3]; }
        $folder = (string) (getenv('DUALSCAN_PLUGIN_FOLDER') ?: basename(dirname(__DIR__)));
        $keyring = new ServerKeyring($storage, $folder, $publicRoots);
        $provisionedKey = getenv('DUALSCAN_SERVER_KEY_FILE');
        if ($provisionedKey !== false && $provisionedKey !== '') { $keyring->importFrom($provisionedKey); }
        $password = $command === 'backup' ? $keyring->resolveOrCreate() : $keyring->requireExisting();
    }
    $pdo = new PDO($required('DUALSCAN_DB_DSN'), $required('DUALSCAN_DB_USER'), (string) getenv('DUALSCAN_DB_PASSWORD'), [PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
    $manager = new ImageManager(new PdoDatabase($pdo), $storage);
    $options = ['siteUrl' => (string) getenv('DUALSCAN_SITE_URL'), 'tablePrefix' => (string) (getenv('DUALSCAN_TABLE_PREFIX') ?: 'wp_'), 'progress' => static function ($phase, $done, $total): void { fwrite(STDERR, json_encode(['phase' => $phase, 'done' => $done, 'total' => $total]) . "\n"); }];
    if ($command === 'backup') { if (!isset($argv[2])) { throw new RuntimeException('A site root is required.'); } $result = $manager->create($argv[2], $password, $options); }
    elseif ($command === 'inspect') { if (!isset($argv[2])) { throw new RuntimeException('An image path is required.'); } $result = $manager->inspect($argv[2], $password); }
    else {
        if (!isset($argv[2], $argv[3]) || !in_array('--confirm-replace', $argv, true)) { throw new RuntimeException('Restore replaces destination files and every base table in the destination database; provide IMAGE TARGET_ROOT --confirm-replace.'); }
        $options['preservePaths'] = in_array('--preserve-config', $argv, true) ? ['wp-config.php'] : [];
        $options['authoringRecovery'] = in_array('--authoring-recovery', $argv, true);
        $result = $manager->restore($argv[2], $password, $argv[3], $options);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) { fwrite(STDERR, json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n"); exit(1); }
