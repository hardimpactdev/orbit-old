<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Jobs;

use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Enums\ProjectStatus;
use HardImpact\Orbit\Core\Enums\RepoIntent;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\Provision\ProvisionLogger;
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
use HardImpact\Orbit\Core\Support\ProjectHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job for creating a new project with native Laravel broadcasting.
 *
 * This job handles the complete project provisioning process:
 * - Repository operations (clone, fork, template)
 * - Dependency installation
 * - Environment configuration
 * - Database setup
 *
 * Status updates are broadcast via native Laravel events to Reverb.
 */
final class CreateProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for provisioning

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1; // Don't retry - provisioning is not idempotent

    protected string $slug;

    public function __construct(
        protected int $projectId,
        protected array $options,
    ) {
        $this->slug = Str::slug($options['name']);
    }

    /**
     * Execute the job.
     */
    public function handle(ProvisionPipeline $pipeline, ConfigurationService $configService): void
    {
        $project = Project::findOrFail($this->projectId);
        $node = Node::findOrFail($project->node_id);

        // Initialize logger with native Laravel broadcasting
        $logger = new ProvisionLogger($this->slug, $this->projectId);

        $project->update([
            'status' => ProjectStatus::Queued,
            'job_id' => $this->job?->uuid() ?? $project->job_id,
            'error_message' => null,
        ]);

        $logger->broadcast('provisioning');
        Log::info("CreateProjectJob: Starting project creation for {$this->slug}");

        try {
            // Determine project path
            $projectPath = $this->determineProjectPath($project, $configService, $node);

            // Create project directory if it doesn't exist
            if (! is_dir($projectPath)) {
                if (! mkdir($projectPath, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$projectPath}");
                }
            }

            // Update project with path
            $project->update(['path' => $projectPath]);

            // Build provision context
            $context = $this->buildContext($projectPath, $node);

            // Determine repo intent
            $intent = RepoIntent::fromPayload($this->options);

            // Phase 1: Repository Operations
            $context = ProjectHelper::handleRepositoryOperations($context, $intent, $pipeline, $logger);

            // Phase 2: Clone repository (for clone/fork/template flows)
            if ($context->cloneUrl) {
                $logger->broadcast('cloning');
                $result = $pipeline->cloneRepository($context, $logger);
                if ($result->isFailed()) {
                    throw new \RuntimeException($result->error ?? 'Clone failed');
                }
            }

            // Phase 3: Run provision pipeline
            $logger->broadcast('setting_up');
            $result = $pipeline->run($context, $logger);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Provisioning failed');
            }

            // Phase 4: Finalize
            $logger->broadcast('finalizing');

            // Detect project type and public folder
            $hasPublicFolder = is_dir("{$projectPath}/public");
            $projectType = ProjectHelper::detectProjectType($projectPath);

            // Update project with final details
            $project->update([
                'status' => ProjectStatus::Ready,
                'github_repo' => $context->githubRepo,
                'url' => "https://{$this->slug}.{$context->tld}",
                'domain' => "{$this->slug}.{$context->tld}",
                'has_public_folder' => $hasPublicFolder,
                'project_type' => $projectType,
                'error_message' => null,
            ]);

            // Broadcast ready BEFORE Caddy reload to ensure event is received
            // (Caddy reload may temporarily drop WebSocket connections)
            $logger->broadcast('ready');

            // Regenerate Caddyfile and reload Caddy (runs on host via systemd)
            if ($hasPublicFolder) {
                $this->regenerateCaddy($logger);
            }
            Log::info("CreateProjectJob: Project {$this->slug} created successfully");

        } catch (\Throwable $e) {
            $project->update([
                'status' => ProjectStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            $logger->broadcast('failed', $e->getMessage());
            Log::error("CreateProjectJob: Project {$this->slug} creation failed", [
                'error' => $e->getMessage(),
            ]);

            // Cleanup empty directory
            if (isset($projectPath) && is_dir($projectPath) && ! glob("{$projectPath}/*")) {
                @rmdir($projectPath);
            }

            throw $e;
        }
    }

    /**
     * Determine the project path for the project.
     */
    protected function determineProjectPath(Project $project, ConfigurationService $configService, Node $node): string
    {
        // If path already set on project, use it
        if ($project->path) {
            return $project->path;
        }

        // If directory option provided, use it
        if (! empty($this->options['directory'])) {
            return ProjectHelper::expandPath($this->options['directory']);
        }

        // Get default path from config
        $config = $configService->getConfig($node);
        $paths = $config['data']['paths'] ?? [];
        $basePath = $paths[0] ?? '~/projects';

        return ProjectHelper::expandPath("{$basePath}/{$this->slug}");
    }

    /**
     * Build the provision context from options.
     */
    protected function buildContext(string $projectPath, Node $node): ProvisionContext
    {
        // Get TLD from environment
        $tld = $node->tld ?? config('orbit.tld');

        // Parse clone URL if provided
        $cloneUrl = $this->options['template'] ?? $this->options['clone_url'] ?? null;

        return new ProvisionContext(
            slug: $this->slug,
            projectPath: $projectPath,
            projectId: $this->projectId,
            cloneUrl: $cloneUrl,
            template: ($this->options['is_template'] ?? false) ? $cloneUrl : null,
            visibility: $this->options['visibility'] ?? 'private',
            phpVersion: $this->options['php_version'] ?? null,
            dbDriver: $this->options['db_driver'] ?? null,
            sessionDriver: $this->options['session_driver'] ?? null,
            cacheDriver: $this->options['cache_driver'] ?? null,
            queueDriver: $this->options['queue_driver'] ?? null,
            fork: $this->options['fork'] ?? false,
            displayName: $this->options['name'] ?? null,
            tld: $tld,
            organization: $this->options['org'] ?? null,
        );
    }


    /**
     * Get the slug for this project.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Get the tags for the job (for Horizon).
     */
    public function tags(): array
    {
        return [
            'create-project',
            "project:{$this->slug}",
            "project-id:{$this->projectId}",
        ];
    }


    /**
     * Regenerate Caddyfile and reload Caddy.
     *
     * Caddy runs on the host via systemd, not in Docker.
     * Uses the CLI's caddy:reload command which regenerates the Caddyfile
     * and reloads Caddy in one step.
     */
    protected function regenerateCaddy(ProvisionLogger $logger): void
    {
        $logger->info('Regenerating Caddy configuration...');

        $cliPath = config('orbit.cli_path', '/usr/local/bin/orbit');
        $result = \Illuminate\Support\Facades\Process::timeout(30)
            ->run("{$cliPath} caddy:reload --json 2>/dev/null");

        if ($result->successful()) {
            $output = json_decode($result->output(), true);
            if ($output['success'] ?? false) {
                $logger->info('Caddy configuration reloaded');

                return;
            }
        }

        $logger->info('Warning: Could not reload Caddy - you may need to reload manually');
    }
}
