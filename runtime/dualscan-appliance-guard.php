<?php
/**
 * Plugin Name: Dualscan Sealed Appliance Runtime
 * Description: Server-installed policy for a public WordPress application image.
 * Version: 1.0.1
 * License: GPL-2.0-or-later
 *
 * There is deliberately no web, option, cookie, account, query-string or captured
 * wp-config.php switch that disables this policy. Recover authoring access with
 * the trusted host's offline CLI, never through an HTTP unlock route.
 */
if (PHP_SAPI === 'cli') { return; }

$dualscanDenyWeb = static function (): void {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Dualscan-Runtime: sealed');
    }
    echo 'This application endpoint is unavailable.';
    exit;
};

$dualscanNormalizePath = static function (string $value): string {
    $value = str_replace('\\', '/', $value);
    for ($index = 0; $index < 4; $index++) { $next = rawurldecode($value); if ($next === $value) { break; } $value = $next; }
    $value = strtolower(str_replace('\\', '/', $value));
    return preg_replace('~/+~', '/', $value) ?? $value;
};

$dualscanMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($dualscanMethod, ['GET', 'HEAD', 'POST'], true)) { $dualscanDenyWeb(); }
if ((defined('WP_ADMIN') && WP_ADMIN) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) { $dualscanDenyWeb(); }

$dualscanPaths = [
    parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/',
    (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
    (string) ($_SERVER['PHP_SELF'] ?? ''),
];
foreach ($dualscanPaths as $dualscanPath) {
    $dualscanPath = $dualscanNormalizePath($dualscanPath);
    if (str_contains($dualscanPath, "\0") || preg_match('~(?:^|/)(?:wp-admin|wp-json|graphql)(?:/|$)|(?:^|/)(?:wp-login|xmlrpc|wp-cron|wp-signup|wp-activate|wp-mail|wp-trackback|wp-comments-post)\.php(?:/|$)~', $dualscanPath)) { $dualscanDenyWeb(); }
}
$dualscanRuntimeRoot = dirname(__DIR__, 2);
$dualscanScript = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
$dualscanFrontController = realpath($dualscanRuntimeRoot . '/index.php');
if ($dualscanScript === '' || $dualscanFrontController === false || realpath($dualscanScript) !== $dualscanFrontController) { $dualscanDenyWeb(); }
foreach (['rest_route', 'graphql', 'graphql_route', 'wc-ajax', 'customize_changeset_uuid', 'customize_theme', 'preview', 'preview_id'] as $dualscanQueryKey) {
    if (array_key_exists($dualscanQueryKey, $_GET) || array_key_exists($dualscanQueryKey, $_POST)) { $dualscanDenyWeb(); }
}

// The trusted wp-config prepend executes before normal WordPress initialization.
// Load only WordPress's hook definitions here; wp-settings uses require_once too.
if (!function_exists('add_action') && is_file($dualscanRuntimeRoot . '/wp-includes/plugin.php')) { require_once $dualscanRuntimeRoot . '/wp-includes/plugin.php'; }
// Later lifecycle checks cover custom REST prefixes and alternative dispatchers.
if (function_exists('add_action')) {
    add_action('admin_init', $dualscanDenyWeb, PHP_INT_MIN);
    add_action('login_init', $dualscanDenyWeb, PHP_INT_MIN);
    add_action('rest_api_init', $dualscanDenyWeb, PHP_INT_MIN);
    add_action('wp_ajax_nopriv_dualscan_no_web_access', $dualscanDenyWeb, PHP_INT_MIN);
}
if (function_exists('add_filter')) {
    add_filter('authenticate', static function ($user) { return new WP_Error('dualscan_sealed_runtime', 'Web sign-in is unavailable.'); }, PHP_INT_MAX);
    add_filter('wp_authenticate_user', static function ($user) { return new WP_Error('dualscan_sealed_runtime', 'Web sign-in is unavailable.'); }, PHP_INT_MAX);
    add_filter('send_auth_cookies', '__return_false', PHP_INT_MAX);
    add_filter('pre_option_users_can_register', '__return_zero', PHP_INT_MAX);
    add_filter('determine_current_user', static function ($userId) { return 0; }, PHP_INT_MAX);
    add_filter('show_admin_bar', '__return_false', PHP_INT_MAX);
    add_filter('xmlrpc_enabled', '__return_false', PHP_INT_MAX);
    add_filter('wp_is_application_passwords_available', '__return_false', PHP_INT_MAX);
    add_filter('rest_authentication_errors', static function ($result) {
        return new WP_Error('dualscan_sealed_runtime', 'This application endpoint is unavailable.', ['status' => 403]);
    }, PHP_INT_MAX);
}
unset($dualscanPaths, $dualscanPath, $dualscanScript, $dualscanRuntimeRoot, $dualscanFrontController, $dualscanMethod, $dualscanQueryKey, $dualscanNormalizePath);
