<?php
declare(strict_types=1);
// Trusted container startup is separate from imported WordPress content.
$root = '/var/www/html';
$policyPath = $root . '/wp-content/mu-plugins/dualscan-appliance-policy.json';
try {
    if (is_link($policyPath) || !is_file($policyPath)) { throw new RuntimeException('A sealed .dualscan deployment is required.'); }
    $policy = json_decode((string) file_get_contents($policyPath), true, 32, JSON_THROW_ON_ERROR);
    $guard = $root . '/wp-content/mu-plugins/dualscan-appliance.php';
    if (($policy['mode'] ?? '') !== 'sealed-public-runtime' || !is_file($guard) || is_link($guard) || !hash_equals((string) ($policy['guardSha256'] ?? ''), (string) hash_file('sha256', $guard))) { throw new RuntimeException('Sealed runtime verification failed.'); }
    if (!is_file($root . '/wp-config.php') || !is_file($root . '/index.php')) { throw new RuntimeException('The restored application is incomplete.'); }
    if (is_writable($root . '/wp-config.php')) { throw new RuntimeException('Mount restored application files read-only.'); }
    $worker = proc_open(['/usr/bin/su', '--shell', '/bin/sh', '--command', 'test -r /var/www/html/wp-config.php && test -r /var/www/html/index.php', 'www-data'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($worker) || proc_close($worker) !== 0) { throw new RuntimeException('The PHP worker must be able to read the restored files. Configure deployment ownership/ACL for www-data without making credentials public.'); }
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
if (in_array('--verify-only', $argv, true)) { exit(0); }
pcntl_exec('/usr/local/sbin/php-fpm', ['-F']);
fwrite(STDERR, "Unable to start PHP-FPM.\n"); exit(1);
