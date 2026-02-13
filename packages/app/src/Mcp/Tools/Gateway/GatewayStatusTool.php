<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GatewayStatusTool extends Tool
{
    protected string $name = 'gateway_status';

    protected string $description = 'Get gateway status including VPN client count, DNS mappings, and service health';

    public function __construct(
        protected GatewayManager $gatewayManager,
        protected GatewayDnsService $dnsService,
        protected StatusService $statusService,
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
        $node = Node::getSelf();

        if (! $node) {
            return Response::structured([
                'success' => false,
                'error' => 'No local node configured',
            ]);
        }

        $gateways = $this->gatewayManager->all();
        $mappings = $this->dnsService->getMappings();
        $status = $this->statusService->status($node);

        $services = $status['data']['services'] ?? [];
        $runningCount = count(array_filter($services, fn ($s) => ($s['status'] ?? '') === 'running'));

        return Response::structured([
            'node' => [
                'name' => $node->name,
                'type' => $node->node_type->value,
                'host' => $node->host,
            ],
            'gateways_configured' => count($gateways),
            'dns_mappings' => count($mappings),
            'services_running' => $runningCount,
            'services_total' => count($services),
            'services' => $services,
        ]);
    }
}
