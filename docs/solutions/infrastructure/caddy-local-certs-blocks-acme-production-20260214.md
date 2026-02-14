---
date: 2026-02-14
problem_type: infrastructure
component: caddy
severity: critical
symptoms:
  - "SSL certificate problem: unable to get local issuer certificate"
  - "Browser shows SSL/TLS error on production domain"
root_cause: Global local_certs directive forces all sites to use self-signed certificates
tags: [caddy, ssl, tls, production, lets-encrypt]
---

# Caddy Global local_certs Blocks Let's Encrypt on Production Domains

## Symptom

After adding a production domain (srpm.nl) to the orbit Caddyfile, browsers show SSL errors. `curl -sv` reveals:

```
SSL certificate problem: unable to get local issuer certificate
```

The certificate is served but not trusted — it's a self-signed internal cert, not a Let's Encrypt cert.

## Investigation

1. Checked Caddyfile: found global `local_certs` option at the top
2. The `srpm.nl` block had no `tls` directive, inheriting the global setting

## Root Cause

Orbit's Caddyfile uses a global `local_certs` option (with internal PKI) for development TLDs like `.test` and `.ccc`. This forces **all** site blocks to use self-signed certificates unless explicitly overridden. When a production domain is added without a `tls` override, it also gets a self-signed cert instead of requesting one from Let's Encrypt.

## Solution

Add explicit ACME TLS issuer to the production domain block:

```caddy
# Before (broken - inherits global local_certs)
srpm.nl {
    root * /home/orbit/Projects/srpm/public
    ...
}

# After (fixed - explicitly uses Let's Encrypt)
srpm.nl {
    tls {
        issuer acme
    }
    root * /home/orbit/Projects/srpm/public
    ...
}
```

After updating, reload Caddy: `sudo systemctl reload caddy`

Caddy will automatically request and provision a Let's Encrypt certificate via HTTP-01 challenge.

## Prevention

- **Always add `tls { issuer acme }` to production domain blocks** in Caddyfiles that use global `local_certs`.
- Dev TLDs (`.test`, `.ccc`) use `tls { issuer internal { lifetime 3598d } }` — this is correct.
- Check `sudo journalctl -u caddy -n 20` after reload to verify ACME certificate acquisition.

## Second Occurrence: whisper.hardimpact.dev TLS InternalError

Same root cause hit `whisper.hardimpact.dev` — DNS pointed to hardimpact-prod but no Caddy block existed for it. The global `local_certs` caused a TLS InternalError during handshake. Fix: added reverse proxy block with ACME override:

```caddy
whisper.hardimpact.dev {
    tls {
        issuer acme
    }
    reverse_proxy 10.6.0.7:9000
}
```

This proxies WebSocket traffic to Beast's Whisper Python server over WireGuard VPN.

## Also Fixed: Caddy 502 on PHP-FPM Socket

The SSL error masked a second issue: `dial unix .../php85.sock: connect: permission denied`. Caddy runs as user `caddy` but the socket is `orbit:orbit` with `0660`. Fix:

```bash
sudo usermod -aG orbit caddy
sudo systemctl restart caddy
```

This is already documented in AGENTS.md known issues (Gateway Caddy 502).

## Related

- AGENTS.md "Gateway Caddy 502" known issue
- `docs/solutions/infrastructure/production-node-setup-orbit-cli-20260214.md`
- `docs/solutions/infrastructure/caddy-reload-wipes-custom-sites-20260215.md` (production blocks must go in `sites/*.caddy` to survive regeneration)
