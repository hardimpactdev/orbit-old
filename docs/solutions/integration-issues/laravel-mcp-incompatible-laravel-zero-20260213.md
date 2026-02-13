---
date: 2026-02-13
problem_type: architecture
component: packages/cli, laravel/mcp
severity: moderate
symptoms:
  - "PHAR build conflicts when laravel/mcp is a dependency"
  - "Missing illuminate/http, illuminate/routing, illuminate/validation"
root_cause: laravel/mcp hard-depends on full Laravel HTTP stack that Laravel Zero doesn't ship
tags: [mcp, laravel-zero, phar, architecture]
---

# laravel/mcp is incompatible with Laravel Zero

## Symptom

Attempting to add `laravel/mcp` to orbit-cli (Laravel Zero) causes PHAR build failures. Even if composer resolves, the `McpServiceProvider` calls `Route::group()` in `boot()` which requires `illuminate/routing`.

## Investigation

1. Attempted: Moving MCP tool definitions to orbit-core so both CLI and web could serve them
   Result: Core can't depend on `laravel/mcp` either — it would force the HTTP stack on all consumers including the CLI

2. Attempted: Using only `Mcp::local()` (stdio transport) without HTTP routes
   Result: `McpServiceProvider::boot()` unconditionally imports Route facade. Hard dependencies still required by composer.

3. Researched: `php-mcp/server` as alternative
   Result: Possible but requires rewriting all 26 tool/resource classes against a different API

## Root Cause

`laravel/mcp` requires these packages that Laravel Zero does NOT ship:

| Package | Purpose |
|---------|---------|
| `illuminate/http` | HTTP request/response |
| `illuminate/routing` | Route registration |
| `illuminate/validation` | Input validation |
| `illuminate/json-schema` | Tool schema definitions |

Laravel Zero uses `laravel-zero/foundation` (stripped-down) instead of `laravel/framework` (full-stack). These are fundamentally incompatible.

## Solution

**MCP servers stay in orbit-app** (full Laravel package). Every node that needs MCP already runs orbit-web with PHP-FPM, making HTTP transport available. The CLI does NOT serve MCP.

The CLI can still be an MCP **client** — `McpClient.php` makes plain HTTP calls to external MCP endpoints (Orchestrator). This requires only `illuminate/http` which is registered manually.

### Architecture

```
orbit-app (serves MCP)          orbit-cli (consumes MCP)
├── OrbitServer                  ├── McpClient (HTTP client)
├── GatewayServer                └── Calls Orchestrator MCP
├── 16 tools, 6 resources
└── Deployed via orbit-web
```

## Prevention

- Never add `laravel/mcp` to orbit-cli or orbit-core composer.json
- MCP tool definitions belong in orbit-app only
- If CLI needs MCP capabilities, use HTTP calls to the deployed web app
- When considering moving MCP to a shared package, remember this constraint
