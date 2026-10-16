<?php
namespace Dualscan\CloudOS;
/** Binary-safe logical MySQL/MariaDB table snapshots; no executable SQL dump or shell process. */
abstract class LogicalDatabase implements DatabaseAdapter {
    abstract protected function rows(string $sql): array;
    abstract protected function execute(string $sql): void;
    protected function scalar(string $sql) { $rows = $this->rows($sql); return $rows ? reset($rows[0]) : null; }
    public function identity(): array { return ['database' => (string) $this->scalar('SELECT DATABASE()'), 'serverVersion' => (string) $this->scalar('SELECT VERSION()')]; }
    private static function quote(string $identifier): string {
        if ($identifier === '' || strlen($identifier) > 192 || preg_match('/[\x00-\x1f\x7f]/', $identifier) || !preg_match('//u', $identifier)) { throw new EngineException('Unsupported database identifier.'); }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
    private static function byteSelect(array $columns): string {
        return implode(',', array_map(static fn(string $column): string => 'CAST(' . self::quote($column) . ' AS BINARY) AS ' . self::quote($column), $columns));
    }
    private function tables(): array {
        $tables = [];
        foreach ($this->rows('SHOW FULL TABLES') as $row) {
            $values = array_values($row);
            if (strtoupper((string) ($values[1] ?? '')) !== 'BASE TABLE') { throw new EngineException('Database views are unsupported; no complete image was produced.'); }
            $tables[] = (string) $values[0];
        }
        sort($tables, SORT_STRING);
        if (count($tables) > 10000) { throw new EngineException('Database exceeds the 10,000-table safety limit.'); }
        return $tables;
    }
    private function supportedObjects(): void {
        if ((int) $this->scalar("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE (REFERENCED_TABLE_SCHEMA=DATABASE() AND TABLE_SCHEMA<>DATABASE()) OR (TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>DATABASE())") > 0) { throw new EngineException('Cross-database foreign-key dependencies require a dedicated migration plan.'); }
        foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $object => $column) {
            if ((int) $this->scalar("SELECT COUNT(*) FROM information_schema.$object WHERE $column = DATABASE()") > 0) { throw new EngineException('Database ' . strtolower($object) . ' are unsupported; no complete image was produced.'); }
        }
    }
    public function exportTo(string $directory, ?callable $progress = null): array {
        SafeFiles::privateDirectory($directory); $this->supportedObjects(); $tables = $this->tables();
        $metadata = ['version' => 1, 'rowEncoding' => 'raw-column-bytes-utc-v1', 'identity' => $this->identity(), 'tables' => [], 'scope' => 'All visible base tables in the configured database; InnoDB only. Server users, grants, routines, events, triggers and external databases are not portable.'];
        $previousTimezone = (string) $this->scalar('SELECT @@SESSION.time_zone');
        $previousMode = (string) $this->scalar('SELECT @@SESSION.sql_mode');
        $active = false;
        try {
            $this->execute("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
            $this->execute("SET SESSION time_zone='+00:00'");
            $this->execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->execute('START TRANSACTION WITH CONSISTENT SNAPSHOT'); $active = true;
            foreach ($tables as $number => $name) {
                $quoted = self::quote($name); $schemaRows = $this->rows('SHOW CREATE TABLE ' . $quoted); $schemaValues = array_values($schemaRows[0] ?? []);
                $schema = (string) ($schemaValues[1] ?? ''); self::validateSchema($name, $schema);
                $columns = []; $columnTypes = [];
                foreach ($this->rows('SHOW FULL COLUMNS FROM ' . $quoted) as $column) {
                    if (stripos((string) ($column['Extra'] ?? ''), 'GENERATED') !== false) { throw new EngineException('Generated database columns are unsupported: ' . $name); }
                    if (preg_match('/^(?:geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/i', (string) ($column['Type'] ?? ''))) { throw new EngineException('Spatial database columns require a dedicated migration adapter: ' . $name); }
                    $columns[] = (string) $column['Field']; $columnTypes[] = (string) $column['Type'];
                }
                if (!$columns || count($columns) > 4096) { throw new EngineException('Invalid database column inventory.'); }
                $filename = sprintf('table-%06d.jsonl', $number + 1); $handle = fopen($directory . '/' . $filename, 'xb');
                if (!$handle) { throw new EngineException('Cannot create database snapshot.'); } chmod($directory . '/' . $filename, 0600);
                $count = 0;
                try {
                    do {
                        $page = $this->rows('SELECT ' . self::byteSelect($columns) . ' FROM ' . $quoted . ' LIMIT ' . $count . ',1');
                        foreach ($page as $row) {
                            if (array_keys($row) !== $columns) { throw new EngineException('Database schema changed during export: ' . $name); }
                            $encoded = [];
                            foreach ($columns as $column) { $encoded[] = $row[$column] === null ? null : base64_encode((string) $row[$column]); }
                            $line = SafeFiles::json($encoded) . "\n";
                            if (strlen($line) > 33554432) { throw new EngineException('A database row exceeds the 32 MiB portable row limit.'); }
                            SafeFiles::writeAll($handle, $line); $count++;
                        }
                    } while (count($page) === 1);
                } finally { fclose($handle); }
                $schemaAfter = array_values($this->rows('SHOW CREATE TABLE ' . $quoted)[0] ?? []);
                if (($schemaAfter[1] ?? null) !== $schema) { throw new EngineException('Database schema changed during export: ' . $name); }
                $metadata['tables'][] = ['name' => $name, 'createSql' => $schema, 'columns' => $columns, 'columnTypes' => $columnTypes, 'file' => $filename, 'rows' => $count, 'bytes' => filesize($directory . '/' . $filename), 'sha256' => hash_file('sha256', $directory . '/' . $filename)];
                if ($progress) { $progress('database', $number + 1, count($tables)); }
            }
            if ($tables !== $this->tables()) { throw new EngineException('Database tables changed during export.'); }
            $this->execute('COMMIT'); $active = false;
        } catch (\Throwable $e) { if ($active) { try { $this->execute('ROLLBACK'); } catch (\Throwable $ignored) {} } throw $e; }
        finally { $this->execute("SET SESSION time_zone=UNHEX('" . bin2hex($previousTimezone) . "')"); $this->execute("SET SESSION sql_mode=UNHEX('" . bin2hex($previousMode) . "')"); }
        return $metadata;
    }
    private static function validateSchema(string $name, string $sql): void {
        if (strlen($sql) > 1048576 || !str_starts_with($sql, 'CREATE TABLE ' . self::quote($name) . ' (')) { throw new EngineException('Unsupported table definition: ' . $name); }
        $outside = ''; $depth = 0; $bodyEnd = null; $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character; $closed = false; $outside .= $quote === '`' ? '@I' : '@S';
                while (++$i < $length) {
                    if ($sql[$i] === '\\') { $i++; continue; }
                    if ($sql[$i] === $quote) { if ($i + 1 < $length && $sql[$i + 1] === $quote) { $i++; continue; } $closed = true; break; }
                }
                if (!$closed) { throw new EngineException('Unterminated database schema token.'); }
                continue;
            }
            if ($character === ';' || $character === '#' || substr($sql, $i, 2) === '--' || substr($sql, $i, 2) === '/*') { throw new EngineException('Executable multi-statement or comment SQL is unsupported.'); }
            $outside .= $character;
            if ($character === '(') { $depth++; }
            elseif ($character === ')') { $depth--; if ($depth === 0) { $bodyEnd = $i; } if ($depth < 0) { throw new EngineException('Invalid schema parentheses.'); } }
        }
        if ($depth !== 0 || $bodyEnd === null || preg_match('/@I\s*\.\s*@I|\b(SELECT|OUTFILE|DUMPFILE|LOAD|TABLESPACE|CONNECTION|DIRECTORY|PARTITION|ENCRYPTION|GENERATED|VIRTUAL|STORED|GEOMETRY|POINT|LINESTRING|POLYGON|MULTIPOINT|MULTILINESTRING|MULTIPOLYGON|GEOMETRYCOLLECTION)\b/i', $outside)) { throw new EngineException('This database definition uses unsupported external or executable features.'); }
        $tail = trim(substr($sql, $bodyEnd + 1));
        if (!preg_match("/^ENGINE=InnoDB(?: (?:AUTO_INCREMENT=[0-9]+|(?:DEFAULT )?CHARSET=[a-zA-Z0-9_]+|COLLATE=[a-zA-Z0-9_]+|ROW_FORMAT=[a-zA-Z0-9_]+|KEY_BLOCK_SIZE=[0-9]+|COMMENT='(?:[^'\\\\]|\\\\.|'')*'))*$/D", $tail)) { throw new EngineException('Only portable InnoDB table definitions are supported: ' . $name); }
    }
    private static function tableMetadata(array $metadata): array {
        if (($metadata['version'] ?? null) !== 1 || ($metadata['rowEncoding'] ?? '') !== 'raw-column-bytes-utc-v1' || !is_array($metadata['tables'] ?? null) || !array_is_list($metadata['tables']) || count($metadata['tables']) > 10000) { throw new EngineException('Invalid database metadata.'); }
        $seen = []; $files = [];
        foreach ($metadata['tables'] as $table) {
            if (!is_array($table) || !is_string($table['name'] ?? null) || !is_string($table['createSql'] ?? null) || !is_array($table['columns'] ?? null) || !array_is_list($table['columns']) || !$table['columns'] || count($table['columns']) > 4096 || !is_array($table['columnTypes'] ?? null) || !array_is_list($table['columnTypes']) || count($table['columnTypes']) !== count($table['columns']) || !is_string($table['file'] ?? null) || !preg_match('/^table-[0-9]{6}\.jsonl$/D', $table['file']) || !is_int($table['rows'] ?? null) || $table['rows'] < 0 || !is_int($table['bytes'] ?? null) || $table['bytes'] < 0 || !preg_match('/^[a-f0-9]{64}$/D', $table['sha256'] ?? '')) { throw new EngineException('Invalid database table manifest.'); }
            $fold = strtolower($table['name']); if (isset($seen[$fold]) || isset($files[$table['file']])) { throw new EngineException('Duplicate database table or snapshot.'); } $seen[$fold] = true; $files[$table['file']] = true;
            self::validateSchema($table['name'], $table['createSql']);
            foreach ($table['columnTypes'] as $type) { if (!is_string($type) || $type === '' || strlen($type) > 1048576) { throw new EngineException('Invalid database column type metadata.'); } }
            $columns = []; foreach ($table['columns'] as $column) { if (!is_string($column)) { throw new EngineException('Invalid column.'); } self::quote($column); if (isset($columns[$column])) { throw new EngineException('Duplicate database column.'); } $columns[$column] = true; }
        }
        return $metadata['tables'];
    }
    private static function readRow($handle, int $columnCount): ?array {
        $line = fgets($handle, 33554434);
        if ($line === false) { if (!feof($handle)) { throw new EngineException('Database snapshot read failed.'); } return null; }
        if (!str_ends_with($line, "\n") || strlen($line) > 33554432) { throw new EngineException('Database row is truncated or oversized.'); }
        try { $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new EngineException('Invalid database row encoding.', 0, $e); }
        if (!is_array($row) || !array_is_list($row) || count($row) !== $columnCount) { throw new EngineException('Invalid database row shape.'); }
        foreach ($row as &$value) { if ($value !== null) { if (!is_string($value) || ($decoded = base64_decode($value, true)) === false || base64_encode($decoded) !== $value) { throw new EngineException('Invalid binary database value.'); } $value = $decoded; } } unset($value);
        return $row;
    }
    public function validateFrom(string $directory, array $metadata): void {
        foreach (self::tableMetadata($metadata) as $table) {
            $path = $directory . '/' . $table['file'];
            if (!is_file($path) || is_link($path) || filesize($path) !== $table['bytes'] || !hash_equals($table['sha256'], (string) hash_file('sha256', $path))) { throw new EngineException('Database snapshot hash mismatch.'); }
            $handle = fopen($path, 'rb'); if (!$handle) { throw new EngineException('Cannot read database snapshot.'); } $count = 0;
            try { while (self::readRow($handle, count($table['columns'])) !== null) { $count++; } } finally { fclose($handle); }
            if ($count !== $table['rows']) { throw new EngineException('Database row count mismatch.'); }
        }
    }
    private static function addRowDigest(array &$digest, array $row): void {
        $encoded = []; foreach ($row as $value) { $encoded[] = $value === null ? null : base64_encode((string) $value); }
        $hash = hash('sha256', "Dualscan database row\0" . SafeFiles::json($encoded), true);
        $digest['xor'] = $digest['xor'] ^ $hash; $sum = $digest['sum']; $carry = 0;
        for ($index = 31; $index >= 0; $index--) { $value = ord($sum[$index]) + ord($hash[$index]) + $carry; $sum[$index] = chr($value & 255); $carry = $value >> 8; }
        $digest['sum'] = $sum; $digest['count']++;
    }
    private function verifyRestoredRows(string $directory, array $metadata): void {
        foreach ($metadata['tables'] as $table) {
            $expected = ['count' => 0, 'xor' => str_repeat("\0", 32), 'sum' => str_repeat("\0", 32)]; $actual = $expected;
            $handle = fopen($directory . '/' . $table['file'], 'rb');
            try { while (($row = self::readRow($handle, count($table['columns']))) !== null) { self::addRowDigest($expected, $row); } } finally { fclose($handle); }
            do {
                $page = $this->rows('SELECT ' . self::byteSelect($table['columns']) . ' FROM ' . self::quote($table['name']) . ' LIMIT ' . $actual['count'] . ',1');
                foreach ($page as $row) { if (array_keys($row) !== $table['columns']) { throw new EngineException('Restored database columns changed unexpectedly.'); } self::addRowDigest($actual, array_values($row)); }
            } while (count($page) === 1);
            if ($expected !== $actual) { throw new EngineException('Restored database row verification failed: ' . $table['name']); }
            $schema = array_values($this->rows('SHOW CREATE TABLE ' . self::quote($table['name']))[0] ?? []);
            if (($schema[1] ?? null) !== $table['createSql']) { throw new EngineException('Restored database schema verification failed: ' . $table['name']); }
        }
        $expectedNames = array_column($metadata['tables'], 'name'); sort($expectedNames, SORT_STRING);
        if ($this->tables() !== $expectedNames) { throw new EngineException('Restored database table inventory verification failed.'); }
    }
    private static function insertSql(array $table, array $row): string {
        $columns = implode(',', array_map([self::class, 'quote'], $table['columns'])); $values = [];
        foreach ($row as $index => $value) {
            if ($value === null) { $values[] = 'NULL'; continue; }
            $expression = "UNHEX('" . bin2hex($value) . "')";
            if (strtolower($table['columnTypes'][$index]) === 'json') { $expression = 'CONVERT(' . $expression . ' USING utf8mb4)'; }
            $values[] = $expression;
        }
        return 'INSERT INTO ' . self::quote($table['name']) . ' (' . $columns . ') VALUES (' . implode(',', $values) . ')';
    }
    public function preflightRestoreFrom(string $directory, array $metadata): void {
        $this->validateFrom($directory, $metadata); $this->supportedObjects(); $this->tables();
        $packet = (int) $this->scalar('SELECT @@max_allowed_packet');
        foreach (self::tableMetadata($metadata) as $table) {
            $handle = fopen($directory . '/' . $table['file'], 'rb');
            try { while (($row = self::readRow($handle, count($table['columns']))) !== null) { if (strlen(self::insertSql($table, $row)) + 64 > $packet) { throw new EngineException('Destination max_allowed_packet is too small for this image.'); } } } finally { fclose($handle); }
        }
    }
    public function restoreFrom(string $directory, array $metadata): void {
        $this->preflightRestoreFrom($directory, $metadata); $existing = $this->tables();
        $previousMode = (string) $this->scalar('SELECT @@SESSION.sql_mode');
        $previousTimezone = (string) $this->scalar('SELECT @@SESSION.time_zone');
        $previousForeignKeys = (int) $this->scalar('SELECT @@SESSION.foreign_key_checks');
        try {
            $this->execute("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
            $this->execute("SET SESSION time_zone='+00:00'");
            $this->execute('SET FOREIGN_KEY_CHECKS=0');
            foreach ($existing as $name) { $this->execute('DROP TABLE ' . self::quote($name)); }
            foreach ($metadata['tables'] as $table) { $this->execute($table['createSql']); }
            foreach ($metadata['tables'] as $table) {
                $handle = fopen($directory . '/' . $table['file'], 'rb');
                try { while (($row = self::readRow($handle, count($table['columns']))) !== null) {
                    $this->execute(self::insertSql($table, $row));
                } } finally { fclose($handle); }
            }
            $this->verifyRestoredRows($directory, $metadata);
        } finally { $this->execute('SET FOREIGN_KEY_CHECKS=' . ($previousForeignKeys ? '1' : '0')); $this->execute("SET SESSION time_zone=UNHEX('" . bin2hex($previousTimezone) . "')"); $this->execute("SET SESSION sql_mode=UNHEX('" . bin2hex($previousMode) . "')"); }
    }
    private static function decodedArray(string $bytes, string $label): array {
        $value = @unserialize($bytes, ['allowed_classes' => false]);
        if (!is_array($value)) { throw new EngineException('Unsupported serialized WordPress ' . $label . '.'); }
        return $value;
    }
    private static function stagedRows(string $directory, array $table): \Generator {
        $handle = fopen($directory . '/' . $table['file'], 'rb'); if (!$handle) { throw new EngineException('Cannot read WordPress policy source.'); }
        try { while (($row = self::readRow($handle, count($table['columns']))) !== null) { yield array_combine($table['columns'], $row); } } finally { fclose($handle); }
    }
    private static function administrativeCapabilities(): array {
        return ['manage_options', 'activate_plugins', 'edit_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'edit_theme_options', 'edit_users', 'create_users', 'delete_users', 'promote_users', 'manage_users', 'update_core', 'edit_files', 'manage_network', 'manage_network_options', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_sites', 'setup_network', 'delete_site'];
    }
    private static function positiveId($value): string {
        $value = (string) $value;
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $value) || strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) { throw new EngineException('WordPress principal IDs exceed the supported signed 64-bit range.'); }
        return $value;
    }
    public function prepareAppliancePolicy(string $directory, array $metadata, string $prefix): array {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $prefix)) { throw new EngineException('A standard WordPress table prefix is required for sealed deployment.'); }
        $tables = []; foreach (self::tableMetadata($metadata) as $table) { $tables[$table['name']] = $table; }
        if (isset($tables[$prefix . 'blogs'], $tables[$prefix . 'sitemeta'])) { throw new EngineException('Sealed deployment of WordPress multisite requires a network principal policy; this release supports single-site WordPress.'); }
        foreach (['users' => ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name'], 'usermeta' => ['umeta_id', 'user_id', 'meta_key', 'meta_value'], 'options' => ['option_name', 'option_value'], 'posts' => ['post_author']] as $suffix => $columns) {
            if (!isset($tables[$prefix . $suffix]) || array_diff($columns, $tables[$prefix . $suffix]['columns'])) { throw new EngineException('Sealed deployment requires the standard WordPress ' . $suffix . ' schema.'); }
        }
        foreach ($tables as $table) {
            if (preg_match('/\bREFERENCES\s+' . preg_quote(self::quote($prefix . 'users'), '/') . '\s*\(/i', $table['createSql'])) { throw new EngineException('Sealed administrative-principal removal cannot preserve unknown foreign-key dependencies on WordPress users. Use an explicit attribution adapter or offline authoring recovery.'); }
        }
        $roles = null;
        foreach (self::stagedRows($directory, $tables[$prefix . 'options']) as $row) { if ($row['option_name'] === $prefix . 'user_roles') { $roles = self::decodedArray((string) $row['option_value'], 'roles'); break; } }
        if ($roles === null) { throw new EngineException('WordPress role definitions are missing from this image.'); }
        $roleCaps = [];
        foreach ($roles as $roleName => $definition) {
            if (!is_string($roleName) || !is_array($definition) || !is_array($definition['capabilities'] ?? null)) { throw new EngineException('Unsupported WordPress role definition.'); }
            foreach ($definition['capabilities'] as $capability => $enabled) { if (!is_string($capability) || is_array($enabled) || is_object($enabled)) { throw new EngineException('Unsupported WordPress capability definition.'); } $roleCaps[$roleName][$capability] = (bool) $enabled; }
        }
        $danger = array_fill_keys(self::administrativeCapabilities(), true); $candidates = [];
        foreach (self::stagedRows($directory, $tables[$prefix . 'usermeta']) as $row) {
            if ($row['meta_key'] === $prefix . 'user_level' && preg_match('/^[0-9]+$/D', (string) $row['meta_value']) && (int) $row['meta_value'] >= 8) { $candidates[self::positiveId($row['user_id'])] = true; }
            if ($row['meta_key'] !== $prefix . 'capabilities') { continue; }
            $caps = self::decodedArray((string) $row['meta_value'], 'principal capabilities'); $effective = []; $isAdminRole = false;
            foreach ($caps as $capability => $enabled) {
                if (!is_string($capability) || is_array($enabled) || is_object($enabled)) { throw new EngineException('Unsupported WordPress principal capability.'); }
                if ($enabled && $capability === 'administrator') { $isAdminRole = true; }
                if ($enabled && isset($roleCaps[$capability])) { $effective = array_merge($effective, $roleCaps[$capability]); }
            }
            foreach ($caps as $capability => $enabled) { if (!isset($roleCaps[$capability])) { $effective[$capability] = (bool) $enabled; } }
            if ($isAdminRole || array_intersect_key(array_filter($effective), $danger)) { $candidates[self::positiveId($row['user_id'])] = true; }
        }
        $removed = [];
        foreach (self::stagedRows($directory, $tables[$prefix . 'users']) as $row) { $id = self::positiveId($row['ID']); if (isset($candidates[$id])) { $removed[] = $id; } }
        usort($removed, static fn(string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
        $related = [];
        foreach (['posts' => 'post_author', 'comments' => 'user_id', 'links' => 'link_owner'] as $suffix => $column) { if (isset($tables[$prefix . $suffix])) { if (!in_array($column, $tables[$prefix . $suffix]['columns'], true)) { throw new EngineException('Unsupported WordPress attribution table.'); } $related[] = ['name' => $prefix . $suffix, 'column' => $column]; } }
        return ['version' => 1, 'tablePrefix' => $prefix, 'removedAdministrativeUserIds' => $removed, 'relatedTables' => $related];
    }
    public function finalizeAppliance(array $plan): array {
        if (($plan['version'] ?? null) !== 1 || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $plan['tablePrefix'] ?? '') || !is_array($plan['removedAdministrativeUserIds'] ?? null) || !is_array($plan['relatedTables'] ?? null)) { throw new EngineException('Invalid held WordPress principal policy.'); }
        if ((int) $this->scalar("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=UNHEX('" . bin2hex($plan['tablePrefix'] . 'users') . "')") > 0) { throw new EngineException('WordPress users have foreign-key dependents; sealed principal removal requires an explicit attribution adapter.'); }
        $prefix = $plan['tablePrefix']; $users = self::quote($prefix . 'users'); $meta = self::quote($prefix . 'usermeta'); $ids = [];
        foreach ($plan['removedAdministrativeUserIds'] as $id) { $ids[] = self::positiveId($id); }
        $login = 'dualscan_runtime_' . bin2hex(random_bytes(8)); $password = '!dualscan-disabled-' . bin2hex(random_bytes(32)); $changes = [];
        $sessions = (int) $this->scalar("SELECT COUNT(*) FROM $meta WHERE meta_key=UNHEX('" . bin2hex('session_tokens') . "')");
        $applicationPasswords = (int) $this->scalar("SELECT COUNT(*) FROM $meta WHERE meta_key IN (UNHEX('" . bin2hex('_application_passwords') . "'),UNHEX('" . bin2hex('application_passwords') . "'))");
        $this->execute('START TRANSACTION');
        try {
            $values = [$login, $password, 'dualscan-runtime', '', '', gmdate('Y-m-d H:i:s'), '', '1', 'Dualscan App'];
            $this->execute("INSERT INTO $users (user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES (" . implode(',', array_map(static fn(string $value): string => "UNHEX('" . bin2hex($value) . "')", $values)) . ')');
            $attributionId = self::positiveId($this->scalar('SELECT LAST_INSERT_ID()'));
            if ($ids) {
                $list = implode(',', $ids);
                foreach ($plan['relatedTables'] as $related) {
                    if (!in_array($related['name'] ?? '', [$prefix . 'posts', $prefix . 'comments', $prefix . 'links'], true) || !in_array($related['column'] ?? '', ['post_author', 'user_id', 'link_owner'], true)) { throw new EngineException('Invalid attribution policy table.'); }
                    $this->execute('UPDATE ' . self::quote($related['name']) . ' SET ' . self::quote($related['column']) . '=' . $attributionId . ' WHERE ' . self::quote($related['column']) . ' IN (' . $list . ')');
                    $changes[] = ['name' => $related['name'], 'reason' => 'Reassign removed administrative authors to disabled attribution principal', 'affectedRows' => (int) $this->scalar('SELECT ROW_COUNT()')];
                }
                $this->execute("DELETE FROM $meta WHERE user_id IN ($list)"); $removedMeta = (int) $this->scalar('SELECT ROW_COUNT()');
                $this->execute("DELETE FROM $users WHERE ID IN ($list)"); $removedUsers = (int) $this->scalar('SELECT ROW_COUNT()');
                if ($removedUsers !== count($ids)) { throw new EngineException('Administrative principal removal count mismatch.'); }
            } else { $removedMeta = 0; $removedUsers = 0; }
            $this->execute("DELETE FROM $meta WHERE meta_key IN (UNHEX('" . bin2hex('session_tokens') . "'),UNHEX('" . bin2hex('_application_passwords') . "'),UNHEX('" . bin2hex('application_passwords') . "'))"); $removedAuth = (int) $this->scalar('SELECT ROW_COUNT()');
            if ((int) $this->scalar("SELECT COUNT(*) FROM $meta WHERE meta_key IN (UNHEX('" . bin2hex('session_tokens') . "'),UNHEX('" . bin2hex('_application_passwords') . "'),UNHEX('" . bin2hex('application_passwords') . "'))") !== 0) { throw new EngineException('Authentication metadata removal verification failed.'); }
            if ($ids && (int) $this->scalar("SELECT COUNT(*) FROM $users WHERE ID IN (" . implode(',', $ids) . ')') !== 0) { throw new EngineException('Administrative principals remain after sealing.'); }
            $changes[] = ['name' => $prefix . 'users', 'reason' => 'Remove administrative principals; create disabled non-login attribution principal', 'affectedRows' => $removedUsers + 1];
            $changes[] = ['name' => $prefix . 'usermeta', 'reason' => 'Remove administrative metadata and all user sessions/application passwords', 'affectedRows' => $removedMeta + $removedAuth];
            $this->execute('COMMIT');
            return ['mode' => 'administrative-principals-removed', 'tablePrefix' => $prefix, 'removedAdministrativeUserIds' => $ids, 'attributionUserId' => $attributionId, 'attributionLogin' => $login, 'clearedSessionRecords' => $sessions, 'clearedApplicationPasswordRecords' => $applicationPasswords, 'changedTables' => $changes];
        } catch (\Throwable $error) { try { $this->execute('ROLLBACK'); } catch (\Throwable $ignored) {} throw $error; }
    }

}
