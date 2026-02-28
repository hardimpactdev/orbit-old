<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class SetSslMode extends McpRequest
{
    public function __construct(
        private readonly string $zoneId,
        private readonly string $mode,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_cloudflare_set_ssl';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return [
            'zone_id' => $this->zoneId,
            'mode' => $this->mode,
        ];
    }
}
