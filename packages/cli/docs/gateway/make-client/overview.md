# gateway:make:client overview

Create a new WireGuard VPN client with custom TLD routing.

## Overview

The `gateway:make:client` command:

- Creates a WireGuard client in WG Easy
- Assigns a VPN IP address
- Optionally configures a custom TLD for DNS routing
- Stores the client mapping in configuration

## Usage

Run on the gateway server:

```bash
orbit gateway:make:client [name] [tld]
```

## Arguments

- `name` - Client name (e.g., `laptop`, `macbook`, `server`)
- `tld` - Custom TLD for the client (optional, e.g., `testa`)

## Examples

### Interactive mode

```bash
orbit gateway:make:client
```

### With arguments

```bash
orbit gateway:make:client macbook testa
orbit gateway:make:client server
```

## Client Name

Required. Used to identify the client in WG Easy.

- Can contain letters, numbers, underscores, and hyphens
- Max 32 characters
- Examples: `macbook-pro`, `dev-server`, `john-laptop`

## Custom TLD (Optional)

When provided, all requests to `*.tld` domains route to this client.

Examples:
- TLD `testa` → `https://anything.testa` routes to the client
- Leave empty for no DNS routing

## Output

```
Creating client: macbook
TLD: .testa

✓ Client created
  VPN IP: 10.8.0.3

✓ DNS mapping added: *.testa -> 10.8.0.3

Connection Details:
  Name: macbook
  TLD: .testa
  VPN IP: 10.8.0.3

Next steps:
1. Open WG Easy web UI: http://203.0.113.10:51821
2. Find client 'macbook' and download the configuration
3. Import the config into your WireGuard client
4. Connect to the VPN

Once connected, you can access this machine via:
  https://anything.testa
```

## QR Code

The command can display a QR code for easy mobile setup:

```
Show QR code for mobile setup? (yes/no) [no]:
```

Requires `qrencode` to be installed:

```bash
brew install qrencode
```

## Managing Clients

### List configured clients

View stored client mappings:

```bash
cat ~/.config/orbit/config.json | grep gateway
```

### Remove a client

Use the WG Easy web UI to delete clients.

## See Also

- `orbit setup:gateway` - Set up a gateway server
- `orbit gateway:list` - List configured gateways
