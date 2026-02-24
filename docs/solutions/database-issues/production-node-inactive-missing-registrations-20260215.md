---
date: 2026-02-15
problem_type: data-integrity
component: gateway database (nodes, gateway_projects, deployments)
severity: critical
symptoms:
  - "MCP gateway_nodes shows production node as is_active: false"
  - "MCP gateway_projects returns empty results for projects already running in production"
  - "gateway_deploy refuses to deploy because 'Node is not active'"
root_cause: Node registered with is_active=0 and pre-existing deployments never registered as gateway projects
tags: [gateway, mcp, node, deployment, production, data-integrity]
---

# Production Node Inactive + Pre-Existing Deployments Missing from Registry

## Symptom

1. MCP `gateway_nodes(environment: "production")` returns the Hetzner Production node (id: 5) with `is_active: false`
2. MCP `gateway_projects(slug: "srpm")` returns `total: 0` despite srpm.nl actively running on the production node
3. `gateway_deploy` would refuse to deploy to the node because `GatewayDeployTool` checks `$node->isActive()` (which checks `status === NodeStatus::Active`, not `is_active` boolean — but the inactive flag still confused AI agents)

## Investigation

1. Queried the gateway SQLite database directly via SSH
2. Found `is_active = 0` on the production node
3. Found `gateway_projects` and `deployments` tables completely empty
4. Verified srpm.nl was actually running on production (`~/projects/srpm/current` symlink exists, Caddy serving it)

## Root Cause

Two issues:

1. **Node `is_active` not set on creation**: When the production node was registered, `is_active` defaulted to `0`. The `NodeFactory` sets it to `true`, but manual/CLI registration may not.

2. **No retroactive registration**: Projects deployed before the gateway project registry was implemented have no `GatewayProject` or `Deployment` records. The `gateway_sync_node` tool exists but returned an empty error when run.

## Solution

### Fix 1: Activate the production node

```sql
UPDATE nodes SET is_active = 1 WHERE id = 5;
```

### Fix 2: Register existing project + create deployment record

```bash
# Via MCP
curl -X POST http://orbit.gateway/mcp/gateway \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"gateway_register_project","arguments":{"name":"SRPM","production_domain":"srpm.nl","github_repo":"hardimpactdev/srpm"}},"id":1}'

# Then manually insert deployment record for existing deployment
INSERT INTO deployments (node_id, gateway_project_id, project_slug, project_name,
  github_repo, domain, url, php_version, status, created_at, updated_at)
VALUES (5, <project_id>, 'srpm', 'SRPM', 'hardimpactdev/srpm',
  'srpm.nl', 'https://srpm.nl', '8.5', 'active', datetime('now'), datetime('now'));
```

### Verification

```bash
# All three must return correct data:
curl ... gateway_nodes        # is_active: true
curl ... gateway_projects     # srpm with active_deployments: 1
curl ... gateway_deployments  # srpm on Hetzner Production, status: active
```

## Prevention

- **When adding a new node**: Always set `is_active = 1` explicitly. Don't rely on database defaults.
- **When deploying projects outside the gateway flow** (manual SSH, direct CLI): Register the project and create a deployment record afterward so MCP tools stay in sync.
- **Fix `gateway_sync_node`**: It should discover projects on a node by SSHing in and listing `~/projects/`, then auto-creating missing `GatewayProject` + `Deployment` records. Currently it silently fails.
- **After any infrastructure change**: Always verify through MCP tools end-to-end before declaring it done.

## Related

- `docs/solutions/database-issues/web-cli-sqlite-path-mismatch-20260215.md` — related database config issue
- `docs/solutions/infrastructure/production-node-setup-orbit-cli-20260214.md` — production node setup
- `packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php:65` — `isActive()` check that blocks deployment
- Note: all servers now use `~/projects/` (lowercase) — harmonized on 2026-02-16
