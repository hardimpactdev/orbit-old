<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\SupportsJsonMode;
use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use App\Services\ProvisionLogger;
use App\Services\ReverbBroadcaster;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Enums\ProjectStatus;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
use HardImpact\Orbit\Core\Support\ProjectHelper;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

/**
 * CLI command for release-based deployments on production/staging nodes.
 *
 * Creates timestamped release directories with atomic symlink switching.
 * Shared resources (.env, storage/, database/) persist across releases.
 */
final class ProjectDeployCommand extends Command
{
    use SupportsJsonMode;
    use WithJsonOutput;

    protected $signature = 'project:deploy
        {name : Project name}
        {--clone= : GitHub repo (required on first deploy)}
        {--php= : PHP version to use (8.3, 8.4, 8.5)}
        {--keep=5 : Number of old releases to keep}
        {--directory= : Override base directory}
        {--json : Output as JSON (for programmatic use)}';

    protected $description = 'Deploy a project using release-based zero-downtime deployment';

    private ?ProvisionLogger $logger = null;

    public function handle(
        ConfigManager $config,
        ReverbBroadcaster $broadcaster,
        ProvisionPipeline $pipeline,
    ): int {
        /** @var string $name */
        $name = $this->argument('name');
        $slug = Str::slug($name);
        $keep = (int) $this->option('keep');

        $node = Node::getSelf();
        if (! $node) {
            return $this->failWithMessage('No node found. Run "orbit init" first.');
        }

        $basePath = $this->determineBasePath($config, $slug);
        $tld = $node->tld ?? 'ccc';
        $timestamp = date('Ymd_His');
        $releasePath = "{$basePath}/releases/{$timestamp}";
        $currentLink = "{$basePath}/current";
        $isFirstDeploy = ! is_dir("{$basePath}/releases");

        // For first deploy, --clone is required
        if ($isFirstDeploy && ! $this->option('clone')) {
            return $this->failWithMessage('--clone is required on first deploy.');
        }

        // Resolve or create the project record
        $project = Project::where('slug', $slug)->first();
        if (! $project) {
            $project = Project::create([
                'node_id' => $node->id,
                'name' => $slug,
                'display_name' => $name,
                'slug' => $slug,
                'path' => $basePath,
                'php_version' => $this->option('php') ?? '8.4',
                'status' => ProjectStatus::Queued,
            ]);
        } else {
            $project->update(['status' => ProjectStatus::Queued]);
        }

        $this->logger = new ProvisionLogger(
            broadcaster: $broadcaster,
            command: $this->option('json') ? null : $this,
            slug: $slug,
            projectId: $project->id,
        );

        $this->logger->info("Deploying project: {$name} (release: {$timestamp})");
        $this->logger->broadcast('deploying');

        try {
            if ($isFirstDeploy) {
                $this->firstDeploy($basePath, $releasePath, $currentLink, $slug, $tld, $project, $pipeline, $node);
            } else {
                $this->subsequentDeploy($basePath, $releasePath, $currentLink, $slug, $project, $pipeline, $keep, $node);
            }

            // Detect project type from release
            $effectivePath = readlink($currentLink) ?: $releasePath;
            if (! str_starts_with($effectivePath, '/')) {
                $effectivePath = "{$basePath}/{$effectivePath}";
            }
            $hasPublicFolder = is_dir("{$effectivePath}/public");
            $projectType = ProjectHelper::detectProjectType($effectivePath);

            $project->update([
                'status' => ProjectStatus::Ready,
                'github_repo' => $this->option('clone'),
                'url' => "https://{$slug}.{$tld}",
                'domain' => "{$slug}.{$tld}",
                'has_public_folder' => $hasPublicFolder,
                'project_type' => $projectType,
                'error_message' => null,
            ]);

            $this->logger->broadcast('ready');

            // Create production Caddy block (if first deploy on production node)
            if ($isFirstDeploy && $node->isProduction() && $hasPublicFolder) {
                $this->createProductionCaddyBlock(
                    basePath: $basePath,
                    slug: $slug,
                    domain: $this->resolveProductionDomain($slug, $tld),
                    phpVersion: $this->option('php') ?? '8.4',
                    config: $config
                );
            }

            if ($hasPublicFolder) {
                $this->regenerateCaddy();
            }

            $this->logger->info("Project {$slug} deployed successfully!");

            return $this->outputJsonSuccess([
                'name' => $name,
                'slug' => $slug,
                'project_id' => $project->id,
                'status' => 'ready',
                'url' => "https://{$slug}.{$tld}",
                'path' => $basePath,
                'release' => $timestamp,
                'first_deploy' => $isFirstDeploy,
            ]);

        } catch (\Throwable $e) {
            $project->update([
                'status' => ProjectStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            $this->logger->broadcast('failed', $e->getMessage());
            $this->logger->error($e->getMessage());

            // Cleanup failed release directory
            if (is_dir($releasePath)) {
                Process::run('rm -rf '.escapeshellarg($releasePath));
            }

            if ($this->wantsJson()) {
                $this->outputJsonError($e->getMessage());
            }

            return ExitCode::GeneralError->value;
        }
    }

    private function firstDeploy(
        string $basePath,
        string $releasePath,
        string $currentLink,
        string $slug,
        string $tld,
        Project $project,
        ProvisionPipeline $pipeline,
        ?Node $node = null,
    ): void {
        $this->logger->info('First deploy — setting up directory structure...');

        // Create base directory structure
        $this->createDirectoryStructure($basePath);

        // Clone into release directory
        $cloneUrl = ProjectHelper::normalizeRepoUrl($this->option('clone'));
        $context = new ProvisionContext(
            slug: $slug,
            projectPath: $releasePath,
            projectId: $project->id,
            cloneUrl: $cloneUrl,
            phpVersion: $this->option('php'),
            tld: $tld,
        );

        $this->logger->broadcast('cloning');
        $result = $pipeline->cloneRepository($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error ?? 'Clone failed');
        }

        // Bootstrap .env from .env.example
        $this->bootstrapEnv($basePath, $releasePath, $slug, $tld, $node);

        // Create symlinks in release to shared resources
        $this->createReleaseSymlinks($basePath, $releasePath);

        // Ensure shared storage has Laravel directory structure
        $this->ensureStorageStructure($basePath);

        // Run full provision pipeline (first deploy needs everything)
        $this->logger->broadcast('setting_up');
        $result = $pipeline->run($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error ?? 'Provisioning failed');
        }

        // Create current symlink
        $this->switchCurrent($basePath, $releasePath, $currentLink);
    }

    private function subsequentDeploy(
        string $basePath,
        string $releasePath,
        string $currentLink,
        string $slug,
        Project $project,
        ProvisionPipeline $pipeline,
        int $keep,
        ?Node $node = null,
    ): void {
        $this->logger->info('Subsequent deploy — creating new release...');

        $cloneUrl = $this->resolveCloneUrl($basePath);
        if (! $cloneUrl) {
            throw new \RuntimeException('Could not determine clone URL. Provide --clone or ensure current release has a git remote.');
        }

        $node ??= Node::getSelf();
        $tld = $node->tld ?? 'ccc';

        $context = new ProvisionContext(
            slug: $slug,
            projectPath: $releasePath,
            projectId: $project->id,
            cloneUrl: $cloneUrl,
            phpVersion: $this->option('php'),
            tld: $tld,
            isReleaseDeploy: true,
        );

        // Clone into new release directory
        $this->logger->broadcast('cloning');
        $result = $pipeline->cloneRepository($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error ?? 'Clone failed');
        }

        // Create symlinks to shared resources
        $this->createReleaseSymlinks($basePath, $releasePath);

        // Run deploy pipeline (skips env config, database creation, key generation)
        $this->logger->broadcast('setting_up');
        $result = $pipeline->run($context, $this->logger);
        if ($result->isFailed()) {
            throw new \RuntimeException($result->error ?? 'Deploy failed');
        }

        // Atomic symlink switch
        $this->switchCurrent($basePath, $releasePath, $currentLink);

        // Cleanup old releases
        $this->cleanupReleases($basePath, $keep);
    }

    private function createDirectoryStructure(string $basePath): void
    {
        $dirs = [
            "{$basePath}/releases",
            "{$basePath}/storage/app",
            "{$basePath}/storage/framework/cache",
            "{$basePath}/storage/framework/sessions",
            "{$basePath}/storage/framework/views",
            "{$basePath}/storage/logs",
            "{$basePath}/database",
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->logger->info('Directory structure created');
    }

    private function bootstrapEnv(
        string $basePath,
        string $releasePath,
        string $slug,
        string $tld,
        ?Node $node = null
    ): void {
        $sharedEnv = "{$basePath}/.env";
        $envExample = "{$releasePath}/.env.example";

        if (file_exists($sharedEnv)) {
            $this->logger->info('Shared .env already exists, keeping it');

            return;
        }

        // Copy .env.example or create minimal .env
        if (file_exists($envExample)) {
            copy($envExample, $sharedEnv);
            $env = file_get_contents($sharedEnv);
        } else {
            $this->logger->warn('No .env.example found, creating minimal .env');
            $env = '';
        }

        // Determine APP_URL based on environment
        $appUrl = $this->determineAppUrl($slug, $tld, $node);

        // Set core production values
        $env = $this->setEnvValue($env, 'APP_ENV', 'production');
        $env = $this->setEnvValue($env, 'APP_DEBUG', 'false');
        $env = $this->setEnvValue($env, 'APP_URL', $appUrl);

        // Generate APP_KEY if missing
        if (! preg_match('/^APP_KEY=base64:.+$/m', $env)) {
            $this->logger->info('APP_KEY not found, generating...');
            $key = $this->generateAppKey();
            $env = $this->setEnvValue($env, 'APP_KEY', $key);
        }

        // Set production-optimized defaults (only if missing)
        if ($node && $node->isProduction()) {
            $env = $this->setEnvValueIfMissing($env, 'CACHE_DRIVER', 'redis');
            $env = $this->setEnvValueIfMissing($env, 'SESSION_DRIVER', 'redis');
            $env = $this->setEnvValueIfMissing($env, 'QUEUE_CONNECTION', 'redis');
            $env = $this->setEnvValueIfMissing($env, 'REDIS_HOST', '127.0.0.1');
            $env = $this->setEnvValueIfMissing($env, 'REDIS_PORT', '6379');
        }

        file_put_contents($sharedEnv, $env);
        $this->logger->info('Bootstrapped .env with production defaults');

        // Clear config cache
        $this->clearConfigCache($basePath);
    }

    private function createReleaseSymlinks(string $basePath, string $releasePath): void
    {
        // Symlink .env and storage (full directories)
        $links = [
            '.env' => '../../.env',
            'storage' => '../../storage',
        ];

        foreach ($links as $name => $target) {
            $linkPath = "{$releasePath}/{$name}";

            // Remove existing file/directory in the release
            if (is_dir($linkPath) && ! is_link($linkPath)) {
                Process::run('rm -rf '.escapeshellarg($linkPath));
            } elseif (file_exists($linkPath) || is_link($linkPath)) {
                unlink($linkPath);
            }

            symlink($target, $linkPath);
        }

        // For database, only symlink the SQLite file (keep migrations/factories/seeders from release)
        $this->ensureDatabaseStructure($basePath, $releasePath);

        $this->logger->info('Created symlinks to shared resources');
    }

    private function ensureDatabaseStructure(string $basePath, string $releasePath): void
    {
        // Ensure shared database directory exists
        $sharedDbDir = "{$basePath}/database";
        if (! is_dir($sharedDbDir)) {
            mkdir($sharedDbDir, 0755, true);
        }

        // Only symlink the SQLite file, not the entire directory
        // This preserves migrations/factories/seeders from the release
        $sqliteFile = "{$releasePath}/database/database.sqlite";
        $sharedSqlite = "{$basePath}/database/database.sqlite";

        // Create shared SQLite file if it doesn't exist
        if (! file_exists($sharedSqlite)) {
            touch($sharedSqlite);
        }

        // Remove release's SQLite file and symlink to shared
        if (file_exists($sqliteFile) && ! is_link($sqliteFile)) {
            unlink($sqliteFile);
        }

        if (! is_link($sqliteFile)) {
            symlink('../../database/database.sqlite', $sqliteFile);
        }
    }

    private function ensureStorageStructure(string $basePath): void
    {
        $dirs = [
            "{$basePath}/storage/app/public",
            "{$basePath}/storage/framework/cache/data",
            "{$basePath}/storage/framework/sessions",
            "{$basePath}/storage/framework/testing",
            "{$basePath}/storage/framework/views",
            "{$basePath}/storage/logs",
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function switchCurrent(string $basePath, string $releasePath, string $currentLink): void
    {
        $releaseDir = basename($releasePath);

        // Atomic symlink switch using ln -sfn
        $result = Process::path($basePath)
            ->run("ln -sfn releases/{$releaseDir} current");

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to switch current symlink: '.$result->errorOutput());
        }

        $this->logger->info("Switched current → releases/{$releaseDir}");

        // Reload PHP-FPM to clear opcache and pick up new release
        $this->reloadPhpFpm();
    }

    private function reloadPhpFpm(): void
    {
        $this->logger->info('Reloading PHP-FPM to clear opcache...');

        // Get PHP version from command options or default
        $phpVersion = $this->option('php') ?? '8.4';
        $versionClean = str_replace('.', '', $phpVersion);

        // Try systemd reload first (most common)
        $result = Process::run("sudo systemctl reload php{$versionClean}-fpm 2>&1 || true");

        // If systemd failed, try direct signal to php-fpm
        if (! $result->successful()) {
            Process::run('sudo pkill -USR2 php-fpm 2>&1 || true');
        }

        $this->logger->info('PHP-FPM reload signal sent');
    }

    private function cleanupReleases(string $basePath, int $keep): void
    {
        $releasesDir = "{$basePath}/releases";
        $releases = array_filter(scandir($releasesDir), fn ($d) => ! in_array($d, ['.', '..']));
        sort($releases);

        // Determine which release is currently active
        $currentTarget = is_link("{$basePath}/current") ? readlink("{$basePath}/current") : null;
        $activeRelease = $currentTarget ? basename($currentTarget) : null;

        // Keep the most recent $keep releases plus the active one
        $toRemove = array_slice($releases, 0, max(0, count($releases) - $keep));

        foreach ($toRemove as $release) {
            // Never remove the active release
            if ($release === $activeRelease) {
                continue;
            }

            $path = "{$releasesDir}/{$release}";
            if (is_dir($path)) {
                Process::run('rm -rf '.escapeshellarg($path));
                $this->logger->info("Removed old release: {$release}");
            }
        }
    }

    private function resolveCloneUrl(string $basePath): ?string
    {
        // Use --clone option if provided
        if ($this->option('clone')) {
            return ProjectHelper::normalizeRepoUrl($this->option('clone'));
        }

        // Try to read from current release's git remote
        $currentLink = "{$basePath}/current";
        if (is_link($currentLink)) {
            $result = Process::path(readlink($currentLink) ?: $currentLink)
                ->run('git remote get-url origin');

            if ($result->successful()) {
                $url = trim($result->output());

                return ProjectHelper::normalizeRepoUrl($url) ?: $url;
            }
        }

        return null;
    }

    private function determineBasePath(ConfigManager $config, string $slug): string
    {
        if ($this->option('directory')) {
            return ProjectHelper::expandPath($this->option('directory'));
        }

        $paths = $config->getPaths();
        $basePath = $paths[0] ?? '~/projects';

        return ProjectHelper::expandPath("{$basePath}/{$slug}");
    }

    private function setEnvValue(string $env, string $key, string $value): string
    {
        if (preg_match("/^{$key}=.*/m", $env)) {
            return preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
        }

        return rtrim($env)."\n{$key}={$value}\n";
    }

    /**
     * Determine correct APP_URL based on node environment.
     */
    private function determineAppUrl(string $slug, string $tld, ?Node $node): string
    {
        // Query for registered GatewayProject
        $project = \HardImpact\Orbit\Core\Models\GatewayProject::where('slug', $slug)->first();

        // Use production domain if available and node is production
        if ($node && $node->isProduction() && $project && $project->production_domain) {
            return "https://{$project->production_domain}";
        }

        // Default to internal domain
        return "https://{$slug}.{$tld}";
    }

    /**
     * Generate Laravel-compatible APP_KEY.
     */
    private function generateAppKey(): string
    {
        $key = base64_encode(random_bytes(32));

        return "base64:{$key}";
    }

    /**
     * Set .env value only if key doesn't already exist.
     */
    private function setEnvValueIfMissing(string $env, string $key, string $value): string
    {
        if (! preg_match("/^{$key}=.*/m", $env)) {
            return $this->setEnvValue($env, $key, $value);
        }

        return $env;
    }

    /**
     * Clear Laravel config cache.
     */
    private function clearConfigCache(string $basePath): void
    {
        $currentLink = "{$basePath}/current";
        if (! is_link($currentLink)) {
            return;
        }

        $artisan = readlink($currentLink).'/artisan';
        if (! file_exists($artisan)) {
            return;
        }

        $this->logger->info('Clearing config cache...');

        // Suppress all output when in JSON mode
        if ($this->option('json')) {
            ob_start();
        }

        $result = Process::path(dirname($artisan))
            ->run('php artisan config:clear 2>&1');

        if ($this->option('json')) {
            ob_end_clean();
        }

        if ($result->successful()) {
            $this->logger->info('Config cache cleared');
        } else {
            $this->logger->warn('Failed to clear config cache: '.$result->errorOutput());
        }
    }

    /**
     * Create production Caddy site block with ACME TLS.
     */
    private function createProductionCaddyBlock(
        string $basePath,
        string $slug,
        string $domain,
        string $phpVersion,
        ConfigManager $config
    ): void {
        $sitesDir = $config->getConfigPath().'/caddy/sites';
        $caddyFile = "{$sitesDir}/{$slug}.caddy";

        // Skip if already exists
        if (file_exists($caddyFile)) {
            $this->logger->info("Caddy block already exists: {$caddyFile}");

            return;
        }

        // Ensure sites directory exists
        if (! is_dir($sitesDir)) {
            mkdir($sitesDir, 0755, true);
        }

        // Load stub template
        $stubPath = __DIR__.'/../../stubs/caddy/production-site.caddy.stub';
        if (! file_exists($stubPath)) {
            $this->logger->warn("Caddy stub template not found: {$stubPath}");

            return;
        }

        $stub = file_get_contents($stubPath);

        // Resolve PHP-FPM socket path
        $phpManager = app(\App\Services\PhpManager::class);
        $socketPath = $phpManager->getSocketPath($phpVersion);

        // Replace placeholders
        $block = str_replace([
            'ORBIT_DOMAIN',
            'ORBIT_ROOT_PATH',
            'ORBIT_SOCKET_PATH',
        ], [
            $domain,
            "{$basePath}/current/public",
            $socketPath,
        ], $stub);

        // Write Caddy block
        file_put_contents($caddyFile, $block);
        $this->logger->info("Created production Caddy block: {$caddyFile}");
    }

    /**
     * Resolve production domain from GatewayProject or fallback to internal domain.
     */
    private function resolveProductionDomain(string $slug, string $tld): string
    {
        // Query for registered GatewayProject
        $project = \HardImpact\Orbit\Core\Models\GatewayProject::where('slug', $slug)->first();

        if ($project && $project->production_domain) {
            return $project->production_domain;
        }

        // Fallback to internal domain
        return "{$slug}.{$tld}";
    }

    private function regenerateCaddy(): void
    {
        $this->logger->info('Regenerating Caddy configuration...');

        $result = $this->callSilentlyWhenJson('caddy:reload', ['--json' => true]);

        if ($result === 0) {
            $this->logger->info('Caddy configuration reloaded');
        } else {
            $this->logger->warn('Could not reload Caddy - you may need to reload manually');
        }
    }

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            $this->outputJsonError($message);
        } else {
            $this->error($message);
            $this->hintForError($message);
        }

        return ExitCode::GeneralError->value;
    }

    private function hintForError(string $message): void
    {
        if (str_contains($message, 'orbit init')) {
            $this->line('  <fg=gray>Initialize this node first: orbit init</>');
        } elseif (str_contains($message, '--clone is required')) {
            $this->line('  <fg=gray>Provide the GitHub repo: orbit project:deploy myapp --clone=org/repo</>');
        }
    }
}
