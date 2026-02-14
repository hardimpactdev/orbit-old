# Orchestrator Development

The **orchestrator** is a Laravel backend that provides:

- MCP tools for git operations, project management, task tracking
- API endpoints called by the desktop app (via `orchestrator_url`)
- Cross-project management functionality

## Making Orchestrator Changes

```bash
# SSH into the dev server
ssh orbit@ai

# Navigate to orchestrator
cd ~/projects/orchestrator

# Make changes, run tests
php artisan test

# After controller changes
php artisan waymaker:generate
```

## Key Patterns (from orchestrator CLAUDE.md)

- **Actions** (`app/Actions/`) - Business logic with single `handle()` method
- **Services** (`app/Services/`) - Infrastructure/API wrappers
- **DTOs** (`app/Data/`) - Data transfer objects using spatie/laravel-data
- Uses Waymaker for routing - NEVER edit web.php manually
