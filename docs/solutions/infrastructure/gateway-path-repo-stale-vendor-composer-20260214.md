---
date: 2026-02-14
problem_type: deployment
component: gateway web app, composer path repositories
severity: moderate
symptoms:
  - "Nothing to migrate" after composer update with new migrations in source
  - "composer update" reports no changes despite new files in path repo source
root_cause: Composer path repos with symlink:false don't re-copy when version string is unchanged
tags: [gateway, composer, path-repository, deployment, migrations]
---

# Composer Path Repo Doesn't Re-Copy on Update (Same Version)

## Symptom

After syncing new package code (migrations, models, services) to the gateway's `packages/core/` source directory:

```bash
composer update hardimpactdev/orbit-core hardimpactdev/orbit-app --no-dev
# "Nothing to modify in lock file"

php artisan migrate --force
# "Nothing to migrate"
```

New migration files existed in `packages/core/database/migrations/` but NOT in `vendor/hardimpactdev/orbit-core/database/migrations/`.

## Investigation

1. Attempted: `composer update` after rsync of source packages
   Result: "Nothing to modify in lock file" — version `0.1.99-dev` unchanged

2. Attempted: Checking vendor directory
   Result: Vendor copy was stale (dated Feb 13, missing Feb 14 files)

## Root Cause

The gateway's `composer.json` uses path repositories with `symlink: false`:

```json
"repositories": [
    {"type": "path", "url": "packages/app", "options": {"symlink": false}},
    {"type": "path", "url": "packages/core", "options": {"symlink": false}}
]
```

When `symlink: false`, composer **mirrors** (copies) files from the path into `vendor/`. But `composer update` only re-mirrors when the **version changes** in the lock file. Since both old and new code resolve to `0.1.99-dev`, composer sees no change and skips the copy.

## Solution

Force re-mirror by deleting vendor copies and reinstalling:

```bash
cd ~/.config/orbit/web
rm -rf vendor/hardimpactdev/orbit-core vendor/hardimpactdev/orbit-app
composer install --no-dev
```

This forces composer to re-mirror from the path source, picking up all new files.

### Full gateway deployment workflow:

```bash
# 1. Sync packages from local monorepo
rsync -avz --delete --exclude='vendor/' --exclude='node_modules/' --exclude='.git/' \
  /Users/nckrtl/orbit/packages/core/ gateway:~/.config/orbit/web/packages/core/
rsync -avz --delete --exclude='vendor/' --exclude='node_modules/' --exclude='.git/' \
  /Users/nckrtl/orbit/packages/app/ gateway:~/.config/orbit/web/packages/app/

# 2. Force re-mirror on gateway
ssh gateway 'cd ~/.config/orbit/web && \
  rm -rf vendor/hardimpactdev/orbit-core vendor/hardimpactdev/orbit-app && \
  ~/.local/bin/composer install --no-dev'

# 3. Run migrations
ssh gateway 'cd ~/.config/orbit/web && php artisan migrate --force'
```

## Prevention

- When deploying to the gateway, always delete vendor copies before `composer install`
- Consider using `symlink: true` if the gateway's filesystem supports it (would auto-reflect changes)
- If using versioned releases instead of `@dev`, the version bump would trigger re-copy naturally
- Document the deployment workflow in AGENTS.md

## Related

- `docs/solutions/infrastructure/gateway-mcp-deployment-20260213.md`
- Known issue: "Gateway vendor directory" in root AGENTS.md
