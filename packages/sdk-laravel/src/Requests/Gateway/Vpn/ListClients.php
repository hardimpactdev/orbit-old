<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Vpn;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class ListClients extends McpRequest
{
    protected function toolName(): string
    {
        return 'gateway_clients';
    }
}
