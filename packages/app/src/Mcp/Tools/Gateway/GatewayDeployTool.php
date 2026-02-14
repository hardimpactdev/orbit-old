<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

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
            'name' => $schema->string()->required()->description('Project name/slug'),
            'node_id' => $schema->integer()->required()->description('Target node ID'),
            'template' => $schema->string()->description('Template to use for project creation'),
            'clone' => $schema->string()->description('GitHub repo to clone (e.g. org/repo)'),
            'php_version' => $schema->string()->description('PHP version (e.g. 8.4)'),
            'domain' => $schema->string()->description('Cloudflare FQDN to create A record for (e.g. myapp.com)'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'node_id' => 'required|integer',
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
                'name' => $request->get('name'),
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
