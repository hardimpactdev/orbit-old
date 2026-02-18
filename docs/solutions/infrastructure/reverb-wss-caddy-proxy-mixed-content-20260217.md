---
date: 2026-02-17
problem_type: infrastructure
component: CaddyfileGenerator, Reverb
severity: moderate
symptoms:
  - "Browser blocks ws:// connection from https:// page"
  - "Mixed Content: The page was loaded over HTTPS but attempted to connect to insecure WebSocket"
  - "WebSocket connection to 'ws://localhost:8080' failed"
root_cause: Reverb container runs plain HTTP (ws://localhost:8080) while sites are served over HTTPS via Caddy
tags: [caddy, reverb, websocket, tls, mixed-content]
---

# Browser Blocks WebSocket: Mixed Content (HTTPS page → ws:// Reverb)

## Symptom

Laravel apps served over HTTPS (e.g. `https://laraclaw.bear`) cannot connect to the shared Reverb WebSocket container at `ws://localhost:8080`. Browsers block the insecure WebSocket from a secure page.

## Root Cause

Caddy serves all sites over HTTPS with internal TLS certs. The Reverb Docker container (`orbit-reverb`) listens on plain HTTP port 8080. Browsers enforce mixed content security and refuse `ws://` connections from `https://` pages.

## Solution

Add a Caddy reverse proxy that terminates TLS and proxies to Reverb, making WebSocket available at `wss://reverb.orbit.{tld}`.

### 1. CaddyfileGenerator (source change)

The generator already had `reverb.{tld}` — changed to `reverb.orbit.{tld}` for clearer namespacing:

```php
// packages/cli/app/Services/CaddyfileGenerator.php
// Before
$caddyfile .= "reverb.{$tld} {

// After
$caddyfile .= "reverb.orbit.{$tld} {
```

### 2. Custom sites file (survives caddy:reload with old binary)

Until the CLI binary is rebuilt, `orbit caddy:reload` uses the old code. The custom sites file ensures persistence:

```bash
# ~/.config/orbit/caddy/sites/reverb.caddy
reverb.orbit.bear {
    tls {
        issuer internal {
            lifetime 3598d
        }
    }
    @websocket {
        path /app /app/*
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @websocket localhost:8080
    reverse_proxy localhost:8080
}
```

Having both `reverb.bear` (auto-generated) and `reverb.orbit.bear` (custom file) is harmless — both proxy to the same Reverb container.

### 3. DNS

Gateway dnsmasq resolves `*.bear` → `10.6.0.4` (bear's WireGuard IP). No additional DNS config needed for `reverb.orbit.bear`.

### 4. Laravel app config

Update the app's `.env` or broadcasting config to use the new WSS endpoint:

```env
REVERB_HOST=reverb.orbit.bear
REVERB_PORT=443
REVERB_SCHEME=https
VITE_REVERB_HOST=reverb.orbit.bear
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

## Prevention

- When adding shared services (Reverb, Mailpit, etc.) that need browser access from HTTPS sites, always proxy through Caddy with TLS termination.
- Use `~/.config/orbit/caddy/sites/*.caddy` for domain overrides that differ from the auto-generated Caddyfile (see `caddy-reload-wipes-custom-sites-20260215.md`).
- After updating CaddyfileGenerator source, rebuild and deploy the CLI binary to all nodes so `orbit caddy:reload` generates the correct config natively.

## Related

- `docs/solutions/infrastructure/caddy-reload-wipes-custom-sites-20260215.md` (custom sites pattern)
- `docs/solutions/infrastructure/caddy-local-certs-blocks-acme-production-20260214.md` (TLS cert handling)
- `packages/cli/app/Services/CaddyfileGenerator.php` (source change)
