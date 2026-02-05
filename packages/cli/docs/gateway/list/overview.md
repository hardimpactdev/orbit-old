# gateway:list overview

List configured gateway servers.

## Overview

Display all gateway servers stored in the local Orbit configuration.

## Usage

```bash
orbit gateway:list
```

## Output

```
Configured Gateways:

  Production Gateway
    ID:     production-gateway
    IP:     203.0.113.10
    Subnet: 10.8.0.0/24

  Home VPN
    ID:     home-vpn
    IP:     192.168.1.100
    Subnet: 10.8.1.0/24
```

## When Empty

If no gateways are configured:

```
No gateways configured.
Add a gateway with: orbit gateway:add
```

## Storage Location

Gateways are stored in `~/.config/orbit/config.json` under the `gateways` key.

## See Also

- `orbit gateway:add` - Add a gateway configuration
- `orbit setup` - Interactive setup wizard
