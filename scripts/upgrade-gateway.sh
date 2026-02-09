#!/bin/bash
set -e

# Gateway Upgrade Script
# Upgrades gateway from minimal (DNS+VPN) to full hub-and-spoke architecture
# Adds: Orbit CLI, PHP-FPM, Caddy, Horizon, Docker services

BLUE='\033[0;34m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}==>${NC} $1"
}

log_success() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

check_prerequisites() {
    log_info "Checking prerequisites..."

    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed"
        exit 1
    fi

    if ! command -v sudo &> /dev/null; then
        log_error "sudo is not available"
        exit 1
    fi

    # Check OS
    if [ ! -f /etc/os-release ]; then
        log_error "Cannot determine OS"
        exit 1
    fi

    . /etc/os-release
    if [ "$ID" != "ubuntu" ]; then
        log_error "This script only supports Ubuntu (found: $ID)"
        exit 1
    fi

    log_success "Prerequisites check passed (Ubuntu $VERSION_ID)"
}

install_orbit_cli() {
    log_info "Installing Orbit CLI..."

    if [ -f ~/.local/bin/orbit ]; then
        log_warn "Orbit CLI already exists, backing up..."
        mv ~/.local/bin/orbit ~/.local/bin/orbit.backup.$(date +%s)
    fi

    mkdir -p ~/.local/bin

    if curl -fsSL -o ~/.local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar; then
        chmod +x ~/.local/bin/orbit

        # Verify
        if ~/.local/bin/orbit --version &> /dev/null; then
            log_success "Orbit CLI installed: $(~/.local/bin/orbit --version | head -1)"
        else
            log_error "Orbit CLI installation failed (not executable)"
            exit 1
        fi
    else
        log_error "Failed to download Orbit CLI"
        exit 1
    fi
}

install_php() {
    log_info "Installing PHP 8.4 and 8.5..."

    # Check if already installed
    if dpkg -l | grep -q "php8.5-fpm"; then
        log_warn "PHP 8.5 already installed, skipping..."
        return 0
    fi

    # Add Ondřej PPA
    log_info "Adding Ondřej PHP PPA..."
    sudo apt-get update -qq
    sudo apt-get install -y software-properties-common &> /dev/null
    sudo add-apt-repository -y ppa:ondrej/php &> /dev/null
    sudo apt-get update -qq

    # Install PHP 8.5
    log_info "Installing PHP 8.5..."
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
        php8.5-zip &> /dev/null

    # Install PHP 8.4
    log_info "Installing PHP 8.4..."
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
        php8.4-zip &> /dev/null

    log_success "PHP installed"
}

configure_php_fpm() {
    log_info "Configuring PHP-FPM pools..."

    mkdir -p ~/.config/orbit/php

    # PHP 8.5 pool
    cat > ~/.config/orbit/php/php85-fpm.conf <<EOF
[orbit-php85]
user = $(whoami)
group = $(whoami)
listen = $HOME/.config/orbit/php/php85.sock
listen.owner = $(whoami)
listen.group = $(whoami)
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
EOF

    # PHP 8.4 pool
    cat > ~/.config/orbit/php/php84-fpm.conf <<EOF
[orbit-php84]
user = $(whoami)
group = $(whoami)
listen = $HOME/.config/orbit/php/php84.sock
listen.owner = $(whoami)
listen.group = $(whoami)
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
EOF

    # Link to system configs
    sudo ln -sf ~/.config/orbit/php/php85-fpm.conf /etc/php/8.5/fpm/pool.d/orbit.conf
    sudo ln -sf ~/.config/orbit/php/php84-fpm.conf /etc/php/8.4/fpm/pool.d/orbit.conf

    # Restart PHP-FPM
    sudo systemctl restart php8.5-fpm php8.4-fpm
    sudo systemctl enable php8.5-fpm php8.4-fpm &> /dev/null

    # Verify sockets were created
    sleep 2
    if [ -S ~/.config/orbit/php/php85.sock ] && [ -S ~/.config/orbit/php/php84.sock ]; then
        log_success "PHP-FPM pools configured and running"
    else
        log_error "PHP-FPM sockets not created"
        exit 1
    fi
}

install_caddy() {
    log_info "Installing Caddy..."

    if command -v caddy &> /dev/null; then
        log_warn "Caddy already installed, skipping..."
        return 0
    fi

    sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl &> /dev/null

    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | \
        sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg

    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | \
        sudo tee /etc/apt/sources.list.d/caddy-stable.list > /dev/null

    sudo apt-get update -qq
    sudo apt-get install -y caddy &> /dev/null

    # Create Caddyfile structure
    mkdir -p ~/.config/orbit/caddy/sites

    cat > ~/.config/orbit/caddy/Caddyfile <<EOF
# Gateway Caddyfile
# Import site-specific configs as they're added
import $HOME/.config/orbit/caddy/sites/*.caddy
EOF

    # Link to system Caddyfile
    sudo tee /etc/caddy/Caddyfile > /dev/null <<EOF
import $HOME/.config/orbit/caddy/Caddyfile
EOF

    # Restart Caddy
    sudo systemctl enable caddy &> /dev/null
    sudo systemctl restart caddy

    log_success "Caddy installed and configured"
}

install_horizon() {
    log_info "Setting up Horizon systemd service..."

    if systemctl is-active --quiet orbit-horizon; then
        log_warn "Horizon service already running, skipping..."
        return 0
    fi

    sudo tee /etc/systemd/system/orbit-horizon.service > /dev/null <<EOF
[Unit]
Description=Orbit Horizon Queue Worker
After=network.target

[Service]
Type=simple
User=$(whoami)
WorkingDirectory=$HOME/.config/orbit
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

    sudo systemctl daemon-reload
    sudo systemctl enable orbit-horizon &> /dev/null
    sudo systemctl start orbit-horizon

    # Verify it started
    sleep 2
    if systemctl is-active --quiet orbit-horizon; then
        log_success "Horizon service installed and running"
    else
        log_warn "Horizon service installed but not running (may need Laravel app)"
    fi
}

setup_docker_network() {
    log_info "Setting up Docker network..."

    if docker network inspect orbit &> /dev/null; then
        log_warn "Docker network 'orbit' already exists, skipping..."
        return 0
    fi

    docker network create orbit
    log_success "Docker network 'orbit' created"
}

deploy_docker_services() {
    log_info "Deploying additional Docker services..."

    # Check if services already exist
    if docker ps -a --format '{{.Names}}' | grep -q "^orbit-redis$"; then
        log_warn "Docker services already deployed, skipping..."
        return 0
    fi

    cat > ~/.config/orbit/docker-compose.services.yml <<EOF
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
      - "127.0.0.1:8025:8025"
    restart: unless-stopped

  reverb:
    image: dunglas/frankenphp:latest-php8.3
    container_name: orbit-reverb
    networks:
      - orbit
    ports:
      - "127.0.0.1:8080:8080"
    restart: unless-stopped

volumes:
  postgres-data:
EOF

    cd ~/.config/orbit
    docker compose -f docker-compose.services.yml up -d

    log_success "Docker services deployed"
}

run_health_checks() {
    log_info "Running health checks..."

    local failed=0

    # Check PHP-FPM
    if systemctl is-active --quiet php8.5-fpm && systemctl is-active --quiet php8.4-fpm; then
        log_success "PHP-FPM services running"
    else
        log_error "PHP-FPM services not running"
        failed=1
    fi

    # Check Caddy
    if systemctl is-active --quiet caddy; then
        log_success "Caddy service running"
    else
        log_error "Caddy service not running"
        failed=1
    fi

    # Check Horizon (may not be running if no Laravel app)
    if systemctl is-active --quiet orbit-horizon; then
        log_success "Horizon service running"
    else
        log_warn "Horizon service not running (may need Laravel app)"
    fi

    # Check Docker services
    local expected_services=("orbit-postgres" "orbit-redis" "orbit-mailpit" "orbit-reverb" "dnsmasq" "wg-easy")
    local running_services=$(docker ps --format '{{.Names}}')

    for service in "${expected_services[@]}"; do
        if echo "$running_services" | grep -q "^${service}$"; then
            log_success "Docker service running: $service"
        else
            log_warn "Docker service not running: $service"
        fi
    done

    # Check Orbit CLI
    if command -v orbit &> /dev/null; then
        log_success "Orbit CLI available: $(orbit --version | head -1)"
    else
        log_error "Orbit CLI not available"
        failed=1
    fi

    if [ $failed -eq 0 ]; then
        log_success "All health checks passed!"
    else
        log_error "Some health checks failed"
        return 1
    fi
}

print_summary() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║          Gateway Upgrade Complete!                         ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
    echo "Installed Components:"
    echo "  ✓ Orbit CLI: $(orbit --version | head -1)"
    echo "  ✓ PHP-FPM: 8.4, 8.5 (Unix sockets)"
    echo "  ✓ Caddy: Web server with auto-HTTPS"
    echo "  ✓ Horizon: Queue worker for async jobs"
    echo "  ✓ Docker: orbit network + services"
    echo ""
    echo "Preserved Components:"
    echo "  ✓ DNS: dnsmasq (port 53)"
    echo "  ✓ VPN: wg-easy (ports 51820/51821)"
    echo ""
    echo "Next Steps:"
    echo "  1. Update database: orbit node:add $(hostname -I | awk '{print $1}') --type=gateway"
    echo "  2. Test services: orbit status"
    echo "  3. Deploy a test site: orbit site:create test-site"
    echo ""
}

# Main execution
main() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║          Gateway Upgrade Script                            ║"
    echo "║          From: Minimal (DNS+VPN)                           ║"
    echo "║          To: Full Hub-and-Spoke Architecture               ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""

    check_prerequisites
    install_orbit_cli
    install_php
    configure_php_fpm
    install_caddy
    install_horizon
    setup_docker_network
    deploy_docker_services

    echo ""
    run_health_checks

    print_summary
}

# Run main function
main
