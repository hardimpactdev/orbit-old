<?php

declare(strict_types=1);

namespace HardImpact\Orbit;

use HardImpact\Orbit\Resources\Gateway\CloudflareResource;
use HardImpact\Orbit\Resources\Gateway\DeploymentResource;
use HardImpact\Orbit\Resources\Gateway\DnsResource;
use HardImpact\Orbit\Resources\Gateway\GatewayProjectResource;
use HardImpact\Orbit\Resources\Gateway\GatewayStatusResource;
use HardImpact\Orbit\Resources\Gateway\NodeResource;
use HardImpact\Orbit\Resources\Gateway\VpnResource;
use Saloon\Http\Connector;

class GatewayConnector extends Connector
{
    public function __construct(
        protected string $endpointUrl,
        protected int $timeout = 30,
        protected bool $verifySsl = false,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->endpointUrl;
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
            'verify' => $this->verifySsl,
        ];
    }

    public function status(): GatewayStatusResource
    {
        return new GatewayStatusResource($this);
    }

    public function vpn(): VpnResource
    {
        return new VpnResource($this);
    }

    public function dns(): DnsResource
    {
        return new DnsResource($this);
    }

    public function nodes(): NodeResource
    {
        return new NodeResource($this);
    }

    public function projects(): GatewayProjectResource
    {
        return new GatewayProjectResource($this);
    }

    public function deployments(): DeploymentResource
    {
        return new DeploymentResource($this);
    }

    public function cloudflare(): CloudflareResource
    {
        return new CloudflareResource($this);
    }
}
