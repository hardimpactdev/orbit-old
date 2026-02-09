# Gateway Node Upgrade Plan

**Date:** 2026-02-09
**Gateway:** hetzner (188.245.156.201)
**Status:** Needs upgrade from minimal to full hub-and-spoke architecture

## Current State Assessment

### ✅ Already Configured
- **OS:** Ubuntu 24.04.3 LTS (Noble Numbat)
- **User:** gateway (sudo access)
- **Docker:** Installed and running
- **DNS:** dnsmasq container running (port 53)
- **VPN:** wg-easy container running (ports 51820/udp, 51821/tcp)
- **Config:** `~/.config/orbit/` directory exists
- **Database:** `~/.config/orbit/database.sqlite` exists

### ❌ Missing Components
- **Orbit CLI:** Not installed at `~/.local/bin/orbit`
- **Docker Network:** `orbit` network doesn't exist
- **PHP-FPM:** Not installed (need 8.4, 8.5 pools)
- **Caddy:** Not installed (web server)
- **Horizon:** Not installed (queue worker)
- **Additional Docker Services:** Redis, Postgres, Mailpit, Reverb

### Database State
```
Local nodes table:
- id: 3, name: "Test Gateway", host: 203.0.113.100, type: gateway (test record)

Local gateways table:
- id: 2, name: "hetzner", ip: 188.245.156.201, user: "gateway", subnet: 10.6.0.0/24
```

**Issue:** No corresponding node record for the real gateway at 188.245.156.201.

## Required Changes

### 1. Database Updates

**Create Node Record for Gateway:**
```bash
# On local machine
sqlite3 ~/.config/orbit/database.sqlite "
INSERT INTO nodes (name, host, user, port, node_type, status, is_default, created_at, updated_at)
VALUES ('hetzner', '188.245.156.201', 'gateway', 22, 'gateway', 'active', 0, datetime('now'), datetime('now'));
"
```

**Update Gateway Record:**
```bash
# Change ssh_user from 'gateway' to 'orbit' (if we create orbit user)
# OR keep as 'gateway' but update node type logic
```

### 2. Install Missing Software

**On Gateway (188.245.156.201):**

#### A. Install Orbit CLI
```bash
# Download latest release
curl -L -o ~/.local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar
chmod +x ~/.local/bin/orbit

# OR build from local source and upload
# On local: ~/.composer/vendor/bin/box compile
# scp builds/orbit.phar gateway@188.245.156.201:~/.local/bin/orbit
```

#### B. Install PHP 8.4 and 8.5
```bash
# Add Ondřej PPA
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update

# Install PHP 8.5 (primary)
sudo apt-get install -y \
  php8.5-fpm \
  php8.5-cli \
  php8.5-common \
  php8.5-sqlite3 \
  php8.5-mbstring \
  php8.5-xml \
  php8.5-curl \
  php8.5-pgsql \
  php8.5-redis \
  php8.5-zip

# Install PHP 8.4 (secondary)
sudo apt-get install -y \
  php8.4-fpm \
  php8.4-cli \
  php8.4-common \
  php8.4-sqlite3 \
  php8.4-mbstring \
  php8.4-xml \
  php8.4-curl \
  php8.4-pgsql \
  php8.4-redis \
  php8.4-zip
```

#### C. Configure PHP-FPM Pools
```bash
# Create PHP-FPM configs at ~/.config/orbit/php/

# For PHP 8.5
cat > ~/.config/orbit/php/php85-fpm.conf <<'EOF'
[orbit-php85]
user = gateway
group = gateway
listen = /home/gateway/.config/orbit/php/php85.sock
listen.owner = gateway
listen.group = gateway
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
EOF

# For PHP 8.4
cat > ~/.config/orbit/php/php84-fpm.conf <<'EOF'
[orbit-php84]
user = gateway
group = gateway
listen = /home/gateway/.config/orbit/php/php84.sock
listen.owner = gateway
listen.group = gateway
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
EOF

# Link configs to system
sudo ln -sf /home/gateway/.config/orbit/php/php85-fpm.conf /etc/php/8.5/fpm/pool.d/orbit.conf
sudo ln -sf /home/gateway/.config/orbit/php/php84-fpm.conf /etc/php/8.4/fpm/pool.d/orbit.conf

# Restart PHP-FPM
sudo systemctl restart php8.5-fpm php8.4-fpm
sudo systemctl enable php8.5-fpm php8.4-fpm
```

#### D. Install Caddy
```bash
# Install Caddy
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install -y caddy

# Create Caddyfile
mkdir -p ~/.config/orbit/caddy
cat > ~/.config/orbit/caddy/Caddyfile <<'EOF'
# Gateway Caddyfile
# Import site-specific configs here as they're added
import /home/gateway/.config/orbit/caddy/sites/*.caddy
EOF

# Create sites directory
mkdir -p ~/.config/orbit/caddy/sites

# Link to system Caddyfile
sudo tee /etc/caddy/Caddyfile > /dev/null <<'EOF'
import /home/gateway/.config/orbit/caddy/Caddyfile
EOF

# Start Caddy
sudo systemctl enable caddy
sudo systemctl start caddy
```

#### E. Install Horizon Systemd Service
```bash
# Create systemd service
sudo tee /etc/systemd/system/orbit-horizon.service > /dev/null <<'EOF'
[Unit]
Description=Orbit Horizon Queue Worker
After=network.target

[Service]
Type=simple
User=gateway
WorkingDirectory=/home/gateway/.config/orbit
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

# Enable and start
sudo systemctl daemon-reload
sudo systemctl enable orbit-horizon
sudo systemctl start orbit-horizon
```

#### F. Create Docker Network
```bash
# Create orbit network for inter-service communication
docker network create orbit
```

#### G. Deploy Additional Docker Services
```bash
# Create docker-compose.yml at ~/.config/orbit/docker-compose.yml
cat > ~/.config/orbit/docker-compose.yml <<'EOF'
version: '3.8'

networks:
  orbit:
    external: true

services:
  postgres:
    image: postgres:16-alpine
    container_name: orbit-postgres
    networks:
      - orbit
    environment:
      POSTGRES_USER: orbit
      POSTGRES_PASSWORD: orbit
      POSTGRES_DB: orbit
    volumes:
      - postgres-data:/var/lib/postgresql/data
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    container_name: orbit-redis
    networks:
      - orbit
    restart: unless-stopped

  mailpit:
    image: axllent/mailpit:latest
    container_name: orbit-mailpit
    networks:
      - orbit
    ports:
      - "8025:8025"
    restart: unless-stopped

  reverb:
    image: dunglas/frankenphp:latest-php8.3
    container_name: orbit-reverb
    networks:
      - orbit
    ports:
      - "8080:8080"
    restart: unless-stopped

volumes:
  postgres-data:
EOF

# Start services
cd ~/.config/orbit
docker-compose up -d
```

### 3. Verification Steps

**After Installation:**

```bash
# 1. Check PHP-FPM
systemctl status php8.5-fpm php8.4-fpm
ls -la ~/.config/orbit/php/*.sock

# 2. Check Caddy
systemctl status caddy
curl -I http://localhost

# 3. Check Horizon
systemctl status orbit-horizon

# 4. Check Docker services
docker ps | grep orbit-
docker network inspect orbit

# 5. Check Orbit CLI
orbit --version
orbit status --json

# 6. Check DNS and VPN (already working)
docker ps | grep -E "(dnsmasq|wg-easy)"
ss -tuln | grep 53
```

### 4. Integration Testing

**Test Full Stack:**

```bash
# From local machine:

# 1. Add gateway node to database (if not already done)
orbit node:add 188.245.156.201 --type=gateway --name=hetzner --user=gateway

# 2. Test connection
ssh gateway@188.245.156.201 'orbit status --json'

# 3. Deploy a test site to verify Caddy + PHP-FPM
ssh gateway@188.245.156.201 'orbit site:create test-site --template=laravel'

# 4. Check if site is accessible
curl https://test-site.gateway-tld/
```

## Alternative: Automated Upgrade Script

Instead of manual steps, we could create an upgrade script:

```bash
# Create upgrade script
cat > /tmp/upgrade-gateway.sh <<'SCRIPT'
#!/bin/bash
set -e

echo "==> Installing Orbit CLI..."
curl -L -o ~/.local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar
chmod +x ~/.local/bin/orbit

echo "==> Installing PHP..."
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update
sudo apt-get install -y php8.5-{fpm,cli,common,sqlite3,mbstring,xml,curl,pgsql,redis,zip}
sudo apt-get install -y php8.4-{fpm,cli,common,sqlite3,mbstring,xml,curl,pgsql,redis,zip}

echo "==> Configuring PHP-FPM pools..."
mkdir -p ~/.config/orbit/php
# ... (create pool configs) ...

echo "==> Installing Caddy..."
# ... (install caddy) ...

echo "==> Setting up Horizon..."
# ... (create systemd service) ...

echo "==> Creating Docker network..."
docker network create orbit || true

echo "==> Starting services..."
sudo systemctl restart php8.5-fpm php8.4-fpm caddy
sudo systemctl start orbit-horizon

echo "==> Upgrade complete!"
orbit --version
systemctl status php8.5-fpm php8.4-fpm caddy orbit-horizon --no-pager | grep Active
SCRIPT

# Upload and run
scp /tmp/upgrade-gateway.sh gateway@188.245.156.201:/tmp/
ssh gateway@188.245.156.201 'bash /tmp/upgrade-gateway.sh'
```

## Rollback Plan

If something goes wrong:

1. **Stop new services:**
   ```bash
   sudo systemctl stop orbit-horizon caddy php8.5-fpm php8.4-fpm
   docker-compose down
   ```

2. **Keep DNS and VPN running** (don't touch dnsmasq or wg-easy)

3. **Restore database backup** (if needed)

## Timeline

**Estimated time:** 30-45 minutes

1. Database updates: 5 minutes
2. Install PHP: 5 minutes
3. Configure PHP-FPM: 10 minutes
4. Install Caddy: 5 minutes
5. Setup Horizon: 5 minutes
6. Docker services: 5 minutes
7. Testing: 10 minutes

## Success Criteria

- [ ] Orbit CLI installed and working
- [ ] PHP 8.4 and 8.5 FPM pools running
- [ ] Caddy web server running
- [ ] Horizon queue worker running
- [ ] Docker network 'orbit' exists
- [ ] Additional Docker services running (postgres, redis, mailpit, reverb)
- [ ] DNS and VPN still working (dnsmasq, wg-easy)
- [ ] Node record exists in database with node_type='gateway'
- [ ] Can run `orbit status` successfully
- [ ] Can provision a test site

## Notes

- Keep the ssh_user as "gateway" (don't create new "orbit" user)
- Update node type logic to handle "gateway" user
- DNS (10.6.0.0/24 subnet) and VPN are already configured correctly
- Don't modify wg-easy or dnsmasq containers during upgrade
