# Server-folder encryption

Normal operations require no image password. The installed plugin directory, normally `dualscan-cloud-os-image-manager`, is the key identifier and derivation context. A random 32-byte secret is kept separately under durable private storage. Knowing the public plugin directory name does not decrypt an image.

Configure production storage outside every web-served directory:

```php
define('DUALSCAN_IMAGE_STORAGE', '/srv/private/dualscan-images');
```

The PHP worker must own the storage and key files. The directory must be `0700`; a master key is `0600`. The first create operation initializes the key if needed. Inspect and restore refuse a missing key instead of creating a different one. Normal key status includes a nonsecret key ID, never the secret.

## Operator provisioning

Run the source package's CLI as the same OS user that owns the web keyring. Example environment, with paths adjusted for the actual host:

```sh
export DUALSCAN_PRIVATE_STORAGE=/srv/private/dualscan-images
export DUALSCAN_PUBLIC_ROOT=/var/www/html
export DUALSCAN_PLUGIN_FOLDER=dualscan-cloud-os-image-manager
php bin/keyring.php status
php bin/keyring.php init
```

Keep an independent offline key backup in an owner-only directory **outside** the website and source package:

```sh
php bin/keyring.php export /offline/dualscan-key-backups/publisher-master.key
```

The export is a raw secret file. It must never accompany a publicly distributed image or be committed to Git, included in a plugin ZIP, served by HTTP, or pasted into a support request. The tool refuses public destinations and existing output files. Transfer the backup through a protected operator channel and keep it separately from the images.

On a destination host, configure the same plugin-folder context and its own private storage, then provision the matching key **before** inspecting an image:

```sh
php bin/keyring.php import /offline/dualscan-key-backups/publisher-master.key
php bin/keyring.php status
```

Matching key IDs confirm matching folder/key context without revealing the secret. A different existing key is never overwritten automatically. Use a separately provisioned private keyring when operating a different publisher's image domain. Renaming a `.dualscan` file does not change its key; changing the plugin folder context does.

The file is not a password to embed in the plugin or image. Only its in-memory HKDF-derived value is handed to the authenticated image codec. Database connection passwords and application-password records are separate WordPress concepts; they do not supply this encryption key.

## Recovery and compatibility

Retain the key before terminating a server or discarding private storage. **An image without its matching key cannot be restored.** Auto-generation of a new key cannot recover older images. Back up private storage/key material through the operator's separate secured process.

Previously password-protected images use explicit offline `--password-mode` only. They can be restored into a trusted authoring environment, then re-authored using server-folder mode. The web interface does not prompt for or accept image passwords.
