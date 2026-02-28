<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use HardImpact\Orbit\Data\Gateway\GatewayStatus;
use HardImpact\Orbit\Requests\Gateway\Status\FlushDns;
use HardImpact\Orbit\Requests\Gateway\Status\GetGatewayStatus;

class GatewayStatusResource extends GatewayResource
{
    public function get(): GatewayStatus
    {
        $response = $this->connector->send(new GetGatewayStatus);
        $data = $this->unwrap($response);

        return GatewayStatus::from([
            'node' => $data['node'] ?? [],
            'gateways_configured' => $data['gateways_configured'] ?? 0,
            'dns_mappings' => $data['dns_mappings'] ?? 0,
            'services_running' => $data['services_running'] ?? 0,
            'services_total' => $data['services_total'] ?? 0,
            'services' => $data['services'] ?? [],
        ]);
    }

    /** @return array<string, mixed> */
    public function flushDns(): array
    {
        $response = $this->connector->send(new FlushDns);

        return $this->unwrap($response);
    }
}
