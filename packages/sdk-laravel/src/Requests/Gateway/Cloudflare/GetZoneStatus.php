<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class GetZoneStatus extends McpRequest
{
    public function __construct(
        private readonly ?string $zoneId = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_cloudflare_status';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return array_filter([
            'zone_id' => $this->zoneId,
        ], fn ($v) => $v !== null);
    }
}
