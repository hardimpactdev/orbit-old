---
date: 2026-02-15
problem_type: infrastructure-issue
component: cloudflare-dns
severity: critical
symptoms:
  - "ERR_TOO_MANY_REDIRECTS on new production deployments"
  - "Infinite redirect loop HTTPS→HTTP→HTTPS"
  - "Works locally, fails in production"
root_cause: "Proxied Cloudflare DNS + Flexible SSL mode causes redirect loop with Caddy HTTPS redirects"
tags: [cloudflare, ssl, dns, proxy, redirects, caddy]
---

# Cloudflare Flexible SSL Infinite Redirect Loop

## Symptom

Every new production deployment caused infinite redirect loops:

```
Browser → HTTPS → Cloudflare (Flexible SSL) → HTTP → Caddy → 308 HTTPS → loop
```

Error in browser: `ERR_TOO_MANY_REDIRECTS`

## Root Cause

**The Problem Chain:**

1. `GatewayDeployTool` creates Cloudflare DNS records with `proxied: true` (default)
2. Zone SSL mode is set to "Flexible" (Cloudflare→Origin uses HTTP)
3. Caddy receives HTTP request, redirects to HTTPS
4. Browser follows redirect, hits Cloudflare again
5. Cloudflare converts HTTPS→HTTP again (Flexible mode)
6. Loop repeats forever

## Solution

Always create DNS-only (unproxied) records by default:

```php
// Before (broken - proxied by default)
$record = $this->cloudflare->createRecord($domain, $ip);

// After (fixed - unproxied by default)
$record = $this->cloudflare->createRecord(
    $domain,
    $ip,
    proxied: false
);
```

**Benefits:**
- No redirect loops regardless of SSL mode
- Faster (no Cloudflare proxy hop)
- Simpler (one less moving part)
- Users can manually enable proxy if needed for CDN/DDoS

## Prevention

1. **Default to unproxied DNS records** - only enable proxy when explicitly needed
2. **Verify SSL mode when using proxied records** - ensure it's "Full" or "Strict"
3. **Test new production deployments immediately** - catch redirect loops before users do

## Related

- Added `gateway_cloudflare_set_ssl` tool for SSL mode management
- Caddy always enforces HTTPS redirects for production domains
