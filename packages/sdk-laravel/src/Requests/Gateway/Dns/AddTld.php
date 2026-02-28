<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Dns;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class AddTld extends McpRequest
{
    public function __construct(
        private readonly string $tld,
        private readonly string $ip,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_add_tld';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return [
            'tld' => $this->tld,
            'ip' => $this->ip,
        ];
    }
}
