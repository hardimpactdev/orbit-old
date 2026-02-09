---
date: 2026-02-07
problem_type: workflow
component: packages/cli, gateway
severity: minor
symptoms:
  - "The \"--json\" option does not exist."
  - New CLI commands not found on gateway
root_cause: Gateway runs a separate installed binary that must be rebuilt and deployed after adding new commands
tags: [gateway, deployment, cli, phar]
---

# Gateway CLI must be rebuilt and deployed after adding commands

## Symptom

Running `orbit list:gateway-clients` locally returns:
```
Failed to connect to gateway 'hetzner' via SSH.
```

The SSH command succeeds but the exit code is 1 because the gateway's orbit binary doesn't have the new commands. The `sshCommand()` method returns `null` on non-zero exit codes.

## Root Cause

The gateway server runs its own copy of the orbit CLI binary at `~/.local/bin/orbit`. When new commands are added locally (e.g., `gateway:clients`, `gateway:set-password`), the gateway still runs the old version.

## Solution

Build and deploy after adding new gateway-side commands:

```bash
# 1. Build phar locally (box must be installed globally)
~/.composer/vendor/bin/box compile

# 2. Deploy to gateway
scp builds/orbit.phar gateway@188.245.156.201:~/.local/bin/orbit

# 3. Verify
ssh gateway@188.245.156.201 'export PATH=/home/linuxbrew/.linuxbrew/bin:$HOME/.local/bin:$PATH && orbit --version'
```

### Box installation

Laravel Zero's bundled Box (4.6.7) has a PHP 8.5 compat bug. Install globally:

```bash
composer global require humbug/box
# Then use: ~/.composer/vendor/bin/box compile
```

### SSH to gateway

The gateway SSH user is `gateway` (not `orbit`). Check with:
```bash
sqlite3 ~/.config/orbit/database.sqlite "SELECT ssh_user, ip_address FROM gateways;"
```

## Prevention

- When implementing commands that run ON the gateway (not just locally), remember to build+deploy
- Test gateway-side commands via SSH before testing the local wrapper command
- The `sshCommand()` method in GatewayManager silently returns `null` on failure — check the remote command exists first
