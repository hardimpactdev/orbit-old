<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GatewayClientsTool extends Tool
{
    protected string $name = 'gateway_clients';

    protected string $description = 'List all VPN clients with connection status, IPs, and TLD assignments';

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
        $password = Setting::get('wg_easy_password');
        $host = Setting::get('wg_easy_host', '127.0.0.1');
        $port = (int) Setting::get('wg_easy_port', '51821');

        if (! $password) {
            return Response::structured([
                'success' => false,
                'error' => 'WG Easy password not configured. Set it via gateway:set-password command.',
            ]);
        }

        $wgService = WgEasyService::forGateway($host, $port, $password);
        $clients = $wgService->getClients();
        $mappings = $this->dnsService->getMappings();

        $mappingsByIp = [];
        foreach ($mappings as $mapping) {
            $mappingsByIp[$mapping['ip']] = $mapping['tld'];
        }

        $enrichedClients = array_map(function ($client) use ($mappingsByIp) {
            $ip = str_replace('/32', '', $client['ip']);
            $client['tld'] = $mappingsByIp[$ip] ?? null;
            $client['online'] = $client['latestHandshakeAt'] !== null;

            return $client;
        }, $clients);

        return Response::structured([
            'clients' => $enrichedClients,
            'total' => count($enrichedClients),
            'online' => count(array_filter($enrichedClients, fn ($c) => $c['online'])),
        ]);
    }
}
