---
date: 2026-02-15
problem_type: testing
component: Laravel Middleware / Route Testing
severity: moderate
symptoms:
  - "Middleware unit tests pass but actual routes unprotected"
  - "Test creates test route with middleware, doesn't verify real routes"
  - "Security vulnerability discovered despite passing tests"
root_cause: "Unit tests validate middleware logic but don't verify it's applied to actual routes"
tags: [testing, middleware, routes, integration-tests, security]
---

# Middleware Unit Tests Don't Verify Route Application

## Symptom

Comprehensive middleware tests all pass, but the actual routes the middleware should protect are vulnerable:

```php
// tests/Feature/McpAccessControlTest.php
it('blocks access from unauthorized IPs', function () {
    Route::middleware(McpAccessControl::class)
        ->post('/test-mcp', fn () => response()->json(['success' => true]));

    $response = $this->post('/test-mcp', [], ['REMOTE_ADDR' => '8.8.8.8']);
    $response->assertForbidden();  // ✅ PASSES
});
```

**But in production:**
```bash
# Actual MCP endpoint is unprotected!
curl -X POST https://orbit.gateway/mcp/gateway
# Returns 200 OK, not 403 Forbidden ❌
```

**Impact**: False confidence - tests pass, security vulnerability remains.

## Investigation

### Why Unit Tests Aren't Enough

**What unit tests verify:**
- ✅ Middleware logic works (IP validation, CIDR calculations)
- ✅ Blocked IPs return 403
- ✅ Allowed IPs pass through
- ✅ Logging works

**What unit tests DON'T verify:**
- ❌ Middleware actually applied to real routes
- ❌ Route registration includes middleware
- ❌ Middleware order correct
- ❌ No bypass routes exist

### The Gap

```php
// Unit test creates its own test route
Route::middleware(McpAccessControl::class)
    ->post('/test-mcp', ...);  // ✅ This route is protected

// Actual application routes
Mcp::web('mcp/gateway', GatewayServer::class);  // ❌ Not wrapped in middleware!
```

Unit tests verify middleware *can* work, not that it *is* working on production routes.

## Root Cause

**Testing Philosophy Mismatch:**

| Test Type | What It Verifies | Example |
|-----------|------------------|---------|
| **Unit Test** | Component logic in isolation | "IP validation works" |
| **Integration Test** | Components work together | "Actual routes are protected" |

Testing middleware in isolation creates a **false positive** - the middleware works perfectly, just isn't applied where needed.

## Solution

### Add Integration Tests for Actual Routes

Create tests that hit the real application routes:

```php
// tests/Feature/McpEndpointSecurityTest.php

it('protects mcp/gateway endpoint with access control', function () {
    // Hit the ACTUAL route, not a test route
    $response = $this->post('/mcp/gateway', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'params' => [...],
    ], ['REMOTE_ADDR' => '8.8.8.8']);  // Unauthorized IP

    $response->assertForbidden();  // Verify actual route is protected
    $response->assertSee('MCP access restricted');
});

it('allows mcp/gateway access from VPN network', function () {
    $response = $this->post('/mcp/gateway', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'params' => [...],
    ], ['REMOTE_ADDR' => '10.6.0.50']);  // VPN IP

    $response->assertOk();  // Verify VPN access works
    $response->assertJsonStructure(['jsonrpc']);
});
```

### Test Coverage Strategy

**Level 1: Middleware Unit Tests** (5 tests)
- IP validation logic
- CIDR subnet calculations
- Logging behavior
- Edge cases

**Level 2: Endpoint Integration Tests** (7 tests)
- Actual `/mcp/gateway` route protected
- Actual `/mcp/orbit` route protected
- VPN subnet access allowed
- Localhost access allowed
- Private LANs blocked
- Public IPs blocked
- All VPN IPs in range work

**Result**: 12 total tests, comprehensive coverage from logic → actual routes

## Prevention

### Security Middleware Testing Checklist

When adding security middleware:

- [ ] **Unit tests**: Verify middleware logic works in isolation
- [ ] **Integration tests**: Verify middleware applied to actual routes
- [ ] **Test all protected routes**: Don't just test one, test all
- [ ] **Test positive & negative cases**: Both allowed and blocked IPs
- [ ] **Test edge cases**: Subnet boundaries, localhost, private ranges
- [ ] **Test logging**: Verify blocked attempts are logged

### Example Test Structure

```php
describe('SecurityMiddleware', function () {
    describe('unit tests', function () {
        it('validates IPs correctly', ...);
        it('handles CIDR subnets', ...);
        it('logs blocked attempts', ...);
    });

    describe('integration tests', function () {
        it('protects /admin routes', ...);
        it('protects /api/internal routes', ...);
        it('allows authorized access', ...);
    });
});
```

### Warning Signs

- Tests pass but feature doesn't work in production
- Only testing middleware on mock/test routes
- No tests that hit actual application routes
- Can manually bypass security despite passing tests
- Route registration changes don't trigger test failures

## Real-World Example

**This exact pattern missed a critical security vulnerability:**

```php
// routes/mcp.php (BEFORE - vulnerable)
Mcp::web('mcp/orbit', OrbitServer::class);
Mcp::web('mcp/gateway', GatewayServer::class);
// No middleware! Public access!

// tests/Feature/McpAccessControlTest.php (PASSING ✅)
Route::middleware(McpAccessControl::class)->post('/test-mcp', ...);
// Test route protected, actual routes not!
```

**After adding integration tests:**
```php
// tests/Feature/McpEndpointSecurityTest.php
it('protects mcp/gateway endpoint', function () {
    $this->post('/mcp/gateway', ..., ['REMOTE_ADDR' => '8.8.8.8'])
        ->assertForbidden();  // ❌ FAILS - route not protected!
});
```

Integration test immediately caught the missing middleware application.

## Laravel-Specific Gotchas

### Route Registration Order Matters

```php
// WRONG - middleware doesn't apply
Mcp::web('mcp/gateway', GatewayServer::class);
Route::middleware(McpAccessControl::class)->group(function () {
    // Only routes INSIDE this block are protected
});

// CORRECT - middleware applies
Route::middleware(McpAccessControl::class)->group(function () {
    Mcp::web('mcp/gateway', GatewayServer::class);
});
```

**Integration test catches this** - unit test doesn't.

### Global Middleware vs Route Middleware

```php
// Global (app/Http/Kernel.php) - applies to ALL routes
protected $middleware = [
    McpAccessControl::class,  // Too broad!
];

// Route-specific (preferred) - applies only where needed
Route::middleware(McpAccessControl::class)->group(function () {
    Mcp::web('mcp/gateway', ...);
});
```

**Test both the protected routes AND routes that shouldn't be protected.**

## Related

- **Issue**: MCP endpoints publicly accessible despite middleware implementation
- **Fix**: Added integration tests for actual routes, discovered missing middleware application
- **Result**: 12 security tests (5 unit + 7 integration), full coverage
- **Security Vulnerability**: `docs/solutions/security-issues/mcp-endpoints-public-access-vulnerability-20260215.md`

## Files Involved

- Unit tests: `packages/app/tests/Feature/McpAccessControlTest.php`
- Integration tests: `packages/app/tests/Feature/McpEndpointSecurityTest.php`
- Middleware: `packages/app/src/Http/Middleware/McpAccessControl.php`
- Routes: `packages/app/routes/mcp.php`

## Recommendation

**For security-critical middleware:**
1. Start with unit tests (verify logic)
2. **Immediately add integration tests** (verify application)
3. Test ALL routes that should be protected
4. Test edge cases and attack vectors
5. Run security tests in CI before every deploy

**Golden rule**: If a security feature isn't tested in its real application context, it's not tested.
