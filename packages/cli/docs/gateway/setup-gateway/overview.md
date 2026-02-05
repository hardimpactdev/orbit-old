# setup:gateway overview

Set up a fresh gateway instance on a remote Linux server.

## Overview

The `setup:gateway` command provisions a remote Linux server as an Orbit gateway:

- Creates `orbit` user with passwordless sudo
- Copies SSH authorized_keys
- Hardens SSH (disables password authentication)
- Installs Orbit CLI
- Installs the Gateway template (WG Easy VPN + DNS)

## Usage

```bash
orbit setup:gateway [ip-address] [user]
```

## Arguments

- `ip-address` - Public IP address of the target server
- `user` - Root user to log in as (default: root)

## Examples

### Interactive mode

```bash
orbit setup:gateway
```

Prompts for IP address and user.

### With arguments

```bash
orbit setup:gateway 203.0.113.10 root
orbit setup:gateway 203.0.113.10 ubuntu
```

## Requirements

- Target server running Linux
- SSH key configured on the server
- SSH access configured in `~/.ssh/config`
- Sudo/root access on the target

## What It Does

1. **Tests SSH connection** - Verifies connectivity to the server
2. **Creates `orbit` user** - New user with passwordless sudo
3. **Copies SSH keys** - Transfers authorized_keys to the new user
4. **Hardens SSH** - Disables password authentication
5. **Installs Orbit** - Downloads and installs Orbit CLI
6. **Sets up Gateway** - Installs WG Easy VPN and DNS services

## After Setup

Connect to the gateway:

```bash
ssh orbit@GATEWAY_IP
```

Access the WG Easy web UI:

```
http://GATEWAY_IP:51821
```

Create VPN clients:

```bash
orbit gateway:make:client
```

## Troubleshooting

### SSH connection fails

- Verify the server is reachable: `ping IP_ADDRESS`
- Check SSH config: `cat ~/.ssh/config`
- Ensure your key is on the server: `ssh-copy-id user@ip`

### Setup fails mid-way

You can retry by SSHing in manually:

```bash
ssh orbit@IP_ADDRESS
orbit install --template=gateway
```
