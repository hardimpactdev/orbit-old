# orbit-web

Empty Laravel 12 shell for orbit-app. All UI, routes, and assets come from the orbit-app package (which depends on orbit-core for business logic).

## Architecture

orbit-web is intentionally minimal - just Laravel boilerplate + `composer require orbit-app` (orbit-app depends on orbit-core).

**Dependency chain:** orbit-web → orbit-app → orbit-core

```
orbit-web/
├── app/Providers/
│   ├── AppServiceProvider.php     ← calls OrbitAppServiceProvider::routes()
│   └── HorizonServiceProvider.php
├── bootstrap/
│   └── app.php                    ← registers HandleInertiaRequests middleware
├── config/
├── database/
├── public/
│   └── vendor/orbit/build/        ← published assets (production)
├── routes/
│   └── web.php                    ← empty (orbit-core provides routes)
├── .env
└── composer.json
```

**No frontend files** - no `resources/js`, `resources/css`, `resources/views`, `vite.config.js`, `package.json`.

## How It Works

1. **Routes**: `OrbitAppServiceProvider::routes()` registers all routes from orbit-app
2. **Views**: orbit-app provides `resources/views/app.blade.php` via `loadViewsFrom()`
3. **Assets**: In dev, Vite serves from orbit-app's dev server. In prod, published to `public/vendor/orbit/build/`
4. **Middleware**: `HandleInertiaRequests` comes from orbit-app

## Development

**You don't develop here.** All UI development happens in orbit-app.

```bash
# Start dev server in orbit-app
cd ~/projects/orbit-app
bun run dev

# View in browser
open https://orbit-web.ccc
```

HMR works because orbit-app's service provider configures `Vite::useHotFile()` to point to the package's hot file.

## Production

```bash
# Build assets in orbit-app
cd ~/projects/orbit-app
bun run build

# Publish to this shell
cd ~/projects/orbit-web
php artisan vendor:publish --tag=orbit-assets --force
```

## Key Files

| File | Purpose |
|------|---------|
| `app/Providers/AppServiceProvider.php` | Calls `OrbitAppServiceProvider::routes()` |
| `bootstrap/app.php` | Registers `HandleInertiaRequests` middleware |
| `config/orbit.php` | Published orbit-core config |
| `.env` | Environment config (ORBIT_MODE, ORBIT_CLI_PATH, etc.) |

## Commands

```bash
# Update orbit-app (pulls in orbit-core)
composer update hardimpactdev/orbit-app

# Publish config
php artisan vendor:publish --tag=orbit-config

# Publish assets (production)
php artisan vendor:publish --tag=orbit-assets --force

# Run Horizon
php artisan horizon
```

## Environment Variables

Key orbit-specific variables in `.env`:

```
ORBIT_MODE=web
ORBIT_CLI_PATH=/home/nckrtl/projects/orbit-cli/orbit  # Path to orbit CLI executable
DB_DATABASE=/home/nckrtl/.config/orbit/database.sqlite  # Shared with CLI
VITE_REVERB_HOST=reverb.ccc
```

For development:
- `ORBIT_CLI_PATH` points to the orbit-cli project (changes take effect immediately)
- `DB_DATABASE` points to the CLI's database (shared data)

## Database Migrations

**orbit-web is the migration runner for development.** Run migrations here to update the shared database:

```bash
cd ~/projects/orbit-web
php artisan migrate
```

This runs orbit-core's migrations (via orbit-app) against the shared CLI database.

## Testing

Tests live in orbit-app and orbit-core. This shell only needs basic smoke tests.

```bash
php artisan test
```

## Related Projects

- **orbit-app**: UI package - controllers, routes, Vue components, MCP servers
- **orbit-core**: Business logic package - models, services, pipelines, migrations
- **orbit-cli**: CLI tool
- **orbit-desktop**: NativePHP shell (also uses orbit-app → orbit-core)
