<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GatewayCloudflareStatusTool extends Tool
{
    protected string $name = 'gateway_cloudflare_status';

    protected string $description = 'Get Cloudflare zone info and SSL mode';

    public function __construct(
        protected CloudflareService $cloudflare,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (! $this->cloudflare->isConfigured()) {
            return Response::structured([
                'success' => false,
                'error' => 'Cloudflare not configured. Set cloudflare_api_token and cloudflare_zone_id in settings.',
            ]);
        }

        $zone = $this->cloudflare->getZone();

        if (! $zone) {
            return Response::structured(['success' => false, 'error' => 'Failed to fetch zone info']);
        }

        return Response::structured([
            'success' => true,
            'zone' => [
                'id' => $zone['id'],
                'name' => $zone['name'],
                'status' => $zone['status'],
                'name_servers' => $zone['name_servers'] ?? [],
            ],
        ]);
    }
}
