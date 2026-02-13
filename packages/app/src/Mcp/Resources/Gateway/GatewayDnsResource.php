<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Resources\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class GatewayDnsResource extends Resource
{
    protected string $uri = 'gateway://dns';

    protected string $mimeType = 'application/json';

    public function __construct(
        protected GatewayDnsService $dnsService,
    ) {}

    public function shouldRegister(): bool
    {
        $node = Node::getSelf();

        return $node?->isGateway() ?? false;
    }

    public function name(): string
    {
        return 'gateway-dns';
    }

    public function title(): string
    {
        return 'DNS Configuration';
    }

    public function description(): string
    {
        return 'Current DNS/TLD configuration and mappings on the gateway.';
    }

    public function handle(Request $request): Response
    {
        $mappings = $this->dnsService->getMappings();

        return Response::json([
            'mappings' => $mappings,
            'total' => count($mappings),
        ]);
    }
}
