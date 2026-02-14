<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GatewayProjectsTool extends Tool
{
    protected string $name = 'gateway_projects';

    protected string $description = 'List registered gateway projects with deployment counts and zone info';

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Filter by project slug'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $query = GatewayProject::withCount(['deployments as active_deployments_count' => function ($q) {
            $q->where('status', DeploymentStatus::Active);
        }]);

        if ($slug = $request->get('slug')) {
            $query->where('slug', $slug);
        }

        $projects = $query->get()->map(fn (GatewayProject $p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'github_repo' => $p->github_repo,
            'production_domain' => $p->production_domain,
            'cloudflare_zone_id' => $p->cloudflare_zone_id,
            'cloudflare_zone_name' => $p->cloudflare_zone_name,
            'active_deployments' => $p->active_deployments_count,
            'created_at' => $p->created_at->toIso8601String(),
        ]);

        return Response::structured([
            'projects' => $projects->toArray(),
            'total' => $projects->count(),
        ]);
    }
}
