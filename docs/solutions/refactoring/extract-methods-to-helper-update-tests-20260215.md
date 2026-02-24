---
date: 2026-02-15
problem_type: refactoring
component: ProjectHelper, CLI/Core tests
severity: moderate
symptoms:
  - "ReflectionException: Method normalizeRepoUrl does not exist"
  - "ReflectionException: Method detectProjectType does not exist"
  - "Tests using reflection to call private/protected methods fail after extraction"
root_cause: Tests used reflection to call methods on the original class; after extracting to a helper, the methods no longer exist on the original
tags: [refactoring, testing, reflection, helper-extraction]
---

# Tests Break When Extracting Methods to Helper Classes

## Symptom

After extracting duplicated methods (e.g., `expandPath`, `normalizeRepoUrl`, `detectProjectType`) from multiple classes into a shared `ProjectHelper`, tests that used reflection to call those methods on the original class fail:

```
ReflectionException: Method App\Commands\ProjectCreateCommand::normalizeRepoUrl() does not exist
```

## Root Cause

Tests used `ReflectionClass` to access private/protected methods on the original class. After extraction to a static helper, those methods no longer exist on the original class.

## Solution

Update tests to call the helper directly — no reflection needed since static methods are public:

```php
// Before (reflection on command class)
$command = $this->app->make(ProjectCreateCommand::class);
$reflection = new ReflectionClass($command);
$method = $reflection->getMethod('normalizeRepoUrl');
expect($method->invoke($command, 'https://github.com/user/repo'))->toBe('user/repo');

// After (direct static call)
expect(ProjectHelper::normalizeRepoUrl('https://github.com/user/repo'))->toBe('user/repo');
```

## Checklist: Extracting Methods to Helpers

1. Create the helper class with static methods
2. Update all **production** consumers (commands, jobs, services)
3. **Search for ALL tests** that reference the moved methods:
   ```bash
   grep -rn 'getMethod.*methodName\|->methodName(' tests/ --include="*.php"
   ```
4. Update tests to call the helper directly
5. Run tests in **all packages** (core, app, CLI) — not just the package where the helper lives

## Files Affected in This Session

| Original Location | Test File | Updated To |
|---|---|---|
| `CreateProjectJob::detectProjectType` | `packages/core/tests/Unit/Jobs/CreateProjectJobTest.php` | `ProjectHelper::detectProjectType()` |
| `ProjectCreateCommand::normalizeRepoUrl` | `packages/cli/tests/Feature/ProjectCreateCommandTest.php` | `ProjectHelper::normalizeRepoUrl()` |
| `ProjectCreateCommand::detectProjectType` | `packages/cli/tests/Feature/ProjectCreateCommandTest.php` | `ProjectHelper::detectProjectType()` |

## Prevention

1. **Search all test files** for reflection calls to the method name before extracting
2. **Grep broadly**: `grep -rn 'methodName' packages/*/tests/`
3. **Run all package test suites** after extraction, not just the one you modified
4. **Prefer public static helpers** over private methods tested via reflection — they're easier to test directly

## Related

- `packages/core/src/Support/ProjectHelper.php` — the extracted helper
