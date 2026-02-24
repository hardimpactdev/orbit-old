---
date: 2026-02-18
problem_type: infrastructure
component: Cloudflare / Caddy / Laravel
severity: moderate
symptoms:
  - "cf-cache-status: DYNAMIC on all responses"
  - "Set-Cookie headers preventing CDN caching"
  - "Static assets not cached by Cloudflare edge"
root_cause: "Multiple layers needed: proxied DNS, cache headers, stateless middleware, cache rule"
tags: [cloudflare, caching, cdn, caddy, deployment, performance]
---

# Cloudflare CDN Full-Page Caching — End-to-End Setup

## Symptom

After deploying a Laravel site behind Cloudflare with `proxied: true`, all responses show `cf-cache-status: DYNAMIC` — meaning Cloudflare isn't caching anything, even static marketing pages.

## Investigation

1. Attempted: Added `Cache-Control: public, max-age=86400` via middleware
   Result: Still `DYNAMIC`. Cloudflare saw `Set-Cookie` headers from Laravel's session middleware and refused to cache.

2. Attempted: Used `curl -sI` (HEAD request) to test
   Result: Showed `no-cache, private`. Misleading — CacheControl middleware only runs on GET. Used `curl -s -D- -o /dev/null` instead.

3. Attempted: Created Cloudflare "Cache Everything" rule
   Result: First request `MISS`, second request `HIT`. Success.

## Root Cause

Four layers must ALL be in place for Cloudflare CDN caching to work:

1. **Proxied DNS** (`proxied: true`) — routes traffic through Cloudflare edge
2. **No `Set-Cookie` headers** — Cloudflare refuses to cache responses with cookies, regardless of Cache-Control
3. **`Cache-Control: public`** — tells Cloudflare the response is cacheable
4. **Cache rule** — Cloudflare needs a "Cache Everything" rule to consider caching non-static-file URLs

Missing any single layer results in `cf-cache-status: DYNAMIC`.

## Solution

See `docs/reference/cloudflare-caching.md` for the complete playbook. Summary:

### Infrastructure (automatic on deploy)
- `DeploymentService` creates `proxied: true` DNS for production nodes
- `DeploymentService` sets SSL mode to `strict`
- Caddy template includes `Cache-Control: public, max-age=31536000, immutable` for `/build/*`
- `RemoteDeploymentOrchestrator::ensureCaddyCloudflareToken()` provisions CF API token
- Cache purged after every deploy/undeploy

### Laravel app changes (manual per project)
1. Create `CacheControl` middleware — sets `Cache-Control: public, max-age=86400` on production GET
2. Register `static` middleware group — no `StartSession`, `EncryptCookies`, `VerifyCsrfToken`
3. Split routes: cacheable pages in `routes/static.php`, dynamic in `routes/web.php`

### Cloudflare cache rule (via MCP)
```
gateway_cloudflare_create_cache_rule(project_slug: "my-project")
```

### Verification
```bash
# First request: MISS, second request: HIT
curl -s -D- -o /dev/null https://example.com/ | grep -iE "cache-control|set-cookie|cf-cache"
```

## Key Gotchas Discovered

- **HEAD vs GET**: `curl -sI` sends HEAD. CacheControl middleware only sets headers on GET. Always test with `curl -s -D- -o /dev/null`.
- **CF API token permissions**: Token needs `Zone:Cache Rules:Edit` and `Zone:Cache Purge:Edit` in addition to `Zone:DNS:Edit`.
- **`Set-Cookie` is the #1 blocker**: Even with perfect `Cache-Control`, a single `Set-Cookie` header prevents caching. The stateless middleware group is essential.
- **Inertia works without sessions**: `HandleInertiaRequests` v2 checks `$request->hasSession()` before session access.
- **CSP nonces in cached pages**: Both HTML and CSP header cached together — nonces match. Acceptable for public marketing sites.

## Prevention

- Use `docs/reference/cloudflare-caching.md` as the step-by-step playbook for every new site
- The `gateway_cloudflare_create_cache_rule` MCP tool automates cache rule creation
- `DeploymentService` handles all infrastructure-side caching automatically

## Related

- `docs/reference/cloudflare-caching.md` — complete LLM-oriented playbook
- `packages/core/src/Services/CloudflareService.php` — `createCacheRule()`, `purgeCache()`
- `packages/app/src/Mcp/Tools/Gateway/GatewayCloudflareCreateCacheRuleTool.php` — MCP tool
- `packages/core/src/Services/DeploymentService.php` — auto-purge on deploy
