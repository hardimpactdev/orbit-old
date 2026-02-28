<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class CreateCacheRule extends McpRequest
{
    public function __construct(
        private readonly ?string $zoneId = null,
        private readonly ?string $projectSlug = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_cloudflare_create_cache_rule';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = [];

        if ($this->zoneId !== null) {
            $args['zone_id'] = $this->zoneId;
        }
        if ($this->projectSlug !== null) {
            $args['project_slug'] = $this->projectSlug;
        }

        return $args;
    }
}
