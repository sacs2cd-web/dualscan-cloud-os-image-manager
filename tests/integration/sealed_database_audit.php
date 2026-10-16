<?php
/** Independent, fixture-focused checks of the intentional post-restore account policy. */
declare(strict_types=1);
function captureApplianceDatabaseState(PDO $pdo): array {
    $state = [];
    foreach (['wp_users', 'wp_usermeta', 'wp_posts', 'wp_comments', 'wp_links', 'wp_options'] as $table) {
        $state[$table] = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $state;
}
function auditRows(array $rows): string {
    $encoded = array_map('serialize', $rows); sort($encoded, SORT_STRING);
    return hash('sha256', serialize($encoded));
}
function auditRequire(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function assertApplianceDatabaseState(PDO $pdo, array $before, array $policy): array {
    auditRequire(($policy['mode'] ?? '') === 'administrative-principals-removed' && ($policy['tablePrefix'] ?? '') === 'wp_', 'Missing account-removal deployment policy.');
    $expectedRemoved = [];
    foreach ($before['wp_usermeta'] as $meta) {
        if ($meta['meta_key'] !== 'wp_capabilities') { continue; }
        $caps = unserialize($meta['meta_value'], ['allowed_classes' => false]);
        if (is_array($caps) && !empty($caps['administrator'])) { $expectedRemoved[] = (string) $meta['user_id']; }
    }
    $expectedRemoved = array_values(array_unique($expectedRemoved)); sort($expectedRemoved);
    $declaredRemoved = array_map('strval', $policy['removedAdministrativeUserIds'] ?? []); sort($declaredRemoved);
    auditRequire($expectedRemoved === ['1', '2'] && $declaredRemoved === $expectedRemoved, 'Both fixture administrators must be removed, with no additional user removal.');
    $principal = (string) ($policy['attributionUserId'] ?? '');
    auditRequire(ctype_digit($principal) && $principal !== '0' && !in_array($principal, array_map(static fn($u) => (string) $u['ID'], $before['wp_users']), true), 'Attribution principal must be a fresh user identity.');
    $after = captureApplianceDatabaseState($pdo);
    $attribution = null; $remaining = [];
    foreach ($after['wp_users'] as $user) {
        if ((string) $user['ID'] === $principal) { $attribution = $user; } else { $remaining[] = $user; }
    }
    auditRequire(is_array($attribution), 'Attribution principal was not created.');
    auditRequire($attribution['user_login'] === $policy['attributionLogin'] && str_starts_with($attribution['user_pass'], '!dualscan-disabled-') && (string) $attribution['user_status'] === '1' && $attribution['user_email'] === '' && $attribution['user_activation_key'] === '', 'Attribution principal must have disabled credentials and no email/recovery key.');
    $expectedUsers = array_values(array_filter($before['wp_users'], static fn($u) => !in_array((string) $u['ID'], $expectedRemoved, true)));
    auditRequire(auditRows($remaining) === auditRows($expectedUsers), 'Non-administrator user fields changed or an old administrator survived.');
    $credentialKeys = ['session_tokens', '_application_passwords', 'application_passwords'];
    $expectedMeta = array_values(array_filter($before['wp_usermeta'], static fn($m) => !in_array((string) $m['user_id'], $expectedRemoved, true) && !in_array($m['meta_key'], $credentialKeys, true)));
    auditRequire(auditRows($after['wp_usermeta']) === auditRows($expectedMeta), 'Administrator metadata/credentials survived, or unrelated user metadata changed.');
    $contentCounts = [];
    foreach (['wp_posts' => 'post_author', 'wp_comments' => 'user_id', 'wp_links' => 'link_owner'] as $table => $field) {
        $expectedRows = $before[$table]; $reassigned = 0;
        foreach ($expectedRows as &$row) {
            if (in_array((string) $row[$field], $expectedRemoved, true)) { $row[$field] = $principal; $reassigned++; }
        }
        unset($row);
        auditRequire(auditRows($after[$table]) === auditRows($expectedRows), $table . ' content/fields changed beyond the required attribution reassignment.');
        auditRequire($reassigned >= 2, 'Both administrator fixtures must be represented in ' . $table . '.');
        $contentCounts[$table] = ['allRowsAndOtherColumnsPreserved' => true, 'reassignedRows' => $reassigned];
    }
    auditRequire(auditRows($after['wp_options']) === auditRows($before['wp_options']), 'Site options changed during account removal.');
    foreach ($policy['changedTables'] ?? [] as $table) {
        auditRequire(in_array($table['name'], ['wp_users', 'wp_usermeta', 'wp_posts', 'wp_comments', 'wp_links'], true), 'Deployment declared an unrelated table mutation.');
    }
    return ['passed' => true, 'removedAdministratorIds' => $expectedRemoved, 'attributionUserId' => $principal, 'attributionPrincipalHasDisabledCredentialsAndNoRoles' => true, 'nonAdministrativeUsersUnchanged' => true, 'allSessionsAndApplicationPasswordRecordsRemoved' => true, 'siteOptionsUnchanged' => true, 'content' => $contentCounts];
}
