---
date: 2026-02-16
problem_type: logic-error
component: RemoteDeployContext, DeploymentService
severity: critical
symptoms:
  - "502 Bad Gateway after deployment"
  - "Caddy tries to connect to php84.sock but server only has php85.sock"
root_cause: Hardcoded PHP version fallback (8.4) didn't match actual server PHP version (8.5)
tags: [php-fpm, deployment, socket, auto-detection]
---

# Never Hardcode PHP Version Fallbacks in Deployment Code

## Symptom

After deploying to production, the site returns 502 Bad Gateway. Caddy logs show it's trying to connect to `php84.sock` but the server only has `php85.sock`.

## Root Cause

`RemoteDeployContext::phpVersionClean()` had a hardcoded fallback:

```php
// Before (dangerous)
public function phpVersionClean(): string
{
    return str_replace('.', '', $this->phpVersion ?? '8.4');  // ← silent wrong default
}
```

When `phpVersion` was null (auto-detection not yet run), it silently used `8.4` — but the production server had PHP 8.5 installed.

## Solution

### 1. Throw explicit error instead of fallback

```php
// After (safe)
public function phpVersionClean(): string
{
    if (! $this->phpVersion) {
        throw new \RuntimeException(
            'PHP version not set — auto-detection may have failed. Specify php_version explicitly.'
        );
    }

    return str_replace('.', '', $this->phpVersion);
}
```

### 2. Auto-detect before constructing context

```php
// DeploymentService::deployRemote()
if (! $phpVersion) {
    $phpVersion = $this->orchestrator->detectPhpVersion($target);
    Log::info("Auto-detected PHP {$phpVersion} on node {$target->name}");
}

$ctx = new RemoteDeployContext(
    node: $target,
    // ... other params ...
    phpVersion: $phpVersion,  // ← always set
);
```

### 3. Detection scans actual sockets

```php
// RemoteDeploymentOrchestrator::detectPhpVersion()
$result = $this->ssh->execute($node, 'ls ~/.config/orbit/php/php*.sock 2>/dev/null');
// Regex: /php(\d)(\d)\.sock/ → extracts versions, returns highest
```

## Prevention

- **Never use `??` with a hardcoded version** for PHP socket paths — it silently selects the wrong version
- **Always auto-detect** from the server's actual PHP-FPM sockets
- **Throw exceptions** when required values are missing instead of guessing
- Pattern: `$value ?? throw new RuntimeException('...')` is safer than `$value ?? 'default'`

## Related

- `docs/solutions/infrastructure/php-socket-path-format-convention-20260215.md`
- `docs/solutions/infrastructure/gateway-centric-remote-deploy-architecture-20260216.md`
