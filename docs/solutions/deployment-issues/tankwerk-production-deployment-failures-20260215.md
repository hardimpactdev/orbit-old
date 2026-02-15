---
date: 2026-02-15
problem_type: deployment-issue
component: gateway-deploy-pipeline
severity: critical
symptoms:
  - "gateway_deploy returns 'Failed to parse JSON: Syntax error' despite successful deployment"
  - "Production domain not configured in Caddy (no ACME block)"
  - "Missing .env configuration (empty APP_KEY, wrong APP_URL)"
  - "Cloudflare DNS tools exist but not exposed via MCP"
  - "PHP version mismatch not detected (requested 8.4, node has 8.5)"
root_cause: "Incomplete production deployment pipeline - missing critical post-deploy steps"
tags: [deployment, production, caddy, cloudflare, env-config, mcp-tools]
---

# Tankwerk Production Deployment Post-Mortem

## Summary

Deploying `tankwerk.test` to production under `tankwerk.nl` required 7 manual interventions that should have been automated by the gateway deploy pipeline.

## Issues (Ordered by Severity)

### Issue 3: gateway_deploy JSON Parse Error (CRITICAL)

**Symptom:**
Every deploy attempt returned:
```
{"success": false, "error": "Failed to parse JSON: Syntax error"}
```

**Investigation:**
- Deployment actually succeeded on the node (confirmed via `gateway_sync_node`)
- Gateway reported failure but deployment was active
- Suggests CLI output contains non-JSON content (warnings, banners, logs)

**Root Cause:**
`GatewayDeployTool` or `DeploymentService` expects pure JSON from CLI commands, but the remote command outputs mixed content that breaks the parser.

**Fix Required:**
Update `CommandService::executeRemoteCommand()` or `DeploymentService::deploy()` to:
1. Parse only the final JSON object from CLI output
2. Ignore/strip STDOUT warnings, banners, progress output before JSON
3. Use `--json --quiet` flags consistently for all CLI commands
4. Add error handling for unparseable responses with full output logging

**Related:**
- See `docs/solutions/integration-issues/cli-multiple-json-output-breaks-parser-20260215.md`
- The CLI must emit only a single JSON object when `--json` is used
- All services calling CLI must handle mixed output gracefully

---

### Issue 5: No Caddy Block for Production Domain (CRITICAL)

**Symptom:**
After deployment, only `tankwerk.test` Caddyfile block existed. No `tankwerk.nl` block, so production domain was unreachable.

**Root Cause:**
The deploy pipeline only creates a Caddy site block for `{slug}.{node.tld}` (internal dev domain), not for `GatewayProject->production_domain`.

**Manual Fix:**
```bash
ssh orbit@46.225.89.66
# Append to Caddyfile:
tankwerk.nl {
    root * /home/orbit/projects/tankwerk/public
    php_fastcgi unix//home/orbit/.config/orbit/php/php85.sock
    file_server
    encode gzip
    tls {
        issuer acme
    }
}
# Reload
orbit caddy:reload
```

**Fix Required:**
When deploying to production/staging nodes with a registered project:
1. Check if `$deployment->gatewayProject?->production_domain` exists
2. Create TWO Caddy blocks:
   - Internal: `{slug}.{node.tld}` (existing behavior)
   - Production: `{production_domain}` with `tls { issuer acme }`
3. Store both in `~/.config/orbit/caddy/sites/` so they survive regeneration
4. Auto-reload Caddy after creating blocks

**Implementation:**
Add to `DeploymentService::deployProject()` after successful deployment:
```php
if ($deployment->domain !== $deployment->gatewayProject?->production_domain) {
    app(CaddyService::class)->createProductionSite(
        $deployment->gatewayProject->production_domain,
        $deployment->path,
        $phpVersion
    );
}
```

---

### Issue 7: Missing Production .env Configuration (CRITICAL)

**Symptom:**
Deployed .env had:
- Empty `APP_KEY`
- Wrong `APP_URL=https://tankwerk.test`
- Default/local config values

**Manual Fix:**
```bash
ssh orbit@46.225.89.66
cd ~/projects/tankwerk
php artisan key:generate --force
# Edit .env:
APP_URL=https://tankwerk.nl
APP_ENV=production
APP_DEBUG=false
# Clear cache
php artisan config:clear
```

**Fix Required:**
Add post-deploy step to configure production .env:
1. Generate `APP_KEY` if empty: `php artisan key:generate --force`
2. Set `APP_URL` to production domain (not `{slug}.{node.tld}`)
3. Set `APP_ENV=production`, `APP_DEBUG=false`
4. Prompt for DB credentials or use node defaults
5. Clear config cache

**Implementation:**
Add to `packages/cli/app/Commands/ProjectDeployCommand.php` after migrations:
```php
protected function configureProductionEnv(): void
{
    // Generate key if missing
    if (empty(env('APP_KEY'))) {
        $this->call('key:generate', ['--force' => true]);
    }

    // Update .env values
    $this->updateEnvValue('APP_ENV', 'production');
    $this->updateEnvValue('APP_DEBUG', 'false');
    $this->updateEnvValue('APP_URL', "https://{$this->productionDomain}");

    // Clear cache
    $this->call('config:clear');
}
```

---

### Issue 8: Cloudflare DNS MCP Tools Not Registered (HIGH)

**Symptom:**
The following tools exist in codebase but were NOT available via MCP:
- `gateway_cloudflare_dns` (list records)
- `gateway_cloudflare_add_record`
- `gateway_cloudflare_remove_record`

Only `gateway_cloudflare_zones` and `gateway_cloudflare_status` were exposed.

**Manual Workaround:**
```bash
ssh gateway
php artisan tinker
$cf = app(\HardImpact\Orbit\Core\Services\CloudflareService::class);
$cf->updateRecord($recordId, 'tankwerk.nl', '46.225.89.66', proxied: false, zoneId: $zoneId);
```

**Root Cause:**
Tools exist but aren't registered in `packages/app/src/Mcp/GatewayServer.php`.

**Fix Required:**
Register missing tools in `GatewayServer.php`:
```php
protected function tools(): array
{
    return [
        // ... existing tools
        GatewayCloudflareAddRecordTool::class,
        GatewayCloudflareRemoveRecordTool::class,
        GatewayCloudflareDnsTool::class,  // List records
    ];
}
```

Verify each tool's `shouldRegister()` method returns `true` on gateway nodes.

---

### Issue 4: PHP Version Mismatch Not Detected (MEDIUM)

**Symptom:**
```
dial unix /home/orbit/.config/orbit/php/php84.sock: connect: no such file or directory
```

Deployment requested PHP 8.4 via `gateway_deploy(php_version: "8.4")`, but production node only has PHP 8.5 installed.

**Manual Fix:**
Changed all `php84.sock` references in Caddyfile to `php85.sock` and reloaded Caddy.

**Fix Required:**
Add pre-flight check to detect available PHP versions on target node:
1. SSH to node and check `~/.config/orbit/php/php{version}.sock` files
2. If requested version unavailable:
   - Option A: Fail with error listing available versions
   - Option B: Auto-select highest available version (warn user)
3. Update Caddy config to use correct socket

**Implementation:**
Add to `GatewayDeployTool::preflight()`:
```php
// 4. PHP version availability
if ($phpVersion) {
    $socket = "~/.config/orbit/php/php{$phpVersion}.sock";
    $check = app(SshService::class)->execute($node, "[ -S {$socket} ] && echo exists || echo missing");

    if (trim($check['output']) === 'missing') {
        // Get available versions
        $available = app(SshService::class)->execute($node, "ls ~/.config/orbit/php/php*.sock 2>/dev/null | grep -oP 'php\K[0-9]+' | sort -rn");

        return Response::structured([
            'success' => false,
            'error' => "PHP {$phpVersion} not available on node '{$node->name}'. Available: " . trim($available['output']),
        ]);
    }
}
```

---

### Issue 6: Cloudflare Proxy Infinite Redirect (FIXED)

**Symptom:**
`ERR_TOO_MANY_REDIRECTS` - Cloudflare proxy (orange cloud) terminates SSL, forwards HTTP to Caddy. Caddy redirects HTTP→HTTPS, creating loop.

**Manual Fix:**
Set Cloudflare record to `proxied: false` (grey cloud).

**Status:**
✅ **FIXED in v0.1.107** - DNS records now default to `proxied: false`.

**Related:**
- See `docs/solutions/infrastructure/cloudflare-flexible-ssl-infinite-redirect-20260215.md`
- `DeploymentService` and `GatewayDeployTool` both use `proxied: false` by default

---

### Issue 1: Composer Lock File Mismatch (PROJECT-LEVEL)

**Symptom:**
```
composer install failed — hardimpactdev/craft-laravel required ^0.2.2 but lock file had v0.2.1
```

**Fix:**
Committed updated `composer.lock` from local and pushed.

**Note:**
Not a platform issue, but error message was truncated making diagnosis harder. Deploy error logging could be improved to show full Composer output.

---

### Issue 2: Dev-Only Service Provider Crashes (PROJECT-LEVEL)

**Symptom:**
```
Class "NckRtl\Toolbar\Providers\ToolbarProvider" not found
```

During `php artisan migrate` - dev-only package registered unconditionally in `bootstrap/providers.php`.

**Fix:**
Conditional registration in `AppServiceProvider`:
```php
if (app()->environment('local') && class_exists(\NckRtl\Toolbar\Providers\ToolbarProvider::class)) {
    $this->app->register(\NckRtl\Toolbar\Providers\ToolbarProvider::class);
}
```

**Note:**
Project-level issue, but deploy pipeline could surface this error more clearly instead of just "migrate failed".

---

## Summary of Platform Fixes Required

| Priority | Fix | Files |
|----------|-----|-------|
| **HIGH** | Fix JSON parse error in gateway_deploy | `CommandService.php`, `DeploymentService.php` |
| **HIGH** | Create Caddy block for production domain | `DeploymentService.php`, new `CaddyService` |
| **HIGH** | Generate .env for production | `ProjectDeployCommand.php` |
| **HIGH** | Register Cloudflare DNS MCP tools | `GatewayServer.php` |
| **MEDIUM** | Validate PHP version availability | `GatewayDeployTool.php` preflight |
| **LOW** | Surface full error output for composer/migrate failures | `DeploymentService.php` logging |

## Prevention

1. **Test production deployments end-to-end** - Deploy to a staging node with production-like config
2. **Add integration tests** - Verify Caddy block creation, .env generation, DNS setup
3. **Improve error surfacing** - Log full CLI output, don't truncate errors
4. **Pre-flight validation** - Check PHP versions, DNS zones, Cloudflare access before deploy

## Related

- Cloudflare proxy issue: `docs/solutions/infrastructure/cloudflare-flexible-ssl-infinite-redirect-20260215.md`
- CLI JSON output: `docs/solutions/integration-issues/cli-multiple-json-output-breaks-parser-20260215.md`
- Pre-flight checks: `docs/solutions/integration-issues/deployment-preflight-validation-20260215.md`
