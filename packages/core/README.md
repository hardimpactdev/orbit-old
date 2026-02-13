# Orbit Core

A Laravel package providing shared business logic for the Orbit ecosystem.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hardimpactdev/orbit-core.svg?style=flat-square)](https://packagist.org/packages/hardimpactdev/orbit-core)

## Overview

Orbit Core is the shared foundation for orbit-cli, orbit-app, and their deployable shells (orbit-web, orbit-desktop). It contains:

- **Models**: Node, Gateway, Project, Site, Setting, SshKey, TrackedJob, etc.
- **Gateway Services**: GatewayManager, WgEasyService, GatewayDnsService
- **CLI Wrapper Services**: StatusService, ProjectCliService, ConfigurationService, etc.
- **Pipelines**: ProvisionPipeline, DeletionPipeline (site creation/deletion)
- **Jobs**: CreateSiteJob, DeleteSiteJob
- **Data Objects**: ProvisionContext, DeletionContext, StepResult
- **Events**: SiteProvisioningStatus, SiteDeletionStatus
- **Migrations**: All database schema (nodes, gateways, sites, projects, etc.)

This package contains **no UI components** — controllers, routes, views, and MCP servers live in orbit-app.

## Installation

```bash
composer require hardimpactdev/orbit-core
```

## Namespace

All classes use `HardImpact\Orbit\Core` namespace:

```
HardImpact\Orbit\Core\
  Models\              # Eloquent models (Node, Gateway, Site, etc.)
  Services\
    Gateway\           # VPN/DNS gateway services
    Provision\         # Site provisioning pipeline
    Deletion\          # Site deletion pipeline
    OrbitCli\          # CLI interaction wrappers
  Contracts\           # Interfaces (ProvisionLoggerContract)
  Data\                # DTOs (ProvisionContext, StepResult, etc.)
  Enums\               # NodeType, RepoIntent
  Events\              # Broadcasting events
  Jobs\                # Queueable jobs
```

## Gateway Services

Gateway business logic lives in `src/Services/Gateway/`:

| Service | Purpose | Constructor |
|---------|---------|-------------|
| `GatewayManager` | CRUD gateways, VPN client registration | No dependencies |
| `WgEasyService` | WireGuard VPN API client | `string $host, int $port, string $password` |
| `GatewayDnsService` | TLD-to-IP DNS mappings via dnsmasq | `string $configPath` |

These are consumed by both orbit-cli (via GatewayCliAdapter) and orbit-app (via MCP tools).

## Related Packages

| Package | Purpose |
|---------|---------|
| orbit-app | Web UI, MCP servers, controllers (requires this) |
| orbit-cli | Laravel Zero CLI tool (requires this) |
| orbit-web | Deployable Laravel shell |
| orbit-desktop | NativePHP desktop shell |

## License

MIT
