# Agent Instructions

## Project Overview

**orbit-core** is a Laravel package that provides business logic and data models for the Orbit ecosystem. It contains no UI components - just PHP classes for models, services, jobs, and data structures. Required by orbit-cli, orbit-app, and indirectly by orbit-web/orbit-desktop.

## Monorepo Structure

All packages live in the `orbit-dev` monorepo:

| Package | Path | Purpose |
|---------|------|---------|
| orbit-core | `packages/core` | Business logic, models, services, migrations |
| orbit-app | `packages/app` | Web UI, MCP servers, controllers, Vue frontend |
| orbit-cli | `packages/cli` | Laravel Zero CLI tool |
| orbit-web | `packages/web` | Deployable Laravel shell (requires orbit-app) |
| orbit-desktop | `packages/desktop` | NativePHP desktop shell |

## Package Structure

```
src/
  Models/                    # Eloquent models
    Node.php                 # Has editor_scheme, node_type (local/gateway/client), environment (dev/staging/prod)
    Gateway.php              # Gateway config (ip, subnet, ssh_user, vpn fields)
    GatewayProject.php       # Gateway-level project registry (slug, production_domain, cloudflare_zone_id)
    Project.php              # Project model (local dev projects, deployed projects)
    Workspace.php            # Workspace model for project organization
    Deployment.php           # Cross-node deployment tracking (project_slug, node_id, gateway_project_id, status, cloudflare_record_id)
    Setting.php              # Key-value store (wg_easy_password, cloudflare_api_token, etc.)
    SshKey.php
    TemplateFavorite.php
    TrackedJob.php           # Job tracking
    UserPreference.php
  Contracts/
    ProvisionLoggerContract.php  # Interface for provision loggers
  Services/
    Gateway/                 # Gateway/VPN services (used by CLI + MCP)
      GatewayManager.php     # CRUD gateways, VPN client registration
      WgEasyService.php      # WireGuard VPN API (host, port, password)
      GatewayDnsService.php  # TLD→IP mappings via dnsmasq config files
    CloudflareService.php    # Cloudflare API v4 wrapper (multi-zone DNS records, zone detection, SSL)
    DeploymentService.php    # Cross-node deployment orchestration (deploy, deployProject, undeploy, sync)
    ProvisioningService.php  # Project provisioning orchestration
    NodeService.php          # Node operations
    NodeManager.php          # Node CRUD and management
    SettingEncryptor.php     # Transparent encryption for sensitive Setting values
    DoctorService.php        # Health checks
    SshService.php           # SSH operations
    CliUpdateService.php     # CLI update handling
    DnsResolverService.php   # DNS resolution
    NotificationService.php  # Notifications
    HorizonService.php       # Horizon queue management
    SetupService.php         # Setup and initialization
    WorkspaceDbService.php   # Workspace database operations
    MacPhpFpmConfigService.php  # macOS PHP-FPM configuration
    Provision/               # Project provisioning pipeline
      ProvisionPipeline.php  # Main orchestrator (accepts ProvisionLoggerContract)
      ProvisionLogger.php    # orbit-core's logger (native Laravel events)
      Actions/               # Provisioning steps (CloneRepository, etc.)
    Deletion/                # Project deletion pipeline
      DeletionPipeline.php   # Main orchestrator
      DeletionLogger.php     # orbit-core's deletion logger
      Actions/               # Deletion steps (DropPostgresDatabase, etc.)
    OrbitCli/                # CLI interaction wrappers
      ProjectCliService.php  # Project CLI operations
      ConfigurationService.php  # Includes DNS mapping methods
      ServiceControlService.php
      StatusService.php
      PackageService.php     # Package management
      WorkspaceService.php   # Workspace management
      WorktreeService.php    # Git worktree support
  Data/
    ProvisionContext.php     # Context for provisioning actions
    DeletionContext.php      # Context for deletion actions
    StepResult.php           # Action result wrapper
  Enums/
    RepoIntent.php           # Repository operation type enum
    NodeType.php             # local, gateway, client
    NodeEnvironment.php      # development, staging, production
    DeploymentStatus.php     # pending → deploying → ... → active/failed/removed
  Events/
    ProjectProvisioningStatus.php  # Broadcasting provisioning progress
    ProjectDeletionStatus.php      # Broadcasting deletion progress
  Jobs/
    CreateProjectJob.php     # Async project creation
    DeleteProjectJob.php     # Async project deletion
  Console/Commands/
    OrbitInit.php            # CLI initialization
config/
  orbit.php                  # Package configuration
database/
  migrations/                # Database migrations (nodes, gateways, sites, etc.)
  factories/                 # Model factories
```

## Namespace Convention

All classes use `HardImpact\Orbit\Core` namespace:

```php
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
```

## Development Workflow

orbit-core is a PHP-only package with no frontend assets. Development consists of:

### Local Development

```bash
cd ~/projects/orbit-core
composer test                   # Run PHPUnit tests
composer analyse                # Run PHPStan analysis
composer format                 # Format with Pint
```

### Package Changes

1. Make changes in this package
2. Run tests: `composer test`
3. Commit and push
4. Update consumers: `composer update hardimpactdev/orbit-core`

## Testing

```bash
composer test           # Run all tests
composer analyse        # PHPStan analysis
composer format         # Format with Pint
```


## Important Notes

- Always use fully qualified namespace for models/services
- Never use `App\Models\*` - always `HardImpact\Orbit\Core\Models\*`  
- This package contains NO UI components - only business logic
- UI components (controllers, routes, views) live in orbit-app

### Package Architecture

orbit-core is a business logic package that provides models, services, jobs, and data structures. It contains no UI components.

**What orbit-core provides:**
- Eloquent Models (Node, Gateway, GatewayProject, Project, Workspace, Setting, Deployment, etc.)
- Gateway Services (GatewayManager, WgEasyService, GatewayDnsService)
- CloudflareService (multi-zone DNS management with per-zone operations)
- Pipelines (ProvisionPipeline, DeletionPipeline)
- CLI Wrapper Services (StatusService, ProjectCliService, etc.)
- Jobs (CreateProjectJob, DeleteProjectJob)
- Data Transfer Objects (ProvisionContext, DeletionContext)
- Database migrations and factories

**Consumed by:**
- orbit-cli - Direct dependency for models, gateway services, pipelines
- orbit-app - Direct dependency for models, services, MCP tool backends
- orbit-web/desktop - Indirect dependency through orbit-app

## Flow Documentation

**Critical workflow documentation belongs in `/docs/flows/` (not yet created).**

When adding complex workflows, document them as markdown files with step-by-step flows, data structures, and architectural diagrams.

### Core Architecture Principle

All long-running operations (project creation, provisioning, etc.) MUST:
1. Dispatch a Job to the queue
2. Return immediately to the user
3. Process asynchronously via Horizon
4. Broadcast progress via WebSocket (Echo configured globally in `resources/js/app.ts`)

**Never call CLI commands synchronously from controllers.**

> **Clarification**: This async rule applies to web/desktop consumers (Controllers). Jobs are *supposed* to call the CLI synchronously - that's the whole point of queuing work. The "async" is about freeing the HTTP response, not the job execution itself.

## Site Provisioning Pipeline

Project provisioning logic lives in orbit-core and can be invoked from:
1. **Jobs** (via Horizon) - for web UI initiated creation
2. **CLI** (synchronously) - for `site:create` command with real-time output

### Architecture

The `ProvisionPipeline` accepts a `ProvisionLoggerContract` interface, allowing different consumers to provide their own logging implementation:

```
Web UI → CreateProjectJob (Horizon)        CLI → ProjectCreateCommand
              ↓                                    ↓
       ProvisionPipeline (shared)          ProvisionPipeline (shared)
              ↓                                    ↓
  ProvisionLogger (native events)     ProvisionLogger (console + Pusher)
              ↓                                    ↓
    ProjectProvisioningStatus → Reverb        Pusher SDK → Reverb
              ↓                                    ↓
          Frontend                            Frontend
```

### Key Classes

| Class | Purpose |
|-------|---------|
| `ProvisionPipeline` | Main orchestrator - determines flow based on RepoIntent |
| `ProvisionLoggerContract` | Interface for logging implementations |
| `ProvisionLogger` | orbit-core's implementation using native `event()` calls |
| `ProvisionContext` | Data transfer object for action context |
| `StepResult` | Result wrapper with success/failure + data |
| `RepoIntent` | Enum: Clone, Fork, Template, Composer |

### CLI Integration

The orbit-cli has its own `ProvisionLogger` that implements `ProvisionLoggerContract`:
- Outputs to console for real-time feedback
- Broadcasts to Reverb via Pusher SDK for web UI updates
- Both consumers use the same `ProvisionPipeline` logic

### Provision Actions

Located in `src/Services/Provision/Actions/`:

| Action | Purpose |
|--------|---------|
| `CloneRepository` | Clone repo via `gh repo clone` |
| `ForkRepository` | Fork repo to user's account |
| `CreateGitHubRepository` | Create new repo from template |
| `InstallComposerDependencies` | Run `composer install` |
| `InstallNodeDependencies` | Run `bun ci` or `npm ci` |
| `BuildAssets` | Run build scripts |
| `ConfigureEnvironment` | Generate `.env` file |
| `CreateDatabase` | Create SQLite/PostgreSQL database |
| `GenerateAppKey` | Run `artisan key:generate` |
| `RunMigrations` | Run `artisan migrate` |
| `ConfigureTrustedProxies` | Laravel proxy config |
| `SetPhpVersion` | Create `.php-version` file |
| `RestartPhpContainer` | Restart PHP-FPM |
| `ValidatePackagistPackage` | Check Packagist for composer create |

### Native Broadcasting Events

Status updates use `ShouldBroadcastNow` for immediate delivery:

```php
// ProjectProvisioningStatus
event(new ProjectProvisioningStatus(
    slug: $slug,
    status: 'installing_composer',
    error: null,
    siteId: $site->id,
));
```

Events broadcast on the `provisioning` channel with event names:
- `project.provision.status` - Site creation progress
- `project.deletion.status` - Project deletion progress

## Site Deletion Pipeline

Project deletion mirrors the provisioning architecture with discrete, testable actions:

### Architecture

```
Web UI → DeleteProjectJob (Horizon)        CLI → ProjectDeleteCommand
              ↓                                    ↓
       DeletionPipeline (shared)          DeletionPipeline (shared)
              ↓                                    ↓
  DeletionLogger (native events)     DeletionLogger (console + Pusher)
              ↓                                    ↓
    ProjectDeletionStatus → Reverb          Pusher SDK → Reverb
              ↓                                    ↓
          Frontend                            Frontend
```

### Key Classes

| Class | Purpose |
|-------|---------|
| `DeletionPipeline` | Main orchestrator - runs actions in sequence |
| `DeletionContext` | Data transfer object with site info + options |
| `DeletionLogger` | orbit-core's logger (native Laravel events) |
| `DeleteProjectJob` | Queueable job for web-initiated deletion |

### Deletion Actions

Located in `src/Services/Deletion/Actions/`:

| Action | Purpose | Failure Behavior |
|--------|---------|------------------|
| `DropPostgresDatabase` | Terminate connections + DROP DATABASE | Non-fatal (warn only) |
| `DeleteProjectFiles` | rm -rf project directory (sudo fallback) | Fatal (fails pipeline) |
| `RegenerateCaddyConfig` | Regenerate Caddyfile, reload Caddy | Non-fatal (warn only) |

### DeletionContext Factory Methods

```php
// From Site model
$context = DeletionContext::fromProject($project, keepDatabase: false);

// Load database config from .env file
$context = $context->withDatabaseFromEnv();
```

### Pipeline Flow

1. **DropPostgresDatabase** - Skipped if `keepDatabase=true` or not PostgreSQL
2. **DeleteProjectFiles** - Required step (pipeline fails if this fails)
3. **RegenerateCaddyConfig** - Removes site from Caddyfile

**Note:** Project model deletion is handled by the caller (CLI/Job), not the pipeline.

### Broadcast Statuses

| Status | Description |
|--------|-------------|
| `deleting` | Starting deletion |
| `dropping_database` | Dropping PostgreSQL database |
| `removing_files` | Deleting project directory |
| `regenerating_caddy` | Updating Caddy config |
| `deleted` | Successfully deleted |
| `delete_failed` | Deletion failed |

### Future Extensions

When GitHub repository deletion is implemented:
1. Add `DeleteGitHubRepository` action
2. Set `DeletionContext.keepRepository = false`
3. Add `--delete-repo` flag to CLI/web UI

## After Making Changes

**IMPORTANT: Always complete the full workflow below:**

1. **Test locally**: `composer test`
2. **Run static analysis**: `composer analyse`
3. **Format code**: `composer format`
4. **Commit changes**: Use descriptive commit message
5. **Push via gh CLI**: `git push`
6. **Update consumers** (if needed):
   ```bash
   # orbit-cli (if it uses the changed classes)
   cd ~/projects/orbit-cli
   composer update hardimpactdev/orbit-core
   
   # orbit-app (always needs latest core)
   cd ~/projects/orbit-app
   composer update hardimpactdev/orbit-core
   ```

**Note:** orbit-core has no assets or UI components. All UI development happens in orbit-app.
