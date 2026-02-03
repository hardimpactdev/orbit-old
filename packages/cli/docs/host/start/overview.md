# host:start

Starts a host-level service (services running on the host machine, not in Docker).

## What It Does

1. Identifies the service type from the argument
2. Delegates to the appropriate service manager
3. Reports success or failure

## Arguments

| Argument | Description |
|----------|-------------|
| `service` | Service to start: `caddy` or `php-{version}` |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Supported Services

| Service | Manager | Description |
|---------|---------|-------------|
| `caddy` | CaddyManager | Web server/reverse proxy |
| `php-8.3`, `php-8.4`, etc. | PhpManager | PHP-FPM for specific version |

## Examples

```bash
orbit host:start caddy
orbit host:start php-8.3
```

## Related Commands

- `host:stop` - Stop a service
- `host:restart` - Restart a service
- `start` - Start Docker containers (different from host services)
