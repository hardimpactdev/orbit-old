---
date: 2026-02-16
problem_type: operational
component: DeploymentService, production servers
severity: critical
symptoms:
  - "Site still shows SSL error after code fix"
  - "tankwerk.nl sent an invalid response. ERR_SSL_PROTOCOL_ERROR"
root_cause: Code changes only affect future deployments, not currently running production servers
tags: [deployment, production, operations, live-fix]
---

# Code Changes Don't Fix Live Production Servers

## Symptom

After identifying bugs in deployment code (wrong PHP socket, wrong domain in Caddy config), code was fixed in the monorepo. But the production site (tankwerk.nl) was still down with SSL errors:

```
This site can't provide a secure connection
tankwerk.nl sent an invalid response.
ERR_SSL_PROTOCOL_ERROR
```

## Root Cause

**Code changes affect future deployments, not existing ones.** A running production server has:
- Its own Caddyfile (on disk, loaded in memory)
- Its own PHP-FPM sockets (running processes)
- Its own `.env` and application files

Fixing `DeploymentService.php` or `RemoteCaddyManager.php` in the monorepo does not:
1. Regenerate the production Caddyfile
2. Reload Caddy on the production server
3. Fix any misconfigurations already deployed

## Solution

When a production bug is identified, always do **both**:

### 1. Fix the code (prevents future occurrences)
```php
// Fix the deployment code so future deploys generate correct configs
```

### 2. Fix the live server (resolves the immediate outage)
```bash
# SSH into production and fix the running config
ssh orbit@46.225.89.66
# Edit Caddyfile, fix paths, fix PHP socket references
# Reload: sudo systemctl reload caddy
# Verify: curl -sI https://tankwerk.nl
```

## Prevention

### Mental Checklist for Production Bugs

1. Is a production site currently broken? → Fix the live server FIRST
2. Is the bug in deployment code? → Fix the code SECOND (prevents recurrence)
3. Are gateway deployment records accurate? → Update records THIRD

### Never Claim "Fixed" Until

- [ ] Live site returns HTTP 200
- [ ] SSL certificate is valid
- [ ] Code fix committed (prevents recurrence)
- [ ] Gateway deployment records accurate

### Verification Command

```bash
# Quick health check for all production sites
for domain in srpm.nl lindaretel.nl tankwerk.nl ditis-hr.nl platform11.nl; do
  echo -n "$domain: "
  curl -sI "https://$domain" 2>&1 | head -1
done
```

## Related

- `docs/solutions/infrastructure/caddy-reload-wipes-custom-sites-20260215.md`
- `docs/solutions/infrastructure/gateway-centric-remote-deploy-architecture-20260216.md`
