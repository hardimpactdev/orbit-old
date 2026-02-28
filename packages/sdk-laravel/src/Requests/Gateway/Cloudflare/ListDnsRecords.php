<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class ListDnsRecords extends McpRequest
{
    public function __construct(
        private readonly ?string $name = null,
        private readonly ?string $type = null,
        private readonly ?string $zoneId = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_cloudflare_dns';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = [];

        if ($this->name !== null) {
            $args['name'] = $this->name;
        }
        if ($this->type !== null) {
            $args['type'] = $this->type;
        }
        if ($this->zoneId !== null) {
            $args['zone_id'] = $this->zoneId;
        }

        return $args;
    }
}
