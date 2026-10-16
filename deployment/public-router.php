<?php
declare(strict_types=1);
/** Local qualification router. Production uses the supplied Nginx configuration. */
$deny = static function (): never {
    http_response_code(403); header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store'); header('X-Dualscan-Runtime: sealed');
    echo 'This application endpoint is unavailable.'; exit;
};
$root = realpath((string) (getenv('DUALSCAN_SITE_ROOT') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')));
if ($root === false || !is_file($root . '/index.php')) { $deny(); }
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
for ($i = 0; $i < 4; $i++) { $decoded = rawurldecode($path); if ($decoded === $path) { break; } $path = $decoded; }
if (preg_match('~[\\\\\x00-\x1f\x7f]|(?:^|/)\.{1,2}(?:/|$)~', $path) || !str_starts_with($path, '/')) { $deny(); }
$path = preg_replace('~/+~', '/', $path) ?? $path;
if (preg_match('~(?:^|/)(?:\.[^/]*|wp-admin|wp-json|graphql)(?:/|$)|(?:^|/)(?:wp-login|xmlrpc|wp-cron|wp-signup|wp-activate|wp-mail|wp-trackback|wp-comments-post)\.php(?:/|$)~i', $path)) { $deny(); }
if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), ['GET', 'HEAD', 'POST'], true)) { $deny(); }
foreach (['rest_route','graphql','graphql_route','wc-ajax','customize_changeset_uuid','customize_theme','preview','preview_id'] as $key) {
    if (array_key_exists($key, $_GET) || array_key_exists($key, $_POST)) { $deny(); }
}
if (preg_match('~\.(?:php\d*|phtml|phar|inc)(?:/|$)~i', $path) && $path !== '/index.php') { $deny(); }
$asset = $root . $path;
if (is_file($asset)) {
    if ($path !== '/index.php') {
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'], true) || !preg_match('~\.(?:css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp3|m4a|flac|ogg|wav|mp4|webm|vtt|pdf)$~i', $path)) { $deny(); }
        $real = realpath($asset);
        if ($real === false || !str_starts_with($real, $root . '/')) { $deny(); }
        for ($cursor = $asset; $cursor !== $root; $cursor = dirname($cursor)) { if (is_link($cursor)) { $deny(); } }
        return false;
    }
}
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';
