# AI Agent Development Guide

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

## Hierarchical Context Files

```
orbit-cli/
├── AGENTS.md              # This file - project overview
├── CLAUDE.md              # Symlink → AGENTS.md
├── app/
│   ├── AGENTS.md          # Backend overview, contexts
│   ├── Actions/
│   │   └── AGENTS.md      # Provision action patterns
│   ├── Commands/
│   │   └── AGENTS.md      # CLI command patterns
│   ├── Data/
│   │   └── AGENTS.md      # DTO patterns
│   └── Services/
│       └── AGENTS.md      # Service & platform patterns
└── tests/
    └── AGENTS.md          # Testing patterns
```

## Quick Reference: Commands

### Development

```bash
./vendor/bin/pest                              # Run tests
./vendor/bin/rector --dry-run                  # Check code transformations
./vendor/bin/pint --test                       # Check code style
./vendor/bin/phpstan analyse --memory-limit=512M  # Static analysis
```

### Issue Tracking (beads)

```bash
bd ready                                       # Find available work
bd show <id>                                   # View issue details
bd update <id> --status in_progress            # Claim work
bd close <id>                                  # Complete work
bd sync                                        # Sync with git
```

### Release Workflow

```bash
git tag v0.x.y                                 # Create version tag
git push origin v0.x.y                         # Push tag (triggers build)
orbit upgrade                                  # Update local installation
```

**Note:** Use `v` prefix for git tags (convention), but omit it in composer.json versions.

### Static Binary Build

The CLI is distributed as a self-contained static binary (PHP 8.4 embedded via phpmicro). No PHP installation required on the target machine.

Build targets: `orbit-linux-x86_64`, `orbit-linux-aarch64`, `orbit-macos-aarch64`

The build workflow (`.github/workflows/build-cli.yml`):
1. Compiles PHAR via Box
2. Builds static PHP micro.sfx via static-php-cli (per platform)
3. Combines micro.sfx + PHAR into a single native binary
4. Releases per-platform binaries to hardimpactdev/orbit-cli

## Technology Stack

| Layer | Technology |
|-------|------------|
| Framework | Laravel Zero (CLI) |
| Testing | Pest PHP |
| Static Analysis | PHPStan |
| Code Style | Laravel Pint |
| Refactoring | Rector |
| Containers | Docker Compose (dns, reverb, postgres) |
| Web Server | Caddy |
| PHP Runtime | PHP-FPM (8.3, 8.4, 8.5) |
| Platforms | Linux, macOS |

## Database Configuration

Development and production use **separate databases** to avoid conflicts:

| Instance | Database | Config |
|----------|----------|--------|
| Dev CLI (`~/projects/orbit-cli`) | `database-dev.sqlite` | `.env` file |
| Installed CLI (`~/.local/bin/orbit`) | `database.sqlite` | Default |

**Configuration:**
- `config/database.php` reads `DB_DATABASE` env var (falls back to `~/.config/orbit/database.sqlite`)
- Dev CLI uses `.env` with `DB_DATABASE=/home/nckrtl/.config/orbit/database-dev.sqlite`
- `.env` is gitignored (create locally for development)

**Why separate databases?**
Sites created via dev CLI won't appear in production, and vice versa. This prevents confusion during development.

## Persistence: config.json vs Database

Two persistence layers with distinct responsibilities — do NOT merge them:

| Layer | Location | Purpose | Consumers |
|-------|----------|---------|-----------|
| `config.json` | `~/.config/orbit/config.json` | Declarative system config (tld, paths, services, dns_mappings, site overrides) | ConfigManager, dnsmasq generator, Caddyfile generator, human editors |
| SQLite database | `~/.config/orbit/database.sqlite` | App state tracking (installed_template, installed_at, sites, projects) | Setting model, Eloquent models |

**config.json stays as a file** because:
- Human-readable and editable with any text editor
- Source for derived configs (dnsmasq.conf, Caddyfile)
- Standard pattern for CLI tools
- Inspectable for debugging (`cat ~/.config/orbit/config.json`)

**When renaming stored values**, use lazy migration on read (see `docs/solutions/refactoring/lazy-migration-pattern-template-rename-20260208.md`).

## Project Architecture

**Orbit CLI** - Local PHP dev environment with host PHP-FPM/Caddy and Docker-backed services.

### Directory Structure

```
app/
├── Actions/Install/     # Orbit installation steps
├── Commands/            # Artisan CLI commands
├── Concerns/            # Shared traits
├── Contracts/           # Interfaces (Template, etc.)
├── Data/                # DTOs and value objects
├── Enums/               # PHP enums
├── Providers/           # Service providers
├── Templates/           # Installation templates (DevelopmentTemplate, etc.)
└── Services/            # Business logic
    └── Platform/        # OS-specific adapters
```

**Note:** Site provisioning logic lives in `orbit-core`, but the CLI runs it synchronously with real-time output.

### Key Patterns

| Pattern | Location | Purpose |
|---------|----------|---------|
| Commands | `app/Commands/` | User-facing CLI interface |
| Templates | `app/Templates/` | Installation templates defining platform-specific steps |
| Actions | `app/Actions/Install/` | Orbit installation steps |
| Services | `app/Services/` | CLI-specific business logic |
| GatewayCliAdapter | `app/Services/GatewayCliAdapter.php` | CLI-specific Process operations (SSH, Docker, ifconfig) |
| Platform Adapters | `app/Services/Platform/` | Cross-platform abstraction |
| DTOs | `app/Data/` | Type-safe data containers |
| ReverbBroadcaster | `app/Services/` | WebSocket broadcasting to Reverb |
| ProvisionLogger | `app/Services/` | CLI's provisioning logger (implements `ProvisionLoggerContract`) |
| DeletionLogger | `app/Services/` | CLI's deletion logger (implements `ProvisionLoggerContract`) |

### Site Provisioning Architecture

Site provisioning uses `orbit-core`'s `ProvisionPipeline` but runs synchronously in the CLI for real-time output:

```
CLI site:create command
    ↓
Creates Site record in database (status: queued)
    ↓
Runs ProvisionPipeline synchronously (real-time console output)
    ↓
ProvisionLogger broadcasts to Reverb → Web UI updates
    ↓
Site marked ready on completion
```

The CLI provides its own `ProvisionLogger` implementation that:
1. Outputs to console for real-time feedback
2. Broadcasts to Reverb via Pusher SDK for web UI updates
3. Implements `ProvisionLoggerContract` interface from orbit-core

### Site Deletion Architecture

Site deletion uses `orbit-core`'s `DeletionPipeline` (also synchronously):

```
CLI site:delete command
    ↓
Finds Site record in database (if exists)
    ↓
Runs DeletionPipeline synchronously (real-time console output)
    ↓
DeletionLogger broadcasts to Reverb → Web UI updates
    ↓
Site record deleted from database
```

The CLI provides its own `DeletionLogger` implementation that:
1. Outputs to console for real-time feedback
2. Broadcasts to Reverb via Pusher SDK for web UI updates
3. Implements `ProvisionLoggerContract` interface from orbit-core

**Key flags:**
- `--force` - Skip confirmation prompt
- `--keep-db` - Don't drop the PostgreSQL database
- `--delete-repo` - Delete GitHub repository (passed to Sequence MCP)

## Code Style Guidelines

### PHP Conventions

```php
<?php

declare(strict_types=1);

namespace App\...;

final class MyClass              // Final by default
final readonly class MyAction    // Immutable actions/DTOs
```

### Architecture Rules

- **Commands** orchestrate actions and services
- **Actions** are single-purpose, return `StepResult`
- **Services** handle shared business logic
- All code must work on **both Linux and macOS**
- Use `PlatformAdapter` for OS-specific operations

### Package Dependencies

orbit-cli depends on **orbit-core** for shared business logic (Models, Services, Jobs, DTOs).

**Note**: orbit-core was split from a monolithic package to resolve Laravel Zero PHAR build conflicts. It now contains only PHP business logic, no UI components.

```php
// Import models from orbit-core
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Gateway;

// Import gateway services from orbit-core
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;

// Import pipelines
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
use HardImpact\Orbit\Core\Services\Deletion\DeletionPipeline;

// Import data objects
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Data\StepResult;

// CLI-specific adapter for Process-based operations
use App\Services\GatewayCliAdapter;
```

**Gateway architecture**: Gateway business logic (GatewayManager, WgEasyService, GatewayDnsService) lives in orbit-core. CLI-specific operations that use the Process facade (SSH, Docker commands, ifconfig) live in `GatewayCliAdapter`.

**Important**: Always use `HardImpact\Orbit\Core\` namespace, never `HardImpact\Orbit\`

## Quality Gates

**IMPORTANT:** Every fix must have a test.

Run before every commit:

```bash
./vendor/bin/rector --dry-run
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

## Known Gotchas (Summary)

> Detailed explanations in subdirectory AGENTS.md files

| Gotcha | See File |
|--------|----------|
| Path repositories break CI | Root (below) |
| Bun hangs in background | `app/Actions/AGENTS.md` |
| Platform-specific commands | `app/Services/AGENTS.md` |
| PHP-FPM restart kills web requests | `app/Services/AGENTS.md` |
| JSON output must be clean | `app/Commands/AGENTS.md` |
| Gateway commands need deploy | Root (below) |
| `encrypt()`/`decrypt()` unavailable | Root (below) |

### No encrypt()/decrypt() in Laravel Zero

Laravel Zero doesn't register `EncryptionServiceProvider` or configure an `APP_KEY`. Calling `encrypt()` throws `Target class [encrypter] does not exist`. Store secrets in plain text in the SQLite DB (user-owned, server-local).

### Gateway Commands Need Build+Deploy

Commands that run ON the gateway (e.g., `gateway:clients`, `gateway:set-password`) must be built into a phar and deployed before testing. The gateway runs its own binary at `~/.local/bin/orbit`:

```bash
~/.composer/vendor/bin/box compile
scp builds/orbit.phar gateway@188.245.156.201:~/.local/bin/orbit
```

### NEVER Use Path Repositories

Path repositories in `composer.json` break CI/CD:

```json
// NEVER DO THIS
"repositories": [{"type": "path", "url": "../orbit-core"}]
```

**Solution:** Always use Packagist: `"hardimpactdev/orbit-core": "@dev"`

**If CI is broken:**
1. Remove repositories section from composer.json
2. Delete composer.lock
3. Run `composer install`
4. Commit both files

## Host Services

**IMPORTANT:** Caddy runs on the host via systemd, NOT in Docker.

### Caddy Web Server (Linux)

```bash
sudo systemctl status caddy
sudo systemctl reload caddy      # Reload config after changes
sudo journalctl -u caddy -f      # View logs
orbit caddy:reload               # Regenerate Caddyfile AND reload Caddy
```

Config location: `~/.config/orbit/caddy/Caddyfile` (imported by `/etc/caddy/Caddyfile`)

**Note:** The `caddy:reload` command is the preferred way to update Caddy config after adding new sites. It regenerates the Caddyfile from all detected sites and reloads Caddy in one step. This is called automatically by `CreateSiteJob` during site provisioning.

### Reverb WebSocket

- Docker container: `orbit-reverb`
- Port: `8080`
- Caddy proxies `reverb.{tld}` to `localhost:8080`

## Session Completion (Landing the Plane)

**When ending a session**, complete ALL steps:

1. **File issues** for remaining work
2. **Run quality gates** (if code changed)
3. **Update issue status** - close finished work
4. **PUSH TO REMOTE** - MANDATORY:
   ```bash
   git pull --rebase
   bd sync
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Verify** - All changes committed AND pushed

**CRITICAL:** Work is NOT complete until `git push` succeeds.
