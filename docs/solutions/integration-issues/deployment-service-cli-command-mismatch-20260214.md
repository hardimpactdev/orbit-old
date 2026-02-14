---
date: 2026-02-14
problem_type: integration
component: packages/core/src/Services/DeploymentService.php
severity: critical
symptoms:
  - "DeploymentService calls site:create but CLI only has project:create"
  - "Deployments via gateway MCP tools silently fail"
root_cause: DeploymentService was written using old command names that never existed in the CLI
tags: [deployment, cli, command-names]
---

# DeploymentService CLI Command Name Mismatch

## Symptom

DeploymentService used `site:create`, `site:delete`, and `site:list` CLI commands. The actual CLI commands are `project:create`, `project:delete`, and `project:list`. This caused all gateway-orchestrated deployments to fail.

Additionally, `syncNode()` expected `data['sites']` in the CLI response, but the CLI returns `data['projects']`.

## Investigation

1. Attempted: Deploy SRPM via `orbit project:create` on production node
   Result: Worked directly, proving the CLI uses `project:*` commands
2. Reviewed: DeploymentService source code
   Result: Found all three methods using `site:*` instead of `project:*`

## Root Cause

When DeploymentService was written, the command names were assumed to be `site:*` based on the internal `Site` model. The CLI actually uses `project:*` as the public command namespace.

## Solution

Updated all CLI command references in DeploymentService:

```php
// Before (broken)
$cliArgs = "site:create {$name} --json";
$result = $this->command->executeCommand($node, "site:delete {$deployment->project_slug} --force --json");
$result = $this->command->executeCommand($node, 'site:list --json');
$remoteSites = $result['data']['sites'] ?? $result['data'] ?? [];

// After (fixed)
$cliArgs = "project:create {$name} --json";
$result = $this->command->executeCommand($node, "project:delete {$deployment->project_slug} --force --json");
$result = $this->command->executeCommand($node, 'project:list --json');
$remoteSites = $result['data']['projects'] ?? $result['data']['sites'] ?? $result['data'] ?? [];
```

Updated test expectations in `DeploymentServiceTest.php` to match.

## Prevention

- **CLI command naming convention**: The CLI public namespace is `project:*`, not `site:*`. The `Site` model is internal to orbit-core.
- When writing services that call CLI commands, verify command names against `packages/cli/app/Commands/` directory.
- The `data['sites']` fallback was kept for backward compatibility with older CLI versions.

## Related

- `packages/core/src/Services/DeploymentService.php`
- `packages/core/tests/Unit/Services/DeploymentServiceTest.php`
- `packages/cli/app/Commands/Project/` (actual CLI commands)
