<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Deployments;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class Deploy extends McpRequest
{
    public function __construct(
        private readonly int $nodeId,
        private readonly ?string $projectSlug = null,
        private readonly ?string $name = null,
        private readonly ?string $clone = null,
        private readonly ?string $template = null,
        private readonly ?string $phpVersion = null,
        private readonly ?string $domain = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_deploy';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = ['node_id' => $this->nodeId];

        if ($this->projectSlug !== null) {
            $args['project_slug'] = $this->projectSlug;
        }
        if ($this->name !== null) {
            $args['name'] = $this->name;
        }
        if ($this->clone !== null) {
            $args['clone'] = $this->clone;
        }
        if ($this->template !== null) {
            $args['template'] = $this->template;
        }
        if ($this->phpVersion !== null) {
            $args['php_version'] = $this->phpVersion;
        }
        if ($this->domain !== null) {
            $args['domain'] = $this->domain;
        }

        return $args;
    }
}
