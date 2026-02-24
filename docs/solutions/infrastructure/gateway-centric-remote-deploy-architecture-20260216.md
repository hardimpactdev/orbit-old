---
date: 2026-02-16
problem_type: architecture
component: DeploymentService, RemoteDeploymentOrchestrator
severity: critical
symptoms:
  - "Orbit CLI not found on node 'production'. Install it first."
  - "Production servers require CLI binary for deployment"
root_cause: DeploymentService routed all deployments through CLI, requiring orbit binary on every server
tags: [deployment, ssh, remote-deploy, architecture, production]
---

# Gateway-Centric Remote Deployment (No CLI on Production)

## Symptom

Production deployments required the orbit CLI binary to be installed on every production/staging server. This created an unnecessary dependency — all deployment steps are shell commands the gateway can run directly via SSH.

## Root Cause

`DeploymentService::deploy()` always delegated to `CommandService::executeCommand()` which runs orbit CLI commands on the target node. This required the CLI binary to exist on every server, even though the CLI just runs shell commands (git clone, composer install, artisan migrate, etc.).

## Solution

### Architecture Change

```
Before: Claude Code → gateway MCP → SSH → orbit CLI (on production) → shell commands
After:  Claude Code → gateway MCP → DeploymentService → SSH raw commands → production
```

### New Services (packages/core/src/Services/RemoteDeploy/)

| File | Purpose |
|------|---------|
| `RemoteDeployContext.php` | Readonly DTO: node, slug, paths, timestamp, PHP version |
| `RemoteDeploymentOrchestrator.php` | Sequences ~15 SSH commands with error handling and rollback |
| `RemoteEnvManager.php` | `.env` bootstrap: read `.env.example`, apply production defaults, write via base64 |
| `RemoteCaddyManager.php` | Caddy site block: generate, write to `sites/{slug}.caddy`, reload |

### Routing Logic

`DeploymentService::deploy()` routes based on node environment:

```php
$useRemoteDeploy = $target->isProduction() || $target->isStaging();

if ($useRemoteDeploy) {
    $result = $this->deployRemote($target, $deployment, $slug, $repo, $phpVersion, $project);
} else {
    $result = $this->deployViaCli($target, $name, $repo, $template, $phpVersion);
}
```

### PHP Version Auto-Detection

Before constructing `RemoteDeployContext`, the orchestrator scans the remote server for PHP-FPM sockets:

```php
// RemoteDeploymentOrchestrator::detectPhpVersion()
$result = $this->ssh->execute($node, 'ls ~/.config/orbit/php/php*.sock 2>/dev/null');
// Regex extracts versions, returns highest (e.g., "8.5")
```

This prevents socket mismatch — no more hardcoded fallback versions.

### Preflight Check Changes

`GatewayDeployTool::preflight()` now checks differently per environment:

| Environment | Checks |
|-------------|--------|
| Production/Staging | `gh`, `php`, `composer` available via `command -v` |
| Development | CLI binary via `CommandService::findBinary()` |

### Base64 File Transfer

`.env` files contain `$`, `"`, `=` that break shell escaping. `SshService::writeFile()` uses base64:

```php
public function writeFile(Node $node, string $path, string $content): array
{
    $encoded = base64_encode($content);
    return $this->execute($node, "echo {$encoded} | base64 -d > " . escapeshellarg($path), 30);
}
```

## Prevention

- Production/staging servers only need: PHP-FPM, Caddy, Composer, Git (`gh` CLI), Node.js/Bun
- Never install orbit CLI on production — it's a development tool
- Always auto-detect PHP version from the server, never hardcode a fallback
- `RemoteDeployContext::phpVersionClean()` throws `RuntimeException` if PHP version is null

## Key Files

| File | Role |
|------|------|
| `packages/core/src/Services/DeploymentService.php` | Routes to CLI or orchestrator |
| `packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php` | SSH command sequencing |
| `packages/core/src/Services/RemoteDeploy/RemoteDeployContext.php` | Deployment parameters DTO |
| `packages/core/src/Services/RemoteDeploy/RemoteEnvManager.php` | .env bootstrap |
| `packages/core/src/Services/RemoteDeploy/RemoteCaddyManager.php` | Caddy block management |
| `packages/core/src/Services/SshService.php` | Extended with writeFile/readFile/fileExists/directoryExists |
| `packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php` | Updated preflight checks |

## Related

- `docs/solutions/infrastructure/caddy-reload-wipes-custom-sites-20260215.md` (sites/*.caddy pattern)
- `docs/solutions/infrastructure/php-socket-path-format-convention-20260215.md` (socket naming)
