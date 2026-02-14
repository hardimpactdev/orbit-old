<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use HardImpact\Orbit\Core\Services\DeploymentService;
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
                'error' => $deployment->error_message,
                'deployment_id' => $deployment->id,
            ]);
        }

        $result = [
            'success' => true,
            'deployment' => [
                'id' => $deployment->id,
                'project_slug' => $deployment->project_slug,
                'node' => $node->name,
                'status' => $deployment->status->value,
                'domain' => $deployment->domain,
                'url' => $deployment->url,
                'cloudflare_record_id' => $deployment->cloudflare_record_id,
            ],
        ];

        return Response::structured($result);
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
                'error' => $deployment->error_message,
                'deployment_id' => $deployment->id,
            ]);
        }

        $result = [
            'success' => true,
            'deployment' => [
                'id' => $deployment->id,
                'project_slug' => $deployment->project_slug,
                'node' => $node->name,
                'status' => $deployment->status->value,
                'domain' => $deployment->domain,
                'url' => $deployment->url,
            ],
        ];

        if ($domain && $this->cloudflare->isConfigured() && $node->external_host) {
            $record = $this->cloudflare->createRecord($domain, $node->external_host);
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
}
