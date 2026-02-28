<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class ListZones extends McpRequest
{
    protected function toolName(): string
    {
        return 'gateway_cloudflare_zones';
    }
}
