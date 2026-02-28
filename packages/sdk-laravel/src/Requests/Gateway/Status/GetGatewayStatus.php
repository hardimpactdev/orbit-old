<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Status;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class GetGatewayStatus extends McpRequest
{
    protected function toolName(): string
    {
        return 'gateway_status';
    }
}
