<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GatewayDeploymentsTool extends Tool
{
    protected string $name = 'gateway_deployments';

    protected string $description = 'List deployments across all nodes, filterable by project, node, environment, or status';

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Filter by project slug'),
            'node_id' => $schema->integer()->description('Filter by node ID'),
            'environment' => $schema->string()->description('Filter by node environment: development, staging, production'),
            'status' => $schema->string()->description('Filter by deployment status'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $query = Deployment::with('node');

        if ($project = $request->get('project')) {
            $query->where('project_slug', $project);
        }

        if ($nodeId = $request->get('node_id')) {
            $query->where('node_id', $nodeId);
        }

        if ($environment = $request->get('environment')) {
            $query->whereHas('node', fn ($q) => $q->where('environment', $environment));
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $deployments = $query->get()->map(fn (Deployment $d) => [
            'id' => $d->id,
            'project_slug' => $d->project_slug,
            'project_name' => $d->project_name,
            'github_repo' => $d->github_repo,
            'domain' => $d->domain,
            'url' => $d->url,
            'php_version' => $d->php_version,
            'status' => $d->status->value,
            'error_message' => $d->error_message,
            'node' => [
                'id' => $d->node->id,
                'name' => $d->node->name,
                'environment' => $d->node->environment->value,
            ],
            'created_at' => $d->created_at->toIso8601String(),
        ]);

        return Response::structured([
            'deployments' => $deployments->toArray(),
            'total' => $deployments->count(),
        ]);
    }
}
