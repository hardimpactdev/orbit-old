<?php

declare(strict_types=1);

use HardImpact\Orbit\App\Mcp\GatewayServer;
use HardImpact\Orbit\App\Mcp\OrbitServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| Register MCP servers for AI tool integration.
|
| Orbit server: site management, Docker infrastructure, environment config
| Gateway server: VPN client management, DNS/TLD routing
|
| CLI usage: orbit mcp:start orbit | orbit mcp:start gateway
| HTTP endpoint: POST /mcp/orbit | POST /mcp/gateway
|
*/

// CLI transport (stdio)
Mcp::local('orbit', OrbitServer::class);
Mcp::local('gateway', GatewayServer::class);

// HTTP transport
Mcp::web('orbit', OrbitServer::class);
Mcp::web('gateway', GatewayServer::class);
