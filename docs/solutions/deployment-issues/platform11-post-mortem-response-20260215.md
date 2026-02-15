# Platform11 Post-Mortem: Response & Fixes

**Date**: 2026-02-15
**Deployment**: platform11.nl to Hetzner Production
**Issues Found**: 9
**Status**: ✅ 6 addressed in v0.1.110, 3 need attention

---

## Summary

The platform11.nl deployment revealed critical gaps in the zero-downtime release-based deployment system, PHP-FPM cache management, and package manager detection. Despite previous fixes (v0.1.108/v0.1.109), several issues persisted or were newly discovered.

---

## Issue 1: No `.env` File Created on First Deploy

**Severity**: CRITICAL (P0)
**Status**: ✅ Already Fixed in v0.1.108

### Problem
No `.env` file generated on first deploy. Site returned 500 with "No application encryption key has been specified."

### Root Cause
Was not creating `.env` from `.env.example` or generating `APP_KEY`.

### Solution (v0.1.108)
Enhanced `bootstrapEnv()` method in `ProjectDeployCommand`:
- Copies `.env.example` to shared `.env` location
- Generates `APP_KEY` if missing
- Sets production defaults (`APP_ENV=production`, `APP_DEBUG=false`)
- Uses production domain from `GatewayProject`
- Configures redis drivers on production nodes

### Why It Still Failed
JSON parsing error (Issue 2 from ditis-hr) marked deployment as failed, short-circuiting the bootstrap flow.

---

## Issue 2: PHP-FPM Not Reloaded After Deployment

**Severity**: CRITICAL (P0)
**Status**: ✅ Fixed in v0.1.110

### Problem
After deploying a new release, PHP-FPM continued serving old cached opcodes. The `current` symlink pointed to the new release, but PHP-FPM resolved the old realpath.

### Root Cause
PHP-FPM's opcache caches the resolved realpath of files. Symlink changes are invisible without a reload signal.

### Solution
```php
// packages/cli/app/Commands/ProjectDeployCommand.php

private function switchCurrent(...): void
{
    // Atomic symlink switch
    $result = Process::path($basePath)
        ->run("ln -sfn releases/{$releaseDir} current");

    // NEW: Reload PHP-FPM to clear opcache
    $this->reloadPhpFpm();
}

private function reloadPhpFpm(): void
{
    $phpVersion = $this->option('php') ?? '8.4';
    $versionClean = str_replace('.', '', $phpVersion);

    // Try systemd reload first
    $result = Process::run("sudo systemctl reload php{$versionClean}-fpm 2>&1 || true");

    // Fallback to direct signal
    if (! $result->successful()) {
        Process::run("sudo pkill -USR2 php-fpm 2>&1 || true");
    }
}
```

### Verification
After deploy:
```bash
# Check PHP-FPM received reload signal
sudo journalctl -u php85-fpm --since "1 minute ago" | grep reload
```

---

## Issue 3: Caddy Only Generates `.test` Blocks, Not Production Domains

**Severity**: HIGH (P1)
**Status**: ✅ Already Fixed in v0.1.108

### Problem
Only `platform11.test` block existed. No `platform11.nl` block. SSL connections failed with `ERR_SSL_PROTOCOL_ERROR`.

### Root Cause
`caddy:reload` regenerates from database, which only knows about `.test` domains. Production blocks must be in `~/.config/orbit/caddy/sites/`.

### Solution (v0.1.108)
Auto-creates production Caddy blocks in `ProjectDeployCommand`:

**Trigger**: First deploy on production nodes
**Location**: `~/.config/orbit/caddy/sites/{slug}.caddy`
**Template**: `packages/cli/stubs/caddy/production-site.caddy.stub`

```php
if ($isFirstDeploy && $node->isProduction() && $hasPublicFolder) {
    $this->createProductionCaddyBlock(
        basePath: $basePath,
        slug: $slug,
        domain: $this->resolveProductionDomain($slug, $tld),
        phpVersion: $this->option('php') ?? '8.4',
        config: $config
    );
}
```

### Why It Still Failed
Same as Issue 1 - JSON parsing error short-circuited the flow.

---

## Issue 4: npm Detected Instead of bun

**Severity**: HIGH (P1)
**Status**: ✅ Fixed in v0.1.110

### Problem
Deploy detected `package-lock.json` and tried to use npm (not installed). Failed with `env: 'npm': No such file or directory`.

### Root Cause
Package manager detection was purely lockfile-based with no binary availability check:
```php
// Before (broken)
$packageManager = match (true) {
    file_exists("bun.lock") => 'bun',
    file_exists("package-lock.json") => 'npm',  // Assumes npm exists!
    default => 'npm',
};
```

### Solution
```php
// packages/core/src/Services/Provision/Actions/DetectNodePackageManager.php

private function ensurePackageManagerAvailable(string $preferred, ...): ?string
{
    // Check if preferred manager is available
    if ($this->isCommandAvailable($preferred)) {
        return $preferred;
    }

    // Fallback order: bun → npm → yarn → pnpm
    foreach ($fallbacks as $fallback) {
        if ($this->isCommandAvailable($fallback)) {
            $logger->warn("{$preferred} not found, falling back to {$fallback}");
            return $fallback;
        }
    }

    return null;
}

private function isCommandAvailable(string $command): bool
{
    $result = shell_exec("command -v {$command} 2>/dev/null");
    return ! empty(trim($result ?? ''));
}
```

**Fallback chains**:
- npm detected → tries bun, yarn, pnpm
- bun detected → tries npm, yarn, pnpm

### Verification
Deploy with `package-lock.json` on node without npm should use bun with warning logged.

---

## Issue 5: Cloudflare CNAME Conflict Blocked A Record

**Severity**: MEDIUM (P2)
**Status**: ⚠️ Enhanced in v0.1.109, Could Be Better

### Problem
Adding A record for `platform11.nl` failed silently. Existing CNAME record (to `to.laravel.cloud`) conflicted.

### Root Cause
Cloudflare API returns error but `gateway_cloudflare_add_record` didn't surface it.

### Current Solution (v0.1.109)
Enhanced error message in `GatewayDeployTool`:
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

### Remaining Work
The `gateway_cloudflare_add_record` tool should return actual Cloudflare API errors, not just "Failed to create DNS record".

**Recommended Enhancement**:
```php
// In CloudflareService::createRecord()
catch (\Exception $e) {
    return [
        'success' => false,
        'error' => $e->getMessage(),  // Actual Cloudflare error
        'type' => 'cloudflare_api_error',
    ];
}
```

---

## Issue 6: `database` Symlink Overwritten Migrations

**Severity**: CRITICAL (P0)
**Status**: ✅ Fixed in v0.1.110

### Problem
Shared `database/` symlink overwrote the release's `database` directory, removing migrations/factories/seeders. `php artisan migrate` reported "nothing to migrate".

### Root Cause
```php
// Before (broken) - symlinks entire directory
$links = [
    '.env' => '../../.env',
    'storage' => '../../storage',
    'database' => '../../database',  // Overwrites migrations!
];
```

### Solution
```php
// After (fixed) - only symlink the SQLite file
private function createReleaseSymlinks(...): void
{
    // Symlink .env and storage (full directories)
    $links = [
        '.env' => '../../.env',
        'storage' => '../../storage',
    ];

    foreach ($links as $name => $target) {
        // ... symlink logic
    }

    // For database, only symlink the SQLite file
    $this->ensureDatabaseStructure($basePath, $releasePath);
}

private function ensureDatabaseStructure(...): void
{
    $sharedDbDir = "{$basePath}/database";
    if (! is_dir($sharedDbDir)) {
        mkdir($sharedDbDir, 0755, true);
    }

    // Only symlink database.sqlite, not the directory
    $sqliteFile = "{$releasePath}/database/database.sqlite";
    $sharedSqlite = "{$basePath}/database/database.sqlite";

    if (! file_exists($sharedSqlite)) {
        touch($sharedSqlite);
    }

    if (! is_link($sqliteFile)) {
        symlink('../../database/database.sqlite', $sqliteFile);
    }
}
```

**Result**: Migrations/factories/seeders come from the release. Only the SQLite file is shared across releases.

---

## Issue 7: Redis Not Accessible on Production Node

**Severity**: MEDIUM (P2)
**Status**: ⚠️ Needs Documentation

### Problem
`.env` configured with redis drivers, but Redis container wasn't published to host (`127.0.0.1:6379`). Every request failed with `Connection refused`.

### Root Cause
Redis container exposes port 6379 internally but doesn't publish it to host network.

### Current Workaround
Use `database` driver for session/cache/queue.

### Recommended Solution
**Option 1**: Publish Redis port in Docker Compose
```yaml
# ~/.config/orbit/redis/docker-compose.yml
services:
  redis:
    ports:
      - "127.0.0.1:6379:6379"  # Bind to localhost
```

**Option 2**: Document per-node capabilities
Create `~/.config/orbit/node-capabilities.json`:
```json
{
  "redis": false,
  "postgres": true,
  "mysql": false
}
```

Then `.env` generation checks capabilities and sets appropriate drivers.

---

## Issue 8: Gateway Projects Table Missing on Production Node

**Severity**: LOW (P3)
**Status**: ⚠️ Needs Migration Strategy

### Problem
`gateway_deploy` with `project_slug` failed with `no such table: gateway_projects`.

### Root Cause
Production node's Orbit database hadn't been migrated to include the `gateway_projects` table.

### Recommended Solution
Add automatic migration check to deployment preflight:

```php
// In GatewayDeployTool::preflight()
$hasMigration = app(SshService::class)->execute(
    $node,
    "~/.local/bin/orbit db:check-migration gateway_projects"
);

if (! $hasMigration['success']) {
    return Response::structured([
        'success' => false,
        'error' => "Node '{$node->name}' database is outdated. Run: ssh {$node->user}@{$node->host} '~/.local/bin/orbit migrate'",
    ]);
}
```

---

## Issue 9: Code on Worktree Branch, Not Main

**Severity**: LOW (P3)
**Status**: ⚠️ User Error / Workflow Issue

### Problem
Initial deploy pulled empty `main` branch. Actual code was on worktree branch `vk/7b28-scaffold-new-lar`.

### Root Cause
Project scaffolded via AI tool on separate branch, never merged to main.

### Prevention
This is a workflow issue, not a system bug. Best practices:
1. Always verify `main` branch has code before deploying
2. Use `git push --set-upstream origin main` to ensure main is up-to-date
3. Consider adding a preflight check that verifies the repo isn't empty

---

## Files Modified

| File | Changes |
|------|---------|
| `packages/cli/app/Commands/ProjectDeployCommand.php` | PHP-FPM reload + database symlink fix |
| `packages/core/src/Services/Provision/Actions/DetectNodePackageManager.php` | Binary availability check with fallback |

---

## Testing Plan

### After v0.1.110 Upgrade

**Test 1: PHP-FPM Reload**
```bash
# Deploy a change, verify new code is served immediately
ssh production "cd ~/projects/test && echo '<?php echo \"v2\";' > current/public/test.php"
curl https://test.com/test.php  # Should show v2 without manual FPM reload
```

**Test 2: Database Migrations**
```bash
# After deploy, verify migrations ran
ssh production "cd ~/projects/test/current && php artisan migrate:status"
# Should show all migrations run, not "nothing to migrate"
```

**Test 3: npm Fallback**
```bash
# Deploy project with package-lock.json on node without npm
# Should use bun with warning logged
```

---

## Remaining Action Items

| Priority | Issue | Action |
|----------|-------|--------|
| P2 | Cloudflare API errors | Return actual Cloudflare error messages in MCP tools |
| P2 | Redis accessibility | Publish Redis port or document node capabilities |
| P3 | Node DB migrations | Add migration check to deployment preflight |
| P3 | Empty repo detection | Add preflight check for non-empty repos |

---

## Release Timeline

- **v0.1.108**: `.env` generation, production Caddy blocks, APP_KEY
- **v0.1.109**: MCP pagination, PHP socket format, DNS conflict errors, JSON cleanup
- **v0.1.110**: PHP-FPM reload, database symlink fix, npm/bun fallback ✅

---

## Related Documentation

- [ditis-hr-deployment-post-mortem-response-20260215.md](ditis-hr-deployment-post-mortem-response-20260215.md)
- [tankwerk-production-deployment-failures-20260215.md](tankwerk-production-deployment-failures-20260215.md)
