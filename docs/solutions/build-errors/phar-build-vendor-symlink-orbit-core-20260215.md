---
date: 2026-02-15
problem_type: build-error
component: cli/phar-build
severity: critical
symptoms:
  - 'Class "HardImpact\Orbit\Core\OrbitCoreServiceProvider" not found'
  - "PHP Fatal error in ProviderRepository.php on line 205"
root_cause: Monorepo vendor symlink not resolved during phar compilation
tags: [phar, box, monorepo, symlink, build]
---

# Phar Build Fails — orbit-core Vendor Symlink Not Included

## Symptom

After building the CLI phar with `box compile` and deploying to production:

```
PHP Fatal error: Uncaught Error: Class "HardImpact\Orbit\Core\OrbitCoreServiceProvider" not found
in phar:///home/orbit/.local/bin/orbit/vendor/laravel-zero/foundation/src/Illuminate/Foundation/ProviderRepository.php:205
```

The phar was only 58MB (11,669 files) instead of the expected ~120MB (24,500+ files).

## Root Cause

In the monorepo, `packages/cli/vendor/hardimpactdev/orbit-core` is a symlink:

```
vendor/hardimpactdev/orbit-core -> ../../../core/
```

This works for local development, but `box compile` doesn't follow the symlink properly — the orbit-core source files are excluded from the phar.

## Solution

Before building, replace the symlink with actual files, then restore after:

```bash
# Replace symlink with real files
rm vendor/hardimpactdev/orbit-core
cp -R ../../packages/core vendor/hardimpactdev/orbit-core

# Remove unnecessary files to reduce phar size
rm -rf vendor/hardimpactdev/orbit-core/tests \
       vendor/hardimpactdev/orbit-core/docs \
       vendor/hardimpactdev/orbit-core/.git \
       vendor/hardimpactdev/orbit-core/.github

# Build
~/.composer/vendor/bin/box compile

# Restore symlink for development
rm -rf vendor/hardimpactdev/orbit-core
ln -s ../../../core vendor/hardimpactdev/orbit-core
```

## Prevention

- **Always verify phar file count** after building. If ~11k files instead of ~24k, orbit-core is missing.
- The CI build (`build-cli.yml`) doesn't have this problem because it uses `composer config repositories.core path ../core` which copies files (not symlinks) when `symlink: false` is set.
- Consider adding a `build.sh` script in `packages/cli/` that automates the symlink→copy→build→restore cycle.

## Related

- `docs/solutions/build-errors/monorepo-build-race-condition-orbit-core-20260213.md`
- `.github/workflows/build-cli.yml` (CI build doesn't have this issue)
