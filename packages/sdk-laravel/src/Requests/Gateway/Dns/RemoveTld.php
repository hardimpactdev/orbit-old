<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Dns;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class RemoveTld extends McpRequest
{
    public function __construct(
        private readonly string $tld,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_remove_tld';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return [
            'tld' => $this->tld,
        ];
    }
}
