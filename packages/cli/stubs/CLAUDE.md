# Orbit

Local PHP development environment at ~/.config/orbit/

## Commands

```bash
orbit start         # Start all services
orbit stop          # Stop all services
orbit restart       # Restart everything
orbit status        # Check service status
orbit sites         # List all sites
orbit php <site> <version>  # Set PHP version
```

## Direct Docker Access

```bash
# Start/stop individual services
docker compose -f ~/.config/orbit/postgres/docker-compose.yml up -d
docker compose -f ~/.config/orbit/reverb/docker-compose.yml up -d

# View logs
docker logs -f orbit-postgres
docker logs -f orbit-reverb
docker logs -f orbit-redis
```

## Host Services (systemd)

Caddy runs on the host machine via systemd, not in Docker:

```bash
# Caddy status and logs
sudo systemctl status caddy
sudo journalctl -u caddy -f

# Reload Caddy config
sudo systemctl reload caddy
```

## Sites

Paths in config.json are served as {folder}.{tld} (flat namespace, first match wins).

Set PHP version per project:

```bash
echo "8.4" > ~/projects/mysite/.php-version
```

Or via CLI:

```bash
orbit php mysite 8.4
```

Then restart: `orbit restart`

## Add a New Path

1. Edit ~/.config/orbit/config.json, add path to "paths" array
2. Run: `orbit restart`

## Config Locations

- PHP: ~/.config/orbit/php/php.ini
- Caddy: ~/.config/orbit/caddy/Caddyfile (host service, reload with `sudo systemctl reload caddy`)
- Sites: ~/.config/orbit/config.json
- DNS: ~/.config/orbit/dns/Dockerfile

## Troubleshooting

```bash
# Check all services
orbit status --json | jq .

# Check Caddy (runs on host, not Docker)
sudo systemctl status caddy
sudo journalctl -u caddy --tail 50

# Restart everything
orbit restart
```
