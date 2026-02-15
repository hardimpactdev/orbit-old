---
date: 2026-02-15
problem_type: infrastructure
component: PHP-FPM / Zero-Downtime Deployment
severity: critical
symptoms:
  - "New deployments have no effect"
  - "Users see stale content after deploy"
  - "Symlink updated but code unchanged"
root_cause: "PHP-FPM opcache caches resolved realpaths, symlink changes invisible"
tags: [php-fpm, opcache, symlinks, zero-downtime, deployment]
---

# PHP-FPM Opcache Serves Stale Code After Symlink Switch

## Symptom

In a zero-downtime deployment system using symlinks (e.g., `current → releases/20260215_120000`), new deployments appear to have no effect:

- Atomic symlink switch completes successfully
- `ls -la current` shows correct target: `current → releases/20260215_143000`
- But web requests still serve code from old release: `releases/20260215_120000`
- Manual PHP-FPM restart fixes the issue

**Observable impact**: Deploy appears to succeed but users see stale content until FPM is manually restarted.

## Investigation

### Attempted: Clear application cache
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```
**Result**: No effect. Opcache is at the PHP level, not Laravel level.

### Attempted: Wait for opcache TTL to expire
**Result**: Eventually updates, but could take minutes. Not acceptable for zero-downtime deploys.

### Root Cause Found
PHP-FPM's opcache extension caches the **resolved realpath** of files, not the symlink path. When you switch the symlink:

```bash
# Before deploy
current → releases/20260215_120000
# FPM opcache has: /home/user/projects/app/releases/20260215_120000/public/index.php

# After atomic switch
ln -sfn releases/20260215_143000 current
# Symlink now: current → releases/20260215_143000
# But FPM opcache still has: /home/user/projects/app/releases/20260215_120000/public/index.php ❌
```

PHP-FPM doesn't know the symlink changed. It continues serving from the cached realpath.

## Root Cause

PHP-FPM's opcache stores the resolved (canonical) file paths in memory. Symlinks are resolved once and then cached. When you atomically switch a symlink:

1. ✓ Symlink changes on disk
2. ✗ Opcache still points to old realpath
3. ✗ New requests served from old cached files

**From PHP manual**:
> OPcache improves PHP performance by storing precompiled script bytecode in shared memory, thereby removing the need for PHP to load and parse scripts on each request.

The cached bytecode includes the resolved file path. Symlink changes don't invalidate this cache.

## Solution

Send a **USR2** signal to PHP-FPM after switching the symlink. This gracefully reloads FPM workers without dropping connections:

```php
// packages/cli/app/Commands/ProjectDeployCommand.php

private function switchCurrent(string $basePath, string $releasePath, string $currentLink): void
{
    $releaseDir = basename($releasePath);

    // Atomic symlink switch
    $result = Process::path($basePath)
        ->run("ln -sfn releases/{$releaseDir} current");

    if (! $result->successful()) {
        throw new \RuntimeException('Failed to switch current symlink: ' . $result->errorOutput());
    }

    $this->logger->info("Switched current → releases/{$releaseDir}");

    // NEW: Reload PHP-FPM to clear opcache
    $this->reloadPhpFpm();
}

private function reloadPhpFpm(): void
{
    $this->logger->info('Reloading PHP-FPM to clear opcache...');

    $phpVersion = $this->option('php') ?? '8.4';
    $versionClean = str_replace('.', '', $phpVersion);

    // Try systemd reload (graceful, zero-downtime)
    $result = Process::run("sudo systemctl reload php{$versionClean}-fpm 2>&1 || true");

    // Fallback: direct signal to all php-fpm processes
    if (! $result->successful()) {
        Process::run("sudo pkill -USR2 php-fpm 2>&1 || true");
    }

    $this->logger->info('PHP-FPM reload signal sent');
}
```

### Why USR2 Instead of Restart?

| Signal | Effect | Downtime |
|--------|--------|----------|
| `SIGTERM` (stop) | Hard stop, drops connections | ✗ Yes |
| `systemctl restart` | Stop + start, drops connections | ✗ Yes |
| `SIGUSR2` (reload) | Graceful reload, new workers spawned | ✓ None |
| `systemctl reload` | Sends USR2 signal | ✓ None |

**USR2** tells PHP-FPM to:
1. Spawn new workers with fresh opcache
2. Mark old workers for retirement
3. Let old workers finish current requests
4. Kill old workers when idle

**Result**: Zero dropped connections, fresh opcache.

## Prevention

### Zero-Downtime Deployment Checklist

For any deployment system using symlinks with PHP-FPM:

- [ ] Atomic symlink switch with `ln -sfn`
- [ ] PHP-FPM reload immediately after switch
- [ ] Verify reload succeeded (check logs or process list)
- [ ] Test that new code is served (smoke test endpoint)

### Warning Signs

- Deployments "work" but changes don't appear
- Requires manual FPM restart after every deploy
- Opcache hit rate suspiciously high even after deploys
- Different users see different versions of the site

### Test Case

```php
/** @test */
public function it_reloads_php_fpm_after_symlink_switch(): void
{
    // Mock the symlink switch
    $this->artisan('project:deploy', [
        'name' => 'test',
        '--clone' => 'org/repo',
    ]);

    // Verify PHP-FPM reload was called
    Process::assertRan(function ($command) {
        return str_contains($command, 'systemctl reload php')
            || str_contains($command, 'pkill -USR2 php-fpm');
    });
}
```

### Verification After Deploy

```bash
# Check PHP-FPM received the reload signal
sudo journalctl -u php85-fpm --since "1 minute ago" | grep -i reload

# Expected output:
# Feb 15 20:32:15 server systemd[1]: Reloading PHP 8.5 FastCGI Process Manager
# Feb 15 20:32:15 server php-fpm[12345]: NOTICE: reloading: execvp("/usr/sbin/php-fpm85", ...)

# Or check process start times
ps aux | grep php-fpm | grep -v grep

# New workers should have recent start times
```

## Alternative Approaches

### Approach 1: Disable Opcache (❌ Not Recommended)
```ini
; php.ini
opcache.enable=0
```
**Why not**: Massive performance hit. Opcache provides 3-10x speedup.

### Approach 2: Restart PHP-FPM (❌ Causes Downtime)
```bash
systemctl restart php-fpm
```
**Why not**: Drops active connections. Not zero-downtime.

### Approach 3: Opcache File Validation (⚠️ Partial Fix)
```ini
; php.ini
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```
**Why not**: Checks file mtimes but still caches realpaths. Helps but doesn't solve symlink issue.

### Approach 4: Graceful Reload (✓ Recommended)
```bash
systemctl reload php-fpm  # or kill -USR2
```
**Why yes**: Zero downtime, fresh opcache, clean solution.

## Related

- **Deployment Flow**: Zero-downtime release-based pattern
- **Orbit ProjectDeployCommand**: Fixed in v0.1.110
- **PHP-FPM Documentation**: [Process Control](https://www.php.net/manual/en/install.fpm.configuration.php)
- **Related Issue**: [platform11-post-mortem-response-20260215.md](../deployment-issues/platform11-post-mortem-response-20260215.md)

## Files Modified

- `packages/cli/app/Commands/ProjectDeployCommand.php` - Added `reloadPhpFpm()` method

## Impact Timeline

- **Discovered**: 2026-02-15 during platform11.nl production deployment
- **Fixed**: v0.1.110 (same day)
- **Affected**: All zero-downtime deployments since release-based system launch

## Recommendation

**For any PHP-FPM deployment system using symlinks, always reload FPM after switching the symlink.** This is not optional - it's a fundamental requirement for zero-downtime deploys to work correctly.
