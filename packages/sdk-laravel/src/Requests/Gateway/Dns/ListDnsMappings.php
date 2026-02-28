<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Dns;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class ListDnsMappings extends McpRequest
{
    protected function toolName(): string
    {
        return 'gateway_dns_mappings';
    }
}
