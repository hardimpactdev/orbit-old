---
date: 2026-02-15
problem_type: infrastructure
component: MCP Server / Web App Deployment
severity: medium
symptoms:
  - "MCP tools not updating after package changes"
  - "Gateway has old code despite CLI being updated"
  - "Need to manually patch vendor files"
root_cause: "Gateway web app not in deployment workflow, lacks composer"
tags: [mcp, deployment, gateway, web-app, composer]
---

# Gateway MCP Server Requires Manual Vendor Patching

## Symptom

After releasing a new version of `orbit-app` with MCP server changes:

1. ✓ CLI updated to v0.1.109 (`orbit --version` shows correct version)
2. ✗ Gateway MCP tools don't reflect changes (still showing old behavior)
3. ✗ `/mcp` reconnect doesn't help

**Example**: Added `$defaultPaginationLength = 50` to `GatewayServer.php` in v0.1.109, but gateway MCP still returns 15 tools.

## Investigation

### Attempted: Restart MCP connection
```
/mcp
Reconnect to gateway
```
**Result**: No change. MCP server still uses old code.

### Attempted: Check CLI version
```bash
ssh gateway@gateway "~/.local/bin/orbit --version"
# v0.1.109 ✓
```
**Result**: CLI is updated, but MCP server isn't.

### Root Cause Found

**Key realization**: MCP servers run from a **web application**, not the CLI!

```
Laravel Zero (orbit-cli)
└── Commands, no MCP support ✓

Laravel Full (orbit-app + orbit-web)
└── MCP Servers (GatewayServer, OrbitServer) ✓
```

The MCP endpoints are served by a web app at:
- `POST https://orbit.gateway/mcp/gateway` ← Web app, not CLI
- `POST https://orbit.gateway/mcp/orbit` ← Web app, not CLI

**The CLI and web app are separate deployments!**

### Where is the Gateway Web App?

```bash
ssh gateway@gateway "cat ~/.config/orbit/caddy/sites/orbit-web.caddy"
# http://orbit.gateway {
#     root * /home/gateway/.config/orbit/web/public
#     php_fastcgi unix//home/gateway/.config/orbit/php/php85.sock
# }
```

**Location**: `/home/gateway/.config/orbit/web/`

### The Problem

1. Composer not installed on gateway
2. Web app deployed pre-built (vendor/ directory included)
3. No deployment workflow to update web app packages
4. Changes to `orbit-app` package don't propagate to gateway

## Solution (Temporary Workaround)

**Manual patch** the vendor files directly:

```bash
ssh gateway@gateway

# Add the pagination fix directly to GatewayServer
sed -i '/protected string \$version/a\    public int \$defaultPaginationLength = 50;' \
  ~/.config/orbit/web/vendor/hardimpactdev/orbit-app/src/Mcp/GatewayServer.php

# Verify
grep -A 2 'protected string $version' \
  ~/.config/orbit/web/vendor/hardimpactdev/orbit-app/src/Mcp/GatewayServer.php
# protected string $version = '1.0.0';
# public int $defaultPaginationLength = 50;  ← Added ✓
```

**Limitation**: This is overwritten if `vendor/` is ever rebuilt.

## Proper Solution (Needs Implementation)

### Option 1: Install Composer on Gateway

```bash
ssh gateway@gateway

# Install composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

# Update packages
cd ~/.config/orbit/web
composer update hardimpactdev/orbit-app --no-dev
```

**Pros**: Standard workflow
**Cons**: Requires composer on production gateway

### Option 2: Build Deployment Package Locally

```bash
# On dev machine
cd ~/orbit-monorepo/packages/web
composer install --no-dev --optimize-autoloader
bun run build

# Create deployment archive
tar -czf orbit-web.tar.gz vendor/ public/build/

# Deploy to gateway
scp orbit-web.tar.gz gateway@gateway:/tmp/
ssh gateway@gateway "cd ~/.config/orbit/web && tar -xzf /tmp/orbit-web.tar.gz"
```

**Pros**: No composer needed on gateway
**Cons**: Manual process, easy to forget

### Option 3: Automated Gateway Deployment

Add gateway web app to CI/CD:

```yaml
# .github/workflows/deploy-gateway-web.yml
name: Deploy Gateway Web App

on:
  push:
    tags:
      - "v*"

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Install dependencies
        run: |
          cd packages/web
          composer install --no-dev --optimize-autoloader

      - name: Build assets
        run: |
          cd packages/app
          bun install
          bun run build

      - name: Deploy to gateway
        run: |
          ssh gateway@gateway "mkdir -p ~/deployments/orbit-web"
          rsync -avz packages/web/ gateway@gateway:~/deployments/orbit-web/
```

**Pros**: Automated, version-tracked
**Cons**: Requires SSH deploy keys, more complex

## Prevention

### Web App Update Checklist

When updating `orbit-app` package:

- [ ] Release new tag (triggers CLI build)
- [ ] Update gateway web app (no automated process yet)
- [ ] Restart PHP-FPM on gateway (if opcache)
- [ ] Test MCP endpoints work (`/mcp` reconnect)

### Warning Signs

- MCP tools don't reflect code changes
- `/mcp` reconnect doesn't help
- CLI version correct but MCP server has old behavior
- Vendor directory out of sync with package versions

### Proper Architecture

**Current (Broken)**:
```
orbit-cli (v0.1.109) ✓
    └── Updated via GitHub release

orbit-web on gateway (v0.1.??) ✗
    └── Manual updates required
```

**Desired**:
```
orbit-cli (v0.1.109) ✓
    └── Auto-updated via GitHub release

orbit-web on gateway (v0.1.109) ✓
    └── Auto-deployed via CI/CD or deployment command
```

## Related

- **Issue**: platform11 MCP tools not visible after v0.1.109 release
- **MCP Pagination Fix**: Added in v0.1.109 but required manual patch
- **Gateway Architecture**: Web app separate from CLI
- **Deployment Gap**: No workflow for gateway web app updates

## Files Involved

- `/home/gateway/.config/orbit/web/` - Gateway web app root
- `/home/gateway/.config/orbit/web/vendor/hardimpactdev/orbit-app/` - Package location
- `~/.config/orbit/caddy/sites/orbit-web.caddy` - Caddy configuration

## Action Items

| Priority | Task | Owner |
|----------|------|-------|
| P1 | Install composer on gateway | Infrastructure |
| P2 | Document gateway web app update process | Documentation |
| P3 | Automate gateway web app deployment | DevOps |
| P3 | Add gateway web app to CI/CD | DevOps |

## Recommendation

**Short-term**: Install composer on gateway, document update process

**Long-term**: Add gateway web app to release workflow:
```bash
orbit gateway:update  # Command to update gateway web app
```

This should:
1. SSH to gateway
2. Pull latest orbit-app package
3. Rebuild vendor if needed
4. Restart PHP-FPM
5. Verify MCP endpoints respond
