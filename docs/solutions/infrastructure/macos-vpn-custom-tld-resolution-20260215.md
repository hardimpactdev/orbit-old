---
date: 2026-02-15
problem_type: infrastructure
component: macOS DNS, WireGuard VPN, dnsmasq
severity: critical
symptoms:
  - "Could not resolve host: orbit.gateway"
  - "Claude Code MCP server shows 'failed' despite VPN being connected"
  - "dig resolves but curl/apps cannot resolve custom TLDs"
root_cause: macOS system resolver ignores WireGuard supplemental DNS for non-standard TLDs
tags: [macos, dns, vpn, wireguard, mcp, dnsmasq, resolver]
---

# macOS Cannot Resolve VPN Custom TLDs Without /etc/resolver/ Files

## Symptom

VPN is connected (`ping 10.6.0.1` works), `dig orbit.gateway @10.6.0.1` resolves correctly, but `curl http://orbit.gateway/` fails with "Could not resolve host". Claude Code MCP server shows as "failed".

## Investigation

1. Checked `scutil --dns` — WireGuard DNS (10.6.0.1) registered as **Supplemental** resolver for `localdomain` only:
   ```
   resolver #1
     search domain[0] : localdomain
     nameserver[0] : 10.6.0.1
     flags    : Supplemental, Request A records
   ```
2. Primary resolver is the router (192.168.1.1) which knows nothing about `.gateway`, `.ccc`, `.beast` TLDs.
3. `dig` bypasses the macOS system resolver and queries DNS directly — that's why it works but `curl` doesn't.

## Root Cause

macOS uses `mDNSResponder` for DNS resolution. WireGuard sets the VPN DNS as a "Supplemental" resolver scoped to `localdomain`. Custom TLDs (`.gateway`, `.ccc`, `.beast`) are not in that scope, so queries go to the primary resolver (router → public DNS → NXDOMAIN).

The macOS system resolver only routes queries to specific nameservers when told to via `/etc/resolver/` files.

## Solution

Create resolver files for each custom TLD:

```bash
sudo mkdir -p /etc/resolver
echo "nameserver 10.6.0.1" | sudo tee /etc/resolver/gateway /etc/resolver/ccc /etc/resolver/beast
```

Each file tells macOS: "for queries ending in `.gateway`, ask `10.6.0.1`."

Changes take effect within ~1 second, no restart needed.

## Additional Fixes Found During Investigation

### 1. Gateway dnsmasq missing `.gateway` TLD

The dnsmasq container on the gateway only had `.ccc` and `.beast` entries, not `.gateway`:

```bash
# On gateway
docker exec dnsmasq sh -c 'echo "address=/.gateway/10.6.0.2" >> /etc/dnsmasq.d/dev-tlds.conf'
docker restart dnsmasq
```

### 2. dnsmasq pointed to wrong IP

The `.gateway` TLD must point to `10.6.0.2` (gateway **host** VPN IP where Caddy runs), NOT `10.6.0.1` (wg-easy container IP). Caddy runs on the host, not inside the wg-easy container.

```
# Wrong — wg-easy container, Caddy doesn't listen here
address=/.gateway/10.6.0.1

# Correct — gateway host, Caddy listens on *:80
address=/.gateway/10.6.0.2
```

Verify with: `ssh gateway "ip addr show wg0"` → `inet 10.6.0.2/24`

### 3. HTTPS vs HTTP

Gateway Caddy serves `orbit.gateway` on HTTP only (no TLS configured for internal VPN domains). MCP client URL must use `http://`, not `https://`.

## Prevention

- When adding new VPN TLDs, always create matching `/etc/resolver/` file on macOS clients
- When pointing dnsmasq to a service, verify which IP the service actually listens on (`ss -tlnp` on the host)
- For internal VPN domains, use HTTP unless TLS is explicitly configured in Caddy
- Always test MCP connectivity end-to-end from the actual client machine before declaring it works

## Verification

```bash
# Full chain test from macOS
curl -sf -X POST -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}},"id":1}' \
  --max-time 5 http://orbit.gateway/mcp/gateway

# Expected: {"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2024-11-05",...,"serverInfo":{"name":"Gateway","version":"1.0.0"}}}
```

## Related

- `docs/solutions/infrastructure/dns-resolver-missing-after-orbit-setup-20260213.md`
- `docs/solutions/infrastructure/gateway-mcp-deployment-20260213.md`
