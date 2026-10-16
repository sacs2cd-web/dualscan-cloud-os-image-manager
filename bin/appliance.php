#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$command = $argv[1] ?? '';
if ($command === 'status') {
    require_once dirname(__DIR__) . '/includes/engine-bootstrap.php';
    $root = isset($argv[2]) ? realpath($argv[2]) : false;
    if ($root === false) { fwrite(STDERR, "Provide an existing WordPress root.\n"); exit(1); }
    $files = [];
    foreach (Dualscan\CloudOS\ApplianceDeployment::policyPaths() as $relative) { $path = $root . '/' . $relative; $files[] = ['path' => $relative, 'present' => is_file($path) && !is_link($path), 'sha256' => is_file($path) && !is_link($path) ? hash_file('sha256', $path) : null]; }
    $config = is_file($root . '/wp-config.php') ? file_get_contents($root . '/wp-config.php') : '';
    echo json_encode(['files' => $files, 'earlyBootstrapPresent' => str_contains((string) $config, 'DUALSCAN_APPLIANCE_BOOTSTRAP_V1_BEGIN'), 'note' => 'Offline file status does not replace an HTTP policy test.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit;
}
if (!in_array($command, ['restore', 'recover-authoring'], true)) { fwrite(STDERR, "Usage: php bin/appliance.php restore IMAGE ROOT --confirm-replace [--preserve-config]\n       php bin/appliance.php recover-authoring IMAGE ROOT --confirm-replace --confirm-authoring [--preserve-config]\n       php bin/appliance.php status ROOT\nThe restore command seals the restored runtime and removes administrative principals.\n"); exit(1); }
if ($command === 'recover-authoring' && !in_array('--confirm-authoring', $argv, true)) { fwrite(STDERR, "Offline authoring recovery requires --confirm-authoring. Use only on a trusted authoring host.\n"); exit(1); }
$argv[1] = 'restore';
if ($command === 'recover-authoring') { $argv[] = '--authoring-recovery'; }
require __DIR__ . '/dualscan.php';
