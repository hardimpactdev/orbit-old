<?php

declare(strict_types=1);

use HardImpact\Orbit\App\Http\Middleware\McpAccessControl;
use HardImpact\Orbit\App\Mcp\GatewayServer;
use HardImpact\Orbit\App\Mcp\OrbitServer;
use Illuminate\Support\Facades\Route;
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
| Security: HTTP endpoints restricted to gateway VPN network (10.6.0.0/24)
|
*/

// CLI transport (stdio) - no access control needed
Mcp::local('orbit', OrbitServer::class);
Mcp::local('gateway', GatewayServer::class);

// HTTP transport - restricted to gateway VPN network
Route::middleware(McpAccessControl::class)->group(function () {
    Mcp::web('mcp/orbit', OrbitServer::class);
    Mcp::web('mcp/gateway', GatewayServer::class);
});
