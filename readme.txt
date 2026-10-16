=== Dualscan Cloud OS Image Manager ===
Contributors: dualscan
Requires at least: 6.4
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-key encrypted .dualscan WordPress application images and public-only deployments.

== Description ==
Capture supported WordPress files, plugins, themes, uploads and MySQL base tables in one authenticated encrypted image. Restore only after complete image verification and three server-enforced confirmations. Final deployment removes WordPress administrator accounts while preserving authored content, invalidates sessions, seals web administration, logs out and returns to the public homepage.

Original authoring sites retain trusted publisher access. Restored appliances have no WordPress web unlock. Nginx deployment rules are required to restrict standalone scripts independently of WordPress. Server/provider privileges are outside this plugin's control.

Single-site PHP 8.2+ installations, same canonical URL and table prefix, local regular files and supported InnoDB tables. Multisite, external files, symlinks, unsupported database features and cross-domain migration are not supported. Frontend REST/admin-ajax/login-dependent applications require separate qualification. Read README.md before production deployment.

No external storage service or tracking is used. Durable private local storage and a server-managed key are required. The public plugin-folder name identifies the key context; it is not the encryption secret. No password is embedded or requested. The EULA is a publisher-review draft preserving non-waivable and open-source rights.

== Installation ==
1. Upload the ZIP through Plugins > Add Plugin on the trusted authoring site.
2. Activate as the publishing administrator.
3. Configure private DUALSCAN_IMAGE_STORAGE outside all public document roots.
4. Open Dualscan Images. Pause writes before image creation or deployment.

== Changelog ==
= 1.0.1 =
Passwordless server-folder keyring plus the independent implementation, encrypted image engine, three-confirmation deployment and sealed public runtime.
