<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GatewayNodesTool extends Tool
{
    protected string $name = 'gateway_nodes';

    protected string $description = 'List all nodes with their environment, status, and deployment count';

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'environment' => $schema->string()->description('Filter by environment: development, staging, or production'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $query = Node::where('node_type', '!=', NodeType::Gateway)
            ->withCount('deployments');

        $environment = $request->get('environment');
        if ($environment) {
            $query->where('environment', $environment);
        }

        $nodes = $query->get()->map(fn (Node $node) => [
            'id' => $node->id,
            'name' => $node->name,
            'host' => $node->host,
            'external_host' => $node->external_host,
            'environment' => $node->environment?->value ?? 'development',
            'node_type' => $node->node_type->value,
            'status' => $node->status->value,
            'is_active' => $node->is_active,
            'tld' => $node->tld,
            'deployments_count' => $node->deployments_count,
        ]);

        return Response::structured([
            'nodes' => $nodes->toArray(),
            'total' => $nodes->count(),
        ]);
    }
}
