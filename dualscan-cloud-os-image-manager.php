<?php
/**
 * Plugin Name: Dualscan Cloud OS Image Manager
 * Description: Create and restore protected .dualscan WordPress site images with owner-controlled plugin management.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Dualscan
 * License: GPL-2.0-or-later
 * Text Domain: dualscan-cloud-os-image-manager
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
define('DUALSCAN_CLOUD_VERSION', '1.0.1');
define('DUALSCAN_CLOUD_FILE', __FILE__);
require_once __DIR__ . '/includes/engine-bootstrap.php';
require_once __DIR__ . '/includes/class-managed-access.php';
require_once __DIR__ . '/includes/class-deployment-terms.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook(__FILE__, [\Dualscan\CloudOS\ManagedAccess::class, 'activate']);
\Dualscan\CloudOS\ManagedAccess::boot();
\Dualscan\CloudOS\Plugin::boot();
