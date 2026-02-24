---
date: 2026-02-16
problem_type: security
component: CaddyfileGenerator, RemoteCaddyManager, production stubs
severity: moderate
symptoms:
  - "No security headers on any HTTP response"
  - "Sensitive paths (.env, .git, vendor) publicly accessible"
  - "PHP exposes version and error details to clients"
root_cause: Security hardening was never added to the Caddy config generation or PHP stubs
tags: [caddy, security-headers, php-hardening, production]
---

# Caddy Security Headers: Snippets vs Inline

## Context

Three separate config sources generate Caddy site blocks:
1. `CaddyfileGenerator` (dev Caddyfiles) - generates full Caddyfile with global block
2. `RemoteCaddyManager` (remote deploys) - writes individual `.caddy` files via SSH
3. `production-site.caddy.stub` - template for custom production sites

## Key Decision: Snippets vs Inline

**Dev Caddyfiles (CaddyfileGenerator)** use Caddy snippets:
```caddy
(security_headers) { ... }
(path_blocking) { ... }

mysite.test {
    import security_headers
    import path_blocking
}
```

**Remote deploy `.caddy` files and production stubs** use inline directives:
```caddy
mysite.nl {
    header { X-Content-Type-Options "nosniff" ... }
    @blocked path /.env ...
    respond @blocked 404
}
```

### Why the difference?

Remote `.caddy` files are concatenated into an existing Caddyfile via the `sites/*.caddy` import pattern. They cannot use snippets because:
1. Snippets must be defined at the top level of the Caddyfile
2. Older servers may not have the snippet definitions yet
3. The `.caddy` files must be self-contained

### HSTS: Dev vs Production

- **Dev sites**: No HSTS. Dev uses `local_certs` (self-signed). HSTS would lock browsers into HTTPS with untrusted certs.
- **Production sites**: HSTS with `max-age=63072000; includeSubDomains; preload` (2 years).
- A `(security_headers_production)` snippet is available for custom `.caddy` files that need HSTS via the snippet system.

## Path Blocking

Both dev and production block:
```
/.env /.env.* /.git/* /vendor/* /storage/* /config/* /database/*
/node_modules/* /.htaccess /composer.json /composer.lock
/package.json /package-lock.json /bun.lock* /vite.config.* /artisan
```

Production stubs omit `/node_modules/*` from Vite matchers since Vite doesn't run on production.

## PHP Hardening

Applied at two levels:
1. **php.ini stub** - `display_errors = Off`, `expose_php = Off`, session security
2. **FPM pool stub** - `php_admin_*` directives (cannot be overridden by `ini_set()`)
3. **Conditional `disable_functions`** - Only for production templates (`php-production`, `client`), keeps `proc_open`/`curl_exec` for Guzzle

## Safety: Existing Servers Unaffected

All changes only affect **new installations and new deployments**:
- Stub changes: only used during `orbit setup`
- CaddyfileGenerator: only regenerates on explicit `orbit caddy:reload`
- RemoteCaddyManager: `configure()` skips if `.caddy` file already exists
- FPM pool: only applied during `orbit install`/`orbit setup`

## Files

| File | Pattern |
|------|---------|
| `packages/cli/app/Services/CaddyfileGenerator.php` | Snippets + imports |
| `packages/core/src/Services/RemoteDeploy/RemoteCaddyManager.php` | Inline in TEMPLATE constant |
| `packages/cli/stubs/caddy/production-site.caddy.stub` | Inline |
| `packages/cli/stubs/php/php.ini` | Production-safe defaults |
| `packages/cli/stubs/php-fpm-pool.conf.stub` | php_admin security directives |
| `packages/cli/app/Actions/Install/{Linux,Mac}/ConfigurePhpFpm.php` | Conditional disable_functions |

## Related
- `infrastructure/caddy-local-certs-blocks-acme-production-20260214.md` - ACME override for production domains
- `infrastructure/caddy-reload-wipes-custom-sites-20260215.md` - Why custom configs go in `sites/*.caddy`
