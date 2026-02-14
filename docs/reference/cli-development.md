# Orbit CLI Development

The **orbit CLI** manages projects, Caddy configs, Docker containers, and more. Source code lives in the monorepo at `packages/cli/`.

## Making CLI Changes

CLI source is in the monorepo alongside orbit-core:

```bash
# CLI commands
packages/cli/app/Commands/

# CLI services
packages/cli/app/Services/

# Shared core (models, pipelines, services)
packages/core/src/
```

Test locally:

```bash
cd packages/cli
./vendor/bin/pest                              # Run tests
./vendor/bin/phpstan analyse --memory-limit=512M  # Static analysis
```

## CLI Release Workflow

The monorepo triggers CI builds on `v*` tag push:

```bash
# 1. Commit and push
git add ... && git commit -m "feat: description"

# 2. Tag and push (triggers Build and Release CLI workflow)
git tag v0.1.XXX
git push origin main --tags

# 3. Wait for CI to complete
gh run list --repo hardimpactdev/orbit --limit 5
gh run watch <build-run-id> --repo hardimpactdev/orbit --exit-status

# 4. Verify release on orbit-cli repo
gh release view v0.1.XXX --repo hardimpactdev/orbit-cli
```

CI produces per-platform static binaries: `orbit-linux-x86_64`, `orbit-linux-aarch64`, `orbit-macos-aarch64`

## Upgrading Nodes

After a release, upgrade all nodes:

```bash
# Dev server
ssh ai "~/.local/bin/orbit upgrade"

# Gateway
ssh gateway "~/.local/bin/orbit upgrade"

# Production
ssh orbit@46.225.89.66 "~/.local/bin/orbit upgrade"

# Verify
ssh ai "~/.local/bin/orbit --version"
ssh gateway "~/.local/bin/orbit --version"
ssh orbit@46.225.89.66 "~/.local/bin/orbit --version"
```

If `orbit upgrade` fails (e.g., node has a phar instead of static binary):

```bash
ssh <node> 'curl -sL \
  https://github.com/hardimpactdev/orbit-cli/releases/download/v0.1.XXX/orbit-linux-x86_64 \
  -o /tmp/orbit-new && chmod +x /tmp/orbit-new && mv /tmp/orbit-new ~/.local/bin/orbit'
```

## Local Phar Build (Quick Testing)

For testing changes before a proper release, build a phar locally:

```bash
cd packages/cli

# Replace vendor symlink with real files (required for phar)
rm vendor/hardimpactdev/orbit-core
cp -R ../../packages/core vendor/hardimpactdev/orbit-core
rm -rf vendor/hardimpactdev/orbit-core/{tests,docs,.git,.github}

# Build
~/.composer/vendor/bin/box compile

# Deploy to a server
scp builds/orbit.phar orbit@<host>:~/.local/bin/orbit

# Restore symlink for development
rm -rf vendor/hardimpactdev/orbit-core
ln -s ../../../core vendor/hardimpactdev/orbit-core
```

**Warning**: Phar-based installs can't self-upgrade via `orbit upgrade`. Replace with the static binary from CI when ready.

## Key Paths

| Path | Purpose |
|------|---------|
| `packages/cli/app/Commands/` | CLI commands |
| `packages/cli/app/Services/` | CLI-specific services |
| `packages/core/src/` | Shared business logic (models, pipelines) |
| `packages/cli/builds/orbit.phar` | Local phar build output |
| `~/.local/bin/orbit` | Installed CLI on any node |

## How Desktop Communicates with CLI

For remote environments, the desktop app uses **direct API calls** to the remote web app (`https://orbit.{tld}/api/...`), which then executes CLI commands:

1. **Vue frontend** calls remote API directly (e.g., `DELETE /api/projects/{slug}`)
2. **Remote web app** (via PHP-FPM on host) dispatches a job to Redis queue
3. **Horizon** (systemd/launchd service on host) picks up the job and runs CLI command

For operations that require SSH, the NativePHP backend uses `LaunchpadService::executeCommand()` which runs `orbit <command> --json` over SSH.
