<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class FlushCache extends McpRequest
{
    public function __construct(
        private readonly ?string $zoneId = null,
        private readonly ?string $projectSlug = null,
        private readonly ?string $urls = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_cloudflare_flush_cache';
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
        if ($this->urls !== null) {
            $args['urls'] = $this->urls;
        }

        return $args;
    }
}
