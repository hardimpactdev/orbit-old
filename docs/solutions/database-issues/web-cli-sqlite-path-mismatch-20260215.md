---
date: 2026-02-15
problem_type: configuration
component: packages/web/config/database.php
severity: critical
symptoms:
  - "Gateway MCP tools return empty results"
  - "Nodes registered via CLI not visible in web app"
  - "MCP gateway_nodes returns no nodes"
root_cause: Web app and CLI used different default SQLite database paths
tags: [database, sqlite, mcp, gateway, config]
---

# Web App and CLI Use Different SQLite Databases

## Symptom

Gateway MCP tools (e.g. `gateway_nodes`, `gateway_deployments`) return empty results even though nodes were registered via the CLI. The web app appears to have no data.

## Investigation

1. CLI registers nodes into `~/.config/orbit/database.sqlite` (hardcoded in `packages/cli/config/database.php`)
2. Web app reads from `database_path('database.sqlite')` which resolves to `~/.config/orbit/web/database/database.sqlite`
3. Two separate databases — CLI writes to one, web reads from the other

## Root Cause

`packages/web/config/database.php` used Laravel's default `database_path('database.sqlite')` instead of the shared orbit database path. The CLI hardcodes its path to `~/.config/orbit/database.sqlite` via `$_SERVER['HOME']`.

## Solution

Change the web app's SQLite default to match the CLI:

```php
// packages/web/config/database.php

// Before (broken)
'database' => env('DB_DATABASE', database_path('database.sqlite')),

// After (fixed)
$home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
// ...
'database' => env('DB_DATABASE', "{$home}/.config/orbit/database.sqlite"),
```

`DB_DATABASE` env var still overrides the default, so NativePHP/desktop can use a different path.

## Prevention

- When adding a new Laravel shell that consumes orbit-core, always set the SQLite default to `~/.config/orbit/database.sqlite`
- The canonical database path is defined in `packages/cli/config/database.php` — all other packages must match it
- The `DB_DATABASE` env var in `.env` can override, but the config default must be consistent

## Related

- `packages/cli/config/database.php` — canonical database path
- `packages/web/CLAUDE.md` — documents `DB_DATABASE` env var for shared database
