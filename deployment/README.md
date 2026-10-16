# Public application deployment

Restoring a `.dualscan` image through the manager defaults to a sealed public WordPress runtime. The authored application remains live; WordPress administration and web authentication are disabled. The source authoring site is unaffected by backup creation.

## On an existing PHP/WordPress host

Install and activate the manager on the destination, configure private storage and compatible database credentials, and use its image review and three-confirmation deployment flow. The final action ends the current session and returns to the public homepage. Apply equivalent host-level restrictions from `nginx.conf` as part of provisioning; the MU/config guards alone cannot intercept a standalone PHP script that never loads WordPress. Deny the whole wp-admin path, all other PHP entry points, configuration/dotfiles and backup downloads. Keep private images outside the document root.

The CLI in `bin/dualscan.php` can restore without web administration. It requires explicit replacement confirmation, private storage, a compatible destination database, and the matching server-folder key provisioned outside the website. Use its documented environment variables. Destination credentials must be valid for the deployment network; localhost inside a container is the container itself. Retain a suitable destination wp-config and use `--preserve-config` when required. Preserve the authored canonical URL and prefix. Do not do blind SQL string replacement on serialized site data.

After restore, inspect the returned `deployment` result and its declared policy hashes and database mutations. Stop serving requests until deployment is complete. Background writers must be stopped independently. Successful web deployment clears its own maintenance marker; fatal recovery failures retain it for offline operator recovery.

## Optional container template — not locally executed

The supplied Compose template wraps an **already restored, sealed** site and its **already restored, reachable** MySQL database. It does not provision a VM, cloud account or database, restore an image automatically, configure DNS/TLS, or charge for hosting.

1. Restore the image using the offline engine or manager in a controlled staging environment.
2. Put the restored root at an absolute path outside the manager source. Configure its destination DB connection for the container's network. Configure the canonical hostname to match the authored image and terminate TLS at a trusted reverse proxy.
3. Configure ownership or a narrow ACL so PHP workers (`www-data`, UID 33 in the provided image) can read the restored site and wp-config without exposing its credentials. For example, use worker ownership and `0400` for wp-config on a dedicated deployment tree. Do not make credentials world-readable to work around permissions. The template mounts the root read-only.
4. From the source root, set `DUALSCAN_RESTORED_SITE` to that absolute path and run:

```sh
docker compose -f deployment/compose.yaml config
docker compose -f deployment/compose.yaml up -d --build
```

The public listener binds only `127.0.0.1:8080` for the host reverse proxy. PHP-FPM is exposed only on the container network, and no SSH service/port is provided. `restart: unless-stopped` restarts the application after container failure or Docker daemon restart; enable the Docker service at host startup. The server-side cron container invokes wp-cron without exposing its HTTP endpoint. Docker availability, network/database readiness, daemon startup and actual uptime remain hosting responsibilities.

Before production, build and run this template on the target Linux host, pin approved image digests, configure TLS/backups/DB readiness, verify credentials and file ownership, then test every public route and required plugin feature. Docker/Nginx are not installed in the current local test environment; only native PHP/MySQL and the local route policy have executable qualification here. Keep services current through the deployment operator; a sealed WordPress admin cannot install security updates.

## Public behavior and recovery

The sealed policy permits public front-controller GET, HEAD and POST and a narrow static-asset set. It denies wp-admin, wp-login, REST, XML-RPC, admin-ajax, direct plugin PHP and related management dispatchers. Public forms implemented on the normal frontend can work; REST/admin-ajax/account-dependent applications must be redesigned or separately profiled before publication. Preserve authored content and capabilities through a qualified app profile rather than weakening the general policy.

Never run PHP's built-in server as the production host. `public-router.php` is provided only for local route qualification, for example:

```sh
php -S 127.0.0.1:8769 -t /absolute/restored/site deployment/public-router.php
```

An operator can stop and replace the deployment with another sealed image or a fresh WordPress install. Trusted offline `--authoring-recovery` is a disaster-recovery operation outside the deployed public app; there is no HTTP recovery endpoint. The original image retains its authored accounts for offline recovery, while the running sealed database deletes administrative principals. Host filesystem/database root authority is unaffected by deleting WordPress accounts. Do not advertise that this application can revoke provider SSH or guarantee invulnerability.
