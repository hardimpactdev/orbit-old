# Orbit

A monorepo containing the orbit infrastructure management stack: CLI, core services, web app, and desktop GUI. Designed to be managed via Claude Code (LLM-as-interface) using MCP tools and skills, with an optional NativePHP/Electron desktop GUI.

## Important: Working with Environments

**Projects/sites run on remote servers, not locally.** When referencing a URL like `https://orbit-cli.ccc/` or `https://platform11-2026.ccc/`:

1. **Look up the environment** - Check which server hosts this site (TLD indicates the server)
2. **SSH into the server** - Use `ssh user@IP` to access the server
3. **Fix issues on the remote server** - Caddy configs, project files, and orbit CLI are there

**Current environments:**
| Environment | SSH Command | TLD | MCP Endpoint | Notes |
|-------------|-------------|-----|--------------|-------|
| Ubuntu VPS | `ssh orbit@ai` | `.ccc` | `POST https://orbit.ccc/mcp/orbit` | Main dev server, CLI source at `~/projects/orbit-cli/` |
| Gateway | `ssh gateway` | N/A | `POST https://orbit.gateway/mcp/gateway` | VPN hub, DNS routing (user: `gateway`) |
| Production | `ssh orbit@46.225.89.66` | N/A | N/A | Hetzner, hosts srpm.nl, proxies whisper.hardimpact.dev→Beast |
| Local | N/A (localhost) | `.test` | N/A | Local machine |

**Key paths on remote servers:**

- Projects: `~/projects/`
- **Orbit CLI source code**: `~/projects/orbit-cli/` - THIS IS WHERE TO MAKE CLI CHANGES
- Launchpad config: `~/.config/orbit/`
- Caddy config: `~/.config/orbit/caddy/Caddyfile`
- PHP-FPM sockets: `~/.config/orbit/php/php{version}.sock` (e.g., `php85.sock`, `php84.sock`)
- PHP-FPM pool configs: `~/.config/orbit/php/php{version}-fpm.conf`
- Worktrees config: `~/.config/orbit/worktrees.json`
- Horizon service (Linux): `/etc/systemd/system/orbit-horizon.service`

**Important:** The orbit CLI source code lives on the remote server at `ssh orbit@ai:~/projects/orbit-cli/`. Any changes to CLI behavior (site scanning, Caddy generation, worktrees, etc.) must be made there via SSH.

## Project Overview

This is a Laravel 12 application wrapped in NativePHP/Electron that provides a GUI for the orbit CLI tool. It can manage:

- Local orbit installations (on the same machine)
- Remote orbit installations (via SSH)
- Provisioning new servers from scratch

### Platform Support

**This is a macOS-only application.** Key macOS-specific dependencies:

- DNS resolver management via `/etc/resolver/` files
- Touch ID authentication for sudo via `pam_tid.so`
- `expect` for PTY spawning to enable Touch ID
- Assumes `dig` command is available (ships with macOS)

The remote environments being managed can run any Linux distribution (Ubuntu recommended), but the desktop app itself only runs on macOS.

## Conventions

- Every `packages/*/CLAUDE.md` must be a **symlink** to `AGENTS.md` (not a standalone copy). Verify with `ls -la packages/*/CLAUDE.md`.
- Detailed reference material belongs in `docs/reference/`, not in AGENTS.md files. Keep AGENTS.md focused on orientation and active gotchas.
- **Factory completeness**: Always include ALL model columns in factory definitions - don't rely on database-level `->default()` values. Eloquent doesn't load DB defaults after INSERT. If a query filters by a column (`is_active`, `environment`), the factory must explicitly set it.
- **Node host validation**: The `Node` model validates hosts on save. It accepts IPs, FQDNs, and single-label SSH aliases (e.g. `ai`, `gateway`). If adding new validation, test against `Node::factory()->create()` to ensure Faker data still passes.
- **SQLite test databases**: Always set `'foreign_key_constraints' => true` when using SQLite `:memory:` test databases with CASCADE foreign keys.
- **CLI command namespace**: The CLI public commands use `project:*` (`project:create`, `project:delete`, `project:list`), NOT `site:*`. The `Site` model is internal to orbit-core. When writing services that call CLI commands, verify names against `packages/cli/app/Commands/`.
- **CLI argument escaping**: When building CLI command strings in services (e.g. `DeploymentService`), always use `escapeshellarg()` for user-supplied values. Use array-based building (`$args[] = '--flag=' . escapeshellarg($val)`) and `implode(' ', $args)`.
- **Never pass secrets as CLI arguments**: Tokens/passwords passed as CLI arguments are visible in `ps aux`. Use stdin piping instead. See `docs/solutions/security-issues/api-token-cli-argument-leaks-process-list-20260215.md`.
- **Capture model state before service mutations**: When an MCP tool or controller calls a service that modifies a model, capture any needed values BEFORE the service call. The in-memory model may be stale after mutation. See `docs/solutions/logic-errors/stale-model-after-service-mutation-20260215.md`.
- **macOS VPN custom TLDs need `/etc/resolver/` files**: macOS system resolver ignores WireGuard supplemental DNS for non-standard TLDs. Each custom TLD (`.ccc`, `.gateway`, `.beast`) needs a `/etc/resolver/{tld}` file pointing to the gateway DNS (`10.6.0.1`). See `docs/solutions/infrastructure/macos-vpn-custom-tld-resolution-20260215.md`.
- **Gateway dnsmasq IPs**: The `.gateway` TLD must point to `10.6.0.2` (host VPN IP where Caddy runs), not `10.6.0.1` (wg-easy container). Always verify with `ssh gateway "ip addr show wg0"`.
- **Verify MCP connectivity end-to-end**: After configuring MCP servers, always test the full chain from the actual client before declaring it works: `curl -sf -X POST -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}' http://orbit.gateway/mcp/gateway`
- **Shared SQLite database path**: All packages (CLI, web, desktop) must default to `~/.config/orbit/database.sqlite`. The canonical path is in `packages/cli/config/database.php`. Never use `database_path()` as default — it resolves to a package-specific path. `DB_DATABASE` env var can override per-deployment. See `docs/solutions/database-issues/web-cli-sqlite-path-mismatch-20260215.md`.
- **Register pre-existing deployments**: Projects deployed outside the gateway flow (manual SSH, direct CLI) must be retroactively registered as `GatewayProject` + `Deployment` records. Otherwise MCP tools can't see them. Use `gateway_register_project` then insert a deployment with `status: active`.
- **Production server uses `~/Projects/`** (capital P): The Hetzner production node stores projects at `~/Projects/`, not `~/projects/`. The `gateway_sync_node` tool and any SSH-based project discovery must check both paths.
- **Auto-detect project info before asking**: When deploying or registering a project, never ask the user for information you can look up yourself. Check the local filesystem for the project directory (`~/Projects/`, `~/Clients/`, `~/projects/`), then read `git remote -v` to get the repo URL. Pass `github_repo` to `gateway_register_project` so subsequent deploys auto-retrieve it.
- **Error handling: Always use `trim() ?: fallback`**: Never rely on `??` alone for error messages - empty strings bypass it. Use `trim($error ?? '') ?: 'Fallback message'` to catch `null`, empty strings, and whitespace. See `docs/solutions/logic-errors/empty-error-null-coalescing-operator-20260215.md`.
- **Gateway deployment pre-flight checks**: Before executing remote deployments, MCP tools must validate: (1) SSH connectivity via `SshService::testConnection()`, (2) CLI binary exists via `CommandService::findBinary()`, (3) GitHub repo access via `gh repo view {repo}`. Return actionable errors with SSH commands or setup instructions. See `GatewayDeployTool::preflight()`.
- **PHP version auto-detection**: When no `php_version` is provided to `gateway_deploy`, the `DeploymentService` auto-detects it from the project's `composer.json` via GitHub API (`GitHubService::detectPhpVersion()`). Falls back to post-clone detection if GitHub API fails.
- **CLI `--json` output must be single object**: When adding `--json` support to CLI commands, suppress all intermediate output and only emit the final result as a single JSON object. Multiple JSON objects break `SshService::executeJson()`. See `docs/solutions/integration-issues/cli-multiple-json-output-breaks-parser-20260215.md`.
- **MCP HTTP endpoints require access control**: All infrastructure management endpoints (MCP servers, admin APIs) MUST have IP-based access control from day one. Use middleware to restrict to VPN network + localhost. Public endpoints are critical security vulnerabilities. See `docs/solutions/security-issues/mcp-endpoints-public-access-vulnerability-20260215.md`.
- **Path repository deployment workflow**: When using composer path repositories with `symlink: false`, changes to source packages don't automatically update vendor on `composer update` (version unchanged = no re-mirror). Fix: `rm -rf vendor/{package} && composer install`. See `docs/solutions/build-errors/composer-path-repo-stale-vendor-no-remirror-20260215.md`.
- **Test security middleware at integration level**: Unit tests verify middleware logic but don't catch missing route application. Always add integration tests that hit actual protected routes (e.g., test `/mcp/gateway`, not `/test-route`). See `docs/solutions/test-failures/middleware-unit-tests-miss-route-application-20260215.md`.

## Package Architecture

The orbit-dev monorepo contains four packages:

```
packages/
├── core/       # Shared models, services, migrations (used by CLI + app)
├── app/        # Web app: MCP servers, controllers, Vue frontend, Inertia
├── cli/        # Laravel Zero CLI: commands, install templates, phar binary
└── desktop/    # NativePHP/Electron wrapper (optional GUI)
```

### What Lives Where

| Package | Contains | Used By |
|---------|----------|---------|
| **core** | Node/Gateway/GatewayProject/Setting/Deployment models, gateway services (GatewayManager, WgEasyService, GatewayDnsService), CloudflareService (multi-zone DNS), DeploymentService, CLI wrapper services, migrations | CLI, App, Desktop |
| **app** | MCP servers (OrbitServer, GatewayServer), HTTP controllers, Vue pages, Inertia routes | Desktop (NativePHP), Remote web deployments |
| **cli** | CLI commands, install templates (Gateway/Client/Local), GatewayCliAdapter (Process-based operations), phar build | Installed on all nodes |
| **desktop** | NativePHP config, Electron window management | Local macOS only |

### Gateway Services in Core

Gateway business logic lives in `packages/core/src/Services/Gateway/`:

- **GatewayManager** - CRUD for gateway configs, VPN client registration, subnet detection
- **WgEasyService** - WireGuard VPN API client (constructor: `string $host, int $port, string $password`)
- **GatewayDnsService** - TLD-to-IP mappings via dnsmasq config files (constructor: `string $configPath`)

CLI-specific operations (Process facade, SSH, Docker commands) live in `packages/cli/app/Services/GatewayCliAdapter.php`.

## Deployment System

Orbit provides cross-node deployment orchestration with release-based zero-downtime deployments, multi-zone Cloudflare DNS management, and centralized tracking — all driven via MCP tools or CLI.

### How to Deploy a Project to Production

**This is the recommended workflow.** No Docker, no Spin, no manual SSH.

1. **Register the project on the gateway** (once):
   ```
   MCP: gateway_register_project(name: "My App", production_domain: "myapp.nl")
   CLI: orbit project:register
   ```
   Auto-detects Cloudflare zone from the domain. Creates a `GatewayProject` record.

2. **Deploy to a node**:
   ```
   MCP: gateway_deploy(project_slug: "my-app", node_id: 3)
   ```
   - Production/staging nodes: Uses `project:deploy` (release-based, zero-downtime)
   - Development nodes: Uses `project:create` (direct)
   - Auto-creates Cloudflare DNS record pointing to node's `external_host`
   - Tracks deployment in `Deployment` model

3. **Subsequent deploys** (redeploy/update):
   ```
   MCP: gateway_deploy(project_slug: "my-app", node_id: 3, clone: "org/repo")
   ```
   Creates new timestamped release, runs build steps, atomic symlink switch.

4. **Remove a deployment**:
   ```
   MCP: gateway_undeploy(deployment_id: 42)
   ```
   Deletes project on node, cleans up Cloudflare DNS, marks as removed.

### Deployment Strategies

**`DeploymentService`** auto-selects strategy based on `Node->environment`:

| Environment | CLI Command | Strategy |
|-------------|-------------|----------|
| Production | `project:deploy` | Release-based (zero-downtime) |
| Staging | `project:deploy` | Release-based (zero-downtime) |
| Development | `project:create` | Direct (in-place) |

### Release-Based Deployment (`project:deploy`)

Zero-downtime deployments with timestamped releases and atomic symlink switching:

```
~/projects/{slug}/
├── releases/
│   ├── 20260215_143022/     # Previous release
│   └── 20260215_150134/     # Current release
├── current -> releases/20260215_150134   # Atomic symlink
├── .env                     # Shared across releases
├── storage/                 # Shared across releases
└── database/                # Shared across releases
```

**First deploy**: Creates structure, clones repo, bootstraps `.env`, runs full provision pipeline, creates `current` symlink.

**Subsequent deploys**: Clones into new release, symlinks shared resources, runs build steps, atomic switch of `current` symlink. Keeps 5 recent releases for rollback.

### Multi-Zone Cloudflare DNS

Each `GatewayProject` links to a specific Cloudflare zone. `CloudflareService` supports per-zone operations:

- **Zone detection**: `detectZoneForDomain("srpm.nl")` finds the best matching zone via longest suffix match
- **Domain derivation**: Production nodes get `GatewayProject->production_domain`, dev/staging get `{slug}.{tld}`
- **Auto-setup on deploy**: Creates A record → `node->external_host`, stores `cloudflare_record_id` in deployment
- **Auto-cleanup on undeploy**: Deletes DNS record

### Key Models

| Model | Purpose |
|-------|---------|
| **GatewayProject** | Project registry: slug, production_domain, cloudflare_zone_id |
| **Deployment** | Per-node instance: project_slug, node_id, status, domain, cloudflare_record_id |
| **Node** | Server: environment (dev/staging/prod), external_host, tld |

### MCP Gateway Tools (Deployment)

| Tool | Purpose |
|------|---------|
| `gateway_register_project` | Register project with zone auto-detection |
| `gateway_deploy` | Deploy to node (project-based or legacy) |
| `gateway_undeploy` | Remove deployment + DNS cleanup |
| `gateway_sync_node` | Discover existing projects on a node |
| `gateway_deployments` | List/filter deployments |
| `gateway_projects` | List registered projects |
| `gateway_nodes` | List nodes with deployment counts |
| `gateway_cloudflare_*` | DNS record management (zones, add, remove, list) |

### Key Files

| File | Purpose |
|------|---------|
| `packages/core/src/Services/DeploymentService.php` | Deployment orchestration |
| `packages/cli/app/Commands/ProjectDeployCommand.php` | Release-based deployment CLI |
| `packages/core/src/Models/GatewayProject.php` | Project registry model |
| `packages/core/src/Models/Deployment.php` | Deployment tracking model |
| `packages/core/src/Enums/DeploymentStatus.php` | Status: Pending → Active/Failed/Removed |
| `packages/core/src/Services/CloudflareService.php` | Multi-zone DNS management |
| `packages/app/src/Mcp/GatewayServer.php` | All gateway MCP tools |

## Reference Documentation

Detailed documentation is organized in `docs/reference/`:

| Document | Contents |
|----------|----------|
| [architecture.md](docs/reference/architecture.md) | PHP-FPM diagram, communication patterns, direct API calls, key services, models, async provisioning |
| [database.md](docs/reference/database.md) | NativePHP dual database setup, migration gotchas |
| [routes.md](docs/reference/routes.md) | All route listings |
| [common-tasks.md](docs/reference/common-tasks.md) | SSH issues, provisioning issues, DNS resolver, worktrees, Touch ID, host services, asset publishing |
| [ui-guidelines.md](docs/reference/ui-guidelines.md) | Color palette, layouts, tables, buttons, forms, badges, spacing |
| [orchestrator-development.md](docs/reference/orchestrator-development.md) | Orchestrator changes, key patterns, Waymaker routing |
| [cli-development.md](docs/reference/cli-development.md) | Making CLI changes, building, release workflow, desktop-CLI communication |
| [e2e-testing.md](docs/reference/e2e-testing.md) | Desktop flow test, WebSocket broadcasting architecture |
| [mcp-servers.md](docs/reference/mcp-servers.md) | OrbitServer, GatewayServer tools/resources, conditional registration, gateway deployment |

## Development

```bash
# Install dependencies
composer install
npm install

# Run the app in development
php artisan native:serve

# Build for production
php artisan native:build

# Run migrations (IMPORTANT: run both!)
php artisan migrate                           # Standard database
php artisan migrate --database=nativephp      # NativePHP database

# Run tests
php artisan test
```

## Related Projects

- **orbit-cli**: The command-line tool this app controls (static binary, PHP embedded - no system PHP needed)
    - Source: `packages/cli/` in this monorepo
    - Releases: `https://github.com/hardimpactdev/orbit-cli/releases`
    - Update: `orbit upgrade` (self-updates to latest platform binary)

- **orchestrator**: Laravel API backend for cross-project management
    - Source: `ssh orbit@ai:~/projects/orchestrator/`
    - Provides MCP tools for git, project, and task management
    - Desktop connects via `orchestrator_url` setting

## Known Issues

- **NativePHP database sync**: Migrations must be run on BOTH databases. See [docs/reference/database.md](docs/reference/database.md).
- **"No such table" errors**: Usually means the NativePHP database hasn't been migrated.
- **PHP-FPM socket permissions**: Ensure the orbit user has proper permissions on `~/.config/orbit/php/` directory
- **Horizon service not starting**: Check systemd logs with `journalctl -u orbit-horizon` (Linux) or `launchctl list | grep horizon` (macOS)
- **Web app .env hostnames**: When Horizon runs on host (not Docker), use `localhost` for `REDIS_HOST` and `REVERB_HOST` instead of Docker container names like `orbit-redis`
- **`orbit restart` stops Caddy/Horizon**: The CLI's restart command currently stops but doesn't restart host services. Manually restart with `sudo systemctl start caddy orbit-horizon` (Linux)
- **Bun install hangs**: Fixed in CLI v0.0.17+ with `CI=1` and `--no-progress` flags. Update CLI if experiencing this issue.
- **Gateway Caddy 502**: Caddy runs as user `caddy`. If PHP-FPM socket is owned by `gateway:gateway` with 0660 permissions, Caddy can't access it. Fix: `sudo usermod -aG gateway caddy && sudo systemctl restart caddy`
- **Gateway vendor directory**: Composer path repos with `symlink: false` mirror files into vendor. `composer update` does NOT re-copy when the version string is unchanged (e.g. `0.1.99-dev`). Fix: `rm -rf vendor/hardimpactdev/orbit-core vendor/hardimpactdev/orbit-app && composer install --no-dev`. See `docs/solutions/infrastructure/gateway-path-repo-stale-vendor-composer-20260214.md`.
- **MCP tool schema API**: Use `return ['name' => $schema->string()->required()->description('...')]` (flat array). Do NOT use `$schema->object([...])->required(['name'])->toArray()` - that API was removed.
- **Monorepo build race condition**: The `build-cli.yml` and `split.yml` workflows both trigger on `v*` tag push. The CLI build uses a path repository (`../core`) to ensure it always gets the correct orbit-core version. Do NOT change this back to Packagist resolution - see `docs/solutions/build-errors/monorepo-build-race-condition-orbit-core-20260213.md`.
- **CI must use path repositories**: All CI jobs that depend on sibling packages (cli->core, app->core, web->core+app) must use `composer config repositories.{pkg} path ../{sibling}` instead of resolving from Packagist. Packagist versions lag behind the monorepo and cause bootstrap failures. See `docs/solutions/build-errors/cli-ci-stale-orbit-core-router-crash-20260213.md`.
- **Phar self-upgrade shutdown crash**: The UpgradeCommand uses `exit(0)` after launching the deferred replacement script. This is intentional - normal `return` causes zlib errors during PHP shutdown. See `docs/solutions/runtime-errors/phar-self-upgrade-zlib-crash-20260213.md`.
- **MCP tools fail with "No local node configured"**: `Node::getSelf()` returns the node where `is_default = true`. If no node has this flag set, all MCP tools fail. Fix: set `is_default = true` on the local node record.
- **ProjectScanner conditionally sets array keys**: `domain`, `url`, `secure` are only set on projects with a `public/` folder. Always use null coalescing (`$project['domain'] ?? null`) when accessing these keys.
- **Caddy production domains need ACME override**: The orbit Caddyfile uses global `local_certs` for dev TLDs. Production domains MUST add `tls { issuer acme }` to their block, otherwise they get self-signed certs. See `docs/solutions/infrastructure/caddy-local-certs-blocks-acme-production-20260214.md`.
- **Case-sensitive filenames on Linux**: macOS is case-insensitive, Linux is not. Renaming `Home.vue` → `home.vue` on macOS won't be tracked by git. Use `git mv` with a temp name to force it. Always verify Inertia page filenames match controller references before deploying to Linux.
- **Custom Caddy configs survive regeneration**: Production domains, reverse proxies, and any non-orbit-managed Caddy blocks MUST be placed in `~/.config/orbit/caddy/sites/*.caddy` files. The CaddyfileGenerator imports these at the end. Never append custom blocks directly to the Caddyfile — `caddy:reload` regenerates it from scratch. See `docs/solutions/infrastructure/caddy-reload-wipes-custom-sites-20260215.md`.
- **Local phar build needs vendor symlink replaced**: In the monorepo, `packages/cli/vendor/hardimpactdev/orbit-core` is a symlink that `box compile` doesn't follow. Before building: `rm vendor/hardimpactdev/orbit-core && cp -R ../../packages/core vendor/hardimpactdev/orbit-core`. Restore after: `rm -rf vendor/hardimpactdev/orbit-core && ln -s ../../../core vendor/hardimpactdev/orbit-core`. See `docs/solutions/build-errors/phar-build-vendor-symlink-orbit-core-20260215.md`.
- **Release-based deployments on production**: Production projects use `project:deploy` (not `project:create`) with timestamped releases and atomic symlink switching. Structure: `~/projects/{slug}/releases/{timestamp}/`, `current` symlink, shared `.env`/`storage`/`database` at base. `DeploymentService` auto-selects the command based on node environment.
- **`gateway_sync_node` silently fails**: The tool returns `{"success": false, "error": ""}` with no useful message. Until fixed, manually register projects and create deployment records for pre-existing deployments. See `docs/solutions/database-issues/production-node-inactive-missing-registrations-20260215.md`.
