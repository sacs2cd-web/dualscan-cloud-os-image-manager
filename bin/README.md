# Offline image and recovery CLI

Use 64-bit PHP 8.1+, OpenSSL and PDO MySQL. The CLI does not load WordPress or execute scripts from an image. It requires an explicitly provisioned destination MySQL/MariaDB database.

```
php bin/dualscan.php backup SITE_ROOT
php bin/dualscan.php inspect IMAGE
php bin/dualscan.php restore IMAGE TARGET_ROOT --confirm-replace [--preserve-config]
```

**Restore defaults to a sealed public application. It deletes WordPress administrative accounts.** This includes custom roles and direct capabilities that grant WordPress administration, plugin/theme management or user escalation. It clears all users' saved sessions and application passwords. Posts, comments and links attributed to removed administrators are reassigned to a fresh disabled, role-free attribution principal. Other users and their content remain; web login is disabled for everyone. Plugin data and role definitions remain unchanged. These intentional account/attribution changes are declared in the deployment result.

The runtime installs a trusted early guard in `wp-config.php` and an independent MU policy. Captured authoring flags, user accounts, database options, cookies and URL parameters cannot enable its web administration. Public frontend GET, HEAD and POST through the root WordPress front controller remain available. Standard WordPress admin, login, REST, AJAX and XML-RPC dispatch are denied. Bespoke plugin endpoints need application-specific review; a restored application is executable PHP, not protection against the hosting provider or a server operator with file/database privileges. There is no SSH server or remote shell feature in this package.

The authoring source is unchanged by creating an image. Normal manager installation keeps the author's management UI. Sealing applies to restored copies.

## Trusted authoring recovery

Only a trusted offline operator should use:

```
php bin/dualscan.php restore IMAGE TARGET_ROOT --confirm-replace --authoring-recovery [--preserve-config]
```

`--authoring-recovery` deliberately opts out of admin removal and sealing. It removes a previous managed bootstrap and the two managed runtime policy files so a restored authoring backup can boot normally. This flag is never accepted from a WordPress web request. A backup made after administrative accounts were removed cannot recreate accounts that no longer existed in that backup.

`bin/appliance.php` provides explicit aliases:

```
php bin/appliance.php restore IMAGE ROOT --confirm-replace [--preserve-config]
php bin/appliance.php recover-authoring IMAGE ROOT --confirm-replace --confirm-authoring [--preserve-config]
php bin/appliance.php status ROOT
```

The `status` command checks local policy files and hashes; it does not replace an HTTP acceptance test. All CLI entry points reject web execution.

## Server-managed encryption

Normal backup, inspection and restore use a private server key automatically. End users do not enter image passwords. The installed plugin folder basename is a normalized key context; it is public and is **not** the encryption secret. A random 32-byte master is stored at `DUALSCAN_PRIVATE_STORAGE/keyring/<folder>.key` outside every web root. HKDF-SHA256 binds it to the folder context. The key is never embedded in the plugin, source archive, `.dualscan` image or manifest. Status prints only the context and a key identifier.

Keep this private storage durable and back up the key separately from site images. A different server must receive the same private master and use the same folder context before it can open an image. Renaming the context or generating another master does not unlock an existing image. Lost keys cannot be reconstructed from the plugin name.

```
php bin/keyring.php init
php bin/keyring.php status
php bin/keyring.php export /private/key-backups/dualscan-package.key
```

Export requires a new destination file in an owner-only directory (mode 700); the raw key file uses mode 600. Transfer it through an operator-controlled private channel, separately from images and plugin downloads. On the destination, after setting its private storage, public root and matching folder context:

```
php bin/keyring.php import /private/transferred/dualscan-package.key
php bin/appliance.php restore IMAGE ROOT --confirm-replace
```

No HTTP key export/import route is provided. A different existing key is never overwritten automatically. Keep older keys available before intentionally provisioning an isolated new keyring.

The CLI default is server-folder key mode; `--server-folder-key` makes that choice explicit. **Only** explicit offline `--password-mode` uses `DUALSCAN_IMAGE_PASSWORD` for older password-protected images. This legacy flag does not create a password prompt or web password workflow.

## Settings and replacement policy

Environment settings:

- `DUALSCAN_DB_DSN`: PDO MySQL DSN, including the intended database and `charset=utf8mb4`.
- `DUALSCAN_DB_USER`, `DUALSCAN_DB_PASSWORD`: database credentials.
- `DUALSCAN_PRIVATE_STORAGE`: dedicated owner-only directory outside all web-served document roots.
- `DUALSCAN_PUBLIC_ROOT`: existing public document root; normal CLI key operations require it to prevent public key storage.
- `DUALSCAN_PLUGIN_FOLDER`: optional server folder context, defaulting to the installed package basename.
- `DUALSCAN_SERVER_KEY_FILE`: optional private raw key to provision explicitly before the operation; it must be outside public roots and have mode 600.
- `DUALSCAN_IMAGE_PASSWORD`: used only with explicit offline `--password-mode` (12–1,024 bytes); never pass it as a CLI argument.
- `DUALSCAN_SITE_URL`, `DUALSCAN_TABLE_PREFIX`: source identity metadata for backup (prefix defaults to `wp_`).

Restore replaces root files and **every visible base table in the configured database**. Use a dedicated database and a maintenance window with external writers stopped. All image bytes, paths, manifest hashes and database row payloads are validated in private staging before replacement. An authenticated encrypted rollback image is created and validated before mutation. Caught failures attempt to recover the original target's files, configuration, database and prior sealed/authoring state. A failure after sealing does not silently seal the recovered authoring target. Hard process kills, disk faults or hardware failures can require offline recovery from that encrypted rollback image. Keep the matching private server key securely; legacy password-mode images also require their original password.

`--preserve-config` retains the destination configuration body and credentials; sealed restore adds its managed early guard while preserving the file's existing permission mode. Guard paths use `__DIR__`, so moving the restored root does not leave a native absolute path embedded. The engine does not rewrite site URLs, table prefixes or serialized plugin options. Prepare migrations separately. The web manager additionally requires a matching site URL and prefix.

CLI results use JSON on stdout; progress and errors use stderr. Exit status is 0 on success and 1 on error.

## Supported image scope

Snapshots contain readable regular files and directories under the supplied root, including hidden files, core, plugins, themes, uploads and MU plugins, plus all visible InnoDB base tables in the configured database. Files are copied and rehashed; membership/stat changes abort the backup. The database uses a consistent InnoDB transaction. Application-level consistency across files and database requires stopped external writers. Source files are never removed.

Sealed deployment requires standard single-site WordPress paths and principal tables. Multisite, custom user tables and inbound foreign keys to WordPress users require explicit deployment adapters: admin removal must not silently cascade into plugin data. Visible cross-database foreign-key dependencies are refused. Exact offline authoring recovery does not apply principal removal.

Symlinks, external content roots, unreadable/special files, path case/Unicode collisions, views, triggers, routines, events, generated/spatial columns and unsupported table definitions fail explicitly. Server users/grants, DNS, mailboxes, external databases, ownership, ACLs and extended attributes are outside image scope. Database values retain raw column bytes, including legacy text charsets; JSON uses explicit UTF-8 conversion and TIMESTAMP values use UTC during snapshot/restore. Prior session settings are restored. Restored schema and rows are checked before deployment policy changes.

Limits: 1 TiB image, 500,000 filesystem entries, 10,000 tables, 64 MiB manifest, 32 MiB encoded row. Portable SQL row retrieval uses one-row pages to bound memory; large databases need enough execution time and may take substantial time.

## Format

`.dualscan` version 1 is an original encrypted format: `DUALSCAN` + version byte, bounded JSON header, PBKDF2-SHA256 with 600,000 iterations and random 32-byte salt (fed by the server-derived key in normal operation), then AES-256-GCM records with unique 12-byte nonces, 1 MiB plaintext chunks and a final authenticated zero-length record. The complete header, chunk number and length are authenticated on every record. The encrypted payload contains a length-prefixed JSON manifest followed by exact file bytes. There is no ZIP expansion, executable installer or cloud dependency.
