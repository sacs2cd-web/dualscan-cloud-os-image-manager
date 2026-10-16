<?php
/** Create deterministic synthetic fixtures in an isolated, disposable WordPress install. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php fixtures.php DISPOSABLE_WORDPRESS_ROOT\n");
    exit(2);
}
$root = realpath($argv[1]);
if ($root === false || !str_contains($root, 'dualscan-cloud-os-image-manager-runtime')) {
    throw new RuntimeException('Refusing to modify a non-test installation.');
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8767';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
global $wpdb;
$table = $wpdb->prefix . 'dualscan_fixture';
$wpdb->query("DROP TABLE IF EXISTS `$table`");
$sql = "CREATE TABLE `$table` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
 `payload` longblob NOT NULL,
 `optional_value` text NULL,
 `amount` decimal(30,12) NOT NULL,
 `serialized` longtext NOT NULL,
 `latin_text` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
 `latin_label` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
 `json_value` json NULL,
 `timestamp_value` timestamp NULL DEFAULT NULL,
 `unsigned_max` bigint unsigned NOT NULL DEFAULT 0,
 `bits8` bit(8) NOT NULL DEFAULT b'00000000',
 `bit_one` bit(1) NOT NULL DEFAULT b'0',
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if ($wpdb->query($sql) === false) {
    throw new RuntimeException($wpdb->last_error);
}
$binary = str_repeat(implode('', array_map('chr', range(0, 255))), 257);
$url = get_option('home');
$rows = [
    ['label' => "Música — 日本語 — 🟣", 'payload' => $binary, 'optional_value' => null,
     'amount' => '18446744073709551.123456789012',
     'serialized' => serialize(['url' => $url . '/uploads/été.jpg', 'emoji' => '💎', 'nested' => ['quote' => "a'b\\c\0"]])],
    ['label' => "quotes ' \" slash \\ newline\n", 'payload' => "\0\xff\xfe\n\r'\"\\",
     'optional_value' => '', 'amount' => '-0.000000000001', 'serialized' => serialize(['empty' => '', 'null' => null])],
];
foreach ($rows as $row) {
    if ($wpdb->insert($table, $row, ['%s', '%s', '%s', '%s', '%s']) === false) {
        throw new RuntimeException($wpdb->last_error);
    }
}
// Preserve storage bytes in mixed-character-set plugin tables, not the connection's UTF-8 projection.
if ($wpdb->query("UPDATE `$table` SET latin_text=UNHEX('e9f1ff'),latin_label=UNHEX('e9f1ff')") === false) {
    throw new RuntimeException($wpdb->last_error);
}
$json = json_encode(['unicode' => '日本語 💎', 'nested' => ['slash' => 'a/b\\c', 'null' => null, 'number' => 1.2345, 'array' => [true, 0, -2]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if ($wpdb->query("UPDATE `$table` SET json_value=CONVERT(UNHEX('" . bin2hex($json) . "') USING utf8mb4),timestamp_value=FROM_UNIXTIME(1700000000),unsigned_max=18446744073709551615,bits8=UNHEX('ff'),bit_one=UNHEX('01') WHERE id=1") === false) {
    throw new RuntimeException($wpdb->last_error);
}
// A plugin's custom table does not have to use the WordPress table prefix.
$wpdb->query('CREATE TABLE IF NOT EXISTS independent_plugin_data (id INT PRIMARY KEY, value LONGBLOB NOT NULL) ENGINE=InnoDB');
$wpdb->query('DELETE FROM independent_plugin_data');
$wpdb->insert('independent_plugin_data', ['id' => 1, 'value' => "independent\0plugin\xff"], ['%d', '%s']);
$owner = get_user_by('login', 'dualscan_admin');
$postId = wp_insert_post(['post_title' => 'Dualscan fixture — café 💎', 'post_content' => "Exact content\n日本語\nhttps://example.invalid/fixture", 'post_status' => 'publish', 'post_author' => $owner->ID]);
if (is_wp_error($postId)) {
    throw new RuntimeException($postId->get_error_message());
}
update_option('dualscan_fixture_nested', ['url' => $url, 'payload' => ['emoji' => '💎', 'blank' => '', 'null' => null]]);
update_post_meta($postId, '_dualscan_fixture', ['file' => "été/写真.png", 'binary' => "\0\xff"]);
$authoredPosts = [$postId];
$adminCommentIds = [];
$adminLinkIds = [];
foreach (['dualscan_admin', 'dualscan_client'] as $login) {
    $author = get_user_by('login', $login);
    if (!$author) { continue; }
    if ($login !== 'dualscan_admin') {
        $authoredPosts[] = wp_insert_post(['post_title' => 'Second administrator published fixture', 'post_content' => "Second admin content must survive account removal.\n💎", 'post_status' => 'publish', 'post_author' => $author->ID]);
    }
    $adminCommentIds[] = wp_insert_comment(['comment_post_ID' => $postId, 'comment_author' => $login, 'comment_author_email' => $login . '@example.invalid', 'comment_content' => 'Published synthetic comment from ' . $login, 'comment_approved' => 1, 'user_id' => $author->ID]);
    if ($wpdb->insert($wpdb->links, ['link_url' => 'https://example.invalid/' . $login, 'link_name' => 'Synthetic link from ' . $login, 'link_owner' => $author->ID]) === false) { throw new RuntimeException($wpdb->last_error); }
    $adminLinkIds[] = $wpdb->insert_id;
}
$editor = get_user_by('login', 'dualscan_editor');
if ($editor) {
    update_user_meta($editor->ID, '_application_passwords', [['uuid' => 'synthetic', 'name' => 'Disabled integration fixture', 'password' => '!synthetic-disabled-test-value', 'created' => time()]]);
    update_user_meta($editor->ID, 'application_passwords', 'synthetic disabled legacy credential');
    $tokens = get_user_meta($editor->ID, 'session_tokens', true);
    $tokens = is_array($tokens) ? $tokens : [];
    $tokens[hash('sha256', 'synthetic-session-fixture')] = ['expiration' => time() + 86400, 'ip' => '127.0.0.1', 'ua' => 'Synthetic integration fixture', 'login' => time()];
    update_user_meta($editor->ID, 'session_tokens', $tokens);
}
$files = [
    'wp-content/plugins/fixture-active/fixture-active.php' => "<?php\n/* Plugin Name: Dualscan Active Fixture */\nadd_filter('the_content', static fn(\$s) => \$s);\n",
    'wp-content/plugins/fixture-inactive/fixture-inactive.php' => "<?php\n/* Plugin Name: Dualscan Inactive Fixture */\n",
    'wp-content/mu-plugins/fixture-mu.php' => "<?php\n/* Plugin Name: Dualscan MU Fixture */\n",
    'wp-content/themes/fixture-theme/style.css' => "/*\nTheme Name: Dualscan Fixture Theme\nVersion: 1.0\n*/\n",
    'wp-content/themes/fixture-theme/index.php' => "<?php echo 'Synthetic fixture theme';\n",
    'wp-content/uploads/2026/10/été/写真.bin' => $binary,
    'wp-content/uploads/2026/10/photo.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR42mP8z8Dwn4GBgYGJAQoAHgQCAfT+DT0AAAAASUVORK5CYII='),
    '.dualscan-fixture-hidden' => "hidden root file\n",
    'custom-root-data.json' => json_encode(['custom' => 'root-level data', 'utf8' => '日本語'], JSON_UNESCAPED_UNICODE),
    'wp-content/uploads/empty-file' => '',
];
foreach ($files as $path => $data) {
    $absolute = $root . '/' . $path;
    if (!is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0755, true);
    }
    file_put_contents($absolute, $data);
}
if (!is_dir($root . '/wp-content/uploads/empty-directory')) {
    mkdir($root . '/wp-content/uploads/empty-directory', 0755, true);
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin('fixture-active/fixture-active.php');
if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}
echo json_encode(['fixturePostId' => $postId, 'authoredPublishedPostIds' => $authoredPosts, 'administratorCommentIds' => $adminCommentIds, 'administratorLinkIds' => $adminLinkIds, 'editorCredentialFixtureUserId' => $editor ? $editor->ID : null, 'customTables' => [$table, 'independent_plugin_data'], 'files' => array_map(static fn($v) => ['bytes' => strlen($v), 'sha256' => hash('sha256', $v)], $files), 'activePlugins' => get_option('active_plugins')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
