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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
final class GatewayCloudflareRemoveRecordTool extends Tool
{
    protected string $name = 'gateway_cloudflare_remove_record';

    protected string $description = 'Remove a DNS record from Cloudflare';

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
            'record_id' => $schema->string()->required()->description('Cloudflare DNS record ID to remove'),
            'zone_id' => $schema->string()->description('Cloudflare zone ID (falls back to global setting)'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'record_id' => 'required|string',
            'zone_id' => 'nullable|string',
        ]);

        $zoneId = $request->get('zone_id');

        if (! $this->cloudflare->isConfigured($zoneId)) {
            return Response::structured([
                'success' => false,
                'error' => 'Cloudflare not configured. Run: orbit cloudflare:configure',
            ]);
        }

        $deleted = $this->cloudflare->deleteRecord($request->get('record_id'), $zoneId);

        return Response::structured([
            'success' => $deleted,
            'removed_record_id' => $deleted ? $request->get('record_id') : null,
        ]);
    }
}
