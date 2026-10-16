# Dualscan Cloud OS Image Manager 1.0.1

Create a server-key encrypted `.dualscan` image of a supported WordPress installation, then deploy it as a public application with WordPress administration removed. This package includes a WordPress plugin and an offline deployment engine.

## Authoring and deployment

1. Install the plugin ZIP on the original, trusted authoring site and activate it as the publishing administrator. Open **Dualscan Images**. The installing owner retains authoring access on this source installation; customer accounts cannot manage plugins or the image manager.
2. Pause site changes, scheduled jobs and other database writers. Create an image without entering a password. Download it and have the operator back up the server key separately. Backup creation does not remove source accounts.
3. On the destination, select the image and authenticate it. The manager checks the complete encrypted image, files, paths, database definitions and row digests before accepting deployment.
4. Complete **three separate confirmations**: replacement of the installation, authorization to use its IP, and the versioned deployment agreement with acknowledgment of permanent loss of WordPress administration. Only then does **DEPLOY INSTANCE** become available. Approvals are enforced on the server, bound to the owner, ciphertext SHA-256 and agreement digest, expire after 30 minutes, and can be consumed once.
5. Deployment saves an encrypted recovery image, replaces the supported files and database, deletes administrative principals, preserves their authored content using disabled attribution accounts, invalidates stored sessions and application passwords, and installs the sealed runtime. The browser's authentication cookies are expired and it returns immediately to the public homepage.

The restored application has no WordPress web login, dashboard, plugin management, REST API or XML-RPC access. Existing administrator cookies do not reopen it. Captured authoring/recovery constants cannot unlock a sealed deployment. `/wp-admin` files remain in the authenticated site image because themes and plugins may include core helpers internally; their HTTP endpoints are denied. Use the provided Nginx rules to deny standalone scripts before PHP executes.

To change the application, publish a new image or provision a fresh WordPress installation through the deployment operator. A trusted offline CLI has an explicit authoring recovery option for publisher disaster recovery. There is no web unlock or hidden administrator account.

## What is captured

All regular files and directories within the WordPress root, including core, plugins (active and inactive), must-use plugins, themes, uploads, hidden files, empty directories and `wp-config.php`; all visible supported base tables in the connected database, including tables without the WordPress prefix. No silent path exclusion is used. Readability, traversal, collision, changed-source and database feature checks reject an incomplete or unsupported image.

The format uses AES-256-GCM authenticated chunks and server-derived key material (HKDF-SHA-256 bound to the plugin folder, then PBKDF2-HMAC-SHA-256 with 600,000 iterations). Every chunk, manifest and terminal boundary is authenticated. A wrong server key, modified chunk, truncated image or trailing bytes must fail before restore changes public files or the database. This is a new container format, not a renamed ZIP.

## Supported v1 scope

- PHP 8.2+ with OpenSSL and mysqli or PDO MySQL; WordPress 6.4+; a single-site installation with its configuration inside its root.
- InnoDB tables that the engine can export and recreate. Views, triggers, routines, events, non-InnoDB tables, generated/spatial columns, symlinks and special files are rejected. Multisite, custom user-table layouts, inbound foreign keys to the users table and cross-database foreign keys are refused for sealed deployment to prevent incomplete account removal or cascading plugin data loss.
- Local files under the selected site root. Bring external content paths into that root before imaging. Cloud-offloaded media, external databases and third-party services are outside the image.
- Destination web restore requires the authored canonical URL and table prefix. It retains destination `wp-config.php` connection settings, then adds the trusted sealed guard. Automatic cross-domain serialized URL migration is not implemented.
- Standard public frontend GET/HEAD/POST routes and static assets. Custom frontend features requiring REST, admin-ajax, XML-RPC, account login or direct plugin PHP endpoints are incompatible with the default sealed policy and require a separately reviewed application profile. The supplied container mounts code read-only; frontend file uploads require separate qualification and a narrowly scoped writable data volume.

This captures the supported WordPress application, not a disk/VM snapshot: server packages, PHP extensions, database grants, DNS, TLS, email, remote storage, filesystem ACLs and file ownership are provisioned separately. It does not remove a server owner's or hosting provider's infrastructure privileges.

## Storage, availability and recovery

Configure an absolute private directory outside **both** the WordPress root and server document root:

```php
define('DUALSCAN_IMAGE_STORAGE', '/srv/private/dualscan-images');
```

New private directories are created with mode `0700`, image/state files with `0600`; existing storage must already have private ownership and permissions. Existing shared directories are never silently chmodded. Server keys and derived encryption material are not saved in job records or browser persistent storage. The persistent private default is a sibling directory outside the WordPress root; configure DUALSCAN_IMAGE_STORAGE explicitly on a production host. The plugin default no longer uses temporary storage for new keys.

The web uploader sends 1 MiB chunks and accepts images up to 64 GiB. The offline engine supports larger bounded images; see [CLI documentation](../bin/README.md). Creation/restore currently run within one long PHP operation. Large sites need a host with sufficient execution time, memory, disk and process lifetime; use the CLI on hosts with short HTTP timeouts. Stop background writers yourself before backup/restore. Maintenance mode covers the destructive web-restore phase but cannot stop unrelated database writers.

Controlled restore errors trigger restoration of the pre-deployment files and database. A killed process or host failure can interrupt recovery; encrypted recovery images are retained outside the public root for offline repair. Do not blindly retry after an interrupted deployment.

See [deployment instructions](../deployment/README.md) for the offline CLI, Nginx front controller and optional always-running container template. The template provides automatic container restart and server-side cron. It does not launch a hosting service, and its container stack has not been executed in this local environment.

## Agreement and licensing

The bundled click-through agreement is a **draft for the publisher's legal review**, not a guarantee of enforceability in any jurisdiction. It includes the requested proprietary reverse-engineering/IP restrictions, release of claims to the extent permitted by law, and permanent WordPress-admin acknowledgment. It expressly preserves applicable open-source license rights and non-waivable statutory protections. WordPress and this plugin's GPL rights are not overridden by the deployment agreement.

The agreement source is `includes/class-deployment-terms.php`. Change its version when changing the agreement; its SHA-256 is bound to each approval. Approval records are private server records, not a tamper-proof external legal evidence service.

## Qualification

See [delivery verification](DELIVERY-VERIFICATION.md), [security review](SECURITY-REVIEW.md), and the repository verification records for local WordPress/MySQL round trips, authorization checks, image integrity tests and source hashes. Test secrets and runtime databases are excluded from delivery archives.

License: GPL-2.0-or-later.

## Server-folder encryption — 1.0.1

Normal web and CLI operations use the actual plugin package folder (normally `dualscan-cloud-os-image-manager`) as the key identifier and HKDF context. A random 32-byte master key is stored as `keyring/<folder>.key` under durable private image storage, with a `0700` directory and `0600` key file. The folder name is public metadata; it is not the secret and is insufficient to decrypt an image. No encryption password or server master key is embedded in the image, plugin, source archive or JavaScript.

Create may initialize a missing key. Inspect and restore require an existing key and never silently generate a replacement. For another server, an operator must provision the matching master key and use the same plugin-folder context. See [server-key provisioning](SERVER-KEYS.md). Export/import it through the offline `bin/keyring.php` tool and a protected operator channel, separately from public images. Keep a durable offline key backup before retiring or reinstalling a server; losing the key loses access to all its images. Never place a key under the website, include it in an application image, or publish it with source. Changing the plugin folder changes the derivation context.

Previously password-protected images remain recoverable through explicit offline `--password-mode`. They are not opened by the passwordless web UI; migrate them using a trusted authoring-recovery environment and create a new server-key image.
