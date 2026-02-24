<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\RemoteDeploy;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Support\Facades\Log;

class RemoteDeploymentOrchestrator
{
    public function __construct(
        protected SshService $ssh,
        protected RemoteEnvManager $envManager,
        protected RemoteCaddyManager $caddyManager,
    ) {}

    /**
     * Detect the latest available PHP-FPM version on a remote node.
     * Scans for socket files and returns the highest version found (e.g. "8.5").
     */
    public function detectPhpVersion(Node $node): ?string
    {
        $result = $this->ssh->execute($node, 'ls ~/.config/orbit/php/php*.sock 2>/dev/null');
        if (! $result['success'] || empty(trim($result['output'] ?? ''))) {
            return null;
        }

        $versions = [];
        if (preg_match_all('/php(\d)(\d)\.sock/', $result['output'], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $versions[] = $match[1] . '.' . $match[2];
            }
        }

        if (empty($versions)) {
            return null;
        }

        rsort($versions);

        return $versions[0];
    }

    /**
     * Execute a full deployment on the remote node.
     * Auto-detects first deploy vs subsequent deploy.
     */
    public function deploy(RemoteDeployContext $ctx): array
    {
        $isFirstDeploy = ! $this->ssh->directoryExists($ctx->node, "{$ctx->basePath}/releases");

        if ($isFirstDeploy && ! $ctx->repo) {
            return ['success' => false, 'error' => 'Repository URL is required for first deploy'];
        }

        try {
            if ($isFirstDeploy) {
                return $this->firstDeploy($ctx);
            }

            return $this->subsequentDeploy($ctx);
        } catch (\Throwable $e) {
            Log::error("Remote deployment failed for {$ctx->slug}: {$e->getMessage()}");

            // Rollback: remove the failed release directory
            $this->ssh->execute($ctx->node, "rm -rf " . escapeshellarg($ctx->releasePath), 30);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function firstDeploy(RemoteDeployContext $ctx): array
    {
        $steps = [];

        // 1. Create directory structure
        $steps[] = $this->step($ctx, 'Create directory structure',
            "mkdir -p {$ctx->basePath}/{releases,storage/app/public,storage/framework/{cache/data,sessions,testing,views},storage/logs,database}",
            timeout: 10, fatal: true
        );

        // 2. Clone repository
        $steps[] = $this->step($ctx, 'Clone repository',
            'gh repo clone ' . escapeshellarg($ctx->repo) . ' ' . escapeshellarg($ctx->releasePath),
            timeout: 300, fatal: true
        );

        // 3. Bootstrap .env
        $envResult = $this->envManager->bootstrap($ctx);
        $steps[] = $envResult;
        if (! $envResult['success']) {
            throw new \RuntimeException('Failed to bootstrap .env: ' . ($envResult['error'] ?? 'unknown'));
        }

        // 4. Create symlinks
        $steps[] = $this->step($ctx, 'Create symlinks',
            "cd {$ctx->releasePath} && rm -rf .env storage && ln -s ../../.env .env && ln -s ../../storage storage",
            timeout: 10, fatal: true
        );

        // 5. SQLite database setup (symlink is 3 levels deep: database/ → timestamp/ → releases/ → basePath)
        $this->step($ctx, 'SQLite setup',
            "touch {$ctx->basePath}/database/database.sqlite && rm -f {$ctx->releasePath}/database/database.sqlite && ln -s ../../../database/database.sqlite {$ctx->releasePath}/database/database.sqlite",
            timeout: 10, fatal: false
        );

        // 6. Composer install
        $steps[] = $this->step($ctx, 'Composer install',
            "cd {$ctx->releasePath} && composer install --no-interaction --no-scripts --no-dev --optimize-autoloader",
            timeout: 600, fatal: true
        );

        // 7-9. Node dependencies and build
        $this->installNodeDeps($ctx, $steps);

        // 10. Migrations
        $steps[] = $this->step($ctx, 'Run migrations',
            "cd {$ctx->releasePath} && php artisan config:clear && php artisan migrate --force",
            timeout: 120, fatal: true
        );

        // 11. Optimize
        $this->step($ctx, 'Optimize',
            "cd {$ctx->releasePath} && php artisan optimize",
            timeout: 30, fatal: false
        );

        // 12. Switch symlink
        $steps[] = $this->step($ctx, 'Switch symlink',
            "cd {$ctx->basePath} && ln -sfn releases/{$ctx->timestamp} current",
            timeout: 10, fatal: true
        );

        // 13. Reload PHP-FPM
        $this->step($ctx, 'Reload PHP-FPM',
            "sudo systemctl reload php{$ctx->phpVersionClean()}-fpm 2>&1 || true",
            timeout: 10, fatal: false
        );

        // 14-15. Caddy configuration
        $this->configureCaddy($ctx, $steps);

        return [
            'success' => true,
            'first_deploy' => true,
            'release' => $ctx->timestamp,
            'domain' => $ctx->domain(),
            'url' => $ctx->appUrl(),
        ];
    }

    private function subsequentDeploy(RemoteDeployContext $ctx): array
    {
        $steps = [];

        // Resolve clone URL from existing release if not provided
        $repo = $ctx->repo;
        if (! $repo) {
            $result = $this->ssh->execute($ctx->node, "cd {$ctx->currentLink} && git remote get-url origin");
            $repo = trim($result['output'] ?? '');
            if (! $repo) {
                throw new \RuntimeException('Could not determine clone URL. Provide --clone or ensure current release has a git remote.');
            }
        }

        // 1. Clone into new release
        $steps[] = $this->step($ctx, 'Clone repository',
            'gh repo clone ' . escapeshellarg($repo) . ' ' . escapeshellarg($ctx->releasePath),
            timeout: 300, fatal: true
        );

        // 2. Create symlinks
        $steps[] = $this->step($ctx, 'Create symlinks',
            "cd {$ctx->releasePath} && rm -rf .env storage && ln -s ../../.env .env && ln -s ../../storage storage",
            timeout: 10, fatal: true
        );

        // 3. SQLite symlink (3 levels deep: database/ → timestamp/ → releases/ → basePath)
        $this->step($ctx, 'SQLite symlink',
            "rm -f {$ctx->releasePath}/database/database.sqlite && ln -s ../../../database/database.sqlite {$ctx->releasePath}/database/database.sqlite",
            timeout: 10, fatal: false
        );

        // 4. Composer install
        $steps[] = $this->step($ctx, 'Composer install',
            "cd {$ctx->releasePath} && composer install --no-interaction --no-scripts --no-dev --optimize-autoloader",
            timeout: 600, fatal: true
        );

        // 5-7. Node dependencies and build
        $this->installNodeDeps($ctx, $steps);

        // 8. Migrations
        $steps[] = $this->step($ctx, 'Run migrations',
            "cd {$ctx->releasePath} && php artisan config:clear && php artisan migrate --force",
            timeout: 120, fatal: true
        );

        // 9. Optimize
        $this->step($ctx, 'Optimize',
            "cd {$ctx->releasePath} && php artisan optimize",
            timeout: 30, fatal: false
        );

        // 10. Switch symlink
        $steps[] = $this->step($ctx, 'Switch symlink',
            "cd {$ctx->basePath} && ln -sfn releases/{$ctx->timestamp} current",
            timeout: 10, fatal: true
        );

        // 11. Reload PHP-FPM
        $this->step($ctx, 'Reload PHP-FPM',
            "sudo systemctl reload php{$ctx->phpVersionClean()}-fpm 2>&1 || true",
            timeout: 10, fatal: false
        );

        // 12. Cleanup old releases
        $this->cleanupReleases($ctx);

        return [
            'success' => true,
            'first_deploy' => false,
            'release' => $ctx->timestamp,
            'domain' => $ctx->domain(),
            'url' => $ctx->appUrl(),
        ];
    }

    /**
     * Remove a deployment from the remote node.
     */
    public function undeploy(RemoteDeployContext $ctx): array
    {
        // Remove the entire project directory
        $result = $this->ssh->execute($ctx->node, "rm -rf " . escapeshellarg($ctx->basePath), 30);
        if (! $result['success']) {
            return ['success' => false, 'error' => trim($result['error'] ?? '') ?: 'Failed to remove project directory'];
        }

        // Remove Caddy block and reload
        $this->caddyManager->remove($ctx);

        // Reload PHP-FPM
        $this->ssh->execute($ctx->node, "sudo systemctl reload php{$ctx->phpVersionClean()}-fpm 2>&1 || true", 10);

        return ['success' => true];
    }

    private function step(RemoteDeployContext $ctx, string $name, string $command, int $timeout = 30, bool $fatal = true): array
    {
        Log::info("Remote deploy [{$ctx->slug}]: {$name}");

        $result = $this->ssh->execute($ctx->node, $command, $timeout);

        if (! $result['success'] && $fatal) {
            throw new \RuntimeException("{$name} failed: " . trim($result['error'] ?? '') ?: 'Unknown error');
        }

        if (! $result['success']) {
            Log::warning("Remote deploy [{$ctx->slug}]: {$name} failed (non-fatal): " . trim($result['error'] ?? ''));
        }

        return array_merge($result, ['step' => $name]);
    }

    private function installNodeDeps(RemoteDeployContext $ctx, array &$steps): void
    {
        // Detect package manager
        $detectResult = $this->ssh->execute($ctx->node,
            "[ -f {$ctx->releasePath}/bun.lock ] && echo bun || ([ -f {$ctx->releasePath}/package-lock.json ] && echo npm || echo none)",
            10
        );
        $pkgManager = trim($detectResult['output'] ?? 'none');

        if ($pkgManager === 'none') {
            return;
        }

        // Install dependencies
        $installCmd = $pkgManager === 'bun'
            ? "cd {$ctx->releasePath} && CI=1 bun install --no-progress"
            : "cd {$ctx->releasePath} && npm ci";

        $installResult = $this->step($ctx, "Install node deps ({$pkgManager})", $installCmd, timeout: 300, fatal: false);
        $steps[] = $installResult;

        // Check if build script exists
        $hasBuild = $this->ssh->execute($ctx->node,
            "cd {$ctx->releasePath} && node -e \"const p=require('./package.json'); process.exit(p.scripts && p.scripts.build ? 0 : 1)\" 2>/dev/null",
            10
        );

        if ($hasBuild['success']) {
            $buildCmd = $pkgManager === 'bun'
                ? "cd {$ctx->releasePath} && CI=1 bun run build"
                : "cd {$ctx->releasePath} && npm run build";

            $buildResult = $this->step($ctx, 'Build assets', $buildCmd, timeout: 600, fatal: false);
            $steps[] = $buildResult;
        }
    }

    private function configureCaddy(RemoteDeployContext $ctx, array &$steps): void
    {
        // Check if the release has a public folder (indicates a web project)
        $hasPublic = $this->ssh->execute($ctx->node, "[ -d {$ctx->releasePath}/public ] && echo yes || echo no");
        if (trim($hasPublic['output'] ?? '') !== 'yes') {
            return;
        }

        $this->ensureCaddyCloudflareToken($ctx->node);

        $caddyResult = $this->caddyManager->configure($ctx);
        $steps[] = $caddyResult;

        if ($caddyResult['success'] && ! ($caddyResult['skipped'] ?? false)) {
            $reloadResult = $this->caddyManager->reload($ctx);
            $steps[] = $reloadResult;
        }
    }

    /**
     * Ensure the Cloudflare API token is available to Caddy via systemd environment.
     * Idempotent — only writes if the override file doesn't exist or has a different token.
     */
    private function ensureCaddyCloudflareToken(Node $node): void
    {
        $token = Setting::get('cloudflare_api_token');
        if (! $token) {
            return;
        }

        $overridePath = '/etc/systemd/system/caddy.service.d/cloudflare.conf';

        // Check if override already has the correct token
        $existing = $this->ssh->execute($node, "sudo cat {$overridePath} 2>/dev/null | grep -oP 'CLOUDFLARE_API_TOKEN=\\K.*'");
        if (trim($existing['output'] ?? '') === $token) {
            return;
        }

        Log::info("Setting Cloudflare API token for Caddy on node {$node->name}");

        $content = "[Service]\nEnvironment=CLOUDFLARE_API_TOKEN={$token}\n";

        $this->ssh->execute($node, "sudo mkdir -p /etc/systemd/system/caddy.service.d", 10);
        $this->ssh->execute($node, "echo " . escapeshellarg($content) . " | sudo tee {$overridePath} > /dev/null", 10);
        $this->ssh->execute($node, "sudo systemctl daemon-reload", 10);
    }

    private function cleanupReleases(RemoteDeployContext $ctx): void
    {
        // Protect the current release by resolving the symlink
        $currentResult = $this->ssh->execute($ctx->node, "readlink {$ctx->currentLink} 2>/dev/null");
        $currentRelease = basename(trim($currentResult['output'] ?? ''));

        // List releases, sorted newest first, skip the most recent N
        $keep = $ctx->keepReleases + 1;
        $result = $this->ssh->execute($ctx->node,
            "ls -1t {$ctx->basePath}/releases 2>/dev/null | tail -n +{$keep}",
            10
        );

        $oldReleases = array_filter(explode("\n", trim($result['output'] ?? '')));

        foreach ($oldReleases as $release) {
            $release = trim($release);
            if ($release === '' || $release === $currentRelease) {
                continue;
            }

            $this->ssh->execute($ctx->node,
                "rm -rf " . escapeshellarg("{$ctx->basePath}/releases/{$release}"),
                30
            );
            Log::info("Remote deploy [{$ctx->slug}]: Removed old release {$release}");
        }
    }
}
