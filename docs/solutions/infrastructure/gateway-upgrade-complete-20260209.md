# Gateway Node Upgrade - Complete ✅

**Date:** 2026-02-09
**Gateway:** hetzner (188.245.156.201)
**Status:** ✅ Successfully upgraded to full hub-and-spoke architecture

---

## Upgrade Summary

The gateway node has been successfully upgraded from minimal setup (DNS + VPN only) to full hub-and-spoke orchestration architecture.

### Components Installed

#### ✅ Orbit CLI
- **Version:** 0.1.55
- **Location:** `~/.local/bin/orbit`
- **Built with:** `./build-phar.sh` (includes orbit-core)
- **Size:** 69,728,479 bytes (16,368 files)

#### ✅ PHP 8.5
- **CLI:** PHP 8.5.2 (cli) (built: Jan 18 2026)
- **FPM:** Running as systemd service
- **Socket:** `~/.config/orbit/php/php85.sock` (permissions: `srw-rw----`)
- **Pool:** Configured at `~/.config/orbit/php/php85-fpm.conf`
- **User:** gateway
- **PM Settings:** dynamic (max_children=10, start_servers=2)

#### ✅ Caddy
- **Status:** Running (systemd)
- **Port:** 80 (HTTP)
- **Config:** `/etc/caddy/Caddyfile` → `~/.config/orbit/caddy/Caddyfile`
- **Sites Directory:** `~/.config/orbit/caddy/sites/`
- **Test Response:** HTTP 200 OK

#### ⚠️ Horizon
- **Status:** Service configured but not running
- **Service File:** `/etc/systemd/system/orbit-horizon.service`
- **Reason:** Expected - will start when Laravel app is deployed
- **Command:** `sudo systemctl start orbit-horizon` (when needed)

#### ✅ Docker Network
- **Name:** orbit
- **Type:** bridge
- **Purpose:** Inter-service communication

#### ✅ Docker Services

| Service | Container | Status | Purpose |
|---------|-----------|--------|---------|
| Redis | orbit-redis | Running | Queue backend for Horizon |
| Mailpit | orbit-mailpit | Healthy | Mail testing server |
| Reverb | orbit-reverb | Healthy | WebSocket server |
| DNS | dnsmasq | Up 2 days | DNS resolver (preserved) |
| VPN | wg-easy | Up 3 weeks | WireGuard VPN (preserved) |

---

## Health Check Results

```
=== Orbit CLI ===
Orbit 0.1.55

=== PHP 8.5 CLI ===
PHP 8.5.2 (cli) (built: Jan 18 2026 14:12:15) (NTS)

=== PHP 8.5 FPM ===
✓ PHP 8.5 FPM running
✓ PHP 8.5 socket exists

=== Caddy ===
✓ Caddy running
HTTP/1.1 200 OK

=== Horizon ===
⚠ Horizon not running (expected - needs Laravel app)

=== Docker Network ===
✓ Docker network 'orbit' exists

=== Docker Services ===
orbit-reverb    Up 2 minutes (healthy)
orbit-redis     Up 2 minutes
orbit-mailpit   Up 2 minutes (healthy)
dnsmasq         Up 2 days
wg-easy         Up 3 weeks (healthy)
```

---

## Database Integration

Gateway node added to local database:

```json
{
    "id": 4,
    "name": "hetzner",
    "host": "188.245.156.201",
    "user": "gateway",
    "port": 22,
    "node_type": "gateway"
}
```

---

## Preserved Components

The upgrade preserved all existing working components:

- ✅ **DNS:** dnsmasq container (port 53) - Up 2 days
- ✅ **VPN:** wg-easy container (ports 51820/51821) - Up 3 weeks
- ✅ **Config Directory:** `~/.config/orbit/` structure intact
- ✅ **Database:** `~/.config/orbit/database.sqlite` untouched

---

## Architecture Achieved

```
┌─────────────────────────────────────────────────────────────┐
│              Gateway Node (188.245.156.201)                 │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  HOST SERVICES                                              │
│  ├── Orbit CLI 0.1.55                                       │
│  ├── PHP 8.5 CLI (for Horizon)                              │
│  ├── PHP 8.5 FPM (Unix socket)                              │
│  ├── Caddy (web server)                                     │
│  └── Horizon (systemd service - ready for Laravel)          │
│                                                             │
│  DOCKER SERVICES (orbit network)                            │
│  ├── orbit-redis (queue backend)                            │
│  ├── orbit-mailpit (mail testing)                           │
│  ├── orbit-reverb (WebSocket)                               │
│  ├── dnsmasq (DNS resolver)                                 │
│  └── wg-easy (VPN server)                                   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Next Steps

### 1. Deploy Orbit Web App (Optional)
```bash
# SSH into gateway
ssh gateway@188.245.156.201

# Deploy web UI
~/.local/bin/orbit web:install

# Start Horizon
sudo systemctl start orbit-horizon
```

### 2. Add Client Nodes
```bash
# From local machine
php orbit node:add <client-ip> --type=client --name="Client 1"
php orbit node:provision <node-id>
```

### 3. Test Project Provisioning
```bash
# Deploy a test Laravel project to verify full stack
ssh gateway@188.245.156.201
~/.local/bin/orbit project:create test-site --template=laravel
```

---

## Verification Commands

### Check All Services
```bash
ssh gateway@188.245.156.201 'systemctl status php8.5-fpm caddy orbit-horizon --no-pager'
```

### Check Docker Services
```bash
ssh gateway@188.245.156.201 'docker ps --format "table {{.Names}}\t{{.Status}}"'
```

### Test PHP-FPM Socket
```bash
ssh gateway@188.245.156.201 'ls -la ~/.config/orbit/php/*.sock'
```

### Test Caddy
```bash
ssh gateway@188.245.156.201 'curl -I http://localhost'
```

### Test Orbit CLI
```bash
ssh gateway@188.245.156.201 '~/.local/bin/orbit --version'
```

---

## Issues Resolved During Upgrade

### 1. PHAR Build Missing orbit-core
- **Problem:** Direct `box compile` created broken PHAR (4,917 files)
- **Solution:** Used `./build-phar.sh` to properly include orbit-core (16,368 files)

### 2. Caddy Permission Errors
- **Problem:** Caddy couldn't read user config files
- **Solution:** Set proper permissions (755 directories, 644 files) and simplified initial Caddyfile

### 3. User Feedback Iteration
- **Initial:** PHP 8.4 + 8.5 + Postgres
- **Final:** PHP 8.5 only (CLI + FPM), SQLite database, no Postgres

---

## Key File Locations

| Component | Path |
|-----------|------|
| Orbit CLI | `~/.local/bin/orbit` |
| Config Directory | `~/.config/orbit/` |
| PHP-FPM Socket | `~/.config/orbit/php/php85.sock` |
| PHP-FPM Pool Config | `~/.config/orbit/php/php85-fpm.conf` |
| Caddy Config | `~/.config/orbit/caddy/Caddyfile` |
| Caddy Sites | `~/.config/orbit/caddy/sites/*.caddy` |
| System Caddyfile | `/etc/caddy/Caddyfile` |
| Horizon Service | `/etc/systemd/system/orbit-horizon.service` |
| SQLite Database | `~/.config/orbit/database.sqlite` |
| Docker Compose | `~/.config/orbit/docker-compose.services.yml` |

---

## Rollback Plan (if needed)

If issues arise, services can be stopped without affecting DNS/VPN:

```bash
# Stop new services
sudo systemctl stop caddy php8.5-fpm orbit-horizon

# Stop Docker services (preserve DNS/VPN)
cd ~/.config/orbit
docker compose -f docker-compose.services.yml down
```

DNS (dnsmasq) and VPN (wg-easy) will remain running.

---

## Success Criteria ✅

- [x] Orbit CLI installed and working (0.1.55)
- [x] PHP 8.5 CLI available
- [x] PHP 8.5 FPM pool running with Unix socket
- [x] Caddy web server running and responding
- [x] Horizon systemd service configured
- [x] Docker network 'orbit' exists
- [x] Docker services running (Redis, Mailpit, Reverb)
- [x] DNS and VPN still working (dnsmasq, wg-easy)
- [x] Node record exists in database with node_type='gateway'
- [x] Health checks passing

---

## Timeline

**Actual time:** ~60 minutes (including iterations and troubleshooting)

- Phase 1: PHAR build and CLI installation: 15 minutes
- Phase 2: PHP, Caddy, Horizon setup: 30 minutes (including permission fixes)
- Phase 3: Docker services deployment: 5 minutes
- Verification and documentation: 10 minutes

---

## Related Documentation

- [Gateway Upgrade Plan](./gateway-upgrade-plan-20260209.md) - Original assessment and plan
- [Hub-and-Spoke Architecture](../architecture/hub-and-spoke-implementation-20260209.md) - Overall architecture docs
- [Gateway CLI Deploy Workflow](./gateway-cli-deploy-workflow-20260207.md) - Related gateway workflows

---

**Status:** ✅ Gateway upgrade complete and verified
**Next:** Deploy web UI or provision client nodes
