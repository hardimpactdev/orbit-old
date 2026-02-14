<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp;

use HardImpact\Orbit\App\Mcp\Resources\Gateway\GatewayClientsResource;
use HardImpact\Orbit\App\Mcp\Resources\Gateway\GatewayDeploymentsResource;
use HardImpact\Orbit\App\Mcp\Resources\Gateway\GatewayDnsResource;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayAddTldTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayClientsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareAddRecordTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareDnsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareRemoveRecordTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareStatusTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCreateClientTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeploymentsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeployTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDnsMappingsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayNodesTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayRemoveTldTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayStatusTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewaySyncNodeTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayUndeployTool;
use Laravel\Mcp\Server;

final class GatewayServer extends Server
{
    protected string $name = 'Gateway';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'INSTRUCTIONS'
        # Orbit Gateway

        The Gateway is the central VPN hub and deployment registry for all Orbit nodes. It runs WireGuard (via WG Easy) for secure machine-to-machine communication, dnsmasq for custom TLD routing, and tracks all project deployments across the network.

        ## Architecture

        ```
        Gateway (VPN Hub + Deployment Registry)
        ├── WG Easy (WireGuard VPN server + web UI)
        ├── dnsmasq (DNS with custom TLD routing)
        ├── Caddy (reverse proxy)
        ├── Deployment tracking (projects across all nodes)
        └── Cloudflare DNS management (optional)
        ```

        ## Key Concepts

        - **VPN Clients**: Machines connected to the gateway via WireGuard
        - **TLD Mappings**: DNS entries routing custom TLDs (e.g. `.ccc`) to specific VPN client IPs
        - **Nodes**: Registered machines with an environment (development/staging/production)
        - **Deployments**: Project instances tracked across nodes
        - **Cloudflare DNS**: Optional public DNS management for deployed projects

        ## Common Workflows

        1. **Deploy a project to a node**:
           - Use `gateway_nodes` to list available nodes (filter by environment)
           - Use `gateway_deployments` to check where a project is already deployed
           - Use `gateway_deploy` to deploy with optional Cloudflare DNS setup

        2. **Undeploy a project**:
           - Use `gateway_undeploy` to remove a deployment and clean up DNS

        3. **Sync existing projects**:
           - Use `gateway_sync_node` to discover projects already on a node

        4. **Manage Cloudflare DNS**:
           - Use `gateway_cloudflare_status` to check zone info
           - Use `gateway_cloudflare_dns` to list records
           - Use `gateway_cloudflare_add_record` / `gateway_cloudflare_remove_record` for manual DNS management

        5. **VPN management**:
           - Use `gateway_create_client` to create a VPN client
           - Use `gateway_add_tld` / `gateway_remove_tld` for TLD routing

        6. **View gateway health**:
           - Use `gateway_status` for an overview of the gateway node
        INSTRUCTIONS;

    protected array $tools = [
        GatewayStatusTool::class,
        GatewayClientsTool::class,
        GatewayCreateClientTool::class,
        GatewayDnsMappingsTool::class,
        GatewayAddTldTool::class,
        GatewayRemoveTldTool::class,
        GatewayNodesTool::class,
        GatewayDeployTool::class,
        GatewayDeploymentsTool::class,
        GatewaySyncNodeTool::class,
        GatewayUndeployTool::class,
        GatewayCloudflareStatusTool::class,
        GatewayCloudflareDnsTool::class,
        GatewayCloudflareAddRecordTool::class,
        GatewayCloudflareRemoveRecordTool::class,
    ];

    protected array $resources = [
        GatewayClientsResource::class,
        GatewayDnsResource::class,
        GatewayDeploymentsResource::class,
    ];
}
