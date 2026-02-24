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
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareCreateCacheRuleTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareFlushCacheTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareSetSslTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareStatusTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCloudflareZonesTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCreateClientTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeploymentsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeployTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDnsMappingsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayFlushDnsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayNodesTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayProjectsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayRegisterProjectTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayRemoveTldTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayStatusTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewaySyncNodeTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayUndeployTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayUpdateCaddyTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayUpdateNodeTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayUpdateTldTool;
use Laravel\Mcp\Server;

final class GatewayServer extends Server
{
    protected string $name = 'Gateway';

    protected string $version = '1.0.0';

    public int $defaultPaginationLength = 50;

    protected string $instructions = <<<'INSTRUCTIONS'
        # Orbit Gateway

        The Gateway is the central VPN hub and deployment registry for all Orbit nodes. It runs WireGuard (via WG Easy) for secure machine-to-machine communication, dnsmasq for custom TLD routing, and tracks all project deployments across the network.

        ## Architecture

        ```
        Gateway (VPN Hub + Deployment Registry)
        ├── WG Easy (WireGuard VPN server + web UI)
        ├── dnsmasq (DNS with custom TLD routing)
        ├── Caddy (reverse proxy)
        ├── Project registry (cross-node deployment tracking)
        └── Cloudflare DNS management (multi-zone)
        ```

        ## Key Concepts

        - **VPN Clients**: Machines connected to the gateway via WireGuard
        - **TLD Mappings**: DNS entries routing custom TLDs (e.g. `.bear`) to specific VPN client IPs
        - **Nodes**: Registered machines with an environment (development/staging/production)
        - **Gateway Projects**: Registered projects that own deployments across nodes, with optional Cloudflare zone
        - **Deployments**: Project instances tracked across nodes
        - **Cloudflare DNS**: Multi-zone DNS management — each project can target a different zone

        ## Common Workflows

        1. **Register a project** (recommended for multi-node projects):
           - Use `gateway_cloudflare_zones` to see available zones
           - Use `gateway_register_project` with a production_domain — zone auto-detected
           - Use `gateway_projects` to list registered projects

        2. **Deploy a registered project**:
           - Use `gateway_nodes` to list available nodes (filter by environment)
           - Use `gateway_deploy` with `project_slug` — domain and DNS auto-derived
           - Dev nodes get `{slug}.{node.tld}`, production nodes get the production domain

        3. **Deploy without a project** (legacy/ad-hoc):
           - Use `gateway_deploy` with `name` + optional `domain` for manual DNS

        4. **Undeploy a project**:
           - Use `gateway_undeploy` to remove a deployment and clean up DNS

        5. **Sync existing projects**:
           - Use `gateway_sync_node` to discover projects already on a node

        6. **Manage Cloudflare DNS**:
           - Use `gateway_cloudflare_zones` to list all zones
           - Use `gateway_cloudflare_status` to check a specific zone (pass zone_id)
           - Use `gateway_cloudflare_dns` to list records (pass zone_id for specific zone)
           - Use `gateway_cloudflare_add_record` / `gateway_cloudflare_remove_record` for manual DNS

        7. **VPN management**:
           - Use `gateway_create_client` to create a VPN client
           - Use `gateway_add_tld` / `gateway_remove_tld` for TLD routing

        8. **View gateway health**:
           - Use `gateway_status` for an overview of the gateway node

        9. **Enable Cloudflare CDN caching** (see docs/reference/cloudflare-caching.md):
           - Use `gateway_cloudflare_create_cache_rule` with `project_slug` to enable caching for a zone
           - Use `gateway_cloudflare_flush_cache` with `project_slug` to purge after changes
           - Full-page caching also requires app-level changes (stateless middleware group)

        10. **Update node properties**:
           - Use `gateway_update_node` to change host, external_host, name, environment, status, or is_active

        11. **Update Caddy security headers**:
           - Use `gateway_update_caddy` with `dry_run: true` to preview changes
           - Use `gateway_update_caddy` with `dry_run: false` to apply (creates .bak backups)
           - Pass `slug` to update a single project, or omit for all projects on a node
        INSTRUCTIONS;

    protected array $tools = [
        GatewayStatusTool::class,
        GatewayClientsTool::class,
        GatewayCreateClientTool::class,
        GatewayDnsMappingsTool::class,
        GatewayAddTldTool::class,
        GatewayRemoveTldTool::class,
        GatewayNodesTool::class,
        GatewayRegisterProjectTool::class,
        GatewayProjectsTool::class,
        GatewayDeployTool::class,
        GatewayDeploymentsTool::class,
        GatewaySyncNodeTool::class,
        GatewayUndeployTool::class,
        GatewayCloudflareZonesTool::class,
        GatewayCloudflareStatusTool::class,
        GatewayCloudflareDnsTool::class,
        GatewayCloudflareAddRecordTool::class,
        GatewayCloudflareRemoveRecordTool::class,
        GatewayCloudflareSetSslTool::class,
        GatewayCloudflareFlushCacheTool::class,
        GatewayCloudflareCreateCacheRuleTool::class,
        GatewayFlushDnsTool::class,
        GatewayUpdateTldTool::class,
        GatewayUpdateNodeTool::class,
        GatewayUpdateCaddyTool::class,
    ];

    protected array $resources = [
        GatewayClientsResource::class,
        GatewayDnsResource::class,
        GatewayDeploymentsResource::class,
    ];
}
