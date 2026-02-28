<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Nodes;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class UpdateTld extends McpRequest
{
    public function __construct(
        private readonly int $nodeId,
        private readonly string $newTld,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_update_tld';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return [
            'node_id' => $this->nodeId,
            'new_tld' => $this->newTld,
        ];
    }
}
