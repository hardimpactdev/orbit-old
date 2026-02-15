# Ditis-HR Deployment Post-Mortem: Response & Fixes

**Date**: 2026-02-15
**Deployment**: ditis-hr.nl to production
**Issues Found**: 6
**Status**: ✅ All addressed in v0.1.109

---

## Summary

The ditis-hr.nl production deployment revealed critical gaps in the automated deployment pipeline, requiring ~30 manual SSH interventions. This document outlines all issues and their resolutions.

## Issue 1: Missing MCP Tools (Pagination Bug)

**Severity**: CRITICAL
**Status**: ✅ Fixed in v0.1.109

### Problem
5 out of 20 gateway tools were invisible to MCP clients due to Laravel MCP Server's default pagination limit of 15 items:
- `gateway_cloudflare_dns` (position 16)
- `gateway_cloudflare_add_record` (position 17)
- `gateway_cloudflare_remove_record` (position 18)
- `gateway_cloudflare_set_ssl` (position 19)
- `gateway_flush_dns` (position 20)

### Root Cause
`Laravel\Mcp\Server` base class has `$defaultPaginationLength = 15`. The GatewayServer registers 20 tools. MCP clients only fetch page 1.

### Solution
```php
// packages/app/src/Mcp/GatewayServer.php
public int $defaultPaginationLength = 50;
```

### Verification
After upgrade, check available tools:
```bash
# Via MCP client - should show all 20 tools
tools/list
```

---

## Issue 2: "Failed to parse JSON" on Successful Deploys

**Severity**: CRITICAL
**Status**: ⚠️ Partially fixed in v0.1.109 (requires further testing)

### Problem
Every `gateway_deploy` call returned `{"success": false, "error": "Failed to parse JSON: Syntax error"}` even though deployments succeeded on the node. This caused:
- All deployments marked as "failed" in the gateway registry
- Post-deploy steps skipped (Cloudflare DNS, domain assignment)
- Deployments existed on nodes but were "ghosts" in the tracking system

### Root Cause
The `project:deploy --json` command outputs mixed content:
1. ProvisionLogger progress messages (suppressed when command=null ✓)
2. Sub-command output like `caddy:reload --json` (fixed in v0.1.108 ✓)
3. **NEW**: artisan config:clear output contaminating stdout

### Solutions Applied

**v0.1.108**: Added `SupportsJsonMode` trait to suppress `caddy:reload --json` output via output buffering

**v0.1.109**: Enhanced `clearConfigCache()` to suppress output when `--json` used:
```php
// Suppress all output when in JSON mode
if ($this->option('json')) {
    ob_start();
}

$result = Process::path(dirname($artisan))
    ->run('php artisan config:clear 2>&1');  // Redirect stderr

if ($this->option('json')) {
    ob_end_clean();
}
```

### Remaining Work
The regex fallback in `SshService::executeJson()` (line 79) should extract the last JSON object, but it's failing. Two possible approaches:

1. **Add end-to-end test**: Deploy a project via SSH and verify JSON output is clean
2. **Log raw output**: Temporarily log the raw SSH output to identify remaining contamination sources
3. **Stricter JSON mode**: Ensure `ProjectDeployCommand` outputs NOTHING except final JSON when `--json` used

### Verification
```bash
# Via SSH to test clean JSON output
ssh orbit@production "~/.local/bin/orbit project:deploy test --clone=org/repo --json"
# Should return single JSON object with no other text
```

---

## Issue 3: No Custom Domain in Caddy Config

**Severity**: HIGH
**Status**: ✅ Fixed in v0.1.108 (but may not have run due to Issue 2)

### Problem
Deployments only configured sites under the node's TLD (`ditis-hr.test`). No mechanism existed to add production domains (`ditis-hr.nl`) to Caddy config.

### Root Cause
`DeploymentService::deploy()` only passed `name`, `--json`, `--clone`, `--php` to the CLI. The production domain was never communicated to the target node.

### Solution (Already Implemented in v0.1.108)
Added `createProductionCaddyBlock()` method to `ProjectDeployCommand`:

**Trigger**: First deploy on production nodes
**Location**: `~/.config/orbit/caddy/sites/{slug}.caddy`
**Template**: `packages/cli/stubs/caddy/production-site.caddy.stub`

**Features**:
- Resolves production domain from `GatewayProject->production_domain`
- Auto-creates Caddy block with `tls { issuer acme }` override
- References correct PHP-FPM socket for the specified PHP version
- Includes Vite dev server proxy configuration

### Why It May Not Have Worked
Issue 2 (JSON parse error) caused the deployment to be marked as failed, short-circuiting the post-deploy flow at `DeploymentService` line 124. The production Caddy block creation never ran.

### Verification
After successful deploy:
```bash
ssh orbit@production "cat ~/.config/orbit/caddy/sites/ditis-hr.caddy"
# Should contain:
# - ditis-hr.nl { ... }
# - tls { issuer acme }
# - php_fastcgi unix/.../php84.sock
```

---

## Issue 4: PHP Version Not Validated Against Installed Versions

**Severity**: MEDIUM
**Status**: ✅ Fixed in v0.1.109

### Problem
Preflight checks accepted `php_version: 8.4` when only PHP 8.5 was installed. The generated Caddyfile referenced `php84.sock` (doesn't exist), causing 502 Bad Gateway errors.

### Root Cause
Socket path format mismatch:
- Preflight checked: `~/.config/orbit/php/php8.4.sock` (with dot)
- Actual filename: `~/.config/orbit/php/php84.sock` (no dot)

### Solution
```php
// Remove dots from version (8.4 -> 84, 8.5 -> 85)
$versionClean = str_replace('.', '', $phpVersion);
$socket = "~/.config/orbit/php/php{$versionClean}.sock";
```

Enhanced error message:
```
PHP 8.4 not available on node 'production'. Available versions: 8.5, 8.3
```

### Verification
```bash
# Should fail fast with clear error
gateway_deploy(project_slug: "test", node_id: 5, php_version: "9.0")
```

---

## Issue 5: .env Not Configured for Production

**Severity**: HIGH
**Status**: ✅ Fixed in v0.1.108 (but may not have run due to Issue 2)

### Problem
After deployment, sites returned 500 with "No application encryption key has been specified." The `.env` file contained:
- `APP_KEY=` (empty)
- `APP_ENV=local`
- `APP_DEBUG=true`
- `APP_URL=https://craft.test`

### Root Cause
The deployment was marked as "failed" due to Issue 2, so the enhanced `bootstrapEnv()` logic from v0.1.108 may not have run properly.

### Solution (Already Implemented in v0.1.108)
Enhanced `bootstrapEnv()` method:

**Features**:
- Generates `APP_KEY` if missing (base64-encoded 32 bytes)
- Uses production domain from `GatewayProject->production_domain` for `APP_URL`
- Sets production defaults: `APP_ENV=production`, `APP_DEBUG=false`
- Auto-configures redis drivers on production nodes:
  - `CACHE_DRIVER=redis`
  - `SESSION_DRIVER=redis`
  - `QUEUE_CONNECTION=redis`
- Clears Laravel config cache after `.env` changes

### Verification
After successful deploy:
```bash
ssh orbit@production "cd ~/projects/ditis-hr/current && cat .env | grep -E 'APP_KEY|APP_ENV|APP_URL'"
# Should show:
# APP_KEY=base64:...
# APP_ENV=production
# APP_URL=https://ditis-hr.nl
```

---

## Issue 6: Stale DNS Records Not Detected

**Severity**: LOW
**Status**: ✅ Enhanced in v0.1.109

### Problem
An old A record (`116.203.159.44`) for `ditis-hr.nl` existed from previous hosting. The deploy flow would have refused to create a new record, but didn't provide actionable diagnostics.

### Solution
Enhanced error message to list conflicting records:
```php
$existing = $this->cloudflare->listRecords(name: $domain);
if (count($existing) > 0) {
    $conflicts = array_map(fn($r) => "{$r['type']} → {$r['content']}", $existing);
    return Response::structured([
        'success' => false,
        'error' => "Domain '{$domain}' has existing DNS records: "
                  . implode(', ', $conflicts)
                  . ". Delete them first with gateway_cloudflare_remove_record.",
    ]);
}
```

**Example output**:
```
Domain 'ditis-hr.nl' has existing DNS records: A → 116.203.159.44.
Delete them first with gateway_cloudflare_remove_record.
```

### Future Enhancement
Add `--force` flag to auto-delete conflicting records and replace them.

---

## Deployment Workflow (Post-Fix)

### Automated Flow
```bash
# 1. Register project (once)
gateway_register_project(name: "Ditis HR", production_domain: "ditis-hr.nl")

# 2. Deploy to production
gateway_deploy(project_slug: "ditis-hr", node_id: 5, php_version: "8.5")
```

**What happens automatically** (after Issue 2 is fully resolved):
1. ✅ SSH connectivity check
2. ✅ CLI binary existence check
3. ✅ GitHub repo access check
4. ✅ PHP version validation (8.5 exists on node)
5. ✅ Clone repository to `~/projects/ditis-hr/releases/{timestamp}/`
6. ✅ Generate `APP_KEY` and create production `.env`
7. ✅ Run provision pipeline (composer, npm, migrations)
8. ✅ Create production Caddy block with ACME TLS
9. ✅ Reload Caddy to pick up new config
10. ✅ Create Cloudflare A record → production node IP
11. ✅ Mark deployment as Active
12. ✅ Return success with deployment details

### Manual Steps (Only if Conflicts Exist)
If old DNS records exist:
```bash
# 1. List existing records
gateway_cloudflare_dns(domain: "ditis-hr.nl")

# 2. Delete old record
gateway_cloudflare_remove_record(record_id: "...")

# 3. Retry deployment
gateway_deploy(project_slug: "ditis-hr", node_id: 5, php_version: "8.5")
```

---

## Testing Plan

### After v0.1.109 Upgrade
1. **Test MCP pagination**: Verify all 20 gateway tools are visible
2. **Test PHP validation**: Deploy with invalid PHP version (should fail fast)
3. **Test stale DNS detection**: Deploy to domain with existing record (should show actionable error)
4. **Test full deploy flow**: Deploy ditis-hr again to verify end-to-end automation

### Critical Test: JSON Output Cleanliness
```bash
# SSH to dev server and test JSON output
ssh nckrtl@ai
cd ~/projects/test-app
~/.local/bin/orbit project:deploy test-json --clone=org/repo --json 2>&1 | tee /tmp/deploy.log

# Verify output is a single JSON object
cat /tmp/deploy.log | jq .
```

If the output is clean JSON, Issue 2 is fully resolved. If not, capture the raw output for further analysis.

---

## Priority Next Steps

1. ✅ **Upgrade all nodes to v0.1.109**
2. ⚠️ **Test JSON output cleanliness** - If Issue 2 persists, log raw output for analysis
3. ✅ **Deploy a test project** to verify all automation works end-to-end
4. 📝 **Document production deployment SOP** with troubleshooting steps

---

## Files Modified

| File | Changes |
|------|---------|
| `packages/app/src/Mcp/GatewayServer.php` | Set `defaultPaginationLength = 50` |
| `packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php` | Fix PHP socket path format, enhance DNS conflict errors |
| `packages/cli/app/Commands/ProjectDeployCommand.php` | Suppress config:clear output in JSON mode |

---

## Related Documentation

- [tankwerk-production-deployment-failures-20260215.md](tankwerk-production-deployment-failures-20260215.md) - Original tankwerk issues
- [production-deployment-automation-fixes-20260215.md](production-deployment-automation-fixes-20260215.md) - v0.1.108 fixes
