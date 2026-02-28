<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Projects;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class ListGatewayProjects extends McpRequest
{
    public function __construct(
        private readonly ?string $slug = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_projects';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return array_filter([
            'slug' => $this->slug,
        ], fn ($v) => $v !== null);
    }
}
