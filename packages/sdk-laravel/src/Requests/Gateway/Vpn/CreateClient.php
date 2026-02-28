<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Vpn;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class CreateClient extends McpRequest
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $tld = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_create_client';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return array_filter([
            'name' => $this->name,
            'tld' => $this->tld,
        ], fn ($v) => $v !== null);
    }
}
