<?php
/** Real WordPress role/capability policy check on the disposable local site. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 3) { exit(2); }
$root = realpath($argv[1]);
if ($root === false || !str_contains($root, '/dualscan-cloud-os-image-manager-runtime/')) { throw new RuntimeException('Only the isolated test site is supported.'); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8767'; $_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_plugin_active('dualscan-cloud-os-image-manager/dualscan-cloud-os-image-manager.php')) { throw new RuntimeException('Manager must be activated first.'); }
$report = ['passed' => true, 'runtime' => 'Real WordPress 7.1.3 roles, users and hooks', 'mode' => 'Original authoring installation before image restoration', 'cases' => []];
foreach ([['dualscan_admin', 'LocalAdminOnly2026!', 'administrator', true], ['dualscan_client', 'LocalClientOnly2026!', 'administrator', false], ['dualscan_editor', 'LocalEditorOnly2026!', 'editor', false]] as [$login, $password, $role, $owner]) {
    $user = get_user_by('login', $login);
    if (!$user) {
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.invalid', 'role' => $role]);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $user = get_user_by('id', $id);
    }
    wp_set_current_user($user->ID);
    $plugins = apply_filters('all_plugins', get_plugins());
    $case = ['role' => $role, 'imageCapability' => current_user_can('dualscan_manage_images'), 'pluginActivationCapability' => current_user_can('activate_plugins'), 'pluginInstallationCapability' => current_user_can('install_plugins'), 'pluginEditingCapability' => current_user_can('edit_plugins'), 'settingsCapability' => current_user_can('manage_options'), 'pluginListCount' => count($plugins)];
    $ok = $case['imageCapability'] === $owner && $case['pluginActivationCapability'] === $owner && $case['pluginInstallationCapability'] === $owner && $case['pluginEditingCapability'] === $owner && $case['settingsCapability'] === $owner && ($owner ? $case['pluginListCount'] >= 3 : $case['pluginListCount'] === 0);
    if (!$ok) { throw new RuntimeException('Permission case failed: ' . $login . ' ' . json_encode($case)); }
    $case['passed'] = true;
    $report['cases'][$login] = $case;
}
file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode(['passed' => true, 'cases' => count($report['cases']), 'report' => $argv[2]]), "\n";
