<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Nodes;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class ListNodes extends McpRequest
{
    public function __construct(
        private readonly ?string $environment = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_nodes';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return array_filter([
            'environment' => $this->environment,
        ], fn ($v) => $v !== null);
    }
}
