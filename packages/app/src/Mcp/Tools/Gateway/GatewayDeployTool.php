<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use HardImpact\Orbit\Core\Services\DeploymentService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayDeployTool extends Tool
{
    protected string $name = 'gateway_deploy';

    protected string $description = 'Deploy a project to a target node with optional Cloudflare DNS setup';

    public function __construct(
        protected DeploymentService $deploymentService,
        protected CloudflareService $cloudflare,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Project name/slug (required unless project_slug is provided)'),
            'node_id' => $schema->integer()->required()->description('Target node ID'),
            'project_slug' => $schema->string()->description('Registered project slug — auto-derives domain and uses project zone'),
            'template' => $schema->string()->description('Template to use for project creation'),
            'clone' => $schema->string()->description('GitHub repo to clone (e.g. org/repo)'),
            'php_version' => $schema->string()->description('PHP version (e.g. 8.4)'),
            'domain' => $schema->string()->description('Cloudflare FQDN to create A record for (e.g. myapp.com)'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'node_id' => 'required|integer',
            'project_slug' => 'nullable|string|max:255',
            'template' => 'nullable|string',
            'clone' => 'nullable|string',
            'php_version' => 'nullable|string',
            'domain' => 'nullable|string',
        ]);

        $node = Node::find($request->get('node_id'));

        if (! $node) {
            return Response::structured(['success' => false, 'error' => 'Node not found']);
        }

        if (! $node->isActive()) {
            return Response::structured(['success' => false, 'error' => 'Node is not active']);
        }

        $repo = $request->get('clone') ?? GatewayProject::where('slug', $request->get('project_slug'))->value('github_repo');
        $phpVersion = $request->get('php_version');

        $preflight = $this->preflight($node, $repo, $phpVersion);
        if ($preflight) {
            return $preflight;
        }

        // Project-based deployment flow
        if ($projectSlug = $request->get('project_slug')) {
            return $this->deployWithProject($projectSlug, $node, $request);
        }

        // Legacy name-based deployment flow
        $name = $request->get('name');
        if (! $name) {
            return Response::structured(['success' => false, 'error' => 'Either name or project_slug is required']);
        }

        return $this->deployLegacy($name, $node, $request);
    }

    private function deployWithProject(string $projectSlug, Node $node, Request $request): ResponseFactory
    {
        $project = GatewayProject::where('slug', $projectSlug)->first();

        if (! $project) {
            return Response::structured(['success' => false, 'error' => "Project '{$projectSlug}' not found. Register it first with gateway_register_project."]);
        }

        try {
            $deployment = $this->deploymentService->deployProject($project, $node, array_filter([
                'clone' => $request->get('clone'),
                'template' => $request->get('template'),
                'php_version' => $request->get('php_version'),
            ]));
        } catch (\RuntimeException $e) {
            return Response::structured(['success' => false, 'error' => $e->getMessage()]);
        }

        if ($deployment->isFailed()) {
            return Response::structured([
                'success' => false,
                'error' => $deployment->error_message ?: 'Deployment failed for unknown reason',
                'deployment_id' => $deployment->id,
            ]);
        }

        return Response::structured([
            'success' => true,
            'deployment' => $this->formatDeploymentResult($deployment, $node),
        ]);
    }

    private function deployLegacy(string $name, Node $node, Request $request): ResponseFactory
    {
        $domain = $request->get('domain');

        if ($domain && $this->cloudflare->isConfigured()) {
            if (! $this->cloudflare->isDomainAvailable($domain)) {
                return Response::structured([
                    'success' => false,
                    'error' => "Domain '{$domain}' already has a DNS record in Cloudflare",
                ]);
            }
        }

        try {
            $deployment = $this->deploymentService->deploy($node, [
                'name' => $name,
                'clone' => $request->get('clone'),
                'template' => $request->get('template'),
                'php_version' => $request->get('php_version'),
            ]);
        } catch (\RuntimeException $e) {
            return Response::structured(['success' => false, 'error' => $e->getMessage()]);
        }

        if ($deployment->isFailed()) {
            return Response::structured([
                'success' => false,
                'error' => $deployment->error_message ?: 'Deployment failed for unknown reason',
                'deployment_id' => $deployment->id,
            ]);
        }

        $result = [
            'success' => true,
            'deployment' => $this->formatDeploymentResult($deployment, $node),
        ];

        if ($domain && $this->cloudflare->isConfigured() && $node->external_host) {
            $record = $this->cloudflare->createRecord(
                $domain,
                $node->external_host,
                proxied: false
            );
            if ($record) {
                $deployment->update([
                    'cloudflare_record_id' => $record['id'],
                    'domain' => $domain,
                ]);
                $result['cloudflare'] = [
                    'record_id' => $record['id'],
                    'domain' => $domain,
                    'target' => $node->external_host,
                ];
            }
        }

        return Response::structured($result);
    }

    private function preflight(Node $node, ?string $repo, ?string $phpVersion = null): ?ResponseFactory
    {
        $ssh = app(SshService::class)->testConnection($node);
        if (! $ssh['success']) {
            return Response::structured([
                'success' => false,
                'error' => "Cannot reach node '{$node->name}' via SSH: " . ($ssh['message'] ?: 'connection failed'),
            ]);
        }

        $binary = app(CommandService::class)->findBinary($node);
        if (! $binary) {
            return Response::structured([
                'success' => false,
                'error' => "Orbit CLI not found on node '{$node->name}'. Install it first.",
            ]);
        }

        if ($repo) {
            $repoCheck = app(SshService::class)->execute(
                $node,
                "gh repo view {$repo} --json name 2>&1"
            );
            if (! $repoCheck['success'] || str_contains($repoCheck['output'] ?? '', 'Could not resolve') || str_contains($repoCheck['error'] ?? '', 'auth login')) {
                return Response::structured([
                    'success' => false,
                    'error' => "Node '{$node->name}' cannot access repo '{$repo}'. Run `gh auth login` on the node to authenticate: ssh {$node->user}@{$node->host}",
                ]);
            }
        }

        // PHP version availability
        if ($phpVersion) {
            $socket = "~/.config/orbit/php/php{$phpVersion}.sock";
            $check = app(SshService::class)->execute($node, "[ -S {$socket} ] && echo exists || echo missing");

            if (trim($check['output'] ?? '') === 'missing') {
                // Get available PHP versions
                $available = app(SshService::class)->execute(
                    $node,
                    "ls ~/.config/orbit/php/php*.sock 2>/dev/null | grep -oP 'php\K[0-9]+' | sort -rn | tr '\n' ', ' | sed 's/,$//'"
                );

                return Response::structured([
                    'success' => false,
                    'error' => "PHP {$phpVersion} not available on node '{$node->name}'. Available versions: " . trim($available['output'] ?: 'none'),
                ]);
            }
        }

        return null;
    }

    private function formatDeploymentResult(Deployment $deployment, Node $node): array
    {
        return [
            'id' => $deployment->id,
            'project_slug' => $deployment->project_slug,
            'node' => $node->name,
            'status' => $deployment->status->value,
            'domain' => $deployment->domain,
            'url' => $deployment->url,
            'cloudflare_record_id' => $deployment->cloudflare_record_id,
        ];
    }
}
