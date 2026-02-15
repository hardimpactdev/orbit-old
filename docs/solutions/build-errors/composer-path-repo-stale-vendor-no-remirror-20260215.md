---
date: 2026-02-15
problem_type: build
component: Composer / Path Repositories
severity: moderate
symptoms:
  - "composer update shows 'Nothing to modify in lock file'"
  - "Changes to source package not reflected in vendor after update"
  - "Middleware exists in packages/app but not in vendor/hardimpactdev/orbit-app"
root_cause: "Composer path repositories with symlink:false don't re-mirror on update when version unchanged"
tags: [composer, path-repository, vendor, deployment, mirror]
---

# Composer Path Repository Doesn't Re-Mirror on Update

## Symptom

After updating source files in a path repository, `composer update` doesn't reflect changes in vendor:

```bash
# Update source files
scp McpAccessControl.php gateway:~/.config/orbit/web/packages/app/src/Http/Middleware/
scp mcp.php gateway:~/.config/orbit/web/packages/app/routes/

# Try to update vendor
cd ~/.config/orbit/web
composer update hardimpactdev/orbit-app --no-dev

# Output: "Nothing to modify in lock file"
# Vendor files unchanged!
```

**Impact**: Security fixes, bug fixes, or new features in source packages don't deploy to vendor.

## Investigation

### Attempted: composer update with cache clear
```bash
composer clear-cache
composer update hardimpactdev/orbit-app --with-dependencies --no-dev
```
**Result**: Still shows "Nothing to modify", no changes to vendor files.

### Attempted: composer update --prefer-dist
```bash
composer update hardimpactdev/orbit-app --prefer-dist --no-dev
```
**Result**: Same issue - vendor files not updated.

### Root Cause Found

Path repositories with `symlink: false` **mirror files on install**, but **NOT on update** when the version string is unchanged:

```json
// composer.json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/app",
      "options": {
        "symlink": false  // Files are MIRRORED, not symlinked
      }
    }
  ],
  "require": {
    "hardimpactdev/orbit-app": "0.1.99-dev"  // Version unchanged!
  }
}
```

**Why it fails:**
1. First install: Composer mirrors files from `packages/app` → `vendor/hardimpactdev/orbit-app`
2. Source files updated in `packages/app`
3. `composer update` checks version: `0.1.99-dev` (unchanged)
4. Composer: "Nothing to update, version is same"
5. **Mirroring skipped** - vendor files stay stale

## Solution

### Option 1: Remove and Reinstall (Immediate Fix)

```bash
# Force Composer to re-mirror by removing vendor directory
rm -rf vendor/hardimpactdev/orbit-app
composer install --no-dev

# Verify files updated
grep -A 3 'McpAccessControl' vendor/hardimpactdev/orbit-app/routes/mcp.php
```

**Why it works**: Fresh install triggers mirroring regardless of version.

### Option 2: Bump Version in Source Package

```json
// packages/app/composer.json
{
  "version": "0.1.99-dev"  // Change to "0.1.100-dev"
}
```

Then:
```bash
composer update hardimpactdev/orbit-app --no-dev
```

**Why it works**: Version change triggers update, which re-mirrors.

### Option 3: Use Symlinks (Development Only)

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/app",
      "options": {
        "symlink": true  // Changes instantly visible
      }
    }
  ]
}
```

**Why it works**: Symlinks point directly to source, no mirroring needed.

**Warning**: Don't use in production - symlinks break if source directory moves.

## Prevention

### When Using Path Repositories with symlink:false

**Update Workflow**:
1. Update source files in `packages/app`
2. Remove vendor package: `rm -rf vendor/hardimpactdev/orbit-app`
3. Reinstall: `composer install --no-dev`
4. Verify changes: Check vendor files match source

**Alternative**: Bump version in source `composer.json` before `composer update`

### Warning Signs

- `composer update` reports "Nothing to modify" after source changes
- Vendor files don't match source files
- Security fixes or features not deploying despite updating source
- Different behavior between local (symlinked) and remote (mirrored) environments

### Recommended Pattern

**For CI/CD and Production**: Always use versioned releases (Packagist), not path repos

**For Local Development**: Use `symlink: true` for instant updates

**For Gateway-Style Deployments**:
- Use path repos for easier updates
- Document the "remove + install" workflow
- Consider automating with deployment script

## Path Repository Behavior Matrix

| Scenario | symlink: true | symlink: false |
|----------|---------------|----------------|
| First install | Creates symlink | Mirrors files |
| Source file changes | ✅ Instant (points to source) | ❌ Not detected |
| composer update (same version) | N/A (symlink always current) | ❌ No re-mirror |
| composer update (new version) | N/A | ✅ Re-mirrors |
| Source dir moved/deleted | ❌ Breaks | ✅ Still works |
| Production safe | ❌ No | ✅ Yes |

## Related

- **Issue**: Gateway MCP security fix deployment
- **Context**: Gateway web app uses path repos, dev server uses Packagist
- **Solution**: Removed vendor + reinstall to force re-mirror
- **Documentation**: `docs/solutions/infrastructure/gateway-mcp-server-manual-patch-workaround-20260215.md`

## Files Involved

- Deployment target: `~/.config/orbit/web/vendor/hardimpactdev/orbit-app/`
- Source: `~/.config/orbit/web/packages/app/`
- Config: `~/.config/orbit/web/composer.json`

## Recommendation

**Short-term**: Use `rm -rf vendor/{package} && composer install` workflow

**Long-term**:
- CI/CD deployments should use Packagist versions
- Path repos only for rapid local iteration
- Consider deployment automation that handles vendor refresh

**For Orbit Gateway**:
- Install Composer on gateway (done in v0.1.111 deployment)
- Document vendor refresh workflow
- Consider automated web app deployment alongside CLI releases
