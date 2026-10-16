# Presentation verification

The four screenshots in `docs/media` were captured from the actual running Dualscan Cloud OS Image Manager 1.0.1 in Chrome on October 16, 2026. They show the production interface, not rendered concept artwork. Each final image was opened independently and visually checked: the intended panel is present, the workspace is complete, and the text and controls are legible.

The isolated demonstration used WordPress 7.1.3, PHP 8.2.0 and MySQL 5.7.39 on localhost. The site was named **Dualscan Cloud Demo**, with synthetic published content headed **Welcome to your application**. It did not contain a customer site or customer accounts.

| Screenshot | Actual state shown |
| --- | --- |
| `workspace.jpg` | Authoring workspace with automatic server-managed encryption and a saved recovery image. |
| `recovery-step-1.jpg` | First confirmation: replacement of this installation and removal of administrative accounts while preserving published content. |
| `recovery-step-2.jpg` | Second confirmation: authorization to deploy the included material. The first server-bound confirmation had been accepted. |
| `recovery-step-3-eula.jpg` | Third confirmation: the versioned deployment agreement, rights acknowledgment and permanent loss of WordPress administration. The first two confirmations had been accepted; all three final acknowledgments remained unchecked and **DEPLOY INSTANCE** was disabled. |

A real encrypted `.dualscan` image was created and downloaded through the authenticated production API, then uploaded through the normal browser interface and fully inspected before the confirmation screens appeared. The image contained 14 database tables and 4,320 file/directory entries, and measured 111,383,338 bytes. Its SHA-256 was `f4af8e6359a3dcc00bb1b92516da13cf6e6e4381a5b0f2b841fb4e7e0251f571`.

The final **DEPLOY INSTANCE** action was never submitted during these captures. Independent runtime records confirm that the approval plans stopped after confirmation two and no restore job was submitted. The demonstration remained an authoring installation with its administrative accounts retained. After the captures, the browser tab was closed and all task-owned PHP/MySQL listeners were stopped.

The screenshots illustrate the interface and confirmation sequence. Completed restore, account removal, logout, encrypted-image authentication and access restrictions were exercised separately; see `verification/runtime-keyring-qualification.json` for results, scope and disclosed source differences. The presentation itself does not establish compatibility with a particular production host.

The current source installed for capture is recorded in `verification/runtime-presentation-plugin-files.json`. Image preparation, final screenshot hashes and shutdown are recorded in `verification/runtime-presentation-preparation.json`, `verification/runtime-presentation-captures.json` and `verification/runtime-presentation-shutdown.json`.

The presentation contains screenshots and safe verification metadata. The actual demonstration image, private server key, database files, session cookies and fixture login credentials remain outside the deliverable.
