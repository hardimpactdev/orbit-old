# Architecture

## PHP-FPM Architecture (Current)

The orbit stack uses **PHP-FPM on the host** (not containerized) with **Caddy** as the web server:

```
┌─────────────────────────────────────────────────────────────────┐
│                        HOST MACHINE                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────────────────────┐   │
│   │  Caddy (host)   │────▶│ PHP-FPM Pools (host)            │   │
│   │  Port 80/443    │     │  ~/.config/orbit/php/       │   │
│   │  Single binary  │     │  ├── php85.sock                 │   │
│   └─────────────────┘     │  └── php84.sock                 │   │
│                           │                                 │   │
│                           └─────────────────────────────────┘   │
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────┐                   │
│   │ Horizon (host)  │     │ Orbit CLI   │                   │
│   │ systemd/launchd │     │ Direct access   │                   │
│   └─────────────────┘     └─────────────────┘                   │
│                                                                  │
│   ┌────────────────────────────────────────────────────┐        │
│   │              Docker Network: orbit              │        │
│   │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐│        │
│   │  │ Postgres │ │  Redis   │ │ Mailpit  │ │ Reverb ││        │
│   │  └──────────┘ └──────────┘ └──────────┘ └────────┘│        │
│   └────────────────────────────────────────────────────┘        │
│                                                                  │
│   ┌─────────────┐                                               │
│   │ DNS (dnsmasq)│ ← Container, host network                    │
│   └─────────────┘                                               │
└─────────────────────────────────────────────────────────────────┘
```

**Benefits of PHP-FPM architecture:**

- Single Caddy instance on host (no dual-Caddy complexity)
- PHP-FPM has direct access to CLI, Bun, git, composer
- Horizon runs natively with full host access
- Simpler debugging and log access

**Services on host:**

- **PHP-FPM**: Multiple pools (8.4, 8.5) with Unix sockets
- **Caddy**: Web server with automatic HTTPS (systemd on Linux, brew services on macOS)
- **Horizon**: Queue worker as systemd (Linux) or launchd (macOS) service

**Services in Docker:**

- PostgreSQL, Redis, Mailpit, Reverb, dnsmasq

## Communication Pattern

- **Local environments**: NativePHP backend → Direct PHP process execution
- **Remote environments**: Vue frontend → Direct HTTP to remote orbit web app API

## Direct API Calls (Important Performance Optimization)

**Problem:** NativePHP uses `php artisan serve` which is single-threaded. When the Vue frontend makes multiple fetch calls through the NativePHP backend, requests are serialized and block each other. This caused slow page navigation (e.g., Dashboard → Projects) because Inertia navigation requests had to wait behind data-fetching API calls.

**Solution:** For remote environments, the Vue frontend calls the remote orbit web app API directly at `https://orbit.{tld}/api/...`, bypassing the NativePHP backend entirely.

```
BEFORE (slow):
Vue → fetch('/api/environments/1/status') → NativePHP (single-threaded) → SSH → CLI

AFTER (fast):
Vue → fetch('https://orbit.ccc/api/status') → Direct to remote server
```

**Implementation:**

1. `EnvironmentController` passes `remoteApiUrl` prop to Vue pages (e.g., `https://orbit.ccc/api`)
2. Vue pages use a `getApiUrl(path)` helper that returns the remote URL when available
3. For local environments or when TLD isn't set, falls back to NativePHP backend
4. The TLD is cached in the `environments.tld` database column

**What uses direct API calls:**

- Dashboard/Show: status, sites, config, worktrees, restart, PHP version, worktree unlink
- Projects page: list, delete, rebuild, PHP version change, provision status
- Services page: status, start/stop/restart (all and individual), logs
- Workspaces: list, delete, workspace details, add/remove projects, linked packages

**What still uses NativePHP backend:**

- SSH connection testing (testConnection) - requires SSH
- Environment provisioning - requires SSH
- Config saving with TLD changes - requires local DNS resolver updates
- CLI installation and updates - requires SSH
- DNS resolver management - requires local sudo
- Opening external URLs/editors - requires Shell::openExternal
- Project creation form - uses Inertia for validation, redirects, and orchestrator integration

**Remote API location:** `~/.config/orbit/web/` on the remote server (installed via `orbit web:install`)

## Key Services

- **SshService** (`app/Services/SshService.php`): Handles SSH connections with ControlMaster pooling. Control sockets stored in `/tmp/orbit-ssh/` to avoid macOS path length limits.

- **LaunchpadService** (`app/Services/LaunchpadService.php`): Wraps orbit CLI commands. Searches multiple paths for the binary (`$HOME/projects/orbit/orbit`, `$HOME/.local/bin/orbit`, etc.).

- **CliUpdateService** (`app/Services/CliUpdateService.php`): Manages the local CLI installation at `~/.local/bin/orbit`.

- **DnsResolverService** (`app/Services/DnsResolverService.php`): Manages macOS DNS resolver files in `/etc/resolver/`. Uses `expect` to spawn sudo with a PTY, enabling Touch ID authentication via `pam_tid.so`. Key methods:
    - `updateResolver(Environment, tld)`: Creates/updates `/etc/resolver/{tld}` pointing to the environment's DNS
    - `removeResolver(tld)`: Removes a resolver file when no longer needed
    - `getManagedResolvers()`: Lists all resolver files managed by Launchpad

- **ProvisioningService** (`app/Services/ProvisioningService.php`): Provisions new environments with the complete Orbit stack. Handles these steps:
    1. Clear old SSH host keys (prevents conflicts when environment is reset)
    2. Test root SSH connection
    3. Create `orbit` user
    4. Setup SSH key for orbit user
    5. Configure passwordless sudo
    6. Secure SSH (disable password auth, disable root login)
    7. Test orbit user connection
    8. Install Docker
    9. Configure DNS (disable systemd-resolved, set to 1.1.1.1)
    10. Add Ondrej PPA for PHP (Linux)
    11. Install PHP-FPM versions (8.4, 8.5)
    12. Configure PHP-FPM pools with Unix sockets
    13. Install Caddy web server
    14. Install orbit CLI from GitHub releases
    15. Create directory structure (`~/projects`)
    16. Initialize orbit stack
    17. Configure Horizon as systemd service
    18. Start orbit services

## Models

- **Node**: Represents a local or remote machine with orbit installed
    - Fields: name, host, user, port, is_local, is_default, orchestrator_url, metadata, last_connected_at
    - Provisioning fields: status, provisioning_log, provisioning_error, provisioning_step, provisioning_total_steps
    - Status values: `provisioning`, `active`, `error`
    - Environment: `development`, `staging`, `production` (NodeEnvironment enum)
    - Relationships: `deployments()` HasMany

- **Deployment**: Tracks a project deployed to a specific node (cross-node registry)
    - Fields: node_id (FK->nodes), project_slug, project_name, github_repo, domain, url, php_version, status, error_message, cloudflare_record_id, metadata
    - Status values: `pending`, `deploying`, `cloning`, `setting_up`, `installing`, `building`, `active`, `failed`, `removed` (DeploymentStatus enum)
    - Unique constraint: `(node_id, project_slug)`
    - Relationship: `node()` BelongsTo

- **Project**: Represents a project tracked across environments
    - Fields: name, github_url

- **Setting**: Key-value store for app settings (editor preference, SSH keys, Cloudflare credentials, etc.)

- **SshKey**: Manages SSH keys for environment provisioning

## Async Project Provisioning

Project creation uses an async workflow via the bundled web app API:

1. **Desktop** submits create project form -> Vue calls `POST https://orbit.{tld}/api/projects`
2. **Web App** (runs via PHP-FPM) -> `ProjectController` dispatches `CreateProjectJob` to Redis queue
3. **Horizon** (runs on HOST as systemd/launchd service) -> Picks up job, calls CLI `orbit provision`
4. **CLI** handles: GitHub repo creation, git clone, composer install, bun install, migrations, Caddy reload
5. **Job** broadcasts status via Reverb (if reachable from host)
6. **Desktop** refreshes project list to see new project

**Architecture note:** The web app runs via PHP-FPM on the host and dispatches jobs to Horizon which also runs on the HOST as a system service. This is critical because:

- CLI needs access to host filesystem (`~/projects/`)
- Bun/Node need proper PATH on the host
- PHP-FPM processes run as the `orbit` user with full host access

**Key files (remote server `~/projects/orbit-cli/web/`):**

- `app/Http/Controllers/Api/ProjectController.php` - Dispatches CreateProjectJob
- `app/Jobs/CreateProjectJob.php` - Runs CLI provision command via Horizon
- `config/horizon.php` - Queue worker configuration (timeout: 120s)

**Key files (CLI `~/projects/orbit-cli/`):**

- `app/Commands/ProvisionCommand.php` - Actual provisioning logic

**Status flow:** `provisioning` -> `creating_repo` -> `cloning` -> `setting_up` -> `installing_composer` -> `installing_npm` -> `building` -> `finalizing` -> `ready`

**Expected timing:** ~20-30 seconds for liftoff-starterkit template

**Testing provisioning:**
Use the `/test-provision` skill for step-by-step debugging, or run the test script:

```bash
bash .claude/scripts/test-provision-flow.sh test-$(date +%s) --cleanup
```

**Common issues:**

- **Job times out**: Check Horizon timeout in `config/horizon.php` (should be 120s)
- **Bun hangs**: CLI now uses `CI=1` and `--no-progress` flags to prevent hanging in non-TTY environments
- **Project not appearing**: Check `~/.config/orbit/web/storage/logs/laravel.log`
- **Reverb broadcast fails**: Web app .env must use `REVERB_HOST=localhost` (not Docker hostname)

## External Integrations

- **Editor Support**: Opens projects via SSH Remote extension
    - URL format: `{editor}://vscode-remote/ssh-remote+user@host/path?windowId=_blank`
    - Supported editors: Cursor, VS Code, VS Code Insiders, Windsurf, Antigravity, Zed

- **Browser**: Uses `Shell::openExternal()` via `/open-external` route to open URLs in system browser
