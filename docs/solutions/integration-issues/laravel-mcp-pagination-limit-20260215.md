---
date: 2026-02-15
problem_type: integration
component: MCP Server
severity: critical
symptoms:
  - "MCP tools at positions >15 not visible to clients"
  - "Cloudflare DNS tools missing from tool list"
root_cause: "Laravel MCP Server default pagination length is 15"
tags: [mcp, pagination, laravel-mcp, tooling]
---

# Laravel MCP Server Pagination Hides Tools Beyond Position 15

## Symptom

When registering 20+ tools in an MCP server, only the first 15 appear to MCP clients. Tools registered at positions 16+ are invisible and cannot be invoked.

**Observable impact**:
- `gateway_cloudflare_dns` (position 16) - not available
- `gateway_cloudflare_add_record` (position 17) - not available
- `gateway_cloudflare_remove_record` (position 18) - not available
- `gateway_cloudflare_set_ssl` (position 19) - not available
- `gateway_flush_dns` (position 20) - not available

## Investigation

### Attempted: Check tool registration order
Result: All 20 tools were properly registered in the correct order in `GatewayServer.php`

### Attempted: Check MCP client configuration
Result: Client was configured correctly and working for other servers

## Root Cause

The `Laravel\Mcp\Server` base class has a default pagination length of 15 items:

```php
// vendor/laravel/mcp/src/Server.php
protected int $defaultPaginationLength = 15;
```

When MCP clients request the tool list, they only receive page 1 (15 items). Clients don't automatically request additional pages, so tools beyond position 15 become invisible.

## Solution

Override the pagination length in your MCP server class:

```php
// Before (broken) - uses default 15
final class GatewayServer extends Server
{
    protected string $name = 'Gateway';
    protected string $version = '1.0.0';
}

// After (fixed) - explicit pagination limit
final class GatewayServer extends Server
{
    protected string $name = 'Gateway';
    protected string $version = '1.0.0';

    public int $defaultPaginationLength = 50;  // ← Add this
}
```

**File**: `packages/app/src/Mcp/GatewayServer.php`

## Prevention

### When Creating MCP Servers
1. Count total tools + resources in your server
2. If count > 15, set `$defaultPaginationLength` explicitly
3. Set to reasonable upper bound (50-100) to avoid future issues

### Warning Signs
- Tools mysteriously "missing" from MCP client
- Alphabetically late tools not appearing
- Tools with similar names work but others don't

### Test Case
Add to MCP server tests:

```php
/** @test */
public function it_registers_all_tools_without_pagination_limit(): void
{
    $server = new GatewayServer();
    $tools = $server->tools();

    // Verify no tools are lost to pagination
    $this->assertGreaterThanOrEqual(20, count($tools));
    $this->assertTrue($server->hasTool('gateway_cloudflare_dns'));
    $this->assertTrue($server->hasTool('gateway_flush_dns'));
}
```

## Related

- **Laravel MCP Documentation**: Does not mention pagination behavior
- **Orbit GatewayServer**: Fixed in v0.1.109
- **Orbit OrbitServer**: Should verify tool count < 15 or add pagination override

## Impact Timeline

- **Discovered**: 2026-02-15 during ditis-hr.nl production deployment
- **Fixed**: v0.1.109 (same day)
- **Affected**: All Cloudflare DNS management operations since GatewayServer launch

## Recommendation

For any MCP server with >10 tools, proactively set `$defaultPaginationLength = 50` to avoid this issue.
