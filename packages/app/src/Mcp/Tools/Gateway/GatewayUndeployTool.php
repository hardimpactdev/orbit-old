<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\DeploymentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
final class GatewayUndeployTool extends Tool
{
    protected string $name = 'gateway_undeploy';

    protected string $description = 'Remove a deployment from a node and clean up Cloudflare DNS records';

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
            'deployment_id' => $schema->integer()->required()->description('Deployment ID to remove'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'deployment_id' => 'required|integer',
        ]);

        $deployment = Deployment::find($request->get('deployment_id'));

        if (! $deployment) {
            return Response::structured(['success' => false, 'error' => 'Deployment not found']);
        }

        $projectSlug = $deployment->project_slug;
        $nodeName = $deployment->node->name;

        $this->deploymentService->undeploy($deployment);

        return Response::structured([
            'success' => true,
            'removed' => [
                'project' => $projectSlug,
                'node' => $nodeName,
                'cloudflare_cleaned' => $deployment->hasCloudflareRecord(),
            ],
        ]);
    }
}
