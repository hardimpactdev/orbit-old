<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Nodes;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class UpdateCaddy extends McpRequest
{
    public function __construct(
        private readonly int $nodeId,
        private readonly ?string $slug = null,
        private readonly bool $dryRun = true,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_update_caddy';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = [
            'node_id' => $this->nodeId,
            'dry_run' => $this->dryRun,
        ];

        if ($this->slug !== null) {
            $args['slug'] = $this->slug;
        }

        return $args;
    }
}
