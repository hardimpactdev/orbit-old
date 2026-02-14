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
final class GatewayCloudflareZonesTool extends Tool
{
    protected string $name = 'gateway_cloudflare_zones';

    protected string $description = 'List all available Cloudflare zones for the configured API token';

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
        $zones = $this->cloudflare->listZones();

        if ($zones === []) {
            return Response::structured([
                'success' => false,
                'error' => 'No zones returned. Run: orbit cloudflare:configure',
            ]);
        }

        $mapped = array_map(fn (array $z) => [
            'id' => $z['id'],
            'name' => $z['name'],
            'status' => $z['status'] ?? null,
        ], $zones);

        return Response::structured([
            'success' => true,
            'zones' => $mapped,
            'total' => count($mapped),
        ]);
    }
}
