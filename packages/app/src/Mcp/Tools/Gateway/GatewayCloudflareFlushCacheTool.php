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

final class GatewayCloudflareFlushCacheTool extends Tool
{
    protected string $name = 'gateway_cloudflare_flush_cache';

    protected string $description = 'Purge Cloudflare cache for a zone (everything or specific URLs)';

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
            'urls' => $schema->string()->description('Comma-separated URLs for targeted purge (omit to purge everything)'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'zone_id' => 'nullable|string',
            'project_slug' => 'nullable|string',
            'urls' => 'nullable|string',
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

        $urlsParam = $request->get('urls');

        if ($urlsParam) {
            $urls = array_map('trim', explode(',', $urlsParam));
            $success = $this->cloudflare->purgeCacheByUrls($urls, $zoneId);

            return Response::structured([
                'success' => $success,
                'zone_id' => $zoneId,
                'purged' => 'urls',
                'url_count' => count($urls),
            ]);
        }

        $success = $this->cloudflare->purgeCache($zoneId);

        return Response::structured([
            'success' => $success,
            'zone_id' => $zoneId,
            'purged' => 'everything',
        ]);
    }
}
