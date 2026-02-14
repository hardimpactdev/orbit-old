# MCP Servers

The orbit web app (`packages/app`) exposes two MCP servers for AI tool integration. Both support CLI (stdio) and HTTP transports.

## OrbitServer (`orbit`)

Site management, Docker infrastructure, environment config. Registers on Local/Client nodes.

**Tools:**

| Tool | Type | Description |
|------|------|-------------|
| `orbit_status` | read-only | Service status, running containers, sites count, TLD, PHP version |
| `orbit_start` | mutating | Start all Docker services |
| `orbit_stop` | mutating | Stop all Docker services |
| `orbit_restart` | mutating | Restart all Docker services |
| `orbit_projects` | read-only | List projects with domains, paths, PHP versions |
| `orbit_php` | mutating | Get/set/reset PHP version for a project |
| `orbit_project_create` | mutating | Create new project with optional GitHub template |
| `orbit_project_delete` | destructive | Delete project with cascade deletion |
| `orbit_logs` | read-only | Get service logs from Docker containers |
| `orbit_worktrees` | read-only | List git worktrees with subdomains |

**Resources:** `orbit://infrastructure`, `orbit://config`, `orbit://env-template/{type}`, `orbit://projects`

**Prompts:** `configure-laravel-env`, `setup-horizon`

**Connect from Claude Code (local):**
```bash
claude mcp add --transport stdio orbit -- php artisan mcp:start orbit --cwd /path/to/orbit-app
```

**Connect from Claude Code (remote via HTTP):**
```bash
claude mcp add --transport http orbit-remote https://orbit.ccc/mcp/orbit
```

## GatewayServer (`gateway`)

VPN client management, DNS/TLD routing, cross-node deployment tracking, and Cloudflare DNS management. Registers only on Gateway nodes (via `shouldRegister()` on each tool).

**Tools:**

| Tool | Type | Description |
|------|------|-------------|
| `gateway_status` | read-only | Node info, VPN client count, DNS mappings, services status |
| `gateway_clients` | read-only | All VPN clients with online status, TLD mappings, IPs |
| `gateway_create_client` | mutating | Create VPN client + optional TLD mapping |
| `gateway_dns_mappings` | read-only | All TLD-to-IP mappings |
| `gateway_add_tld` | mutating | Add DNS mapping for a TLD to a VPN IP |
| `gateway_remove_tld` | destructive | Remove a DNS mapping |
| `gateway_nodes` | read-only | List nodes with environment, status, deployment count |
| `gateway_deploy` | mutating | Deploy project to a node with optional Cloudflare DNS |
| `gateway_deployments` | read-only | List deployments filtered by project, node, environment, status |
| `gateway_sync_node` | mutating | Discover existing projects on a node and sync to deployments |
| `gateway_undeploy` | destructive | Remove deployment from node, clean up Cloudflare DNS |
| `gateway_cloudflare_status` | read-only | Cloudflare zone info and SSL mode |
| `gateway_cloudflare_dns` | read-only | List Cloudflare DNS records |
| `gateway_cloudflare_add_record` | mutating | Create a Cloudflare DNS record |
| `gateway_cloudflare_remove_record` | destructive | Delete a Cloudflare DNS record |

**Resources:** `gateway://clients`, `gateway://dns`, `gateway://deployments`

**Connect from Claude Code (remote via HTTP):**
```bash
claude mcp add --transport http gateway https://orbit.gateway/mcp/gateway
```

## Conditional Registration

Gateway tools only register when the current node is a Gateway (`Node::getSelf()?->isGateway()`). OrbitServer tools only register on Local/Client nodes. This prevents tools from appearing on the wrong node type.

## Gateway MCP Deployment

The gateway needs a minimal orbit-app deployment: PHP-FPM + Caddy serving MCP HTTP routes. No frontend, no NativePHP, no desktop - just the API/MCP endpoints.

**Prerequisites on gateway:**
- PHP-FPM running (installed via `orbit install --template=gateway`)
- Caddy running with route to orbit-app
- SQLite database with node record (node_type = 'gateway')
- orbit-core + orbit-app packages installed

**Deployment steps:**
```bash
ssh orbit@gateway

# Clone/update the web app
cd ~/.config/orbit/web
composer install --no-dev

# Ensure node record exists with gateway type
php artisan orbit:init --type=gateway

# Caddy route (add to Caddyfile):
# orbit.gateway {
#     root * /home/orbit/.config/orbit/web/public
#     php_fastcgi unix//home/orbit/.config/orbit/php/php85.sock
# }

# Verify MCP endpoint
curl -X POST https://orbit.gateway/mcp/gateway \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```
