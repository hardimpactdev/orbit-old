# gateway:add overview

Add a gateway server configuration to Orbit.

## Overview

Store gateway server details locally for easy access. The name is stored as-is, while the ID is generated in kebab-case from the name.

## Usage

```bash
orbit gateway:add [name] [ip] [subnet]
```

## Arguments

- `name` - Gateway name (e.g., `Production Gateway`)
- `ip` - External IP address
- `subnet` - VPN subnet (default: `10.8.0.0/24`)

## Examples

### Interactive mode

```bash
orbit gateway:add
```

### With arguments

```bash
orbit gateway:add "Production Gateway" 203.0.113.10
orbit gateway:add "Home VPN" 192.168.1.100 10.8.1.0/24
```

## ID Generation

The gateway ID is automatically generated from the name in kebab-case:

| Name | ID |
|------|-----|
| Hard Impact | `hard-impact` |
| Production Gateway | `production-gateway` |
| My VPN #1 | `my-vpn-1` |

If the ID already exists, a counter is appended:
- First: `hard-impact`
- Second: `hard-impact-1`
- Third: `hard-impact-2`

## Storage

Gateways are stored in `~/.config/orbit/config.json`:

```json
{
  "gateways": [
    {
      "id": "hard-impact",
      "name": "Hard Impact",
      "ip": "203.0.113.10",
      "subnet": "10.8.0.0/24",
      "created_at": "2026-02-04T10:00:00+00:00"
    }
  ]
}
```

## See Also

- `orbit gateway:list` - List configured gateways
- `orbit setup` - Interactive setup wizard
- `orbit setup:gateway` - Set up a gateway server
