---
date: 2026-02-15
problem_type: logic-error
component: error-handling
severity: moderate
symptoms:
  - "Deployment fails with {success: false, error: ''}"
  - "Empty error messages throughout the stack"
root_cause: "Null coalescing operator (??) doesn't catch empty strings"
tags: [error-handling, operators, php]
---

# Empty Error Messages with Null Coalescing Operator

## Symptom

Deployment failed with no useful error message:
```json
{"success": false, "error": ""}
```

The actual error (GitHub repo inaccessible due to missing `gh` auth) was lost at multiple layers of the stack.

## Investigation

1. **Attempted**: Check if errors were being set at all
   - Result: Errors WERE being set, but as empty strings `""`

2. **Attempted**: Trace through error propagation layers
   - Result: Found `??` operators at every layer
   - Issue: `??` only catches `null`, not empty strings

## Root Cause

PHP's null coalescing operator (`??`) only handles `null` values:

```php
// WRONG - empty string bypasses ??
$error = '' ?? 'Fallback';  // Returns ''

// RIGHT - trim + ?: catches empty strings
$error = trim($error ?? '') ?: 'Fallback';  // Returns 'Fallback'
```

Error messages were set to empty strings at various points, then propagated through multiple layers, each using `??` which didn't catch them.

**Error flow:**
```
CLI returns: {error: ""}
  ↓
SshService: error ?? 'SSH failed'  → still ""
  ↓
CommandService: error ?? 'Remote failed' → still ""
  ↓
DeploymentService: error ?? 'Deploy failed' → still ""
  ↓
GatewayDeployTool: error_message (empty)
```

## Solution

Replace `??` with `trim() ?: fallback` pattern at every error propagation layer:

```php
// Before (broken)
'error' => $result['error'] ?? 'Default message'

// After (fixed)
'error' => trim($result['error'] ?? '') ?: 'Default message'
```

### Files Updated

**packages/core/src/Services/SshService.php** (line 64):
```php
'error' => $result->errorOutput() ?: $result->output() ?: 'SSH command failed',
```

**packages/core/src/Services/OrbitCli/Shared/CommandService.php** (line 131):
```php
'error' => trim($result['error'] ?? '') ?: 'Remote command failed with no error output',
```

**packages/core/src/Services/DeploymentService.php** (lines 77, 163):
```php
// deploy() method
'error_message' => trim($result['error'] ?? '') ?: 'Deployment command failed — check node connectivity and CLI installation',

// syncNode() method
'error' => trim($result['error'] ?? '') ?: 'Failed to list projects on node',
```

**packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php** (lines 112, 150):
```php
'error' => $deployment->error_message ?: 'Deployment failed for unknown reason',
```

## Prevention

1. **Never rely on `??` alone for error messages** - always use `trim() ?: fallback`
2. **Test with empty strings** - not just `null` values
3. **Add tests for empty error propagation** - verify fallback messages appear
4. **Use pattern consistently** - apply at every layer where errors flow

## Warning Signs

- Error handling only checks `?? null`
- No trimming before checking error strings
- Empty error messages in production logs
- "Unknown error" messages when actual errors occurred

## Test Cases

```php
it('stores meaningful error when CLI returns empty error string', function () {
    $this->commandService->shouldReceive('executeCommand')
        ->andReturn(['success' => false, 'error' => '']);

    $deployment = $this->service->deploy($node, ['name' => 'test']);

    expect($deployment->error_message)->not->toBeEmpty();
    expect($deployment->error_message)->toBe('Deployment command failed — check node connectivity and CLI installation');
});
```

## Related

- Convention added to CLAUDE.md: "Always use `trim() ?: fallback` pattern for error handling"
