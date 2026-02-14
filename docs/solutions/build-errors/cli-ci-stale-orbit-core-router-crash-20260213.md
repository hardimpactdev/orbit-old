---
date: 2026-02-13
problem_type: build-error
component: .github/workflows/ci.yml
severity: critical
symptoms:
  - "Target class [router] does not exist"
  - "Laravel framework bootstrap failed"
  - PHPStan crashes during CLI CI
root_cause: CLI CI resolved orbit-core from Packagist instead of monorepo, getting stale version without router guard
tags: [ci, phpstan, laravel-zero, monorepo, path-repository]
---

# CLI CI Crashes on PHPStan: "Target class [router] does not exist"

## Symptom

CLI CI job fails at the PHPStan step with:

```
Laravel framework bootstrap failed
Error: Target class [router] does not exist.
```

Stack trace shows `OrbitCoreServiceProvider::registerRouteBindings()` calling `Route::model()` which requires the Router — unavailable in Laravel Zero.

## Investigation

1. Locally PHPStan works fine (monorepo uses path repos via root composer.json)
2. CI installs orbit-core from Packagist via `composer install`
3. The Packagist version lags behind the monorepo — missing the router guard added in the same session
4. App CI already had the fix (path repo), but CLI CI was missed

## Root Cause

The monorepo split publishes packages to Packagist asynchronously. When CI runs `composer install`, it resolves orbit-core from Packagist which may not have the latest code. The `OrbitCoreServiceProvider` had a router guard (`if (! $this->app->bound('router'))`) in the monorepo but the Packagist version didn't have it yet.

## Solution

Add path repository for orbit-core in the CLI CI job, matching the pattern already used by app CI:

```yaml
# Before (broken) — resolves from Packagist
- name: Install dependencies
  working-directory: packages/cli
  run: composer install --prefer-dist --no-progress

# After (fixed) — resolves from monorepo
- name: Install dependencies
  working-directory: packages/cli
  run: |
    composer config repositories.orbit-core path ../core --no-plugins
    rm -f composer.lock
    composer install --prefer-dist --no-progress
```

## Prevention

- **All monorepo CI jobs** that depend on sibling packages must use path repositories
- Check all `composer install` steps in CI when adding new cross-package dependencies
- The pattern: `composer config repositories.{package} path ../{sibling} --no-plugins`
- Always `rm -f composer.lock` after adding path repo to force fresh resolution

## Related

- `docs/solutions/build-errors/monorepo-build-race-condition-orbit-core-20260213.md` — same root cause in build-cli workflow
- `docs/solutions/integration-issues/laravel-mcp-incompatible-laravel-zero-20260213.md` — Laravel Zero lacks Router
