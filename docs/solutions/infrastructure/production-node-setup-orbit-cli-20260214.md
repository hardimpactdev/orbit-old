---
date: 2026-02-14
problem_type: workflow
component: infrastructure
severity: moderate
symptoms:
  - "Need to set up a new production node for orbit-managed deployments"
root_cause: n/a (new workflow documentation)
tags: [production, node-setup, orbit-cli, deployment]
---

# Production Node Setup with Orbit CLI

## Context

Setting up a new production server (e.g., `hardimpact-prod` at 46.225.89.66) to be managed by orbit. The node needs orbit CLI for local project management, with the gateway orchestrating deployments remotely.

## Prerequisites

- Ubuntu server with SSH access
- PHP-FPM and Caddy already running
- Server accessible via VPN (gateway network)

## Setup Steps

### 1. Install orbit CLI (static binary, no system PHP needed)

```bash
curl -fsSL https://github.com/hardimpactdev/orbit-cli/releases/latest/download/orbit-linux-x86_64 -o /usr/local/bin/orbit
chmod +x /usr/local/bin/orbit
orbit --version
```

### 2. Install build tools

```bash
# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Bun (needs unzip)
sudo apt-get install -y unzip
curl -fsSL https://bun.sh/install | bash
source ~/.bashrc
```

### 3. Initialize orbit

```bash
orbit init
```

This creates:
- `~/.config/orbit/` directory structure
- SQLite database at `~/.config/orbit/orbit.db`
- Caddy config include at `~/.config/orbit/caddy/Caddyfile`

### 4. Run migrations

```bash
orbit migrate --force
```

### 5. Initialize the local node record

```bash
orbit:init --name="node-name"
```

**Critical**: This creates the `Node` record with `is_default = true`. Without this, `Node::getSelf()` returns null and all CLI project commands fail with "No node found. Run 'orbit init' first."

### 6. Configure GitHub access (for cloning private repos)

```bash
# Install gh CLI
sudo apt-get install -y gh

# Authenticate (pipe token from another machine if no browser)
echo "ghp_..." | gh auth login --with-token
```

Using `gh` CLI with HTTPS is simpler than configuring SSH keys on production servers.

### 7. Ensure Caddy imports orbit config

The system Caddyfile (`/etc/caddy/Caddyfile`) must include:

```
import /home/orbit/.config/orbit/caddy/Caddyfile
```

### 8. Deploy a project

```bash
orbit project:create my-app --clone=org/repo --php=8.5 --json
```

### 9. Add production domain to Caddyfile

Append a block for the real domain (no `local_certs` — uses Let's Encrypt):

```
example.com {
    root * /home/orbit/Projects/my-app/public
    encode gzip
    php_fastcgi unix//home/orbit/.config/orbit/php/php85.sock
    file_server
}
```

Then reload: `sudo systemctl reload caddy`

## Gotchas

- **`orbit:init` vs `orbit init`**: `orbit init` creates directories/config. `orbit:init --name=X` creates the Node database record. Both are needed.
- **Bun requires unzip**: Install `unzip` before bun installer.
- **GitHub auth**: Use `gh auth login --with-token` for headless servers. SSH key setup is more complex and unnecessary.
- **Caddy production blocks**: Don't use `tls internal` or `local_certs` for production domains. Let Caddy handle Let's Encrypt automatically.

## Related

- `packages/cli/app/Commands/Install/` (orbit init templates)
- `packages/core/src/Console/Commands/OrbitInit.php` (orbit:init command)
- `docs/solutions/infrastructure/gateway-cli-deploy-workflow-20260207.md`
