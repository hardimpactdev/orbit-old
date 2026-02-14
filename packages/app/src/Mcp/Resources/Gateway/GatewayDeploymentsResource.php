<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Resources\Gateway;

use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class GatewayDeploymentsResource extends Resource
{
    protected string $uri = 'gateway://deployments';

    protected string $mimeType = 'application/json';

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function name(): string
    {
        return 'gateway-deployments';
    }

    public function title(): string
    {
        return 'Deployments';
    }

    public function description(): string
    {
        return 'All project deployments across nodes with status and domain information.';
    }

    public function handle(Request $request): Response
    {
        $deployments = Deployment::with('node')->get();

        $grouped = $deployments->groupBy('project_slug')->map(function ($group) {
            return [
                'project_slug' => $group->first()->project_slug,
                'project_name' => $group->first()->project_name,
                'nodes' => $group->map(fn (Deployment $d) => [
                    'node' => $d->node->name,
                    'environment' => $d->node->environment->value,
                    'status' => $d->status->value,
                    'domain' => $d->domain,
                    'url' => $d->url,
                ])->values()->toArray(),
            ];
        })->values();

        return Response::json([
            'deployments' => $grouped->toArray(),
            'total_projects' => $grouped->count(),
            'total_deployments' => $deployments->count(),
        ]);
    }
}
