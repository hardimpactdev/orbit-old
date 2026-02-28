<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use HardImpact\Orbit\Data\Gateway\VpnClient;
use HardImpact\Orbit\Requests\Gateway\Vpn\CreateClient;
use HardImpact\Orbit\Requests\Gateway\Vpn\ListClients;

class VpnResource extends GatewayResource
{
    /**
     * @return VpnClient[]
     */
    public function list(): array
    {
        $response = $this->connector->send(new ListClients);
        $data = $this->unwrap($response);

        return array_map(
            fn (array $client) => VpnClient::from([
                'id' => $client['id'],
                'name' => $client['name'],
                'ip' => $client['ip'],
                'online' => $client['online'] ?? false,
                'tld' => $client['tld'] ?? null,
                'latestHandshakeAt' => $client['latestHandshakeAt'] ?? null,
                'publicKey' => $client['publicKey'] ?? null,
                'enabled' => $client['enabled'] ?? true,
            ]),
            $data['clients'] ?? [],
        );
    }

    public function create(string $name, ?string $tld = null): VpnClient
    {
        $response = $this->connector->send(new CreateClient($name, $tld));
        $data = $this->unwrap($response);
        $client = $data['client'];

        return VpnClient::from([
            'id' => $client['id'],
            'name' => $client['name'],
            'ip' => $client['ip'],
            'online' => $client['online'] ?? false,
            'tld' => $client['tld'] ?? $tld,
            'latestHandshakeAt' => $client['latestHandshakeAt'] ?? null,
            'publicKey' => $client['publicKey'] ?? null,
            'enabled' => $client['enabled'] ?? true,
        ]);
    }
}
