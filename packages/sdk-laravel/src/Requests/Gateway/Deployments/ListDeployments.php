<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Deployments;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class ListDeployments extends McpRequest
{
    public function __construct(
        private readonly ?string $project = null,
        private readonly ?int $nodeId = null,
        private readonly ?string $environment = null,
        private readonly ?string $status = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_deployments';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = [];

        if ($this->project !== null) {
            $args['project'] = $this->project;
        }
        if ($this->nodeId !== null) {
            $args['node_id'] = $this->nodeId;
        }
        if ($this->environment !== null) {
            $args['environment'] = $this->environment;
        }
        if ($this->status !== null) {
            $args['status'] = $this->status;
        }

        return $args;
    }
}
