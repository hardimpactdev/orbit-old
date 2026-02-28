<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Nodes;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class SyncNode extends McpRequest
{
    public function __construct(
        private readonly int $nodeId,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_sync_node';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return [
            'node_id' => $this->nodeId,
        ];
    }
}
