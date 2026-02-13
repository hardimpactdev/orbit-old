---
date: 2026-02-13
problem_type: runtime-error
component: packages/cli/app/Commands/UpgradeCommand.php
severity: moderate
symptoms:
  - "include(): zlib: data error in phar:///path/orbit/vendor/composer/ClassLoader.php"
  - "Class Illuminate\\Contracts\\Container\\BindingResolutionException not found"
  - Exit code 255 after successful upgrade output
root_cause: Deferred shell script replaces phar on disk while PHP process still running
tags: [phar, self-update, zlib, race-condition]
---

# Phar Self-Upgrade Causes zlib Crash During PHP Shutdown

## Symptom

`orbit upgrade` prints success but exits with code 255 and zlib errors:

```
Successfully upgraded to v0.1.100!
Restarting services...
✓ Services restarted
Upgrade complete!
PHP Fatal error: include(): zlib: data error in phar:///...orbit/vendor/composer/ClassLoader.php:576
```

The upgrade actually succeeds — the binary is replaced correctly. The error is cosmetic.

## Root Cause

The UpgradeCommand uses a deferred shell script to replace itself:

```bash
#!/bin/sh
sleep 0.2        # Too short!
mv /tmp/orbit_xx ~/.local/bin/orbit
rm -f ~/.local/bin/orbit.bak
```

The 0.2s sleep was too short. While the PHP process continued running (restarting Docker services, printing output), the script replaced the phar on disk. PHP's autoloader uses lazy decompression from the phar file — when it tried to load a class during shutdown, it read from the now-different file and got corrupted zlib data.

## Solution

1. Move all output and service restarts BEFORE launching the deferred script
2. Increase sleep from 0.2s to 1s
3. Use `exit(0)` immediately after launching the script to skip PHP's normal shutdown sequence

```php
// Do all work first
$this->dockerManager->stopAll();
$this->dockerManager->startAll();
$this->info("Successfully upgraded to {$latestVersion}!");

// Prepare JSON output before launching script
$jsonOutput = $this->wantsJson() ? json_encode([...]) : null;

// Launch deferred replacement (1s delay)
exec(sprintf('nohup %s > /dev/null 2>&1 &', $upgradeScript));

// Output JSON if needed, then exit immediately
if ($jsonOutput !== null) {
    fwrite(STDOUT, $jsonOutput."\n");
}
exit(0);  // Skip PHP shutdown to avoid autoloader reads
```

## Prevention

- When a phar replaces itself, ensure the PHP process exits completely before the replacement happens
- Use `exit(0)` instead of `return` to avoid shutdown hooks triggering class autoloading
- Keep the deferred script sleep generous (1s+)
- Do all class-loading work (service calls, output) before launching the replacement script
