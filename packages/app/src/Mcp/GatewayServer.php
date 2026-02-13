<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp;

use HardImpact\Orbit\App\Mcp\Resources\Gateway\GatewayClientsResource;
use HardImpact\Orbit\App\Mcp\Resources\Gateway\GatewayDnsResource;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayAddTldTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayClientsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayCreateClientTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDnsMappingsTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayRemoveTldTool;
use HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayStatusTool;
use Laravel\Mcp\Server;

final class GatewayServer extends Server
{
    protected string $name = 'Gateway';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'INSTRUCTIONS'
        # Orbit Gateway

        The Gateway is the central VPN hub that connects all Orbit nodes. It runs WireGuard (via WG Easy) for secure machine-to-machine communication and dnsmasq for custom TLD routing across the VPN.

        ## Architecture

        ```
        Gateway (VPN Hub)
        ├── WG Easy (WireGuard VPN server + web UI)
        ├── dnsmasq (DNS with custom TLD routing)
        └── Caddy (reverse proxy)

        Client nodes connect via WireGuard tunnel.
        Each client gets a VPN IP (e.g. 10.8.0.x) and can be assigned a custom TLD.
        ```

        ## Key Concepts

        - **VPN Clients**: Machines connected to the gateway via WireGuard
        - **TLD Mappings**: DNS entries routing custom TLDs (e.g. `.ccc`) to specific VPN client IPs
        - **Gateway DNS**: dnsmasq config that resolves `*.tld` to the correct VPN client

        ## Common Workflows

        1. **Add a new client node**:
           - Use `gateway_create_client` to create a VPN client
           - Optionally assign a TLD with `gateway_add_tld`

        2. **Route traffic to a client**:
           - Use `gateway_add_tld` to map a TLD to the client's VPN IP
           - All `*.tld` traffic will route through the VPN to that client

        3. **Check connected clients**:
           - Use `gateway_clients` to see all VPN clients and their status
           - Use `gateway_dns_mappings` to see TLD routing

        4. **View gateway health**:
           - Use `gateway_status` for an overview of the gateway node
        INSTRUCTIONS;

    protected array $tools = [
        GatewayStatusTool::class,
        GatewayClientsTool::class,
        GatewayCreateClientTool::class,
        GatewayDnsMappingsTool::class,
        GatewayAddTldTool::class,
        GatewayRemoveTldTool::class,
    ];

    protected array $resources = [
        GatewayClientsResource::class,
        GatewayDnsResource::class,
    ];
}
