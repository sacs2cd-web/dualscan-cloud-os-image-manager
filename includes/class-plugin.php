<?php
declare(strict_types=1);

namespace Dualscan\CloudOS;

final class Plugin
{
    private const API = 'dualscan-cloud/v1';
    private const CHUNK = 1048576;
    private const MAX_IMAGE = 68719476736;

    public static function boot(): void
    {
        $plugin = new self();
        add_action('admin_menu', [$plugin, 'menu']);
        add_action('admin_enqueue_scripts', [$plugin, 'assets']);
        add_action('rest_api_init', [$plugin, 'routes']);
        add_action('admin_post_dualscan_cloud_download', [$plugin, 'download']);
    }

    public function menu(): void
    {
        add_menu_page('Dualscan Cloud OS Image Manager', 'Dualscan Images', 'dualscan_manage_images', 'dualscan-cloud-images', [$this, 'page'], 'dashicons-database-export', 58);
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_dualscan-cloud-images' || !current_user_can('dualscan_manage_images')) {
            return;
        }
        wp_enqueue_style('dualscan-cloud', plugins_url('assets/manager.css', DUALSCAN_CLOUD_FILE), [], DUALSCAN_CLOUD_VERSION);
        wp_enqueue_style('dualscan-deployment', plugins_url('assets/deployment.css', DUALSCAN_CLOUD_FILE), ['dualscan-cloud'], DUALSCAN_CLOUD_VERSION);
        wp_enqueue_script('dualscan-cloud', plugins_url('assets/manager.js', DUALSCAN_CLOUD_FILE), [], DUALSCAN_CLOUD_VERSION, true);
        wp_add_inline_script('dualscan-cloud', 'window.DualscanCloud=' . wp_json_encode([
            'api' => esc_url_raw(rest_url(self::API . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'siteUrl' => home_url('/'),
            'version' => DUALSCAN_CLOUD_VERSION,
        ]) . ';', 'before');
    }

    public function page(): void
    {
        if (!current_user_can('dualscan_manage_images')) {
            wp_die('Site owner access is required.', '', ['response' => 403]);
        }
        ?>
        <main id="dualscan-cloud" class="dc-shell">
          <header class="dc-topbar"><div class="dc-brand"><span class="dc-mark" aria-hidden="true">◇</span><div><span class="dc-eyebrow">DUALSCAN CLOUD</span><h1>OS Image Manager</h1></div></div><span class="dc-owner"><i></i> Owner workspace</span></header>
          <section class="dc-hero"><div><p class="dc-eyebrow">YOUR SITE. READY TO RETURN.</p><h2>One image.<br>Your entire WordPress.</h2><p>Keep your files, plugins, themes, media and database together in a protected <strong>.dualscan</strong> image.</p></div><div class="dc-hero-art" aria-hidden="true"><div class="dc-stack dc-stack-back"></div><div class="dc-stack dc-stack-mid"></div><div class="dc-stack dc-stack-front"><span>◇</span><small>.dualscan</small></div><span class="dc-orbit"></span></div></section>
          <div class="dc-overview"><div><span class="dc-stat-label">CURRENT SITE</span><strong><?php echo esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)); ?></strong></div><div><span class="dc-stat-label">IMAGE FORMAT</span><strong>.dualscan</strong></div><div><span class="dc-stat-label">AFTER RESTORE</span><strong>Public application only</strong></div></div>
          <div id="dc-notice" class="dc-notice" role="status" aria-live="polite" hidden></div>
          <section id="dc-deployed" class="dc-card dc-deployed" hidden aria-live="polite"><img src="<?php echo esc_url(plugins_url('assets/public-runtime.svg', DUALSCAN_CLOUD_FILE)); ?>" alt="Public application running. WordPress administration removed."><div><p class="dc-eyebrow">RESTORE COMPLETE</p><h2>Your application is running.</h2><p>Administrator accounts have been removed and their published content preserved. WordPress login, the dashboard and management APIs are disabled.</p><p>To author a new version, replace this deployment with a new .dualscan image or a fresh WordPress installation.</p><a class="dc-button dc-primary" href="<?php echo esc_url(home_url('/')); ?>">Open your application →</a></div></section>
          <div class="dc-columns">
            <section class="dc-card"><div class="dc-card-icon" aria-hidden="true">↗</div><h3>Create an image</h3><p>Save a complete, verified copy of this supported WordPress installation.</p>
              <form id="dc-create"><label for="dc-title">Image name</label><input id="dc-title" name="title" value="<?php echo esc_attr(get_bloginfo('name') . ' · ' . wp_date('M j, Y')); ?>" maxlength="100" required>
                <p class="dc-hint">Encryption is managed automatically by this server. Keep a separate operator backup of its encryption key.</p><label class="dc-check"><input type="checkbox" name="quiet" required><span>I have paused site changes and background writers for a consistent backup.</span></label><button class="dc-button dc-primary" type="submit"><span>＋</span> Create .dualscan image</button>
              </form>
            </section>
            <section class="dc-card"><div class="dc-card-icon dc-cyan" aria-hidden="true">↙</div><h3>Restore an image</h3><p>Open a .dualscan image, review its contents, then restore this site.</p>
              <form id="dc-inspect"><label class="dc-drop" for="dc-image"><span class="dc-upload-icon" aria-hidden="true">⇧</span><strong id="dc-file-label">Choose a .dualscan image</strong><span>Protected site image · uploaded in small parts</span><input id="dc-image" type="file" accept=".dualscan" required></label><p class="dc-hint">This server must have the matching encryption key. No password is needed.</p><button class="dc-button dc-secondary" type="submit">Review image <span>→</span></button></form>
              <div id="dc-review" class="dc-review" hidden><dl id="dc-review-details"></dl>
                <section id="dc-confirm-1"><p class="dc-eyebrow">CONFIRMATION 1 OF 3</p><h4>Replace this installation</h4><p>The image replaces this site’s files and database. A protected recovery image is created first. Administrator accounts will be removed while published content is preserved.</p><label class="dc-check"><input id="dc-restore-quiet" type="checkbox"><span>I have paused site changes and background writers, retained my image and arranged a separate server-key backup, and confirm replacement of this installation.</span></label><button id="dc-confirm-first" class="dc-button dc-secondary" type="button" disabled>Confirm replacement →</button></section>
                <section id="dc-confirm-2" hidden><p class="dc-eyebrow">CONFIRMATION 2 OF 3</p><h4>Confirm your rights</h4><p>Deploy only software, media and data you are authorized to use. Restricted IP and private administration remain unavailable through the public application.</p><label class="dc-check"><input id="dc-ip-accepted" type="checkbox"><span>I have the necessary rights to this image and agree not to misuse protected IP or bypass application access controls.</span></label><button id="dc-confirm-second" class="dc-button dc-secondary" type="button" disabled>Confirm authorized use →</button></section>
                <section id="dc-confirm-3" hidden><p class="dc-eyebrow">CONFIRMATION 3 OF 3</p><h4>Deploy a public application</h4><p id="dc-terms-version"></p><pre id="dc-terms" class="dc-terms" tabindex="0"></pre><label class="dc-check"><input id="dc-eula-accepted" type="checkbox"><span>I have read and agree to the deployment agreement, including the restrictions on proprietary reverse engineering and IP misuse, subject to applicable law and open-source licenses.</span></label><label class="dc-check"><input id="dc-claims-accepted" type="checkbox"><span>I accept the risks and waive and release claims to the extent legally permitted. Non-waivable rights remain protected.</span></label><label class="dc-check"><input id="dc-admin-waived" type="checkbox"><span>I understand that I will be logged out and will never have WordPress Admin access to this deployed instance. The dashboard, login and management APIs will be disabled. Changes require replacing the deployment.</span></label><button id="dc-restore" class="dc-button dc-danger" type="button" disabled>DEPLOY INSTANCE</button></section>
              </div>
            </section>
          </div>
          <section id="dc-progress" class="dc-card dc-progress" aria-live="polite" hidden><div><span class="dc-pulse"></span><h3 id="dc-phase">Preparing your image…</h3><strong id="dc-percent"></strong></div><progress id="dc-progress-bar" max="100" value="0"></progress><p id="dc-progress-note">Keep this window open while the operation completes.</p></section>
          <section class="dc-library"><div class="dc-section-head"><div><p class="dc-eyebrow">YOUR RECOVERY LIBRARY</p><h3>Saved images</h3></div><button id="dc-refresh" class="dc-text-button" type="button">Refresh ↻</button></div><div id="dc-image-list" class="dc-image-list"><div class="dc-empty"><span>◇</span><strong>Your first recovery point starts here.</strong><p>Create an image to keep a verified copy of your site.</p></div></div></section>
          <footer class="dc-footer"><span>Dualscan Cloud OS Image Manager <b><?php echo esc_html(DUALSCAN_CLOUD_VERSION); ?></b></span><span>Private storage · Owner access · Verified images</span></footer>
        </main>
        <?php
    }

    public function permission(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!current_user_can('dualscan_manage_images') || !wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new \WP_Error('dualscan_forbidden', 'Site owner access and a valid session are required.', ['status' => 403]);
        }
        return true;
    }

    public function routes(): void
    {
        $permission = [$this, 'permission'];
        foreach ([
            ['/images', 'GET', 'images'], ['/imports', 'POST', 'startImport'],
            ['/imports/(?P<id>[a-f0-9]{32})/chunks', 'POST', 'uploadChunk'],
            ['/deployments', 'POST', 'startDeployment'], ['/deployments/(?P<id>[a-f0-9]{32})/confirm', 'POST', 'confirmDeployment'],
            ['/jobs', 'POST', 'startJob'], ['/jobs/(?P<id>[a-f0-9]{32})', 'GET', 'jobStatus'],
            ['/jobs/(?P<id>[a-f0-9]{32})/run', 'POST', 'runJob'],
        ] as [$path, $method, $handler]) {
            register_rest_route(self::API, $path, ['methods' => $method, 'permission_callback' => $permission,
                'callback' => fn(\WP_REST_Request $request) => $this->respond(fn() => $this->$handler($request))]);
        }
    }

    private function respond(callable $action)
    {
        try {
            if (is_multisite()) {
                throw new \RuntimeException('This release supports a single WordPress installation. Multisite requires separate qualification.');
            }
            return rest_ensure_response($action());
        } catch (\Throwable $error) {
            return new \WP_Error('dualscan_operation', $error->getMessage(), ['status' => 400]);
        }
    }

    private function storage(): string
    {
        $path = defined('DUALSCAN_IMAGE_STORAGE') ? (string) DUALSCAN_IMAGE_STORAGE : dirname(rtrim(ABSPATH, '/\\')) . '/.dualscan-cloud-private-' . substr(hash('sha256', ABSPATH . DB_NAME), 0, 20);
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new \RuntimeException('Configure an absolute private DUALSCAN_IMAGE_STORAGE directory outside the website.');
        }
        $real = SafeFiles::privateDirectory($path); $root = realpath(ABSPATH);
        if ($real === false || $root === false || $real === $root || str_starts_with($real . '/', rtrim($root, '/') . '/')) {
            throw new \RuntimeException('Image storage must be outside the public WordPress directory.');
        }
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        if ($documentRoot !== false && ($real === $documentRoot || str_starts_with($real . '/', rtrim($documentRoot, '/') . '/'))) {
            throw new \RuntimeException('Private image storage must also be outside the server document root.');
        }
        return $real;
    }

    private function recordPath(string $id, string $kind = 'job'): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new \RuntimeException('Invalid operation identifier.'); }
        return $this->storage() . '/' . $kind . '-' . $id . '.json';
    }

    private function writeRecord(array $record, string $kind = 'job'): void
    {
        $path = $this->recordPath($record['id'], $kind);
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $data = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) { throw new \RuntimeException('Unable to save operation state.'); }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new \RuntimeException('Unable to publish operation state.'); }
    }

    private function readRecord(string $id, string $kind = 'job'): array
    {
        $path = $this->recordPath($id, $kind);
        if (!is_file($path) || is_link($path)) { throw new \RuntimeException('This operation is not available.'); }
        $record = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || (int) ($record['owner'] ?? 0) !== get_current_user_id()) { throw new \RuntimeException('This operation belongs to another owner.'); }
        return $record;
    }

    public function startImport(\WP_REST_Request $request): array
    {
        $bytes = $request->get_param('bytes'); $name = $request->get_param('name');
        if (!is_numeric($bytes) || (float) $bytes !== (float) (int) $bytes || (int) $bytes < 64 || (int) $bytes > self::MAX_IMAGE || !is_string($name) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'dualscan') {
            throw new \RuntimeException('Choose a valid .dualscan image up to 64 GiB.');
        }
        $space = disk_free_space($this->storage());
        if ($space !== false && $space < (int) $bytes + 10485760) { throw new \RuntimeException('Private storage does not have enough free space for this upload.'); }
        $id = bin2hex(random_bytes(16));
        $file = $this->storage() . '/upload-' . $id . '.part';
        $handle = fopen($file, 'x+b'); if ($handle === false) { throw new \RuntimeException('Unable to start the private upload.'); }
        fclose($handle); chmod($file, 0600);
        $record = ['id' => $id, 'owner' => get_current_user_id(), 'name' => sanitize_file_name($name), 'expectedBytes' => (int) $bytes, 'receivedBytes' => 0, 'state' => 'uploading', 'path' => $file, 'createdAt' => gmdate('c')];
        $this->writeRecord($record, 'import');
        return ['id' => $id, 'chunkBytes' => self::CHUNK];
    }

    public function uploadChunk(\WP_REST_Request $request): array
    {
        $record = $this->readRecord((string) $request['id'], 'import');
        $body = $request->get_body(); $offset = $request->get_header('X-Dualscan-Offset');
        if ($record['state'] !== 'uploading' || !ctype_digit($offset) || strlen($body) < 1 || strlen($body) > self::CHUNK) { throw new \RuntimeException('Invalid upload chunk.'); }
        $handle = fopen($record['path'], 'r+b');
        if ($handle === false || !flock($handle, LOCK_EX)) { if (is_resource($handle)) { fclose($handle); } throw new \RuntimeException('The upload could not be locked.'); }
        try {
            $stat = fstat($handle); $size = (int) $stat['size'];
            if ((int) $offset !== $size || $size + strlen($body) > $record['expectedBytes']) { throw new \RuntimeException('Upload offset does not match. Start the upload again.'); }
            fseek($handle, 0, SEEK_END);
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle)) { throw new \RuntimeException('Unable to save the complete chunk.'); }
            $record['receivedBytes'] = $size + strlen($body);
            if ($record['receivedBytes'] === $record['expectedBytes']) {
                $record['state'] = 'ready'; $record['sha256'] = hash_file('sha256', $record['path']);
            }
            $this->writeRecord($record, 'import');
        } finally { flock($handle, LOCK_UN); fclose($handle); }
        return ['id' => $record['id'], 'receivedBytes' => $record['receivedBytes'], 'state' => $record['state']];
    }

    public function startJob(\WP_REST_Request $request): array
    {
        $type = $request->get_param('type');
        if (!in_array($type, ['create', 'inspect', 'restore'], true)) { throw new \RuntimeException('Choose a supported operation.'); }
        if ($type === 'restore') { return $this->startDeploymentRestore($request); }
        if (in_array($type, ['create', 'restore'], true) && $request->get_param('quiesced') !== true) { throw new \RuntimeException('Pause site changes and background writers before creating or restoring an image.'); }
        $id = bin2hex(random_bytes(16));
        $job = ['id' => $id, 'owner' => get_current_user_id(), 'type' => $type, 'state' => 'queued', 'title' => sanitize_text_field((string) ($request->get_param('title') ?? 'WordPress site')), 'createdAt' => gmdate('c'), 'progress' => ['phase' => 'Queued', 'done' => 0, 'total' => 0]];
        if ($type !== 'create') {
            $import = $this->readRecord((string) $request->get_param('importId'), 'import');
            if ($import['state'] !== 'ready') { throw new \RuntimeException('Finish uploading the image first.'); }
            $job['importId'] = $import['id'];
        }
        $this->writeRecord($job);
        return $this->publicJob($job);
    }

    public function startDeployment(\WP_REST_Request $request): array
    {
        $import = $this->readRecord((string) $request->get_param('importId'), 'import');
        $inspection = $this->readRecord((string) $request->get_param('inspectionId'));
        if ($import['state'] !== 'ready' || $inspection['type'] !== 'inspect' || $inspection['state'] !== 'complete' || ($inspection['importId'] ?? '') !== $import['id']) {
            throw new \RuntimeException('Authenticate and inspect this image before deployment.');
        }
        $plan = ['id' => bin2hex(random_bytes(16)), 'owner' => get_current_user_id(), 'importId' => $import['id'],
            'imageSha256' => $import['sha256'], 'termsVersion' => DeploymentTerms::VERSION, 'termsSha256' => DeploymentTerms::hash(),
            'createdAt' => gmdate('c'), 'expiresAt' => time() + 1800, 'step' => 0, 'state' => 'confirming', 'confirmations' => []];
        $this->writeRecord($plan, 'deployment');
        return $this->publicDeployment($plan);
    }

    private function publicDeployment(array $plan): array
    {
        return ['id' => $plan['id'], 'step' => $plan['step'], 'state' => $plan['state'], 'expiresAt' => $plan['expiresAt'],
            'termsVersion' => $plan['termsVersion'], 'termsSha256' => $plan['termsSha256'], 'termsText' => DeploymentTerms::text()];
    }

    private function lockedDeployment(string $id, callable $action): array
    {
        $path = $this->recordPath($id, 'deployment') . '.lock';
        if (is_link($path)) { throw new \RuntimeException('Invalid deployment lock.'); }
        $lock = fopen($path, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) { if (is_resource($lock)) { fclose($lock); } throw new \RuntimeException('Unable to lock this deployment.'); }
        chmod($path, 0600);
        try {
            $plan = $this->readRecord($id, 'deployment');
            if ($plan['state'] === 'consumed' || (int) $plan['expiresAt'] < time() || !hash_equals(DeploymentTerms::hash(), (string) $plan['termsSha256'])) {
                throw new \RuntimeException('This deployment approval has expired or was already used. Review the image again.');
            }
            return $action($plan);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function confirmDeployment(\WP_REST_Request $request): array
    {
        return $this->lockedDeployment((string) $request['id'], function (array $plan) use ($request): array {
            $step = $request->get_param('step');
            if (!is_int($step) || $step !== (int) $plan['step'] + 1 || $step > 3 || $request->get_param('accepted') !== true) {
                throw new \RuntimeException('Accept each of the three confirmations in order.');
            }
            if ($step === 3 && ($request->get_param('eulaAccepted') !== true || $request->get_param('adminAccessWaived') !== true || $request->get_param('claimsAccepted') !== true || !is_string($request->get_param('termsSha256')) || !hash_equals($plan['termsSha256'], $request->get_param('termsSha256')))) {
                throw new \RuntimeException('Accept the deployment agreement, release and permanent loss of WordPress admin access.');
            }
            $plan['step'] = $step; $plan['state'] = $step === 3 ? 'approved' : 'confirming';
            $plan['confirmations'][] = ['step' => $step, 'acceptedAt' => gmdate('c'), 'termsSha256' => $plan['termsSha256']];
            $this->writeRecord($plan, 'deployment');
            return $this->publicDeployment($plan);
        });
    }

    private function startDeploymentRestore(\WP_REST_Request $request): array
    {
        return $this->lockedDeployment((string) $request->get_param('deploymentId'), function (array $plan) use ($request): array {
            $import = $this->readRecord((string) $request->get_param('importId'), 'import');
            if ($plan['state'] !== 'approved' || (int) $plan['step'] !== 3 || count($plan['confirmations']) !== 3 || $plan['importId'] !== $import['id'] || $import['state'] !== 'ready' || !hash_equals((string) $plan['imageSha256'], (string) $import['sha256']) || $request->get_param('confirmation') !== 'DEPLOY INSTANCE' || $request->get_param('quiesced') !== true) {
                throw new \RuntimeException('Complete all three confirmations for this exact image before pressing DEPLOY INSTANCE.');
            }
            $job = ['id' => bin2hex(random_bytes(16)), 'owner' => get_current_user_id(), 'type' => 'restore', 'state' => 'queued',
                'importId' => $import['id'], 'deploymentId' => $plan['id'], 'title' => 'Public application deployment', 'createdAt' => gmdate('c'),
                'progress' => ['phase' => 'Queued', 'done' => 0, 'total' => 0],
                'consent' => ['termsVersion' => $plan['termsVersion'], 'termsSha256' => $plan['termsSha256'], 'imageSha256' => $plan['imageSha256'], 'confirmations' => $plan['confirmations']]];
            // Consume before publishing a runnable job; failures remain safely non-runnable.
            $plan['state'] = 'consumed'; $plan['jobId'] = $job['id']; $this->writeRecord($plan, 'deployment');
            $this->writeRecord($job);
            return $this->publicJob($job);
        });
    }

    private function publicJob(array $job): array
    {
        unset($job['owner']);
        if (isset($job['result']['path'])) { unset($job['result']['path']); }
        return $job;
    }

    public function jobStatus(\WP_REST_Request $request): array
    {
        return $this->publicJob($this->readRecord((string) $request['id']));
    }

    public function runJob(\WP_REST_Request $request): array
    {
        $lockPath = $this->storage() . '/operation.lock';
        if (is_link($lockPath)) { throw new \RuntimeException('Invalid operation lock.'); }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) { fclose($lock); } throw new \RuntimeException('Another image operation is running. Wait for it to finish.'); }
        chmod($lockPath, 0600);
        $job = null; $maintenance = null; $maintenanceContents = null; $retainMaintenance = false;
        try {
            $job = $this->readRecord((string) $request['id']);
            if ($job['state'] !== 'queued') { throw new \RuntimeException('This operation has already started.'); }
            if ($job['type'] === 'restore') {
                $plan = $this->readRecord((string) ($job['deploymentId'] ?? ''), 'deployment');
                $approvedImport = $this->readRecord((string) $job['importId'], 'import');
                if ($plan['state'] !== 'consumed' || ($plan['jobId'] ?? '') !== $job['id'] || (int) $plan['step'] !== 3 || count($plan['confirmations']) !== 3 || (int) $plan['expiresAt'] < time() || $plan['importId'] !== $approvedImport['id'] || !hash_equals(DeploymentTerms::hash(), (string) $plan['termsSha256']) || !hash_equals((string) $plan['imageSha256'], (string) $approvedImport['sha256'])) {
                    throw new \RuntimeException('The three deployment confirmations are not valid for this operation. Review the image again.');
                }
            }
            $privateStorage = $this->storage();
            $keyring = new ServerKeyring($privateStorage, basename(dirname(DUALSCAN_CLOUD_FILE)), [ABSPATH, (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')]);
            $password = $job['type'] === 'create' ? $keyring->resolveOrCreate() : $keyring->requireExisting();
            $job['encryption'] = $keyring->status();
            $job['state'] = 'running'; $job['startedAt'] = gmdate('c'); $this->writeRecord($job);
            @set_time_limit(0); ignore_user_abort(true);
            register_shutdown_function(function () use (&$job): void {
                $last = error_get_last();
                if ($job !== null && $job['state'] === 'running' && $last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    $job['state'] = 'failed'; $job['error'] = 'The host stopped this operation. Review private recovery files before retrying a restore.';
                    try { $this->writeRecord($job); } catch (\Throwable) { }
                }
            });
            global $wpdb;
            $manager = new ImageManager(new WordPressDatabase($wpdb), $privateStorage);
            $options = ['siteUrl' => home_url('/'), 'tablePrefix' => $wpdb->prefix, 'title' => $job['title'],
                'progress' => function (string $phase, int $done, int $total) use (&$job, &$maintenance, &$maintenanceContents): void {
                    if ($job['type'] === 'restore' && $phase === 'restoring' && $done === 0 && !file_exists(ABSPATH . '.maintenance')) {
                        $maintenanceContents = "<?php\n// Dualscan restore " . bin2hex(random_bytes(16)) . "\n\$upgrading = time();\n";
                        $handle = fopen(ABSPATH . '.maintenance', 'xb');
                        if ($handle === false) { throw new \RuntimeException('Unable to put WordPress into maintenance mode before restore.'); }
                        try { SafeFiles::writeAll($handle, $maintenanceContents); fflush($handle); } finally { fclose($handle); }
                        chmod(ABSPATH . '.maintenance', 0600); $maintenance = ABSPATH . '.maintenance';
                    }
                    $job['progress'] = ['phase' => $phase, 'done' => $done, 'total' => $total]; $job['updatedAt'] = gmdate('c'); $this->writeRecord($job);
                }];
            if ($job['type'] === 'create') {
                $this->assertStandardRoots();
                $result = $manager->create(ABSPATH, $password, $options);
            } else {
                $import = $this->readRecord($job['importId'], 'import');
                if ($import['state'] !== 'ready' || !hash_equals($import['sha256'], (string) hash_file('sha256', $import['path']))) { throw new \RuntimeException('The uploaded image changed. Upload it again.'); }
                if ($job['type'] === 'inspect') {
                    $result = $manager->inspect($import['path'], $password);
                } else {
                    $this->assertStandardRoots();
                    $options['requireSiteUrl'] = home_url('/'); $options['requireTablePrefix'] = $wpdb->prefix;
                    $pluginPath = str_replace('\\', '/', substr(realpath(dirname(DUALSCAN_CLOUD_FILE)), strlen(rtrim((string) realpath(ABSPATH), '/')) + 1));
                    $options['preservePaths'] = ['wp-config.php', $pluginPath, '.maintenance'];
                    $result = $manager->restore($import['path'], $password, ABSPATH, $options);
                }
            }
            $job['state'] = 'complete'; $job['completedAt'] = gmdate('c'); $job['result'] = $result;
            if ($job['type'] === 'restore') {
                $job['result']['redirectUrl'] = home_url('/');
                $job['result']['loggedOut'] = true;
            }
            $this->writeRecord($job);
            if ($job['type'] === 'restore') {
                wp_clear_auth_cookie();
                wp_set_current_user(0);
            }
            return $this->publicJob($job);
        } catch (\Throwable $error) {
            $retainMaintenance = $error->getCode() === 1001;
            if ($job !== null && $job['state'] === 'running') {
                $job['state'] = 'failed'; $job['error'] = str_contains($error->getMessage(), 'Incorrect password') ? 'This image requires a matching server encryption key or is damaged.' : $error->getMessage(); $job['completedAt'] = gmdate('c'); $this->writeRecord($job);
                return $this->publicJob($job);
            }
            throw $error;
        } finally {
            if (!$retainMaintenance && $maintenance !== null && is_file($maintenance) && !is_link($maintenance) && file_get_contents($maintenance) === $maintenanceContents) { unlink($maintenance); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function assertStandardRoots(): void
    {
        global $wpdb;
        if (defined('CUSTOM_USER_TABLE') || defined('CUSTOM_USER_META_TABLE') || $wpdb->users !== $wpdb->prefix . 'users' || $wpdb->usermeta !== $wpdb->prefix . 'usermeta') {
            throw new \RuntimeException('Custom WordPress user tables are not supported for sealed account removal.');
        }
        $root = rtrim((string) realpath(ABSPATH), '/');
        $uploads = wp_upload_dir(null, false);
        foreach ([WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, get_theme_root(), $uploads['basedir']] as $path) {
            if (!file_exists($path)) { continue; }
            $real = realpath($path);
            if ($real === false || !str_starts_with($real . '/', $root . '/')) { throw new \RuntimeException('This site uses content outside its WordPress root. Bring those paths into the supported site tree before creating or restoring an image.'); }
        }
        if (!is_file($root . '/wp-config.php')) { throw new \RuntimeException('This release requires wp-config.php inside the WordPress root.'); }
    }

    public function images(\WP_REST_Request $request): array
    {
        $images = [];
        foreach (glob($this->storage() . '/job-*.json') ?: [] as $path) {
            $id = substr(basename($path), 4, 32);
            try { $job = $this->readRecord($id); } catch (\Throwable) { continue; }
            if ($job['type'] !== 'create' || $job['state'] !== 'complete' || empty($job['result']['path']) || !is_file($job['result']['path'])) { continue; }
            $images[] = ['id' => $id, 'title' => $job['title'], 'createdAt' => $job['completedAt'], 'bytes' => filesize($job['result']['path']), 'sha256' => $job['result']['sha256'] ?? hash_file('sha256', $job['result']['path']),
                'downloadUrl' => add_query_arg(['action' => 'dualscan_cloud_download', 'id' => $id, '_wpnonce' => wp_create_nonce('dualscan-download-' . $id)], admin_url('admin-post.php'))];
        }
        usort($images, static fn(array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));
        return ['images' => array_slice($images, 0, 100)];
    }

    public function download(): void
    {
        if (!current_user_can('dualscan_manage_images')) { wp_die('Site owner access is required.', '', ['response' => 403]); }
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        check_admin_referer('dualscan-download-' . $id);
        try {
            $job = $this->readRecord($id);
            $file = realpath((string) ($job['result']['path'] ?? ''));
            if ($job['type'] !== 'create' || $job['state'] !== 'complete' || $file === false || !str_starts_with($file, $this->storage() . '/') || is_link($job['result']['path']) || !is_file($file)) { throw new \RuntimeException('This saved image is not available.'); }
            nocache_headers(); header('Content-Type: application/octet-stream');
            header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($file));
            header('Content-Disposition: attachment; filename="' . sanitize_file_name($job['title']) . '.dualscan"');
            $handle = fopen($file, 'rb'); if ($handle === false) { throw new \RuntimeException('This saved image cannot be opened.'); }
            fpassthru($handle); fclose($handle); exit;
        } catch (\Throwable $error) { wp_die(esc_html($error->getMessage()), '', ['response' => 404]); }
    }
}
