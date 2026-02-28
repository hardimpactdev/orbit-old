# Orbit SDK Laravel

## Overview

Standalone Laravel SDK package for integrating with Orbit infrastructure management instances. Talks to orbit-web via HTTP (REST API + Gateway MCP JSON-RPC). No dependency on orbit-core/app/cli.

## Architecture

```
Orbit (main service / facade target)
├── OrbitConnector → /api/* (REST, Saloon)
│   └── Resources: Status, Project, Service, Workspace, Worktree, Php, Config, Job
└── GatewayConnector → /mcp/gateway (JSON-RPC, Saloon)
    └── Resources: GatewayStatus, Vpn, Dns, Node, GatewayProject, Deployment, Cloudflare
```

## Key Files

| File | Purpose |
|------|---------|
| `src/Orbit.php` | Main entry point, holds both connectors, exposes all resource accessors |
| `src/OrbitConnector.php` | Saloon connector for REST API (`/api/*`) |
| `src/GatewayConnector.php` | Saloon connector for MCP JSON-RPC |
| `src/Requests/Gateway/McpRequest.php` | Base class for all MCP requests (JSON-RPC envelope) |
| `src/OrbitServiceProvider.php` | Registers `Orbit` singleton via spatie/laravel-package-tools |
| `config/orbit.php` | Config: `base_url`, `gateway_url`, `timeout`, `verify_ssl` |

## Namespace

`HardImpact\Orbit\` — intentionally without `Sdk` suffix for clean external usage.

## Conventions

- DTOs extend `Spatie\LaravelData\Data` with camelCase constructor properties
- Requests extend `Saloon\Http\Request` — no DTO creation in requests, resources handle that
- Gateway requests extend `McpRequest` (handles JSON-RPC wrapping/unwrapping)
- Gateway resources extend `GatewayResource` (provides `unwrap()` helper)
- Use `fn ($v) => $v !== null` for `array_filter` (not default callback)
- No dependency on any other orbit package

## Testing

```bash
composer test      # Pest tests with Saloon MockClient
composer analyse   # PHPStan level 5
composer format    # Laravel Pint
```
