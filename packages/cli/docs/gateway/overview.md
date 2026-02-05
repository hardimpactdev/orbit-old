# Gateway Template

The Gateway template provides a central hub for Orbit machines to communicate with each other via VPN.

## Overview

The Gateway template sets up:

- **Docker** - For running standalone services
- **WG Easy** - WireGuard VPN with web UI for easy client management
- **DNS Server** (dnsmasq) - Routes custom TLDs to VPN clients

## Use Cases

- Connect multiple development machines securely
- Access remote servers via custom domains
- Centralize VPN management for your team

## Installation

### Set up a new gateway server

```bash
orbit setup:gateway [ip-address] [user]
```

Example:
```bash
orbit setup:gateway 203.0.113.10 root
```

This will:
1. SSH into the target server
2. Create an `orbit` user with sudo access
3. Copy your SSH authorized_keys
4. Harden SSH (disable password auth)
5. Install Orbit CLI
6. Install the gateway stack

### Requirements

- Target server running Linux
- SSH key configured on the server
- SSH access configured in `~/.ssh/config`
- Sudo/root access on the target

## Gateway Management

### Add a gateway configuration

Store gateway details locally for easy access:

```bash
orbit gateway:add [name] [ip] [subnet]
```

Example:
```bash
orbit gateway:add "Production Gateway" 203.0.113.10 10.8.0.0/24
```

### List configured gateways

```bash
orbit gateway:list
```

### Create VPN clients

On the gateway server, create clients for each machine:

```bash
orbit gateway:make:client
```

This will:
1. Create a WireGuard client in WG Easy
2. Assign a VPN IP
3. Optionally configure a custom TLD (e.g., `.testa`)
4. Store the mapping in DNS

## Connecting Clients

1. SSH into the gateway server
2. Run `orbit gateway:make:client`
3. Follow the prompts to create a client
4. Download the configuration from the WG Easy web UI
5. Import into your WireGuard client

## Web UI

Access the WG Easy web UI at:

```
http://GATEWAY_IP:51821
```

Default credentials are shown after setup.

## DNS Routing

When you assign a TLD to a client (e.g., `.testa`), all requests to `*.testa` domains will route to that client's VPN IP.

Example:
- Client: `macbook` with TLD `.testa`
- VPN IP: `10.8.0.3`
- Access: `https://anything.testa` → routes to `10.8.0.3`
