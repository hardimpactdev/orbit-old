<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\DeploymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewaySyncNodeTool extends Tool
{
    protected string $name = 'gateway_sync_node';

    protected string $description = 'Sync deployments from a node by discovering its projects via CLI';

    public function __construct(
        protected DeploymentService $deploymentService,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->required()->description('Node ID to sync'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'node_id' => 'required|integer',
        ]);

        $node = Node::find($request->get('node_id'));

        if (! $node) {
            return Response::structured(['success' => false, 'error' => 'Node not found']);
        }

        $result = $this->deploymentService->syncNode($node);

        return Response::structured($result);
    }
}
