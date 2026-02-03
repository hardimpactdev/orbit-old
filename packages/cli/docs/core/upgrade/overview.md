# upgrade overview

Upgrades Orbit CLI to the latest version from GitHub releases with platform-aware binary detection.

- Fetches latest release info from GitHub API
- Compares versions to check if update available
- Detects platform (linux-x86_64, linux-aarch64, macos-aarch64)
- Downloads platform-specific static binary (or PHAR fallback)
- Validates binary format (ELF/Mach-O/PHAR)
- Replaces current binary

Post-upgrade tasks (run automatically)

- Database migrations (`db:migrate`)
- Service configuration regeneration (docker-compose.yml)
- Service restart

Failure and recovery paths

- Creates backup before replacement
- Restores backup if replacement fails
- Falls back to orbit.phar asset if no platform binary available
- Uses `pcntl_exec` to launch new binary for post-upgrade tasks

Inputs and options

- --check: Only check for updates without installing
- --post-upgrade: Run post-upgrade tasks only (internal use)
- --json: Output as JSON

Key integrations

- GitHub API for release info
- ServiceManager for docker-compose regeneration
