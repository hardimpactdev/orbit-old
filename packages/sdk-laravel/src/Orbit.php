<?php

declare(strict_types=1);

namespace HardImpact\Orbit;

use HardImpact\Orbit\Resources\ConfigResource;
use HardImpact\Orbit\Resources\Gateway\CloudflareResource;
use HardImpact\Orbit\Resources\Gateway\DeploymentResource;
use HardImpact\Orbit\Resources\Gateway\DnsResource;
use HardImpact\Orbit\Resources\Gateway\GatewayProjectResource;
use HardImpact\Orbit\Resources\Gateway\GatewayStatusResource;
use HardImpact\Orbit\Resources\Gateway\NodeResource;
use HardImpact\Orbit\Resources\Gateway\VpnResource;
use HardImpact\Orbit\Resources\JobResource;
use HardImpact\Orbit\Resources\PhpResource;
use HardImpact\Orbit\Resources\ProjectResource;
use HardImpact\Orbit\Resources\ServiceResource;
use HardImpact\Orbit\Resources\StatusResource;
use HardImpact\Orbit\Resources\WorkspaceResource;
use HardImpact\Orbit\Resources\WorktreeResource;

class Orbit
{
    private OrbitConnector $connector;

    private GatewayConnector $gatewayConnector;

    private ?StatusResource $status = null;

    private ?ProjectResource $projects = null;

    private ?ServiceResource $services = null;

    private ?WorkspaceResource $workspaces = null;

    private ?WorktreeResource $worktrees = null;

    private ?PhpResource $php = null;

    private ?ConfigResource $config = null;

    private ?JobResource $jobs = null;

    private ?GatewayStatusResource $gatewayStatus = null;

    private ?VpnResource $vpn = null;

    private ?DnsResource $dns = null;

    private ?NodeResource $nodes = null;

    private ?GatewayProjectResource $gatewayProjects = null;

    private ?DeploymentResource $deployments = null;

    private ?CloudflareResource $cloudflare = null;

    public function __construct(
        string $baseUrl,
        ?string $gatewayUrl = null,
        int $timeout = 30,
        bool $verifySsl = false,
    ) {
        $this->connector = new OrbitConnector($baseUrl, $timeout, $verifySsl);
        $this->gatewayConnector = new GatewayConnector(
            $gatewayUrl ?? rtrim($baseUrl, '/').'/mcp/gateway',
            $timeout,
            $verifySsl,
        );
    }

    // Orbit REST API resources

    public function status(): StatusResource
    {
        return $this->status ??= new StatusResource($this->connector);
    }

    public function projects(): ProjectResource
    {
        return $this->projects ??= new ProjectResource($this->connector);
    }

    public function services(): ServiceResource
    {
        return $this->services ??= new ServiceResource($this->connector);
    }

    public function workspaces(): WorkspaceResource
    {
        return $this->workspaces ??= new WorkspaceResource($this->connector);
    }

    public function worktrees(): WorktreeResource
    {
        return $this->worktrees ??= new WorktreeResource($this->connector);
    }

    public function php(): PhpResource
    {
        return $this->php ??= new PhpResource($this->connector);
    }

    public function config(): ConfigResource
    {
        return $this->config ??= new ConfigResource($this->connector);
    }

    public function jobs(): JobResource
    {
        return $this->jobs ??= new JobResource($this->connector);
    }

    // Gateway MCP resources

    public function gatewayStatus(): GatewayStatusResource
    {
        return $this->gatewayStatus ??= new GatewayStatusResource($this->gatewayConnector);
    }

    public function vpn(): VpnResource
    {
        return $this->vpn ??= new VpnResource($this->gatewayConnector);
    }

    public function dns(): DnsResource
    {
        return $this->dns ??= new DnsResource($this->gatewayConnector);
    }

    public function nodes(): NodeResource
    {
        return $this->nodes ??= new NodeResource($this->gatewayConnector);
    }

    public function gatewayProjects(): GatewayProjectResource
    {
        return $this->gatewayProjects ??= new GatewayProjectResource($this->gatewayConnector);
    }

    public function deployments(): DeploymentResource
    {
        return $this->deployments ??= new DeploymentResource($this->gatewayConnector);
    }

    public function cloudflare(): CloudflareResource
    {
        return $this->cloudflare ??= new CloudflareResource($this->gatewayConnector);
    }

    // Raw connector access

    public function connector(): OrbitConnector
    {
        return $this->connector;
    }

    public function gatewayConnector(): GatewayConnector
    {
        return $this->gatewayConnector;
    }
}
