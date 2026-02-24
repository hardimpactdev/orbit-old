---
date: 2026-02-18
problem_type: deployment
component: RemoteDeploymentOrchestrator
severity: critical
symptoms:
  - "SQLSTATE[HY000] [14] unable to open database file"
  - "Database file not found after remote deploy"
  - "SQLite symlink broken in release directory"
root_cause: "Relative symlink path was 2 levels deep (../../) instead of 3 (../../../)"
tags: [sqlite, symlink, deployment, remote-deploy, orchestrator]
---

# SQLite Symlink Depth Wrong in RemoteDeploymentOrchestrator

## Symptom

After deploying to production via `RemoteDeploymentOrchestrator`, the Laravel app throws:

```
SQLSTATE[HY000] [14] unable to open database file
```

The SQLite symlink inside the release directory is broken:

```bash
ls -la ~/projects/slug/releases/20260218_200107/database/database.sqlite
# database.sqlite -> ../../database/database.sqlite  ← BROKEN
```

## Investigation

1. Attempted: Check if shared database file exists
   Result: `~/projects/slug/database/database.sqlite` exists and has data

2. Attempted: Resolve the relative symlink path manually
   Result: From `releases/20260218_200107/database/`, `../../` resolves to `releases/`, not the project root

3. Counted directory levels:
   ```
   releases/          ← level 1 (../)
     20260218_200107/ ← level 2 (../../)
       database/      ← level 3 (../../../)  ← this is where the symlink lives
   ```

## Root Cause

The `RemoteDeploymentOrchestrator` created symlinks with `../../database/database.sqlite` in both `firstDeploy()` and `subsequentDeploy()`. The symlink lives at:

```
~/projects/{slug}/releases/{timestamp}/database/database.sqlite
```

From that location, `../../` resolves to `releases/database/database.sqlite` (wrong). The correct relative path needs 3 levels up (`../../../`) to reach the project root where the shared `database/` lives.

```
~/projects/{slug}/                    ← project root (target: ../../../)
├── releases/                         ← ../../ resolves HERE (wrong!)
│   └── 20260218_200107/              ← ../ resolves here
│       └── database/                 ← symlink origin
│           └── database.sqlite → ?
├── database/
│   └── database.sqlite               ← target file
```

## Solution

Fixed both `firstDeploy()` and `subsequentDeploy()` in `RemoteDeploymentOrchestrator`:

```php
// Before (broken) — 2 levels
"ln -s ../../database/database.sqlite {$ctx->releasePath}/database/database.sqlite"

// After (fixed) — 3 levels
"ln -s ../../../database/database.sqlite {$ctx->releasePath}/database/database.sqlite"
```

**File:** `packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php`

Also fixed the existing solution doc at `docs/solutions/infrastructure/laravel-release-database-symlink-pattern-20260215.md` which had the same wrong path in code examples.

## Prevention

- **Count from the symlink source, not the release root**: The symlink lives inside `database/` which is one extra level deep
- **Verify with readlink**: After creating a symlink, verify it resolves correctly:
  ```bash
  readlink -f ~/projects/slug/current/database/database.sqlite
  # Should show: ~/projects/slug/database/database.sqlite
  ```
- **Test on actual deploy**: The `.env` and `storage` symlinks only need `../../` because they live directly in the release root (2 levels: timestamp → releases → base). The `database/database.sqlite` symlink lives one level deeper.

## Related

- `docs/solutions/infrastructure/laravel-release-database-symlink-pattern-20260215.md` — original solution doc (also fixed)
- `packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php` — both firstDeploy and subsequentDeploy
