---
date: 2026-02-13
problem_type: build-error
component: .github/workflows/build-cli.yml
severity: critical
symptoms:
  - "Target class [router] does not exist"
  - "Phar builds with stale orbit-core version"
root_cause: Race condition between build-cli.yml and split.yml on tag push
tags: [ci, build, composer, monorepo, race-condition]
---

# Monorepo Build Race Condition: CLI Gets Stale orbit-core

## Symptom

v0.1.100 phar crashes on startup:

```
Target class [router] does not exist.
```

The phar was built with orbit-core v0.1.99 (missing router guard clause) instead of the code at the v0.1.100 tag.

## Investigation

1. Attempted: Check if OrbitCoreServiceProvider had the guard clause
   Result: Current source had it, but the built phar didn't

2. Attempted: Check split.yml timing
   Result: split.yml and build-cli.yml both trigger on `v*` tag push and run concurrently

## Root Cause

When a `v*` tag is pushed, two workflows trigger simultaneously:
- `split.yml` — splits monorepo packages to standalone repos and pushes tags
- `build-cli.yml` — builds the CLI phar

The build workflow was deleting path repositories and resolving `orbit-core` from Packagist:

```yaml
# Before (broken)
jq 'del(.repositories)' composer.json > composer.tmp && mv composer.tmp composer.json
composer install --no-dev --prefer-dist
```

Since `split.yml` hadn't pushed the new orbit-core tag yet, Composer resolved the previous version (v0.1.99).

## Solution

Use a path repository pointing to the monorepo's local core package:

```yaml
# After (fixed)
jq '.repositories = [{"type": "path", "url": "../core", "options": {"symlink": false}}]' composer.json > composer.tmp && mv composer.tmp composer.json
composer install --no-dev --prefer-dist
```

`symlink: false` ensures Composer copies the files (required for phar compilation).

## Prevention

- In monorepos, always use path repositories for inter-package dependencies during CI builds
- Never rely on Packagist for packages that are published in the same CI pipeline
- If a build depends on a split package, either use path repos or make the build depend on the split job completing first
