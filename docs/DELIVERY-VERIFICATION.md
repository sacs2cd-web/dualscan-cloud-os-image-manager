# Release verification — 1.0.1

The installable plugin, independent source, deployment templates are packaged in `dist/`. `SHA256SUMS` and `RELEASE-MANIFEST.json` identify the deliverables. The builder reads back every ZIP member and verifies its SHA-256 against the source file. Private images, live-site configuration and runtime databases are not packaged.

## Executed qualification

The tests used disposable local WordPress **7.1.3**, PHP **8.2.0**, and MySQL **5.7.39**. Official WordPress core checksums matched 3,782 files before synthetic fixtures and the independent manager were added. No live user site was changed.

| Check | Result |
| --- | --- |
| Engine unit tests | 41 passed, including authenticated encryption, malformed images, path checks, sealing and rollback |
| Native full WordPress/database suite | 12 passed; approximately 111 MB image, 4,301 filesystem entries and 14 database tables |
| Focused principal/foreign-key policy | 8 passed, including dangerous custom roles/direct caps and cascade-loss refusal |
| Authoring access | 3 role cases and 19 actual HTTP permission cases passed |
| Plain/pretty REST URL and error handling | 5 checks executing the actual production JavaScript request function passed |
| Full browser-protocol workflow | Create, download/hash verification, 107 upload chunks, authenticated inspect, sequential approval and sealed restore passed |
| Invalid deployment approvals | 17 skipped, unchecked, mismatched, expired or reused approval cases refused |
| Sealed HTTP access | 23 cases passed, including anonymous and old-owner cookie denial and public homepage availability |
| Independent public-router policy | 21 cases passed, including standalone admin PHP denial, encoded paths, public GET/HEAD/POST and CSS |
| PHP syntax and template checks | Production PHP syntax checks passed; Compose YAML and restart/read-only/listener assertions passed |

The restored database removed both administrative test accounts, retained the non-administrative editor, removed all stored sessions/application passwords, and created a disabled attribution principal. Ten post, four comment and two link rows preserved all other column values while their removed-author IDs were reassigned. Unaffected tables and site options remained identical.

A fault deliberately injected **after account deletion, policy installation and config patching** restored the complete original authoring state: old administrators, database rows, target-only table, files and exact original wp-config. Wrong passwords, image tampering and truncation failed before public mutation. Mixed Latin-1/UTF-8 bytes, binary data, JSON, timestamps across differing session time zones, BIT values and maximum unsigned BIGINT were independently compared.

Successful web deployment returned 17 expired authentication-cookie headers and the public homepage redirect. Production JavaScript uses `window.location.replace` immediately after the successful operation response. All three confirmations and agreement digest/owner/image binding are checked on the server; editing browser controls cannot bypass them. Consent is applied before replacement, never inferred from elapsed time.

The original 1.0.0 runtime qualification binds 28 installed production files and 12 evidence records to that release. It records a CLI README documentation-only change after testing. Version 1.0.1 has its own installed-source inventory and supplemental current-source coverage described below; the older inventory is retained without rewriting history. See the qualification JSON records and named underlying evidence for details.

## Browser proof

The current presentation uses actual application screenshots in `docs/media/`. The workspace and all three deployment confirmations were visually verified and hash-bound. The final agreement remained unchecked and no deployment was submitted for presentation capture. See `PRESENTATION-VERIFICATION.md` and the presentation capture records. Earlier functional qualification independently verified denied administrative routes and the running public homepage.

## Not qualified or deployed

Docker and Nginx are not installed in this local environment, so their supplied production templates were **not executed**. The independent native router was executed; this does not substitute for target-host Nginx/TLS/container qualification. No cloud resources, hosting account, DNS or live instance were created or modified. Cross-domain migration, multisite, unsupported schema layouts and large-production-load behavior are not claimed.

Always-running behavior requires a functioning host web/database service. The optional Compose template specifies automatic restarts, a read-only site mount, a private PHP network, no SSH service and server-side cron. It is a deployment starting point, not a promise of uninterrupted uptime or removal of hosting-provider privileges.

The bundled agreement is a draft for publisher legal review. It preserves applicable open-source and non-waivable rights; no statement guarantees that a blanket release, IP restriction or reverse-engineering restriction is enforceable everywhere.

## 1.0.1 server-folder key qualification

Version 1.0.1 removes image passwords from the web forms and operation requests. The public plugin-folder basename identifies a private server key and supplies HKDF context; it is not itself the encryption secret. Master keys remain outside all document roots and site images. Matching private keys must be provisioned separately on destination servers.

The new native server-keyring probe passed 10 actual filesystem/codec cases; the engine agent's additional 21 keyring tests cover actual encrypted restore, wrong-key and wrong-folder refusal before mutation, private import/export, concurrent initialization and absent secrets. The 1.0.1 full WordPress HTTP run created a 111,375,929-byte image, verified its download, uploaded 107 chunks, authenticated it without a password, completed all three approvals, removed administrators, expired cookies and returned the public homepage redirect. All 17 invalid approval cases and 23 sealed HTTP checks passed again. The public router's 21 cases and JavaScript endpoint regressions also passed again.

The wrong-master web inspect test independently compared critical public files and database values and found no mutation. The correct key was then reinstated and the deployment completed. Raw, hexadecimal and base64 master material and the derived codec secret were absent from the image, manifest, job records and browser JavaScript. Missing-key inspection did not create a replacement key.

`docs/media/workspace.jpg` is the actual updated authoring UI. Browser inspection reported **zero password inputs**, and the initial API notice remained hidden. The earlier screenshots and original core database/rollback reports are retained as historical evidence; they do not claim to show the new UI. Consult the final server-key qualification for the exact source inventory and any post-HTTP error-message or targeted keyring-race changes. Those are disclosed and covered by the new keyring tests rather than relabeling the earlier large-image run.
