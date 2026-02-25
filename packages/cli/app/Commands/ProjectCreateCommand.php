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
use HardImpact\Orbit\Core\Enums\RepoIntent;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
use HardImpact\Orbit\Core\Support\ProjectHelper;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

/**
 * CLI command for creating projects.
 *
 * This command runs the ProvisionPipeline synchronously, giving real-time
 * console output while broadcasting updates to Reverb for web UI updates.
 *
 * @see \HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline
 */
final class ProjectCreateCommand extends Command
{
    use SupportsJsonMode;
    use WithJsonOutput;

    protected $signature = 'project:create
        {name : Project name}
        {--clone= : Existing repo to clone (user/repo or git URL)}
        {--template= : Template repository (user/repo format)}
        {--visibility=private : Repository visibility (private/public)}
        {--php= : PHP version to use (8.3, 8.4, 8.5)}
        {--db-driver= : Database driver (sqlite, pgsql)}
        {--session-driver= : Session driver (file, database, redis)}
        {--cache-driver= : Cache driver (file, database, redis)}
        {--queue-driver= : Queue driver (sync, database, redis)}
        {--fork : Fork the repository instead of importing as new}
        {--organization= : GitHub organization to create the repo under (overrides personal account)}
        {--directory= : Custom directory path for the project}
        {--json : Output as JSON (for programmatic use)}';

    protected $description = 'Create a new project (runs provisioning synchronously with real-time output)';

    private ?ProvisionLogger $logger = null;

    public function handle(
        ConfigManager $config,
        ReverbBroadcaster $broadcaster,
        ProvisionPipeline $pipeline,
    ): int {
        /** @var string $name */
        $name = $this->argument('name');
        $slug = Str::slug($name);

        // Prevent reserved names
        if (strtolower($slug) === 'orbit') {
            return $this->failWithMessage('The name "orbit" is reserved for the system.');
        }

        $node = Node::getSelf();
        if (! $node) {
            return $this->failWithMessage('No node found. Run "orbit init" first.');
        }

        // Check if project already exists
        if (Project::where('slug', $slug)->exists()) {
            return $this->failWithMessage("Project '{$slug}' already exists.");
        }

        // Determine project path before creating record (path is NOT NULL)
        $projectPath = $this->determineProjectPath($config, $slug);

        // Determine PHP version (default to 8.4)
        $phpVersion = $this->option('php') ?? '8.4';

        // Create the project record
        $project = Project::create([
            'node_id' => $node->id,
            'name' => $slug,
            'display_name' => $name,
            'slug' => $slug,
            'path' => $projectPath,
            'php_version' => $phpVersion,
            'status' => ProjectStatus::Queued,
        ]);

        // Initialize logger with broadcaster for Reverb updates
        $this->logger = new ProvisionLogger(
            broadcaster: $broadcaster,
            command: $this->option('json') ? null : $this,
            slug: $slug,
            projectId: $project->id,
        );

        $this->logger->info("Creating project: {$name}");
        $this->logger->broadcast('provisioning');

        try {
            // Create project directory if it doesn't exist
            if (! is_dir($projectPath)) {
                if (! mkdir($projectPath, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$projectPath}");
                }
            }

            // Build provision context
            $context = $this->buildContext($slug, $projectPath, $project->id, $node);

            // Build options array for RepoIntent
            $options = $this->buildOptions($name);

            // Determine repo intent
            $intent = RepoIntent::fromPayload($options);

            // Phase 1: Repository Operations (fork/template)
            $context = ProjectHelper::handleRepositoryOperations($context, $intent, $pipeline, $this->logger);

            // Phase 2: Clone repository (for clone/fork/template flows)
            if ($context->cloneUrl) {
                $this->logger->broadcast('cloning');
                $result = $pipeline->cloneRepository($context, $this->logger);
                if ($result->isFailed()) {
                    throw new \RuntimeException($result->error ?? 'Clone failed');
                }
            }

            // Phase 3: Run provision pipeline
            $this->logger->broadcast('setting_up');
            $result = $pipeline->run($context, $this->logger);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Provisioning failed');
            }

            // Phase 4: Finalize
            $this->logger->broadcast('finalizing');

            // Detect project type and public folder
            $hasPublicFolder = is_dir("{$projectPath}/public");
            $projectType = ProjectHelper::detectProjectType($projectPath);
            $tld = $config->getTld();

            // Update project with final details
            $project->update([
                'status' => ProjectStatus::Ready,
                'github_repo' => $context->githubRepo,
                'url' => "https://{$slug}.{$tld}",
                'domain' => "{$slug}.{$tld}",
                'has_public_folder' => $hasPublicFolder,
                'project_type' => $projectType,
                'error_message' => null,
            ]);

            // Broadcast ready BEFORE Caddy reload
            $this->logger->broadcast('ready');

            // Regenerate Caddyfile and reload Caddy
            if ($hasPublicFolder) {
                $this->regenerateCaddy();
            }

            $this->logger->info("Project {$slug} created successfully!");

            return $this->outputJsonSuccess([
                'name' => $name,
                'slug' => $slug,
                'project_id' => $project->id,
                'status' => 'ready',
                'url' => "https://{$slug}.{$tld}",
                'path' => $projectPath,
            ]);

        } catch (\Throwable $e) {
            $project->update([
                'status' => ProjectStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            $this->logger->broadcast('failed', $e->getMessage());
            $this->logger->error($e->getMessage());

            // Cleanup empty directory
            if (is_dir($projectPath) && ! glob("{$projectPath}/*")) {
                @rmdir($projectPath);
            }

            if ($this->wantsJson()) {
                $this->outputJsonError($e->getMessage());
            }

            return ExitCode::GeneralError->value;
        }
    }

    /**
     * Determine the project path.
     */
    private function determineProjectPath(ConfigManager $config, string $slug): string
    {
        // If directory option provided, use it
        if ($this->option('directory')) {
            return ProjectHelper::expandPath($this->option('directory'));
        }

        // Get default path from config
        $paths = $config->getPaths();
        $basePath = $paths[0] ?? '~/projects';

        return ProjectHelper::expandPath("{$basePath}/{$slug}");
    }

    /**
     * Build the provision context from command options.
     */
    private function buildContext(string $slug, string $projectPath, int $projectId, Node $node): ProvisionContext
    {
        $tld = $node->tld ?? 'ccc';

        // Parse clone URL if provided
        $cloneUrl = $this->option('clone') ?? $this->option('template');
        if ($cloneUrl) {
            $cloneUrl = ProjectHelper::normalizeRepoUrl($cloneUrl);
        }

        return new ProvisionContext(
            slug: $slug,
            projectPath: $projectPath,
            projectId: $projectId,
            cloneUrl: $cloneUrl,
            template: $this->option('template') ? $cloneUrl : null,
            visibility: $this->option('visibility') ?? 'private',
            phpVersion: $this->option('php'),
            dbDriver: $this->option('db-driver'),
            sessionDriver: $this->option('session-driver'),
            cacheDriver: $this->option('cache-driver'),
            queueDriver: $this->option('queue-driver'),
            fork: (bool) $this->option('fork'),
            displayName: $this->argument('name'),
            tld: $tld,
            organization: $this->option('organization'),
        );
    }

    /**
     * Build options array for RepoIntent determination.
     */
    private function buildOptions(string $name): array
    {
        $options = ['name' => $name];

        if ($this->option('clone')) {
            $options['template'] = ProjectHelper::normalizeRepoUrl($this->option('clone'));
        } elseif ($this->option('template')) {
            $options['template'] = ProjectHelper::normalizeRepoUrl($this->option('template'));
            $options['is_template'] = true;
        }

        if ($this->option('fork')) {
            $options['fork'] = true;
        }

        return $options;
    }

    /**
     * Regenerate Caddyfile and reload Caddy.
     */
    private function regenerateCaddy(): void
    {
        $this->logger->info('Regenerating Caddy configuration...');

        // Call our own caddy:reload command
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
        if (str_contains($message, 'reserved')) {
            $this->line('  <fg=gray>Choose a different project name</>');
        } elseif (str_contains($message, 'orbit init')) {
            $this->line('  <fg=gray>Initialize this node first: orbit init</>');
        } elseif (str_contains($message, 'already exists')) {
            $this->line('  <fg=gray>List projects with: orbit project:list</>');
        }
    }
}
