# Orbit App

A Laravel package providing the web interface and MCP servers for the Orbit ecosystem.

## Overview

Orbit App contains:

- **Controllers & Routes**: Web UI, API endpoints, node management
- **MCP Servers**: OrbitServer (local nodes) and GatewayServer (gateway nodes)
- **Vue Frontend**: Pages, components, layouts (Inertia.js + Tailwind)
- **Middleware**: HandleInertiaRequests, ImplicitNode

Depends on orbit-core for business logic. Required by orbit-web (production shell) and orbit-desktop (NativePHP shell).

## Installation

```bash
composer require hardimpactdev/orbit-app
```

## MCP Servers

Two MCP servers with conditional tool registration based on node type:

| Server | Node Type | Tools | Description |
|--------|-----------|-------|-------------|
| OrbitServer | Local/Client | 10 | Site management, Docker, PHP config |
| GatewayServer | Gateway | 6 | VPN clients, DNS/TLD routing |

**HTTP endpoints:** `POST /mcp/orbit`, `POST /mcp/gateway`
**CLI transport:** `php artisan mcp:start orbit`, `php artisan mcp:start gateway`

## Testing

```bash
composer test
```

## License

MIT
