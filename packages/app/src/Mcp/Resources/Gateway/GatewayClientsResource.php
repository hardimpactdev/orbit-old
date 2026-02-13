<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Resources\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class GatewayClientsResource extends Resource
{
    protected string $uri = 'gateway://clients';

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
        return 'gateway-clients';
    }

    public function title(): string
    {
        return 'VPN Clients';
    }

    public function description(): string
    {
        return 'All VPN clients connected to the gateway with their status and TLD assignments.';
    }

    public function handle(Request $request): Response
    {
        $password = Setting::get('wg_easy_password');
        $host = Setting::get('wg_easy_host', '127.0.0.1');
        $port = (int) Setting::get('wg_easy_port', '51821');

        if (! $password) {
            return Response::json([
                'error' => 'WG Easy password not configured',
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

        return Response::json([
            'clients' => $enrichedClients,
            'total' => count($enrichedClients),
            'online' => count(array_filter($enrichedClients, fn ($c) => $c['online'])),
        ]);
    }
}
