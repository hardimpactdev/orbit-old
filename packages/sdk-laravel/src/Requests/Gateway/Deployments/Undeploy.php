<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Deployments;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class Undeploy extends McpRequest
{
    public function __construct(
        private readonly int $deploymentId,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_undeploy';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return [
            'deployment_id' => $this->deploymentId,
        ];
    }
}
