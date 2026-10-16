<?php
declare(strict_types=1);

namespace Dualscan\CloudOS;

/** Managed client UI and capabilities; the hosting owner retains filesystem access. */
final class ManagedAccess
{
    public const OWNER_OPTION = 'dualscan_cloud_owner_id';
    private const PLUGIN_CAPS = [
        'activate_plugins', 'activate_plugin', 'deactivate_plugin', 'install_plugins',
        'upload_plugins', 'update_plugins', 'delete_plugins', 'delete_plugin',
        'edit_plugins', 'edit_plugin', 'resume_plugins', 'manage_network_plugins',
        'manage_options', 'edit_themes', 'edit_theme_options', 'install_themes',
        'upload_themes', 'update_themes', 'delete_themes', 'switch_themes',
        'edit_theme', 'edit_files', 'create_users', 'delete_users', 'promote_users',
        'edit_users', 'delete_user', 'promote_user', 'unfiltered_html', 'unfiltered_upload',
    ];

    public static function activate(): void
    {
        if ((int) get_option(self::OWNER_OPTION, 0) > 0) {
            return;
        }
        $id = get_current_user_id();
        $user = get_userdata($id);
        if ($id < 1 || !$user || empty($user->allcaps['manage_options'])) {
            wp_die('Activate Dualscan as the site owner. For WP-CLI, specify --user=<administrator>.');
        }
        add_option(self::OWNER_OPTION, $id, '', false);
    }

    public static function isOwner(?int $id = null): bool
    {
        $id ??= get_current_user_id();
        if ($id < 1) {
            return false;
        }
        $user = get_userdata($id);
        if (defined('DUALSCAN_CLOUD_RECOVERY') && DUALSCAN_CLOUD_RECOVERY === true && $user && !empty($user->allcaps['manage_options'])) {
            return true;
        }
        if (is_multisite() && is_super_admin($id)) {
            return true;
        }
        return (int) get_option(self::OWNER_OPTION, 0) === $id;
    }

    public static function boot(): void
    {
        add_filter('map_meta_cap', static function (array $caps, string $cap, int $id, array $args): array {
            if ($cap === 'dualscan_manage_images') {
                return self::isOwner($id) ? ['manage_options'] : ['do_not_allow'];
            }
            if (in_array($cap, self::PLUGIN_CAPS, true) && !self::isOwner($id)) {
                return ['do_not_allow'];
            }
            if ($cap === 'edit_user' && (int) ($args[0] ?? 0) !== $id && !self::isOwner($id)) { return ['do_not_allow']; }
            return $caps;
        }, 100, 4);
        add_filter('all_plugins', static fn(array $plugins): array => self::isOwner() ? $plugins : []);
        add_action('admin_menu', static function (): void {
            if (!self::isOwner()) {
                remove_menu_page('plugins.php');
                remove_submenu_page('plugins.php', 'plugin-editor.php');
                // Custom plugin settings pages are hidden along with the core
                // Plugins screen. Ordinary content editing remains available.
                foreach (($GLOBALS['menu'] ?? []) as $entry) {
                    if (isset($entry[2]) && !self::coreAdminSlug((string) $entry[2])) { remove_menu_page((string) $entry[2]); }
                }
                foreach (($GLOBALS['submenu'] ?? []) as $parent => $entries) {
                    foreach ($entries as $entry) {
                        if (isset($entry[2]) && !self::coreAdminSlug((string) $entry[2])) { remove_submenu_page((string) $parent, (string) $entry[2]); }
                    }
                }
            }
        }, 999);
        add_action('admin_init', static function (): void {
            if (self::isOwner()) {
                return;
            }
            $page = $GLOBALS['pagenow'] ?? '';
            $action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
            $pluginAction = $page === 'update.php' && in_array($action, ['install-plugin', 'upload-plugin', 'update-plugin', 'activate-plugin', 'delete-plugin'], true);
            $customPage = isset($_REQUEST['page']) && is_scalar($_REQUEST['page']) && (string) $_REQUEST['page'] !== '' && !(defined('DOING_AJAX') && DOING_AJAX);
            if (in_array($page, ['plugins.php', 'plugin-install.php', 'plugin-editor.php'], true) || $pluginAction || $customPage) {
                wp_die('Plugin management is controlled by the site owner.', 'Managed site', ['response' => 403]);
            }
        }, 1);
        add_filter('rest_pre_dispatch', static function ($result, $server, \WP_REST_Request $request) {
            if (str_starts_with($request->get_route(), '/wp/v2/plugins') && !self::isOwner()) {
                return new \WP_Error('dualscan_managed_plugins', 'Plugin management is controlled by the site owner.', ['status' => 403]);
            }
            return $result;
        }, 10, 3);
    }

    private static function coreAdminSlug(string $slug): bool
    {
        if (str_starts_with($slug, 'separator')) { return true; }
        $parts = wp_parse_url($slug);
        if (!is_array($parts) || isset($parts['host'])) { return false; }
        $query = []; wp_parse_str($parts['query'] ?? '', $query);
        if (isset($query['page'])) { return false; }
        $path = $parts['path'] ?? '';
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) { return false; }
        $core = realpath(ABSPATH . 'wp-admin/' . $path);
        $admin = realpath(ABSPATH . 'wp-admin');
        return $core !== false && $admin !== false && is_file($core) && str_starts_with($core, $admin . '/');
    }
}
