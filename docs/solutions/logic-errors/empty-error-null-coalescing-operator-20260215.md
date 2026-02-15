---
date: 2026-02-15
problem_type: Logic error
component: Error handling (multiple services)
severity: moderate
symptoms:
  - "Deployment fails with empty error: {\"success\": false, \"error\": \"\"}"
  - "MCP tools return no diagnostic information"
root_cause: Null coalescing operator (??) doesn't catch empty strings
tags: [error-handling, operators, deployment, mcp]
---

# Empty Error Strings Bypass Null Coalescing Operator

## Symptom

Deployment failures return empty error messages to MCP clients:

```json
{
  "success": false,
  "error": "",
  "deployment_id": 2
}
```

Investigation showed errors were being "caught" by fallback logic but still arriving empty.

## Investigation

**Attempted 1:** Add more `?? 'fallback'` operators at each layer
**Result:** Still getting empty strings - the operator was present but not working

**Attempted 2:** Check if errors were actually being set
**Result:** Found that `$result->errorOutput()` and `$result['error']` were returning `""` (empty string), not `null`

## Root Cause

The null coalescing operator (`??`) only triggers on `null` or undefined values, **not on empty strings**.

```php
// This FAILS to catch empty strings
$error = $result['error'] ?? 'Command failed';

// When $result['error'] = "" (empty string):
$error = "";  // Empty string is truthy for ??, fallback never used
```

PHP's `??` operator checks `isset()` and `!== null`, but empty strings pass both checks.

## Solution

Use the Elvis operator (`?:`) which checks truthiness, or combine with `trim()`:

```php
// Before (broken)
return [
    'error' => $result['error'] ?? 'Command failed',
];

// After (fixed) - Option 1: Elvis operator
return [
    'error' => $result['error'] ?: 'Command failed',
];

// After (fixed) - Option 2: Explicit empty check
return [
    'error' => trim($result['error'] ?? '') ?: 'Command failed',
];
```

**Why Option 2 is better:**
- Catches `null`, empty string, and whitespace-only strings
- More defensive against edge cases
- Self-documenting intent

### Files Changed

**`packages/core/src/Services/SshService.php` line 64:**
```php
// Before
'error' => $result->errorOutput(),

// After
'error' => $result->errorOutput() ?: $result->output() ?: 'SSH command failed',
```

**`packages/core/src/Services/OrbitCli/Shared/CommandService.php` line 131:**
```php
// Before
'error' => $result['error'] ?? 'Command failed',

// After
'error' => trim($result['error'] ?? '') ?: 'Remote command failed with no error output',
```

**`packages/core/src/Services/DeploymentService.php` lines 76, 155:**
```php
// Before
'error_message' => $result['error'] ?? 'Deployment failed',

// After (line 76)
'error_message' => trim($result['error'] ?? '') ?: 'Deployment command failed — check node connectivity and CLI installation',

// After (line 155)
'error' => trim($result['error'] ?? '') ?: 'Failed to list projects on node',
```

**`packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php` lines 104, 142:**
```php
// Before
'error' => $deployment->error_message,

// After
'error' => $deployment->error_message ?: 'Deployment failed for unknown reason',
```

## Prevention

### Rule: Never trust `??` for error messages

Always use `trim()` + `?:` for error handling:

```php
// ❌ BAD - Empty strings pass through
$error = $result['error'] ?? 'Default';

// ✅ GOOD - Catches null, empty, and whitespace
$error = trim($result['error'] ?? '') ?: 'Default';
```

### Test Case

Add to service tests:

```php
it('stores meaningful error when CLI returns empty error string', function () {
    $node = Node::factory()->client()->create();

    $this->commandService->shouldReceive('executeCommand')
        ->once()
        ->andReturn([
            'success' => false,
            'error' => '',  // Empty string
        ]);

    $deployment = $this->service->deploy($node, ['name' => 'test']);

    expect($deployment->status)->toBe(DeploymentStatus::Failed);
    expect($deployment->error_message)->not->toBeEmpty();
    expect($deployment->error_message)->toContain('Deployment command failed');
});
```

### Warning Signs

- Empty error messages in logs/responses
- Fallback messages never triggering despite errors
- `??` operator used for string values that might be empty
- No `trim()` on user input or external data before null checks

## Related

- Similar pattern exists in validation: empty strings bypass `required` rules
- Database queries: empty strings are not `NULL`, affects `IS NULL` checks
- Frontend: empty string inputs pass `v-if` checks
