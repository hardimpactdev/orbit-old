<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway\Nodes;

use HardImpact\Orbit\Requests\Gateway\McpRequest;

class UpdateNode extends McpRequest
{
    public function __construct(
        private readonly int $nodeId,
        private readonly ?string $name = null,
        private readonly ?string $host = null,
        private readonly ?string $externalHost = null,
        private readonly ?string $user = null,
        private readonly ?int $port = null,
        private readonly ?string $environment = null,
        private readonly ?string $status = null,
        private readonly ?bool $isActive = null,
    ) {}

    protected function toolName(): string
    {
        return 'gateway_update_node';
    }

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        $args = ['node_id' => $this->nodeId];

        if ($this->name !== null) {
            $args['name'] = $this->name;
        }
        if ($this->host !== null) {
            $args['host'] = $this->host;
        }
        if ($this->externalHost !== null) {
            $args['external_host'] = $this->externalHost;
        }
        if ($this->user !== null) {
            $args['user'] = $this->user;
        }
        if ($this->port !== null) {
            $args['port'] = $this->port;
        }
        if ($this->environment !== null) {
            $args['environment'] = $this->environment;
        }
        if ($this->status !== null) {
            $args['status'] = $this->status;
        }
        if ($this->isActive !== null) {
            $args['is_active'] = $this->isActive;
        }

        return $args;
    }
}
