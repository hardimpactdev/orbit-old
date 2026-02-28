<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Cloudflare;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class AddDnsRecord extends McpRequest
{
    public function __construct(
        private readonly string $name,
        private readonly string $content,
        private readonly string $type = 'A',
        private readonly bool $proxied = false,
        private readonly ?string $zoneId = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_cloudflare_add_record';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = [
            'name' => $this->name,
            'content' => $this->content,
            'type' => $this->type,
            'proxied' => $this->proxied,
        ];

        if ($this->zoneId !== null) {
            $args['zone_id'] = $this->zoneId;
        }

        return $args;
    }
}
