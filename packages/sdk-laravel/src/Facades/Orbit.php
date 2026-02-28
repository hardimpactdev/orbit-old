<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Facades;

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
use Illuminate\Support\Facades\Facade;

/**
 * @method static StatusResource status()
 * @method static ProjectResource projects()
 * @method static ServiceResource services()
 * @method static WorkspaceResource workspaces()
 * @method static WorktreeResource worktrees()
 * @method static PhpResource php()
 * @method static ConfigResource config()
 * @method static JobResource jobs()
 * @method static GatewayStatusResource gatewayStatus()
 * @method static VpnResource vpn()
 * @method static DnsResource dns()
 * @method static NodeResource nodes()
 * @method static GatewayProjectResource gatewayProjects()
 * @method static DeploymentResource deployments()
 * @method static CloudflareResource cloudflare()
 *
 * @see \HardImpact\Orbit\Orbit
 */
class Orbit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \HardImpact\Orbit\Orbit::class;
    }
}
