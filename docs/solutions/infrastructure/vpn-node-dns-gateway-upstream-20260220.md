---
date: 2026-02-20
problem_type: infrastructure
component: GenerateDnsConfig, orbit-dns (dnsmasq), GatewayManager
severity: critical
symptoms:
  - "ping: recall.beast: Name or service not known (from VPN-connected node)"
  - "Cross-node TLDs resolve from gateway but not from sibling nodes"
  - "WireGuard wg0.conf has DNS = 10.6.0.1 but node still uses public DNS"
root_cause: orbit-dns on VPN nodes uses 8.8.8.8/8.8.4.4 as upstream instead of gateway DNS; WireGuard DNS setting is ignored because local orbit-dns intercepts port 53 first
tags: [linux, dns, dnsmasq, vpn, wireguard, gateway, provisioning, orbit-dns]
---

# VPN Node Cannot Resolve Cross-Node Custom TLDs

## Symptom

A node connected to the gateway VPN (e.g., bear at `10.6.0.4`) cannot resolve TLDs
belonging to other VPN nodes (e.g., `recall.beast` at `10.6.0.7`), even though:
- The gateway's dnsmasq has the correct mapping (`address=/.beast/10.6.0.7`)
- The node's wg0.conf specifies `DNS = 10.6.0.1`
- The same hostname resolves correctly from the gateway itself

## Investigation

1. Checked gateway DNS — `dig @10.6.0.1 recall.beast +short` → `10.6.0.7` ✓
2. Checked bear's resolv.conf — `nameserver 127.0.0.1` (local orbit-dns, not gateway)
3. Checked bear's orbit-dns dnsmasq.conf:
   ```
   address=/.bear/127.0.0.1
   server=8.8.8.8       ← goes to public DNS, knows nothing about .beast
   server=8.8.4.4
   ```
4. Checked wg0.conf — `DNS = 10.6.0.1` is set, but bear's orbit-dns container is already
   bound to `0.0.0.0:53`, so the WireGuard DNS setting is never applied.
5. Attempted SIGHUP to dnsmasq — did NOT reload config (only clears cache, re-reads hosts)
6. Attempted adding `server=/.beast/10.6.0.1` per-TLD — works but wrong architecture

## Root Cause

Two issues:

**1. Provisioning bug** — `GenerateDnsConfig.php` always defaults upstream DNS to `8.8.8.8`/`8.8.4.4`
regardless of whether the node is connected to a gateway.

**2. Architecture misunderstanding** — WireGuard's `DNS` field in wg0.conf is ineffective when
orbit-dns is already listening on port 53 locally. The orbit-dns container intercepts all DNS
queries before WireGuard can redirect them to `10.6.0.1`.

The correct design:
```
[Node orbit-dns]
  address=/.bear/127.0.0.1   ← local TLD resolves to self
  server=10.6.0.1            ← everything else → gateway

[Gateway dnsmasq @ 10.6.0.1]
  address=/.bear/10.6.0.4    ← other nodes reach bear via VPN IP
  address=/.beast/10.6.0.7
  address=/.gateway/10.6.0.2
  server=1.1.1.1             ← internet DNS
```

The node DNS only needs to know its own TLD (pointing to localhost). All cross-node TLD
routing lives centrally in the gateway. Do NOT add per-TLD entries to child nodes.

## Solution

### Code fix (future provisioned nodes)

**`packages/cli/app/Actions/Install/Shared/GenerateDnsConfig.php`**:

```php
// Before (broken) — always uses public DNS
$this->configManager->updateTldInDnsMappings($context->tld);
$this->configManager->writeDnsmasqConf();

// After (fixed) — uses gateway DNS when gatewayId is set
$this->configManager->updateTldInDnsMappings($context->tld);

if ($context->gatewayId !== null) {
    $gateway = $this->gatewayManager->get($context->gatewayId);
    if ($gateway !== null) {
        $gatewayDnsIp = $gateway->getVpnGatewayIp();
        $mappings = $this->configManager->getDnsMappings();
        $mappings = array_values(array_filter($mappings, fn ($m) => $m['type'] !== 'server'));
        $mappings[] = ['type' => 'server', 'value' => $gatewayDnsIp];
        $this->configManager->setDnsMappings($mappings);
    }
}

$this->configManager->writeDnsmasqConf();
```

### Manual fix for existing nodes

```bash
# SSH to the node first: ssh bear

# 1. Update config.json — replace public DNS with gateway
python3 -c "
import json
with open('/home/nckrtl/.config/orbit/config.json') as f:
    config = json.load(f)
config['dns_mappings'] = [m for m in config['dns_mappings'] if m['type'] != 'server']
config['dns_mappings'].append({'type': 'server', 'value': '10.6.0.1'})
with open('/home/nckrtl/.config/orbit/config.json', 'w') as f:
    json.dump(config, f, indent=4)
"

# 2. Write new dnsmasq.conf (adjust TLD and config path as needed)
cat > ~/.config/orbit/dns/dnsmasq.conf << 'EOF'
# Orbit DNS configuration
# Auto-generated - matches config.json dns_mappings

address=/.bear/127.0.0.1
server=10.6.0.1

log-queries
log-facility=-
EOF

# 3. Restart orbit-dns
docker restart orbit-dns

# 4. Verify
ping -c 1 recall.beast   # should resolve
```

### Gateway container naming

The gateway's dnsmasq container must be named `orbit-dns` for MCP tools to work:

```bash
# On gateway
docker rename dnsmasq orbit-dns
```

## Prevention

- Never add per-TLD `server=/.tld/10.6.0.1` entries to child nodes — add them to the gateway only
- When provisioning a gateway-connected node, verify `config.json` has `server=<gateway-ip>` not `server=8.8.8.8`
- SIGHUP does not reload dnsmasq's main config — always `docker restart orbit-dns` after config changes
- WireGuard's `DNS` field in wg0.conf is irrelevant when orbit-dns is running locally on port 53

## Related

- `docs/solutions/infrastructure/linux-php-dns-custom-tld-resolution-20260217.md` — TLD mismatch bug
- `docs/solutions/infrastructure/macos-vpn-custom-tld-resolution-20260215.md` — macOS equivalent
