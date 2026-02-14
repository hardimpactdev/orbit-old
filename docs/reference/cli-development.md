# Orbit CLI Development

The **orbit CLI** manages sites, Caddy configs, Docker containers, and more. Source code lives on the remote dev server, NOT locally.

## Making CLI Changes

**All CLI changes must be made on the remote server:**

```bash
# SSH into the dev server
ssh orbit@ai

# Navigate to CLI source
cd ~/projects/orbit-cli

# Make your changes, test locally
php orbit <command>

# When ready, publish a release (see below)
```

## Building the CLI (Laravel Zero)

The CLI is a Laravel Zero application. Build using Box (Laravel Zero uses Box under the hood).

**Quick local update (no GitHub release):**

```bash
ssh orbit@ai
cd ~/projects/orbit-cli

# Build phar using Box
~/.config/composer/vendor/bin/box compile

# Copy to local bin
cp builds/orbit.phar ~/.local/bin/orbit
```

**Note on `app:build`:** Laravel Zero has a built-in `app:build` command (`php orbit --env=development app:build`) but its bundled Box (4.6.7) has a PHP 8.5 compatibility bug. Use the global Box (4.6.10+) directly until Laravel Zero updates their bundled version.

## CLI Release Workflow

After making changes to the CLI, publish a new release:

**1. On the remote server - Build and release:**

```bash
ssh orbit@ai
cd ~/projects/orbit-cli

# Commit changes first
git add -A && git commit -m "Description of changes"
git push

# Build the phar
~/.config/composer/vendor/bin/box compile

# Create GitHub release with the phar attached
gh release create v1.x.x builds/orbit.phar --title "v1.x.x" --notes "Changelog"
```

**2. Update CLI on servers:**

```bash
# Self-upgrade (preferred - downloads correct platform binary automatically)
orbit upgrade

# Manual install (if orbit isn't installed yet)
# macOS ARM64:
curl -fSL -o ~/.local/bin/orbit https://github.com/hardimpactdev/orbit-cli/releases/latest/download/orbit-macos-aarch64
# Linux x86_64:
curl -fSL -o ~/.local/bin/orbit https://github.com/hardimpactdev/orbit-cli/releases/latest/download/orbit-linux-x86_64
# Linux ARM64:
curl -fSL -o ~/.local/bin/orbit https://github.com/hardimpactdev/orbit-cli/releases/latest/download/orbit-linux-aarch64
chmod +x ~/.local/bin/orbit
```

## Key CLI Paths (on remote server)

| Path                                     | Purpose                             |
| ---------------------------------------- | ----------------------------------- |
| `~/projects/orbit-cli/`                  | CLI source code - make changes here |
| `~/projects/orbit-cli/app/Commands/`     | CLI commands                        |
| `~/projects/orbit-cli/builds/orbit.phar` | Built binary (after `app:build`)    |
| `~/.local/bin/orbit`                     | Installed CLI binary                |

## How Desktop Communicates with CLI

For remote environments, the desktop app primarily uses **direct API calls** to the remote web app (`https://orbit.{tld}/api/...`), which then executes CLI commands:

1. **Vue frontend** calls remote API directly (e.g., `DELETE /api/projects/{slug}`)
2. **Remote web app** (via PHP-FPM on host) dispatches a job to Redis queue
3. **Horizon** (systemd/launchd service on host) picks up the job and runs CLI command (e.g., `orbit project:delete`)

For operations that require SSH (provisioning, config changes with TLD), the NativePHP backend uses:

- `LaunchpadService::executeCommand()` which runs CLI commands over SSH
- Commands are executed as `orbit <command> --json`

**If you change CLI behavior**, you must:

1. Make changes in `~/projects/orbit-cli/` on the remote server
2. Build and release a new version
3. Update the CLI on all servers that need the new version
