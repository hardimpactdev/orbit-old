# project:create overview

Creates a new project by running the ProvisionPipeline synchronously with real-time output.

## Architecture

```
CLI (project:create)
    |
    v
Create Project record in database
    |
    v
ProvisionPipeline (synchronous)
    |
    ├── Repository operations (clone/fork/template)
    ├── Dependency installation (composer, npm/bun)
    ├── Environment configuration
    ├── Database setup
    └── Caddy reload
```

## Flows

| Scenario | Command | Behavior |
|----------|---------|----------|
| Contribute to project | `project:create my-app --clone=user/repo` | Clone repo, origin points to source |
| Contribute via your copy | `project:create my-app --clone=user/repo --fork` | Fork to your account, clone your fork |
| Use GitHub template | `project:create my-app --template=org/template` | Create new repo from template |

## Process

1. CLI validates the project name (rejects reserved name "orbit")
2. CLI creates Project record in database
3. CLI runs ProvisionPipeline synchronously with real-time console output
4. ProvisionLogger broadcasts status via Reverb for web UI updates

### Pipeline execution (in orbit-core)

The ProvisionPipeline handles all provisioning:

1. Create project directory
2. Repository operations (clone, fork, or create from template)
3. Install composer dependencies
4. Detect and install Node dependencies (bun or npm)
5. Build assets
6. Configure environment (.env)
7. Create database
8. Generate app key
9. Run migrations
10. Set PHP version
11. Detect project type (laravel-app, laravel-package, cli, web)
12. Broadcast ready status via WebSocket
13. Regenerate Caddyfile and reload Caddy

## Failure and recovery paths

- Pipeline failures update Project status to 'failed' with error message
- Errors are broadcast via WebSocket for real-time UI updates
- Empty project directories are cleaned up on failure

## Inputs and options

| Option | Description |
|--------|-------------|
| `name` (required) | Project name |
| `--clone` | Repository to clone |
| `--template` | GitHub template repository |
| `--fork` | Fork instead of clone (only with `--clone`) |
| `--organization` | GitHub organization for new repos |
| `--visibility` | Repository visibility (private/public) |
| `--php` | PHP version (8.3, 8.4, 8.5) |
| `--db-driver` | Database driver (sqlite, pgsql) |
| `--session-driver` | Session driver (file, database, redis) |
| `--cache-driver` | Cache driver (file, database, redis) |
| `--queue-driver` | Queue driver (sync, database, redis) |
| `--json` | Output as JSON for programmatic use |

## URL normalization

The CLI normalizes git URLs to `owner/repo` format:

- `git@github.com:user/repo.git` -> `user/repo`
- `https://github.com/user/repo` -> `user/repo`
- `user/repo` -> `user/repo` (unchanged)

## Key integrations

- **orbit-core ProvisionPipeline**: Runs provisioning steps synchronously
- **Laravel Reverb**: Broadcasts real-time status updates via WebSocket
- **GitHub (gh cli)**: Repository operations (clone, fork, template)
- **Caddy**: HTTPS configuration via `orbit caddy:reload`
