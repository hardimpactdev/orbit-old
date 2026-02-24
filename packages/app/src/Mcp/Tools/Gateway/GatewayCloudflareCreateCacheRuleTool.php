<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayCloudflareCreateCacheRuleTool extends Tool
{
    protected string $name = 'gateway_cloudflare_create_cache_rule';

    protected string $description = 'Create a "Cache Everything" cache rule for a Cloudflare zone (idempotent, overwrites existing rules)';

    public function __construct(
        protected CloudflareService $cloudflare,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'zone_id' => $schema->string()->description('Cloudflare zone ID (auto-resolved from project_slug if omitted)'),
            'project_slug' => $schema->string()->description('Resolve zone from registered GatewayProject'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'zone_id' => 'nullable|string',
            'project_slug' => 'nullable|string',
        ]);

        $zoneId = $request->get('zone_id');

        if (! $zoneId && $projectSlug = $request->get('project_slug')) {
            $project = GatewayProject::where('slug', $projectSlug)->first();
            if (! $project) {
                return Response::structured([
                    'success' => false,
                    'error' => "Project '{$projectSlug}' not found",
                ]);
            }
            $zoneId = $project->cloudflare_zone_id;
        }

        if (! $zoneId) {
            return Response::structured([
                'success' => false,
                'error' => 'Either zone_id or project_slug is required',
            ]);
        }

        if (! $this->cloudflare->isConfigured($zoneId)) {
            return Response::structured([
                'success' => false,
                'error' => 'Cloudflare not configured for this zone',
            ]);
        }

        $success = $this->cloudflare->createCacheRule($zoneId);

        return Response::structured([
            'success' => $success,
            'zone_id' => $zoneId,
            'rule' => 'Cache everything - respect origin Cache-Control',
        ]);
    }
}
