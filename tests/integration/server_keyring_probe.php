<?php
/** Native authenticated-image checks of server secrets, folder context and destination provisioning. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 3) { exit(2); }
$runtime = realpath($argv[1]);
if ($runtime === false || !str_contains($runtime, '/dualscan-cloud-os-image-manager-runtime/')) { throw new RuntimeException('Only the owned disposable runtime is allowed.'); }
require dirname(__DIR__, 2) . '/includes/engine-bootstrap.php';
use Dualscan\CloudOS\ServerKeyring;
use Dualscan\CloudOS\ImageCodec;
use Dualscan\CloudOS\SafeFiles;
function keyringRefused(callable $operation, string $name, array &$cases): void {
    try { $operation(); } catch (Throwable $e) { $cases[$name] = ['passed' => true, 'rejection' => $e->getMessage()]; return; }
    throw new RuntimeException('Unexpected success: ' . $name);
}
$root = $runtime . '/tmp/keyring-probe-' . bin2hex(random_bytes(8));
$publicRoots = [$runtime . '/sites/source', $runtime . '/sites/target'];
$folder = 'dualscan-cloud-os-image-manager'; $cases = [];
SafeFiles::privateDirectory($root);
try {
    $primary = new ServerKeyring($root . '/primary', $folder, $publicRoots);
    $secret = $primary->resolveOrCreate();
    $status = $primary->status();
    $masterPath = $root . '/primary/keyring/' . $folder . '.key';
    $master = file_get_contents($masterPath);
    if (strlen($master) !== 32 || (fileperms($masterPath) & 0777) !== 0600 || (fileperms(dirname($masterPath)) & 0777) !== 0700 || !hash_equals($secret, $primary->requireExisting())) { throw new RuntimeException('Persistent private master key properties failed.'); }
    $cases['privatePersistentRandomMaster'] = ['passed' => true, 'bytes' => 32, 'fileMode' => '0600', 'directoryMode' => '0700', 'keyId' => $status['keyId']];
    $plain = $root . '/payload.bin'; $image = $root . '/sample.dualscan'; $output = $root . '/decoded.bin';
    file_put_contents($plain, "Synthetic server-managed image fixture\n" . str_repeat('protected fixture data', 2048)); chmod($plain, 0600);
    ImageCodec::encrypt($plain, $image, $secret);
    if (str_contains(file_get_contents($image), $master) || str_contains(file_get_contents($image), $secret)) { throw new RuntimeException('Raw master/derived secret appeared in the encrypted image.'); }
    $cases['masterAndDerivedSecretNotEmbeddedInImage'] = ['passed' => true];
    $backup = $root . '/provisioning/master.key'; $primary->exportTo($backup);
    $destination = new ServerKeyring($root . '/destination', $folder, $publicRoots);
    $destination->importFrom($backup);
    ImageCodec::decrypt($image, $output, $destination->requireExisting());
    if (hash_file('sha256', $plain) !== hash_file('sha256', $output) || $destination->status()['keyId'] !== $status['keyId']) { throw new RuntimeException('Matching destination provisioning failed.'); }
    unlink($output);
    $cases['sameFolderAndProvisionedMasterDecryptOnDestination'] = ['passed' => true];
    $wrong = new ServerKeyring($root . '/wrong-master', $folder, $publicRoots);
    $wrongSecret = $wrong->resolveOrCreate();
    keyringRefused(static fn() => ImageCodec::decrypt($image, $output, $wrongSecret), 'wrongMasterCannotOpenImage', $cases);
    if (is_file($output)) { throw new RuntimeException('Wrong master left a plaintext output file.'); }
    $otherFolder = new ServerKeyring($root . '/wrong-folder', 'dualscan-other-package', $publicRoots);
    $otherFolder->importFrom($backup);
    $otherSecret = $otherFolder->requireExisting();
    if (hash_equals($secret, $otherSecret)) { throw new RuntimeException('Folder identifier did not bind key derivation.'); }
    keyringRefused(static fn() => ImageCodec::decrypt($image, $output, $otherSecret), 'sameMasterWrongFolderCannotOpenImage', $cases);
    if (is_file($output)) { throw new RuntimeException('Wrong folder left a plaintext output file.'); }
    $missing = new ServerKeyring($root . '/missing', $folder, $publicRoots);
    keyringRefused(static fn() => $missing->requireExisting(), 'inspectDoesNotGenerateMissingMaster', $cases);
    if (is_file($root . '/missing/keyring/' . $folder . '.key')) { throw new RuntimeException('Missing master was generated during inspection.'); }
    keyringRefused(static fn() => new ServerKeyring($publicRoots[0] . '/wp-content/forbidden-keyring', $folder, $publicRoots), 'publicKeyringDirectoryRejected', $cases);
    keyringRefused(static fn() => $primary->exportTo($publicRoots[0] . '/forbidden-master.key'), 'publicMasterExportRejected', $cases);
    keyringRefused(static fn() => new ServerKeyring($root . '/bad-folder', '../bad-folder', $publicRoots), 'folderTraversalRejected', $cases);
    chmod($masterPath, 0644);
    try { keyringRefused(static fn() => $primary->requireExisting(), 'worldReadableMasterRejected', $cases); }
    finally { chmod($masterPath, 0600); }
    $report = ['passed' => true, 'runtime' => 'Native PHP/OpenSSL authenticated image codec and actual filesystem key permissions', 'scope' => 'Synthetic small image and isolated private keyrings outside both document roots; no user keys', 'cases' => $cases, 'serverKeyringSourceSha256' => hash_file('sha256', dirname(__DIR__, 2) . '/includes/class-server-keyring.php'), 'rawKeysWrittenToReports' => false];
    file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode(['passed' => true, 'cases' => count($cases), 'report' => $argv[2]]), "\n";
} finally { SafeFiles::remove($root); }
