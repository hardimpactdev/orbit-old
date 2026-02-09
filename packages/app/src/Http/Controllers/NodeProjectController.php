<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Enums\ProjectStatus;
use HardImpact\Orbit\Core\Jobs\CreateProjectJob;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Models\TemplateFavorite;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;
use HardImpact\Orbit\Core\Services\SshService;
use HardImpact\Orbit\Core\Services\TemplateAnalyzer\EnvParser;
use HardImpact\Orbit\App\Http\Controllers\Concerns\HandlesGitHubIntegration;
use HardImpact\Orbit\App\Http\Controllers\Concerns\ProvidesRemoteApiUrl;
use Illuminate\Http\Request;

/**
 * Controller for project operations within a specific node.
 *
 * Routes are prefixed with /nodes/{node}/ and the node
 * is explicitly passed as a route parameter.
 *
 * @see ProjectController for operations using the "active node" pattern
 */
class NodeProjectController extends Controller
{
    use HandlesGitHubIntegration;
    use ProvidesRemoteApiUrl;

    public function __construct(
        protected ProjectCliService $project,
        protected ConfigurationService $config,
        protected SshService $ssh,
        protected EnvParser $envParser,
    ) {}

    /**
     * Get the SSH service instance (required by HandlesGitHubIntegration).
     */
    protected function getSshService(): SshService
    {
        return $this->ssh;
    }

    /**
     * Projects page (Inertia view).
     */
    public function index(Node $node): \Inertia\Response
    {
        $editor = $node->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($node);
        $reverb = $this->config->getReverbConfig($node);

        return \Inertia\Inertia::render('nodes/Projects', [
            'node' => $node,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
            'reverb' => $reverb['success'] ? [
                'enabled' => $reverb['enabled'] ?? false,
                'host' => $reverb['host'] ?? null,
                'port' => $reverb['port'] ?? null,
                'scheme' => $reverb['scheme'] ?? null,
                'app_key' => $reverb['app_key'] ?? null,
            ] : [
                'enabled' => false,
            ],
        ]);
    }

    /**
     * Projects API (JSON).
     */
    public function indexApi(Node $node)
    {
        return response()->json($this->project->projectList($node));
    }

    /**
     * Sync projects from CLI to database.
     */
    public function syncApi(Node $node)
    {
        $result = $this->project->syncAllProjectsFromCli($node);

        if (! $result['success']) {
            return response()->json($result, 500);
        }

        // Return fresh project list after sync
        return response()->json($this->project->projectList($node));
    }

    /**
     * Show the create project form.
     */
    public function create(Node $node): \Inertia\Response
    {
        $recentTemplates = TemplateFavorite::orderByDesc('last_used_at')
            ->limit(5)
            ->get();

        return \Inertia\Inertia::render('nodes/projects/ProjectCreate', [
            'node' => $node,
            'recentTemplates' => $recentTemplates,
        ]);
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request, Node $node)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'org' => 'nullable|string|max:255',
            'template' => 'nullable|string|max:500',
            'is_template' => 'boolean',
            'fork' => 'boolean',
            'visibility' => 'nullable|in:private,public',
            'php_version' => 'nullable|in:8.3,8.4,8.5',
            'db_driver' => 'nullable|in:sqlite,pgsql',
            'session_driver' => 'nullable|in:file,database,redis',
            'cache_driver' => 'nullable|in:file,database,redis',
            'queue_driver' => 'nullable|in:sync,database,redis',
        ]);

        $isTemplate = ! empty($validated['is_template']);
        $providedRepo = $validated['template'] ?? null;

        $isImportScenario = ! empty($providedRepo) && ! $isTemplate;

        if (! empty($validated['template'])) {
            $template = TemplateFavorite::firstOrCreate(
                ['repo_url' => $validated['template']],
                ['display_name' => $this->extractTemplateName($validated['template'])]
            );
            $template->recordUsage([
                'db_driver' => $validated['db_driver'] ?? null,
                'session_driver' => $validated['session_driver'] ?? null,
                'cache_driver' => $validated['cache_driver'] ?? null,
                'queue_driver' => $validated['queue_driver'] ?? null,
            ]);
        }

        $projectOptions = [
            'name' => $validated['name'],
            'org' => $validated['org'] ?? null,
            'template' => $validated['template'] ?? null,
            'is_template' => $validated['is_template'] ?? false,
            'fork' => $isImportScenario ? ($validated['fork'] ?? false) : false,
            'visibility' => $validated['visibility'] ?? 'private',
            'php_version' => $validated['php_version'] ?? null,
            'db_driver' => $validated['db_driver'] ?? null,
            'session_driver' => $validated['session_driver'] ?? null,
            'cache_driver' => $validated['cache_driver'] ?? null,
            'queue_driver' => $validated['queue_driver'] ?? null,
        ];

        $projectSlug = \Illuminate\Support\Str::slug($validated['name']);
        $config = $this->config->getConfig($node);
        $paths = $config['success'] ? ($config['data']['paths'] ?? []) : [];
        $basePath = $paths[0] ?? '~/projects';
        $projectPath = rtrim((string) $basePath, '/').'/'.$projectSlug;

        $project = Project::create([
            'node_id' => $node->id,
            'name' => $validated['name'],
            'display_name' => $validated['name'],
            'slug' => $projectSlug,
            'path' => $projectPath,
            'php_version' => $validated['php_version'] ?? config('orbit-ui.php.default', '8.4'),
            'github_repo' => $validated['template'] ?? null,
            'has_public_folder' => false,
            'status' => ProjectStatus::Queued,
        ]);

        CreateProjectJob::dispatch($project->id, $projectOptions);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Project creation queued',
                'slug' => $projectSlug,
                'project' => $project,
            ]);
        }

        return redirect()->route('nodes.projects', ['node' => $node->id])
            ->with([
                'provisioning' => $projectSlug,
                'success' => "Project '{$validated['name']}' is being created...",
            ]);
    }

    /**
     * Delete a project from the node.
     */
    public function destroy(Request $request, Node $node, string $projectName)
    {
        $keepDb = $request->boolean('keep_db', false);

        $result = $this->project->deleteProject($node, $projectName, force: true, keepDb: $keepDb);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete project',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Project '{$projectName}' deleted successfully",
        ]);
    }

    /**
     * Rebuild a project (re-run composer install, npm install, build, migrations).
     */
    public function rebuild(Request $request, Node $node, string $projectName)
    {
        $result = $this->project->rebuild($node, $projectName);

        return response()->json($result);
    }

    /**
     * Get the provisioning status of a project.
     */
    public function provisionStatus(Node $node, string $projectSlug)
    {
        $result = $this->project->provisionStatus($node, $projectSlug);

        return response()->json($result);
    }

    /**
     * Get the GitHub user for a node.
     */
    public function githubUser(Node $node)
    {
        $user = $this->project->getGitHubUser($node);

        return response()->json([
            'success' => $user !== null,
            'user' => $user,
        ]);
    }

    /**
     * Get GitHub organizations for a node.
     */
    public function githubOrgs(Node $node)
    {
        $result = $this->project->getGitHubOrgs($node);

        return response()->json($result);
    }

    /**
     * Check if a GitHub repository already exists.
     */
    public function githubRepoExists(Node $node, Request $request)
    {
        $request->validate([
            'repo' => 'required|string',
        ]);

        $repo = $request->input('repo');
        $result = $this->project->checkGitHubRepoExists($node, $repo);

        return response()->json([
            'success' => true,
            'exists' => $result['exists'],
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * Analyze a GitHub template to detect project type and extract driver defaults.
     *
     * Uses gh CLI on the node to access both public and private repos.
     */
    public function templateDefaults(Request $request, Node $node)
    {
        $validated = $request->validate([
            'template' => 'required|string|max:500',
        ]);

        $repo = $this->extractRepoFromTemplate($validated['template']);
        if (! $repo) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid template format. Use owner/repo or a GitHub URL.',
            ]);
        }

        $repoInfo = $this->getRepoInfoViaGh($node, $repo);
        if ($repoInfo === null) {
            return response()->json([
                'success' => false,
                'error' => 'Could not access repository. Check if it exists and you have access.',
            ]);
        }

        $isTemplate = $repoInfo['is_template'] ?? false;
        $defaultBranch = $repoInfo['default_branch'] ?? 'main';

        $envContent = $this->fetchFileViaGh($node, $repo, '.env.example');

        $drivers = [
            'db_driver' => null,
            'session_driver' => null,
            'cache_driver' => null,
            'queue_driver' => null,
        ];

        if ($envContent !== null) {
            $envVars = $this->envParser->parse($envContent);

            $drivers = [
                'db_driver' => $this->normalizeDriver($envVars['DB_CONNECTION'] ?? null),
                'session_driver' => $this->normalizeDriver($envVars['SESSION_DRIVER'] ?? null),
                'cache_driver' => $this->normalizeDriver($envVars['CACHE_STORE'] ?? $envVars['CACHE_DRIVER'] ?? null),
                'queue_driver' => $this->normalizeDriver($envVars['QUEUE_CONNECTION'] ?? null),
            ];
        }

        $composerContent = $this->fetchFileViaGh($node, $repo, 'composer.json');
        $metadata = [
            'framework' => 'unknown',
            'is_template' => $isTemplate,
            'default_branch' => $defaultBranch,
            'repo' => $repo,
            'min_php_version' => null,
            'recommended_php_version' => '8.5',
        ];

        if ($composerContent !== null) {
            $composer = json_decode($composerContent, true);
            if (is_array($composer)) {
                if (isset($composer['require']['laravel/framework'])) {
                    $metadata['framework'] = 'laravel';
                }

                if (isset($composer['require']['php'])) {
                    $phpConstraint = $composer['require']['php'];
                    $metadata['min_php_version'] = $this->extractMinPhpVersion($phpConstraint);
                    $metadata['recommended_php_version'] = $this->getRecommendedPhpVersion($phpConstraint);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'type' => $metadata['framework'],
                'is_template' => $isTemplate,
                'drivers' => $drivers,
                'metadata' => $metadata,
            ],
        ]);
    }
}
