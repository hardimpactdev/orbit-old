# Agent Instructions

## Project Overview

**orbit-app** is a Laravel package that provides the web interface and MCP servers for the Orbit ecosystem. It contains controllers, routes, Vue components, assets, and MCP tool/resource definitions. It depends on orbit-core for business logic and is required by orbit-web and orbit-desktop.

## Monorepo Structure

All packages live in the `orbit-dev` monorepo:

| Package | Path | Purpose |
|---------|------|---------|
| orbit-app | `packages/app` | This package — web UI + MCP servers |
| orbit-core | `packages/core` | Business logic, models, services |
| orbit-cli | `packages/cli` | Laravel Zero CLI tool |
| orbit-web | `packages/web` | Deployable Laravel shell |
| orbit-desktop | `packages/desktop` | NativePHP desktop shell |

## Package Structure

```
src/
  Http/
    Controllers/             # All route handlers
      DashboardController.php
      NodeController.php
      ProjectController.php
      ProvisioningController.php
      SettingsController.php
      SshKeyController.php
    Middleware/
      HandleInertiaRequests.php
      ImplicitNode.php
  Mcp/
    OrbitServer.php          # MCP server for local/client nodes
    GatewayServer.php        # MCP server for gateway nodes
    Tools/                   # Orbit MCP tools (status, projects, etc.)
    Tools/Gateway/           # Gateway MCP tools (clients, DNS, etc.)
    Resources/               # Orbit MCP resources
    Resources/Gateway/       # Gateway MCP resources
    Prompts/                 # MCP prompts
  OrbitAppServiceProvider.php      # Package service provider
resources/
  views/
    app.blade.php            # Root Blade template (Horizon-style)
  js/
    pages/                   # Vue page components
    components/              # Reusable Vue components
    layouts/                 # App layouts
    stores/                  # Pinia stores
    composables/             # Vue composables
    types/                   # TypeScript definitions
    lib/                     # Utility libraries
    app.ts                   # Frontend entry point (configures Echo)
  css/
    app.css                  # Tailwind styles
public/
  hot                        # Vite dev server marker (gitignored)
  build/                     # Production assets (gitignored)
routes/
  web.php                    # Web routes
  api.php                    # API routes
  node.php                   # Node-scoped routes
  mcp.php                    # MCP routes (OrbitServer + GatewayServer)
```

## Namespace Convention

All classes use `HardImpact\Orbit\App` namespace:

```php
use HardImpact\Orbit\App\Http\Controllers\NodeController;
use HardImpact\Orbit\App\Http\Middleware\HandleInertiaRequests;
```

Controllers import models/services from orbit-core:

```php
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
```

## Development Workflow

### Local Development (HMR)

```bash
cd ~/projects/orbit-app
bun run dev                     # Creates public/hot, enables HMR
# Visit https://orbit-web.ccc   # Changes reflect instantly
```

### Production Build

```bash
cd ~/projects/orbit-app
bun run build                   # Creates public/build/

cd ~/projects/orbit-web
php artisan vendor:publish --tag=orbit-assets --force
```

### Package Changes

1. Make changes in orbit-app
2. Run tests: `composer test`
3. Build assets: `bun run build`
4. Commit and push
5. Update consumers: `composer update hardimpactdev/orbit-app`

## Testing

```bash
composer test           # Run all tests
composer analyse        # PHPStan analysis
composer format         # Format with Pint
```

## Important Notes

- Always import models/services from `HardImpact\Orbit\Core\` namespace
- Never use `App\Models\*` - always `HardImpact\Orbit\Core\Models\*`
- Routes are registered via `OrbitAppServiceProvider::routes()` in consumer apps
- Asset publishing tag is `orbit-assets`

## Controller Naming Conventions

Two patterns exist for node-related controllers:

| Pattern | Example | When to Use |
|---------|---------|-------------|
| `NodeXxxController` | `NodeProjectController` | Explicit node from route parameter |
| `XxxController` | `ProjectController` | Uses "active node" from Saloon connector |

When creating new controllers that operate on a specific node passed as a route parameter, prefix with `Node` to avoid conflicts with controllers using the implicit active node pattern.

## Horizon-Style Architecture

orbit-app follows the Laravel Horizon pattern - a self-contained package that serves its own views and assets. Shell apps (orbit-web, orbit-desktop) are empty wrappers.

**What orbit-app provides automatically:**
- Blade view (`orbit::app`) - set as Inertia root view
- Middleware (`HandleInertiaRequests`, `implicit.node`) - auto-registered
- Vite configuration - hot file at `public/hot`, build at `vendor/orbit/build`
- MCP servers (OrbitServer for local nodes, GatewayServer for gateway nodes)

**Shell apps only need:**
```php
// routes/web.php
\HardImpact\Orbit\App\OrbitAppServiceProvider::routes();
```

## MCP Servers

Two MCP servers with conditional registration based on node type:

### OrbitServer (`orbit`)
Registers on Local/Client nodes. 10 tools, 4 resources, 2 prompts for site management.

### GatewayServer (`gateway`)
Registers on Gateway nodes. 15 tools, 3 resources for VPN/DNS management, cross-node deployment tracking, and Cloudflare DNS.

### Conditional Registration
All tools implement `shouldRegister()` checking `Node::getSelf()`. Gateway tools check `->isGateway()`, orbit tools check `!isGateway()`.

### Schema Pattern
Tool schemas return a flat array of Type objects:
```php
public function schema(JsonSchema $schema): array
{
    return [
        'name' => $schema->string()->required()->description('Project name'),
        'template' => $schema->string()->description('Optional template'),
    ];
}
```

### Routes (`routes/mcp.php`)
```php
Mcp::local('orbit', OrbitServer::class);      // stdio
Mcp::local('gateway', GatewayServer::class);  // stdio
Mcp::web('mcp/orbit', OrbitServer::class);    // HTTP POST /mcp/orbit
Mcp::web('mcp/gateway', GatewayServer::class); // HTTP POST /mcp/gateway
```

## UI Conventions

### Vertical Tab Pages
When implementing settings or multi-section pages with vertical tabs:
- Remove border dividers between tabs and content (let spacing create separation)
- Don't duplicate category titles in content when tabs already show active state
- Example: Node Settings page (`/resources/js/pages/nodes/Settings.vue`)

## Mode Configuration

The package supports two modes via `config("orbit.multi_node")`:

- **Web mode** (`false`): Single implicit node, flat routes
- **Desktop mode** (`true`): Multiple nodes, prefixed routes

## WebSocket (Echo/Reverb)

Orbit's frontend uses Laravel's official `@laravel/echo-vue` composables with a
single global Echo connection. The Reverb configuration comes from the active
environment and is injected as an Inertia prop. Component-level subscriptions are
managed by the composables and automatically cleaned up when components unmount.

Key files:
- `resources/js/app.ts` configures Echo from the `reverb` page prop
- `resources/js/composables/useSiteProvisioning.ts` subscribes via `useEchoPublic`
- `resources/js/pages/nodes/Services.vue` listens for service status updates

## After Making Changes

**IMPORTANT: Always complete the full workflow below:**

1. **Test locally**: `composer test`
2. **Build assets**: `bun run build`
3. **Run static analysis**: `composer analyse`
4. **Format code**: `composer format`
5. **Commit changes**: Use descriptive commit message
6. **Push via gh CLI**: `git push`
7. **Update orbit-web**:
   ```bash
   cd ~/projects/orbit-web
   composer update hardimpactdev/orbit-app
   php artisan vendor:publish --tag=orbit-assets --force
   ```

## Asset Publishing Gotcha

When making UI changes that you want to see on orbit-web.ccc:

1. **If Vite dev server is NOT running**: You must build AND publish
   ```bash
   cd ~/projects/orbit-app
   bun run build
   
   cd ~/projects/orbit-web
   php artisan vendor:publish --tag=orbit-assets --force
   ```

2. **Why changes might not appear**: 
   - orbit-web serves from `public/vendor/orbit/build/`
   - These are COPIED from orbit-app, not symlinked
   - Publishing is required after each build

3. **To verify**: Check the timestamp
   ```bash
   ls -la ~/projects/orbit-web/public/vendor/orbit/
   ```

## Vite Development Server with Caddy HTTPS Proxy

When running the Vite dev server behind Caddy for HTTPS:

1. **Use VITE_APP_URL** (not APP_URL) in package.json:
   ```json
   "dev": "sh -c 'VITE_APP_URL=https://$0 vite'"
   ```

2. **Why this matters**: craft-ui's vite config reads `VITE_APP_URL` to:
   - Configure HMR websocket to connect through proxy (`wss://domain.ccc:443`)
   - Set proper origin for CORS and asset URLs
   - Write HTTPS URL to hot file instead of `http://0.0.0.0:5173`

3. **Symptoms of incorrect config**:
   - Browser error: "Mixed Content: page loaded over HTTPS but requested insecure script"
   - HMR not working (changes don't reflect instantly)
   - Hot file contains `http://0.0.0.0:5173` instead of `https://domain.ccc`

4. **To verify it's working**:
   ```bash
   cat ~/projects/orbit-app/public/hot  # Should show https://orbit-web.ccc
   ```