<?php
// Standalone engine bootstrap: deliberately does not load WordPress or a plugin reference.
require_once __DIR__ . '/class-engine-exception.php';
require_once __DIR__ . '/interface-database-adapter.php';
require_once __DIR__ . '/class-safe-files.php';
require_once __DIR__ . '/class-server-keyring.php';
require_once __DIR__ . '/class-image-codec.php';
require_once __DIR__ . '/class-logical-database.php';
require_once __DIR__ . '/class-wordpress-database.php';
require_once __DIR__ . '/class-pdo-database.php';
require_once __DIR__ . '/class-appliance-deployment.php';
require_once __DIR__ . '/class-image-manager.php';
