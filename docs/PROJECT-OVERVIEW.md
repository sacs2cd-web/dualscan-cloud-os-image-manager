# Dualscan™ Cloud OS Image Manager

**Author a WordPress application. Protect its image. Deploy its public experience.**

Dualscan Cloud OS Image Manager turns a supported WordPress installation into a portable, encrypted `.dualscan` application image. It keeps site files, plugins, themes, media and database records together, verifies them before restoration, then deploys a public application with WordPress web administration removed.

The source installation remains the publisher's authoring workspace. The restored installation becomes the public runtime.

## One image, a deliberate deployment

| Stage | What happens |
| --- | --- |
| Author | Build the application on a trusted WordPress site, then pause background writers for a consistent capture. |
| Protect | Create the encrypted image without a user password prompt. A random private server key and the installed package-folder context supply the encryption material. |
| Verify | Authenticate the complete image and validate its paths, file hashes, table definitions and database row digests before changing the destination. |
| Confirm | Complete three separate acknowledgments: replace the installation, confirm authorization, then accept the versioned agreement and loss of WordPress web-admin access. |
| Deploy | Save an encrypted recovery image, restore the supported application, remove administrative principals, clear sessions and install the sealed runtime policy. |
| Run | Log out and return to the public homepage. The application serves supported public routes while WordPress login, dashboard and management endpoints remain closed. |

## The technology

**Authenticated application images.** AES-256-GCM protects bounded chunks and the final boundary. The manifest describes the captured files and database snapshots; exact lengths, hashes and row checks reject modified, incomplete or inconsistent payloads before public mutation.

**Private server-managed keys.** A random 32-byte master remains outside the website. HKDF-SHA256 binds the installed plugin-folder context; per-image salt and PBKDF2-HMAC-SHA256 feed the authenticated codec. The folder name is public context, not a password. Destination operators provision the matching private key separately; it is never embedded in the image, plugin or JavaScript.

**Consent enforced on the server.** Deployment plans bind the publisher, image hash and agreement digest. Ordered confirmations expire after 30 minutes and can authorize one deployment job. A page state or button alone cannot approve restoration.

**A public runtime with a defined boundary.** Standard administrative accounts are removed, their supported content attribution is preserved through a disabled principal, and authentication metadata is cleared. An early bootstrap guard denies WordPress web administration independently of captured authoring flags. A host front controller also denies standalone administrative scripts and direct PHP entry points.

**Recovery kept separate.** Catchable deployment failures attempt restoration of the encrypted pre-deployment snapshot. Operators retain private image and key backups for interrupted-process recovery. The original image remains an authoring snapshot; administrative-account removal applies to its deployed copy.

## What has been exercised

Recorded local qualification uses disposable WordPress 7.1.3 sites, native PHP 8.2.0 and MySQL 5.7.39 with synthetic data. It covers complete image round trips, binary/UTF-8 database values, corruption rejection, access checks, server-key provisioning, three-step approval denials, administrative-principal removal, recovery and public-route policy. The current HTTP image fixture contains 14 database tables and 4,306 file/directory entries.

The supplied Docker Compose/Nginx deployment template has configuration checks; its container stack has not been built or executed in this environment. Local test success is not a claim of production hosting certification or guaranteed uptime. The verification records identify the tested builds and exact scope.

## Supported application scope

Version 1.0.1 targets single-site WordPress with its configuration and local content inside the selected site root, supported InnoDB schemas, standard principal tables and a matching destination URL/table prefix. Public GET/HEAD routes, ordinary front-controller POST and approved static assets form the default runtime surface.

Unsupported structures are refused instead of silently excluded: multisite, custom principal tables, symlinks, special files, unsupported database objects and unsafe attribution/foreign-key dependencies. External media, remote services and other databases require separate provisioning. Applications depending on WordPress login, REST, admin-ajax, direct plugin PHP endpoints or writable runtime files need a separately qualified profile.

Despite its product name, a `.dualscan` file is a WordPress application image—not a universal cloud OS, disk, VM or container snapshot. It does not capture hosting accounts, DNS, TLS, mailboxes, server packages, database grants or filesystem ownership. Application policy cannot revoke the server owner's or provider's infrastructure privileges.

## Public source, private operations

The project declares GPL-2.0-or-later. Its deployment agreement preserves applicable open-source and non-waivable rights. Sealed administration is an operational choice for the deployed application, not a restriction on GPL source rights or a claim of secrecy from the host operator.

This public source release is licensed under GPL-2.0-or-later. Private server keys, customer data and operator credentials stay outside the project distribution. Source publication does not deploy a hosted instance or imply production certification.
