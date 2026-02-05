# setup overview

Interactive setup wizard for Orbit. Configures Orbit locally or connects to a remote gateway.

## Usage

### Interactive Wizard (Default)

Run without arguments to start the interactive wizard:

```bash
orbit setup
```

This will prompt you to choose:
- **Local setup** - Install Orbit on this machine
- **Remote gateway** - Connect to or set up a gateway server

### Legacy Mode

Use options for non-interactive setup:

```bash
orbit setup --tld=test --php-versions=8.4,8.5
```

## Setup Options

### Local Setup

Installs Orbit with the development template:
- PHP-FPM with specified versions
- Caddy web server
- Docker services (DNS, databases, etc.)
- Local DNS resolution

### Remote Gateway

Connect to or configure a gateway server:
- Lists configured gateways
- Sets up new gateway if none exist
- Provides connection instructions

## Legacy Options

- `--tld`: TLD for local development (default: test)
- `--php-versions`: Comma-separated PHP versions (default: 8.4,8.5)
- `--skip-docker`: Skip Docker/OrbStack installation
- `--json`: Output progress as JSON

## Related Commands

- `orbit install` - Direct installation command
- `orbit setup:gateway` - Set up a remote gateway server
- `orbit gateway:add` - Add a gateway configuration
- `orbit gateway:list` - List configured gateways
