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

final class GatewayCloudflareSetSslTool extends Tool
{
    protected string $name = 'gateway_cloudflare_set_ssl';

    protected string $description = 'Set Cloudflare zone SSL/TLS mode';

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
            'zone_id' => $schema->string()->required()->description('Cloudflare zone ID'),
            'mode' => $schema->string()->required()->description('SSL mode: off, flexible, full, strict'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'zone_id' => 'required|string',
            'mode' => 'required|string|in:off,flexible,full,strict',
        ]);

        $zoneId = $request->get('zone_id');
        $mode = $request->get('mode');

        if (! $this->cloudflare->isConfigured($zoneId)) {
            return Response::structured([
                'success' => false,
                'error' => 'Cloudflare not configured for this zone',
            ]);
        }

        $success = $this->cloudflare->setSslMode($zoneId, $mode);

        if (! $success) {
            return Response::structured([
                'success' => false,
                'error' => 'Failed to set SSL mode',
            ]);
        }

        return Response::structured([
            'success' => true,
            'zone_id' => $zoneId,
            'ssl_mode' => $mode,
        ]);
    }
}
