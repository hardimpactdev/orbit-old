# host:restart

Restarts a host-level service (services running on the host machine, not in Docker).

## What It Does

1. Identifies the service type from the argument
2. Delegates to the appropriate service manager
3. Reports success or failure

## Arguments

| Argument | Description |
|----------|-------------|
| `service` | Service to restart: `caddy` or `php-{version}` |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Supported Services

| Service | Manager | Description |
|---------|---------|-------------|
| `caddy` | CaddyManager | Web server/reverse proxy |
| `php-8.3`, `php-8.4`, etc. | PhpManager | PHP-FPM for specific version |

## Use Cases

- After configuration changes to Caddy
- After PHP configuration updates
- When a service becomes unresponsive

## Examples

```bash
orbit host:restart caddy
orbit host:restart php-8.3
```

## Related Commands

- `host:start` - Start a service
- `host:stop` - Stop a service
- `restart` - Restart Docker containers (different from host services)
