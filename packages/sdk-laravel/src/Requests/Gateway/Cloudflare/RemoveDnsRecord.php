<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class RemoveDnsRecord extends McpRequest
{
    public function __construct(
        private readonly string $recordId,
        private readonly ?string $zoneId = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_cloudflare_remove_record';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = ['record_id' => $this->recordId];

        if ($this->zoneId !== null) {
            $args['zone_id'] = $this->zoneId;
        }

        return $args;
    }
}
