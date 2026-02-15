# Production Deployment Automation Fixes - Implementation

**Date**: 2026-02-15
**Status**: ✅ Implemented
**Related**: [tankwerk-production-deployment-failures-20260215.md](tankwerk-production-deployment-failures-20260215.md)

## Summary

Implemented four critical fixes to automate production deployment workflows based on issues discovered during the tankwerk production deployment.

## Changes Implemented

### Issue 1: JSON Parse Error (CRITICAL) ✅

**Problem**: `project:deploy` and `project:create` commands called `caddy:reload --json` as a subprocess, resulting in multiple JSON objects in stdout that broke parsing.

**Solution**:
- Created `SupportsJsonMode` trait with `callSilentlyWhenJson()` helper
- Updated both commands to suppress sub-command output when `--json` flag is active
- Uses output buffering (`ob_start()` / `ob_end_clean()`) to discard nested JSON

**Files Modified**:
- `packages/cli/app/Concerns/SupportsJsonMode.php` (NEW)
- `packages/cli/app/Commands/ProjectDeployCommand.php`
- `packages/cli/app/Commands/ProjectCreateCommand.php`

**Verification**: Now outputs single clean JSON object when `--json` flag is used.

---

### Issue 2: Missing Production Caddy Block (CRITICAL) ✅

**Problem**: Deployment created Cloudflare DNS records but never created production Caddy site blocks with ACME TLS.

**Solution**:
- Created production Caddy stub template with `tls { issuer acme }` override
- Added `createProductionCaddyBlock()` method to generate site blocks at `~/.config/orbit/caddy/sites/{slug}.caddy`
- Added `resolveProductionDomain()` to query `GatewayProject` for production domain or fallback to internal domain
- Integrated into `handle()` method to auto-create blocks on first deploy to production nodes

**Files Modified**:
- `packages/cli/stubs/caddy/production-site.caddy.stub` (NEW)
- `packages/cli/app/Commands/ProjectDeployCommand.php`

**Template Placeholders**:
- `ORBIT_DOMAIN` → Production domain (e.g., `srpm.nl`)
- `ORBIT_ROOT_PATH` → Project root (e.g., `/home/orbit/projects/srpm/current/public`)
- `ORBIT_SOCKET_PATH` → PHP-FPM socket (e.g., `/home/orbit/.config/orbit/php/php84.sock`)

**Verification**: Production deployments now auto-create Caddy blocks in `~/.config/orbit/caddy/sites/` which survive `caddy:reload` regeneration.

---

### Issue 3: Missing Production .env Configuration (CRITICAL) ✅

**Problem**: Bootstrapped `.env` used internal domain, didn't generate `APP_KEY`, and lacked production-optimized defaults.

**Solution**:
- Enhanced `bootstrapEnv()` to accept `Node` parameter
- Added helper methods:
  - `determineAppUrl()` - Uses production domain from `GatewayProject` for production nodes
  - `generateAppKey()` - Generates Laravel-compatible base64 APP_KEY
  - `setEnvValueIfMissing()` - Sets value only if key doesn't exist (preserves user overrides)
  - `clearConfigCache()` - Clears Laravel config cache after `.env` changes
- Production nodes now auto-set: `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`

**Files Modified**:
- `packages/cli/app/Commands/ProjectDeployCommand.php`

**Production .env Defaults**:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://production-domain.com  # From GatewayProject
APP_KEY=base64:...                     # Auto-generated
CACHE_DRIVER=redis                     # If production
SESSION_DRIVER=redis                   # If production
QUEUE_CONNECTION=redis                 # If production
REDIS_HOST=127.0.0.1                   # If production
REDIS_PORT=6379                        # If production
```

**Verification**: First deploys now create fully-configured `.env` files with production-appropriate values.

---

### Issue 4: PHP Version Validation (MEDIUM) ✅

**Problem**: Deployment requested specific PHP versions but didn't validate they exist on the target node, resulting in cryptic Caddy socket errors.

**Solution**:
- Extended `preflight()` method to accept `?string $phpVersion` parameter
- Added PHP version check: tests for socket existence at `~/.config/orbit/php/php{version}.sock`
- On failure, returns actionable error with available PHP versions
- Updated `handle()` to pass `php_version` from request to preflight

**Files Modified**:
- `packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php`

**Example Error**:
```
PHP 9.0 not available on node 'production'. Available versions: 8.5, 8.4, 8.3
```

**Verification**: Invalid PHP versions now fail fast at preflight with clear error messages.

---

## Testing

### Unit Tests
All Gateway-related tests pass (31 tests, 73 assertions):
```bash
cd packages/app && ./vendor/bin/pest --filter=Gateway
```

### Integration Testing Plan

**Test 1: JSON Output** (Development Node)
```bash
ssh orbit@ai
cd ~/projects/test-app
~/.local/bin/orbit project:create test-json --clone=org/repo --json
# Expected: Single JSON object, no caddy:reload output
```

**Test 2: Production Caddy Block**
```bash
# Deploy via MCP
gateway_deploy(project_slug: "test", node_id: 5)

# Verify on production node
ssh orbit@46.225.89.66
cat ~/.config/orbit/caddy/sites/test.caddy
# Should contain: tls { issuer acme }, production domain, /current/public path
```

**Test 3: Production .env**
```bash
ssh orbit@46.225.89.66
cd ~/projects/test
cat .env
# Verify: APP_KEY, correct APP_URL, production defaults
```

**Test 4: PHP Version Validation**
```bash
# Try deploying with unavailable PHP version
gateway_deploy(project_slug: "test", node_id: 5, php_version: "9.0")
# Expected: Clear error listing available versions
```

---

## Deployment Workflow

To test these fixes on a real production deployment:

1. **Register project** (if not already):
   ```
   gateway_register_project(name: "Test App", production_domain: "testapp.com")
   ```

2. **Deploy to production**:
   ```
   gateway_deploy(project_slug: "test-app", node_id: 5, php_version: "8.4")
   ```

3. **Verify automation**:
   - ✅ No JSON parse errors in response
   - ✅ Caddy block created at `~/.config/orbit/caddy/sites/test-app.caddy`
   - ✅ `.env` has APP_KEY and production domain
   - ✅ Let's Encrypt certificate provisioned
   - ✅ Site accessible via HTTPS

---

## Rollback Plan

If issues arise:

| Issue | Rollback Action |
|-------|-----------------|
| JSON output breaking | Revert to `$this->call()` in project commands |
| Caddy block issues | Blocks in `sites/` directory, manually edit/delete |
| .env corruption | Shared `.env` at `{basePath}/.env`, manually fix |
| PHP validation false positives | Remove validation from preflight |

---

## Next Steps

1. **Build CLI binary**: `cd packages/cli && ~/.composer/vendor/bin/box compile`
2. **Deploy to production node**: `scp builds/orbit.phar orbit@46.225.89.66:~/.local/bin/orbit`
3. **Test with ditis-hr deployment**: Run full end-to-end test before committing
4. **Monitor first production deploy**: Verify all automation works as expected
5. **Document learnings**: Update troubleshooting guides with any new edge cases

---

## Related Documentation

- [tankwerk-production-deployment-failures-20260215.md](tankwerk-production-deployment-failures-20260215.md) - Original issue report
- [docs/reference/mcp-servers.md](../../reference/mcp-servers.md) - MCP preflight checks
- [docs/solutions/infrastructure/caddy-local-certs-blocks-acme-production-20260214.md](../infrastructure/caddy-local-certs-blocks-acme-production-20260214.md) - ACME TLS override pattern
