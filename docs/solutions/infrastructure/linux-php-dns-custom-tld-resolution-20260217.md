---
date: 2026-02-17
problem_type: infrastructure
component: CLI install pipeline, ConfigManager, orbit-dns (dnsmasq)
severity: critical
symptoms:
  - "PHP gethostbyname() returns hostname instead of IP for custom TLD domains"
  - "Reverb WebSocket connections fail from PHP on Linux"
  - "Browsers resolve .bear domains but PHP cannot"
root_cause: orbit-dns dnsmasq only mapped .test, never updated to match the node's actual TLD
tags: [linux, dns, dnsmasq, php, glibc, nsswitch, tld, orbit-dns]
---

# Linux PHP Cannot Resolve Custom TLDs via orbit-dns

## Symptom

On a Linux node with TLD `.bear`, PHP's `gethostbyname("reverb.orbit.bear")` returns the hostname string (failure) instead of `127.0.0.1`. Browsers and `dig` may work, but PHP does not.

## Investigation

1. Checked nsswitch.conf: `hosts: files dns` — glibc uses /etc/hosts then DNS
2. Checked /etc/resolv.conf: `nameserver 127.0.0.1` — points to orbit-dns
3. Checked orbit-dns dnsmasq.conf: `address=/.test/127.0.0.1` — only `.test` mapped!
4. Checked config.json: `tld: "bear"` but `dns_mappings[0].tld: "test"` — mismatch

An engineer initially suggested adding `resolve` to nsswitch.conf to use systemd-resolved's D-Bus interface. This would work as a workaround but is the wrong fix — the DNS chain through orbit-dns should handle it.

## Root Cause

Two bugs in the install pipeline:

1. **`dns_mappings` in config.json hardcoded `.test`**: The stub config.json and `getDnsMappings()` default both use `.test`. When a different TLD is configured, only the `tld` field is updated — `dns_mappings` is never synced.

2. **`NodeUpdateTldCommand` didn't update dns_mappings**: When changing a node's TLD, Step 2 updated `config.json.tld` but not `config.json.dns_mappings`, leaving dnsmasq resolving the old TLD.

The DNS resolution chain on Linux:
```
PHP → glibc → nsswitch "files dns" → /etc/resolv.conf → 127.0.0.1 → orbit-dns (dnsmasq)
```
This chain works correctly — the problem was orbit-dns didn't know about the custom TLD.

## Solution

### Code fixes

**1. `ConfigManager::updateTldInDnsMappings()`** — new method:
```php
public function updateTldInDnsMappings(string $newTld): void
{
    $mappings = $this->getDnsMappings();
    $updated = false;

    foreach ($mappings as &$mapping) {
        if ($mapping['type'] === 'address') {
            $mapping['tld'] = $newTld;
            $updated = true;
            break;
        }
    }
    unset($mapping);

    if (! $updated) {
        array_unshift($mappings, [
            'type' => 'address',
            'tld' => $newTld,
            'value' => '127.0.0.1',
        ]);
    }

    $this->setDnsMappings($mappings);
}
```

**2. `GenerateDnsConfig` action** — sync TLD before writing:
```php
$this->configManager->updateTldInDnsMappings($context->tld);
$this->configManager->writeDnsmasqConf();
```

**3. `NodeUpdateTldCommand`** — update dns_mappings in Step 2 + add Step 3b to restart DNS service.

### Manual fix for existing nodes

```bash
# 1. Update config.json dns_mappings
python3 -c "
import json
with open('$HOME/.config/orbit/config.json', 'r') as f:
    config = json.load(f)
tld = config.get('tld', 'test')
config['dns_mappings'] = [
    {'type': 'address', 'tld': tld, 'value': '127.0.0.1'},
    {'type': 'server', 'value': '8.8.8.8'},
    {'type': 'server', 'value': '8.8.4.4'}
]
with open('$HOME/.config/orbit/config.json', 'w') as f:
    json.dump(config, f, indent=4)
"

# 2. Regenerate dnsmasq.conf (orbit CLI)
orbit service:restart dns

# 3. Verify
php -r "echo gethostbyname('anything.YOUR_TLD') . PHP_EOL;"
# Should output: 127.0.0.1
```

## Key Insight: /etc/resolver/ is macOS-only

| Platform | DNS mechanism for custom TLDs |
|----------|-------------------------------|
| macOS | `/etc/resolver/{tld}` files (mDNSResponder) |
| Linux | orbit-dns dnsmasq `address=/.{tld}/127.0.0.1` via /etc/resolv.conf |

The `nsswitch.conf` `resolve` module fix is unnecessary when orbit-dns is running and properly configured. The `hosts: files dns` chain works — it reads /etc/resolv.conf which points to orbit-dns on 127.0.0.1.

systemd-resolved drop-in configs (`/etc/systemd/resolved.conf.d/`) are also unnecessary in this setup since resolv.conf bypasses systemd-resolved entirely.

## Prevention

- When the `tld` field changes in config.json, always update `dns_mappings` too
- After changing dnsmasq.conf, restart the orbit-dns container (dnsmasq reads config on startup only)
- Test DNS with PHP, not just `dig` — they use different resolution paths
- The `ConfigManager::updateTldInDnsMappings()` method now handles this automatically

## Related

- `docs/solutions/infrastructure/macos-vpn-custom-tld-resolution-20260215.md` — macOS equivalent
