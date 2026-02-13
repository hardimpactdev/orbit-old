---
date: 2026-02-13
problem_type: deployment
component: packages/app, gateway
severity: moderate
symptoms:
  - "502 Bad Gateway when accessing orbit.gateway"
  - "404 Not Found on POST /mcp/gateway"
  - "Type::required(): Argument #1 ($required) must be of type bool, array given"
root_cause: Multiple issues - socket permissions, route path registration, and schema API incompatibility
tags: [gateway, mcp, caddy, php-fpm, deployment]
---

# Gateway MCP Deployment - Three Issues

## Issue 1: Caddy 502 Bad Gateway (PHP-FPM socket)

### Symptom
```
HTTP/1.1 502 Bad Gateway
```
Caddy returns 502 when proxying to PHP-FPM socket.

### Root Cause
Caddy runs as user `caddy` (uid=999). PHP-FPM socket at `/home/gateway/.config/orbit/php/php85.sock` has permissions `srw-rw---- gateway:gateway` (0660). Caddy user is not in the `gateway` group.

### Solution
```bash
sudo usermod -aG gateway caddy
sudo systemctl restart caddy
```

### Prevention
When deploying orbit-web to a new node, always ensure the Caddy user is in the PHP-FPM socket owner's group. Check with:
```bash
stat /home/gateway/.config/orbit/php/php85.sock  # socket owner
id caddy                                          # caddy groups
```

---

## Issue 2: MCP Routes 404

### Symptom
```
POST /mcp/gateway → 404 Not Found
```

### Investigation
1. `php artisan route:list --path=mcp` returned empty
2. `php artisan route:list --path=gateway` showed routes at `/gateway` not `/mcp/gateway`

### Root Cause
Two problems:
1. `Mcp::web('gateway', ...)` registers at path `gateway`, not `mcp/gateway`. The first argument is the literal route path.
2. The `laravel/mcp` package autoloads `routes/ai.php` (not custom files). The orbit-app service provider's `loadRoutesFrom()` also works, but routes were double-registered.

### Solution
```php
// routes/mcp.php - use full path prefix
Mcp::web('mcp/orbit', OrbitServer::class);
Mcp::web('mcp/gateway', GatewayServer::class);
```

Also create `routes/ai.php` symlink for the MCP package's autoloader:
```bash
ln -s packages/app/routes/mcp.php routes/ai.php
```

---

## Issue 3: Tool Schema API Incompatibility

### Symptom
```
Type::required(): Argument #1 ($required) must be of type bool, array given
```

### Root Cause
Gateway tools used the old `laravel/mcp` schema API:
```php
// OLD (broken in laravel/mcp v0.5.6+)
return $schema->object([
    'name' => $schema->string('Description here'),
])->required(['name'])->toArray();
```

The current API:
- `$schema->string()` takes no arguments (use `->description()` chain)
- `->required()` is per-property (takes `bool`), not on object (no longer takes `array`)
- `schema()` returns a flat array, not `$schema->object()->toArray()`

### Solution
```php
// NEW (correct)
return [
    'name' => $schema->string()->required()->description('Description here'),
    'tld' => $schema->string()->description('Optional field'),
];
```

### Prevention
Follow the pattern used by existing working tools (e.g. `ProjectCreateTool.php`). The `schema()` method returns a flat associative array of Type objects.

## Related
- `docs/solutions/infrastructure/gateway-cli-deploy-workflow-20260207.md`
- `docs/solutions/infrastructure/gateway-upgrade-complete-20260209.md`
