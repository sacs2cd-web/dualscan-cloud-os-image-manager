# Disposable native integration tests

These tests operate only on the exclusively owned local runtime path containing
`dualscan-cloud-os-image-manager-runtime`. They reject ordinary website paths.
The fixture usernames and passwords are synthetic local test credentials, not
production credentials. No live site, account or database is used.

The current 1.0.1 web qualification is `http_keyring_roundtrip.py`. It submits
no encryption password fields or parameters. The server creates its own
32-byte master in the explicit private storage directory outside WordPress;
the test proves a wrong server key cannot inspect the image or change public
files/database rows, then provisions the original key and completes deployment.
Master bytes, their hex/base64 encodings and the derived secret are checked
against the image, manifest, job JSON and public JavaScript without writing
those secrets to a report. `server_keyring_probe.php` independently qualifies
same-master destination provisioning, folder-context binding, missing/wrong
keys, private permissions and rejected public exports. The original
`http_roundtrip.py` and its evidence describe the historical 1.0.0 web flow;
legacy password images now use the explicit offline CLI recovery mode.

The qualified runtime uses existing MAMP PHP 8.2.0 and MySQL 5.7.39 binaries,
fresh databases `dualscan_source` and `dualscan_target` at `127.0.0.1:33617`,
and official WordPress 7.1.3 trees at localhost ports 8767 and 8768. See
`verification/runtime-environment.json` for the archive hash and official core
file checksum results. The actual runtime and its databases are outside this
source project.

Run `fixtures.php` with the disposable source WordPress root to create uploads,
hidden/root files, empty files/directories, active/inactive/MU plugin fixtures,
a theme and prefixed/non-prefixed custom database tables. Custom data covers
binary bytes, Unicode, NULL/empty values, exact decimals, PHP serialized values,
Latin-1 and UTF-8 columns, JSON, nullable TIMESTAMP, unsigned BIGINT and BIT.

`sync_plugin.py` copies current production code into both fixture installs and
records every installed code hash. `permissions.php` and
`http_permissions.py` test the original authoring installation before image
deployment: real WordPress roles, cookies, nonces, admin screens and REST access.

`http_roundtrip.py` uses the installed factory manager API to create, download,
upload in 1 MiB chunks, inspect and restore an encrypted image. It deliberately
mutates synthetic source files and a custom table row before restoring them.
It verifies three sequential deployment confirmations, rejection of skipped,
unchecked, expired, mismatched or reused approvals, final logout cookies and the
public homepage redirect. Default restore seals the source fixture; the test
does not unseal it. Both anonymous requests and a replay of the previously
authenticated owner cookie are denied administration/login/REST/XML-RPC access.

`roundtrip.php` exercises the independent PDO adapter against the two real
WordPress trees and databases. It compares every source file/directory and
every unaffected table's rows and CREATE TABLE definition. Source and target connections
have different session time zones. It also checks raw mixed-character-set
column bytes, JSON and timestamp instants, original session settings, an
injected failure after actual account removal and guard/bootstrap installation,
successful byte-exact rollback to the original authoring state, and rejection
of wrong passwords, damage, truncation, unsupported objects and symbolic links.
The independent account audit verifies only the declared user/metadata and
content-attribution changes: both administrator accounts are removed, ordinary
users remain, all session/application-password records are removed, and posts,
comments and links retain every field except the required author reassignment.

`policy_preflight.php` checks custom administrative roles, direct plugin
management capabilities, foreign-key refusal before account deletion and
visible cross-database dependencies. `database_adapter.php` checks raw column
bytes and exact DDL without creating a full file image.

`public_router.py` separately qualifies the supplied PHP router on port 8769,
including standalone admin/plugin PHP scripts, protected static policy files,
encoded paths and frontend GET/HEAD/POST. This is not execution of Nginx or
Docker. `manager_endpoint.mjs` executes the production JavaScript request
function against plain and pretty WordPress REST URLs.

`verification/runtime-qualification.json` binds the final runtime evidence and
installed production code hashes. Documentation-only changes are recorded
separately from executable code.
`verification/runtime-keyring-qualification.json` supersedes it for the current
server-managed encryption UI. It explicitly separates the full HTTP tested
installed code from three later synchronized code differences: authentication-error
wording, concurrent private-directory creation and private lock creation. Current
41 engine tests, 21 keyring tests and a fresh 10-case native keyring probe qualify
the current code; the full HTTP fixture was not silently relabeled as identical.

Do not run tests concurrently with fixture-site editing or browser operations.
The test servers bind to localhost and must be stopped after qualification.
