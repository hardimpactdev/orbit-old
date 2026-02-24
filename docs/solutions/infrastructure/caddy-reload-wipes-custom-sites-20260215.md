---
date: 2026-02-15
problem_type: infrastructure
component: CaddyfileGenerator
severity: critical
symptoms:
  - "WebSocket connection failed: Io(Custom { kind: InvalidData, error: AlertReceived(InternalError) })"
  - "TLS handshake InternalError on whisper.hardimpact.dev after caddy:reload"
  - "Production reverse proxy configs disappear after orbit caddy:reload"
root_cause: CaddyfileGenerator regenerates the entire Caddyfile, wiping manually added blocks
tags: [caddy, production, zero-downtime, deployment]
---

# caddy:reload Wipes Custom Site Configs (whisper.hardimpact.dev, srpm.nl)

## Symptom

After running `orbit caddy:reload` on production (`46.225.89.66`), the `whisper.hardimpact.dev` reverse proxy block disappeared from the Caddyfile. The Drift desktop app reported:

```
WebSocket connection failed: Io(Custom { kind: InvalidData, error: AlertReceived(InternalError) })
```

The `srpm.nl` production domain block (with ACME TLS) was also wiped.

## Root Cause

`CaddyfileGenerator::generateCaddyfile()` rebuilds the entire Caddyfile from scratch using only orbit-managed projects (scanned from `~/projects/`). Any manually added Caddy blocks — production domains with ACME, reverse proxies to external services — are lost on every regeneration.

This was triggered during the zero-downtime deployment migration when `caddy:reload` was called after restructuring SRPM to a release-based layout.

## Solution

Added a `sites/` directory convention: `~/.config/orbit/caddy/sites/*.caddy` files are appended to the generated Caddyfile during regeneration.

```php
// At the end of CaddyfileGenerator::generateCaddyfile(), before File::put():
$sitesDir = $this->configManager->getConfigPath().'/caddy/sites';
if (is_dir($sitesDir)) {
    $customFiles = glob("{$sitesDir}/*.caddy");
    if ($customFiles) {
        $caddyfile .= "# Custom site configs\n";
        foreach ($customFiles as $file) {
            $caddyfile .= File::get($file)."\n\n";
        }
    }
}
```

Custom site configs on production:

```bash
# ~/.config/orbit/caddy/sites/srpm.nl.caddy
srpm.nl {
    tls {
        issuer acme
    }
    root * /home/orbit/projects/srpm/current/public
    encode gzip
    php_fastcgi unix//home/orbit/.config/orbit/php/php85.sock
    file_server
}

# ~/.config/orbit/caddy/sites/whisper.hardimpact.dev.caddy
whisper.hardimpact.dev {
    tls {
        issuer acme
    }
    reverse_proxy 10.6.0.7:9000
}
```

## Prevention

- **All production domains and reverse proxies** must be defined as `*.caddy` files in `~/.config/orbit/caddy/sites/`, never appended directly to the Caddyfile.
- The `sites/` directory is not managed by orbit — it survives all `caddy:reload` operations.
- When deploying a new production project, create a corresponding `.caddy` file in `sites/`.

## Related

- `docs/solutions/infrastructure/caddy-local-certs-blocks-acme-production-20260214.md` (ACME override pattern)
- `packages/cli/app/Services/CaddyfileGenerator.php` (the fix)
