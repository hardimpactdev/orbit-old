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
final class GatewayCloudflareDnsTool extends Tool
{
    protected string $name = 'gateway_cloudflare_dns';

    protected string $description = 'List Cloudflare DNS records, optionally filtered by name or type';

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
            'name' => $schema->string()->description('Filter by record name (FQDN)'),
            'type' => $schema->string()->description('Filter by record type (A, CNAME, TXT, etc.)'),
            'zone_id' => $schema->string()->description('Cloudflare zone ID (falls back to global setting)'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $zoneId = $request->get('zone_id');

        if (! $this->cloudflare->isConfigured($zoneId)) {
            return Response::structured([
                'success' => false,
                'error' => 'Cloudflare not configured. Run: orbit cloudflare:configure',
            ]);
        }

        $records = $this->cloudflare->listRecords(
            name: $request->get('name'),
            type: $request->get('type'),
            zoneId: $zoneId,
        );

        $mapped = array_map(fn ($r) => [
            'id' => $r['id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'content' => $r['content'],
            'proxied' => $r['proxied'] ?? false,
            'ttl' => $r['ttl'],
        ], $records);

        return Response::structured([
            'success' => true,
            'records' => $mapped,
            'total' => count($mapped),
        ]);
    }
}
