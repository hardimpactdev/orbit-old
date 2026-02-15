---
date: 2026-02-15
problem_type: test-failure
component: DeploymentServiceTest
severity: minor
symptoms:
  - "No matching handler found for Mockery::executeCommand"
  - "Tests fail after adding escapeshellarg() to CLI argument building"
root_cause: Mock expectations use str_contains() for exact substrings but escapeshellarg() wraps values in quotes
tags: [testing, mockery, escapeshellarg, security-fix]
---

# Mock Assertions Break After Adding escapeshellarg()

## Symptom

After fixing command injection by adding `escapeshellarg()`, Mockery mock expectations fail:

```
No matching handler found for executeCommand(Node, "project:delete 'to-remove' --force --json")
```

The mock expected `project:delete to-remove --force --json` (no quotes) but got `project:delete 'to-remove' --force --json` (with quotes from escapeshellarg).

## Root Cause

`escapeshellarg()` wraps values in single quotes: `my-app` becomes `'my-app'`. Test mocks using `str_contains($cmd, 'project:create my-app')` fail because the actual string is now `project:create 'my-app'`.

## Solution

Split mock assertions to check for parts independently instead of exact substrings:

```php
// Before (breaks with escapeshellarg)
->withArgs(fn ($n, $cmd) => str_contains($cmd, 'project:delete to-remove --force --json'))

// After (works with or without escaping)
->withArgs(fn ($n, $cmd) =>
    str_contains($cmd, 'project:delete')
    && str_contains($cmd, 'to-remove')
    && str_contains($cmd, '--force --json')
)
```

For option-value pairs:

```php
// Before
str_contains($cmd, '--clone=org/repo')

// After (check flag and value separately)
str_contains($cmd, '--clone=') && str_contains($cmd, 'org/repo')
```

## Prevention

1. **When adding escapeshellarg()**: Always check for mock expectations using `str_contains` on the escaped strings
2. **Prefer split assertions**: Check for command name, argument values, and flags independently
3. **Don't assert exact command strings**: They're brittle and break on any formatting change

## Related

- `docs/solutions/security-issues/command-injection-shell-exec-20260131.md`
