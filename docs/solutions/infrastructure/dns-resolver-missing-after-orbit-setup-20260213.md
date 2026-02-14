---
date: 2026-02-13
problem_type: infrastructure
component: orbit-dns
severity: moderate
symptoms:
  - "ERR_NAME_NOT_RESOLVED"
  - "srpm-craft.test's server IP address could not be found"
  - "curl: (6) Could not resolve host"
root_cause: /etc/resolver/test file missing on macOS
tags: [orbit, dns, macos, resolver, .test]
---

# .test Domains Don't Resolve After Orbit Setup

## Symptom

Browser shows `ERR_NAME_NOT_RESOLVED` for any `.test` domain (e.g., `srpm-craft.test`). `curl` fails with exit code 6. However, `dig srpm-craft.test @127.0.0.1` returns `127.0.0.1` correctly — the DNS container is working.

## Investigation

1. `orbit status` shows DNS container running, all 6 services healthy
2. `orbit caddy:reload` succeeds, Caddyfile regenerated
3. `dig @127.0.0.1` resolves correctly — dnsmasq is serving records
4. `curl --resolve srpm-craft.test:443:127.0.0.1 https://srpm-craft.test/` returns 200 — Caddy is serving
5. `/etc/resolver/` directory exists but is empty — macOS has no instruction to use local DNS for `.test`

## Root Cause

macOS doesn't know to route `.test` domain queries to `127.0.0.1`. The Orbit DNS container (dnsmasq) is listening on port 53 and correctly resolves `.test` domains, but without a resolver file in `/etc/resolver/`, macOS sends these queries to the system's default DNS servers (e.g., ISP/router), which don't know about `.test`.

This happens when:
- Orbit was set up without the desktop app's `DnsResolverService` creating the file
- The resolver file was deleted (e.g., system update, manual cleanup)
- Orbit was installed via CLI without the resolver setup step

## Solution

```bash
sudo mkdir -p /etc/resolver
sudo bash -c 'echo "nameserver 127.0.0.1" > /etc/resolver/test'
sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder
```

Verify:

```bash
cat /etc/resolver/test
# nameserver 127.0.0.1

ping -c1 srpm-craft.test
# PING srpm-craft.test (127.0.0.1)
```

## Prevention

- `orbit setup` / `orbit init` on macOS should verify `/etc/resolver/{tld}` exists and create it if missing
- The desktop app's `DnsResolverService` handles this automatically — CLI-only setups may miss it
- Add a check to `orbit status` that warns when the resolver file is missing
