---
date: 2026-02-14
problem_type: security, bug
component: CloudflareService, DeleteProjectFiles
severity: moderate
symptoms:
  - "CloudflareService HTTP requests go to /zones//dns_records (empty zone ID)"
  - "CloudflareService tests pass for isConfigured but fail for API calls"
  - "DeleteProjectFiles rejects paths under /tmp on macOS"
root_cause: Lazy-loaded credentials not resolved before path string interpolation; macOS /tmp symlink resolved differently by realpath()
tags: [lazy-loading, realpath, macOS, symlinks, path-traversal]
---

# Lazy Credential Loading + Path Interpolation Bug

## Symptom 1: CloudflareService empty zone ID

After converting CloudflareService from constructor-loaded credentials to lazy-loading,
API calls produced URLs like `https://api.cloudflare.com/client/v4/zones//dns_records`
(note the empty zone ID). `isConfigured()` worked fine.

```php
// loadCredentials() called inside request(), but $this->zoneId interpolated BEFORE request() is called
public function createRecord(string $name, string $content): ?array
{
    // BUG: $this->zoneId is still null here because loadCredentials() hasn't been called yet
    $response = $this->request('POST', "/zones/{$this->zoneId}/dns_records", [...]);
}
```

## Root Cause

PHP evaluates string interpolation at the call site, not inside the called method.
When `$this->zoneId` is used in the path string that's passed to `request()`, it's
resolved to `null` before `request()` can call `loadCredentials()`.

## Solution

Create a `zonePath()` helper that ensures credentials are loaded before building the path:

```php
protected function zonePath(string $suffix = ''): string
{
    $this->loadCredentials();
    return "/zones/{$this->zoneId}{$suffix}";
}

public function createRecord(string $name, string $content): ?array
{
    $response = $this->request('POST', $this->zonePath('/dns_records'), [...]);
}
```

## Symptom 2: macOS realpath() resolves symlinks differently

Path traversal protection using `realpath()` + `str_starts_with()` failed in tests because:
- Test creates dir under `sys_get_temp_dir()` which returns `/tmp/`
- `realpath('/tmp/delete-test')` returns `/private/tmp/delete-test` (macOS symlink)
- Allowed base set to `/tmp/` doesn't match `/private/tmp/`

## Solution

Also `realpath()` the allowed base path:

```php
// Before (broken on macOS)
$allowedBase = config('orbit.projects_path', $home.'/projects');
if (! str_starts_with($realPath, rtrim($allowedBase, '/').'/')) {

// After (works everywhere)
$configuredBase = config('orbit.projects_path', $home.'/projects');
$allowedBase = realpath($configuredBase) ?: $configuredBase;
if (! str_starts_with($realPath, rtrim($allowedBase, '/').'/')) {
```

## Prevention

1. **Lazy-loading + string interpolation**: When lazy-loading instance properties, never
   use them in string interpolation at call sites. Either call the loader before interpolation,
   or use a helper that loads then interpolates.
2. **realpath() comparisons**: When comparing `realpath()` results, ensure BOTH sides are
   resolved through `realpath()` to handle OS-level symlinks (macOS `/tmp` -> `/private/tmp`).
3. **Path traversal in tests**: Make the allowed directory configurable via `config()` so tests
   can override it without modifying production code.

## Related

- `packages/core/src/Services/CloudflareService.php` - lazy credential loading
- `packages/core/src/Services/Deletion/Actions/DeleteProjectFiles.php` - path traversal protection
