<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GatewayDnsMappingsTool extends Tool
{
    protected string $name = 'gateway_dns_mappings';

    protected string $description = 'List all TLD-to-IP DNS mappings configured on the gateway';

    public function __construct(
        protected GatewayDnsService $dnsService,
    ) {}

    public function shouldRegister(): bool
    {
        $node = Node::getSelf();

        return $node?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $mappings = $this->dnsService->getMappings();

        return Response::structured([
            'mappings' => $mappings,
            'total' => count($mappings),
        ]);
    }
}
