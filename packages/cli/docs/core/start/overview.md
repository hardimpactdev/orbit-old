# start overview

Starts all Orbit services including PHP-FPM, Caddy, and Docker services.

- Detects PHP-FPM sockets on the host
- Generates Caddyfile configuration
- Starts PHP-FPM pools for installed versions (8.3, 8.4)
- Starts host Caddy server
- Starts all enabled Docker services (dns, reverb, postgres, redis, mailpit)

Failure and recovery paths

- Individual service failures are tracked
- Returns partial success if some services fail
- Reports which services failed

Inputs and options

- --json: Output as JSON

Key integrations

- ServiceManager for Docker services
- PhpManager for PHP-FPM pools
- CaddyManager for reverse proxy
- CaddyfileGenerator for config generation
