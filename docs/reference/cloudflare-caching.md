# Cloudflare Caching

Orbit's Cloudflare integration provides multi-layer caching for production sites: static asset caching via Caddy headers, and full-page CDN caching via Cloudflare's edge network. Based on techniques from the [Fast Laravel](https://fastlaravel.com/) course.

## Architecture

```
Browser ←→ Cloudflare Edge (CDN cache) ←→ Caddy (origin) ←→ PHP-FPM
```

Three caching layers:

| Layer | What it caches | Controlled by |
|-------|---------------|---------------|
| Browser | Everything with `max-age` | `Cache-Control` header |
| Cloudflare Edge | Eligible responses (no `Set-Cookie`, `public` directive) | Cache Rule + `Cache-Control` header |
| Caddy | Nothing (origin server) | N/A |

**Why `Set-Cookie` matters**: Laravel's default `web` middleware group adds `Set-Cookie` headers (session + CSRF). Cloudflare correctly refuses to cache any response with `Set-Cookie`. Simply adding `Cache-Control: public` does NOT work — the cookies still prevent caching.

**The solution**: A separate stateless `static` middleware group without session/cookie middleware. Routes in this group produce responses with no `Set-Cookie` headers, allowing Cloudflare to cache them.

## Playbook: Enable Caching for a Site

This is the step-by-step procedure. The infrastructure side (steps 1-2) is handled automatically by Orbit on deploy. The application side (steps 3-5) requires changes to the Laravel app. Step 6 creates the Cloudflare cache rule.

### Step 1: Infrastructure (automatic on deploy)

Orbit handles all of this when deploying to production via `gateway_deploy`:

- **Proxied DNS**: `DeploymentService` creates the A record with `proxied: true` for production nodes, routing traffic through Cloudflare's CDN.
- **SSL mode**: Sets Cloudflare SSL to `strict` (Caddy has real ACME certs via DNS-01).
- **Caddy cache headers**: The production Caddy template includes `Cache-Control: public, max-age=31536000, immutable` for `/build/*` (Vite's content-hashed static assets).
- **DNS-01 TLS**: Caddy uses `caddy-dns/cloudflare` module for certificate acquisition, avoiding the chicken-and-egg problem with proxied DNS + HTTP-01 ACME.
- **CF token provisioned**: `RemoteDeploymentOrchestrator::ensureCaddyCloudflareToken()` auto-sets the Cloudflare API token in Caddy's systemd environment on first deploy.
- **Cache purge**: `DeploymentService` automatically purges the Cloudflare cache after every successful deploy and undeploy.

**Nothing to do here** — this is automatic for any site deployed via `gateway_deploy` to a production node.

### Step 2: Verify static asset caching

After deploying, verify that Vite build assets are cached:

```bash
# Should show: Cache-Control: public, max-age=31536000, immutable
# Should show: cf-cache-status: HIT (on second request)
curl -s -D- -o /dev/null https://example.com/build/assets/app-XXXX.js | grep -iE "cache-control|cf-cache"
```

If `cf-cache-status` shows `DYNAMIC`, the Cloudflare cache rule hasn't been created yet (step 6).

### Step 3: Create the CacheControl middleware

In the Laravel app repository, create `app/Http/Middleware/CacheControl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheControl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! app()->environment('production')) {
            return $response;
        }

        if (! $request->isMethod('GET')) {
            return $response;
        }

        if (! $response->isSuccessful()) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
```

This sets `Cache-Control: public, max-age=86400` (24 hours) only on successful GET requests in production. Adjust `max-age` per site needs.

### Step 4: Register the `static` middleware group

In `bootstrap/app.php`, add the `static` middleware group and `routes/static.php` routing:

```php
use App\Http\Middleware\CacheControl;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('static')->group(base_path('routes/static.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // Keep existing web middleware as-is
        $middleware->web(append: [
            // ... existing middleware
        ]);

        // Stateless group — no StartSession, no EncryptCookies, no VerifyCsrfToken
        $middleware->group('static', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // Add Inertia middleware if the site uses Inertia:
            // \App\Http\Middleware\HandleInertiaRequests::class,
            // Add CSP middleware if the site uses spatie/laravel-csp:
            // \App\Http\Middleware\GenerateAndSetCspNonce::class,
            // \App\Support\Csp\AddCspHeaders::class,
            CacheControl::class,
        ]);
    })
```

The `static` group intentionally omits `StartSession`, `EncryptCookies`, `AddQueuedCookiesToResponse`, `ShareErrorsFromSession`, and `VerifyCsrfToken`. This means: no `Set-Cookie` headers, no session access, no CSRF tokens.

### Step 5: Split routes between `static.php` and `web.php`

Create `routes/static.php` and move cacheable routes there:

```php
<?php
// routes/static.php — pages that can be cached by Cloudflare

// If using Waymaker:
use HardImpact\Waymaker\Facades\Waymaker;
Waymaker::routes();

// Or standard Laravel routes:
Route::get('/', [HomeController::class, 'show']);
Route::get('/about', [AboutController::class, 'show']);
Route::get('/pricing', [PricingController::class, 'show']);
```

Keep dynamic routes in `routes/web.php`:

```php
<?php
// routes/web.php — pages that need sessions/auth/CSRF

Route::get('/login', [LoginController::class, 'show']);
Route::post('/login', [LoginController::class, 'store']);
Route::get('/dashboard', [DashboardController::class, 'show'])->middleware('auth');
```

**Decision guide** — a route goes in `static.php` if it:
- Does NOT call `session()`, `auth()`, `csrf_token()`, or `old()`
- Does NOT set cookies
- Shows the same content to all visitors
- Is OK being stale for up to `max-age` seconds (24h default)

When in doubt, keep it in `web.php`. If a `static.php` route accesses the session, Laravel throws an error immediately — this is the safety net.

### Step 6: Create the Cloudflare cache rule

Use the MCP tool to create the "Cache Everything" rule:

```
gateway_cloudflare_create_cache_rule
  project_slug: "my-project"
```

This creates a cache rule that makes all requests *eligible* for caching, but actual caching depends on the `Cache-Control` header from the origin. Responses with `Set-Cookie` are still bypassed by Cloudflare.

### Step 7: Verify full-page caching

Deploy the app changes, then verify:

```bash
# GET request (not HEAD — middleware only sets headers on GET)
# First request should be MISS, second should be HIT
curl -s -D- -o /dev/null https://example.com/ | grep -iE "cache-control|set-cookie|cf-cache"

# Expected output:
# cache-control: max-age=86400, public
# cf-cache-status: HIT
# (no set-cookie lines)
```

If `cf-cache-status` is `DYNAMIC`:
- Check for `Set-Cookie` headers — means the route is still in the `web` middleware group
- Check `Cache-Control` — if `no-cache, private`, the `CacheControl` middleware isn't running

If `cf-cache-status` is `MISS` on every request:
- The cache rule may not exist — run `gateway_cloudflare_create_cache_rule`
- DNS might not be proxied — check with `gateway_cloudflare_dns`

## Handling Dynamic Content

Most sites have both static pages (marketing, docs) and dynamic pages (dashboards, forms). The key: **static and dynamic routes live in separate middleware groups**.

### Route splitting

```
routes/
├── static.php   # Cached (static middleware — no sessions)
├── web.php      # Not cached (web middleware — has sessions/CSRF)
└── api.php      # Not cached (api middleware — stateless JSON)
```

| Content Type | Route File | Cached? |
|--------------|-----------|---------|
| Marketing pages, docs, landing pages | `static.php` | Yes |
| Login, register, password reset | `web.php` | No |
| User dashboards, account settings | `web.php` | No |
| Contact form submission | `api.php` or `web.php` | No |
| Blog posts (public, no comments) | `static.php` | Yes |

### Forms on cached pages

A cached page cannot include a CSRF token (no session). Two approaches:

**Option A: JavaScript-driven forms (recommended)**

The cached page has the form HTML without a CSRF token. JavaScript fetches a token at submit time:

```php
// routes/api.php
Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]));

// routes/api.php
Route::post('/contact', [ContactController::class, 'store']);
```

```js
const { token } = await fetch('/api/csrf-token').then(r => r.json());
await fetch('/api/contact', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' },
    body: JSON.stringify(formData),
});
```

**Option B: Separate form page**

Link from the cached page to an uncached form page:

```php
// routes/static.php — cached page with link to form
Route::get('/contact', ContactPageController::class);

// routes/web.php — uncached form with CSRF
Route::get('/contact/form', ContactFormController::class);
Route::post('/contact/form', ContactFormSubmitController::class);
```

### Auth-aware UI on cached pages

A cached page can't check `auth()->check()` server-side. Handle it client-side or via a lightweight API:

```php
// routes/api.php
Route::get('/auth/status', fn () => response()->json([
    'authenticated' => auth()->check(),
    'user' => auth()->user()?->only('name', 'avatar'),
]));
```

### Dynamic data on static page shells

Cache the page layout, load dynamic data via JavaScript:

```php
// routes/static.php — cached page shell
Route::get('/blog', BlogIndexController::class);

// routes/api.php — short-lived cached data
Route::get('/api/recent-posts', RecentPostsController::class)
    ->middleware('cache.headers:public;max_age=300'); // 5 min cache
```

### Inertia.js

Inertia works in the `static` middleware group:
- `HandleInertiaRequests` checks `$request->hasSession()` before accessing session data
- `share()` gracefully skips validation errors when no session exists
- Asset versioning works because cache is purged on every deploy

Watch out for:
- Don't share session-dependent data (flash messages, `auth()->user()`) in `HandleInertiaRequests::share()` for static routes — it will error or return stale data
- Inertia's `useForm` needs CSRF tokens — keep form pages in `web.php` or use the JavaScript approach above

### CSP nonces

When Cloudflare caches a page with CSP nonces, the nonce becomes static for the cache duration. Both the HTML body (nonce attribute on script/style tags) and the CSP header are cached together, so they match. The nonce changes on every cache purge (every deploy). This is an acceptable trade-off for public marketing sites with no user input.

### Session alternatives

If a page uses session data unnecessarily (e.g., passing data between middleware and views), replace with:
- **Request attributes**: `$request->attributes->set('key', 'value')` — lives for one request
- **Laravel Context**: `Context::add('key', 'value')` — also useful for logging
- **View data**: `View::share('key', 'value')` — accessible in all Blade templates

## MCP Tools

| Tool | Purpose |
|------|---------|
| `gateway_cloudflare_create_cache_rule` | Create "Cache Everything" rule for a zone (idempotent) |
| `gateway_cloudflare_flush_cache` | Purge cache (everything or specific URLs) |
| `gateway_deploy` | Deploy — auto-creates proxied DNS + purges cache |
| `gateway_cloudflare_zones` | List all Cloudflare zones |
| `gateway_cloudflare_dns` | List DNS records for a zone |
| `gateway_cloudflare_set_ssl` | Set SSL mode (strict for production) |

## Key Files

| File | Role |
|------|------|
| `packages/core/src/Services/CloudflareService.php` | `purgeCache()`, `purgeCacheByUrls()`, `createCacheRule()` |
| `packages/core/src/Services/DeploymentService.php` | Auto-purge on deploy/undeploy, proxied DNS, SSL mode |
| `packages/core/src/Services/RemoteDeploy/RemoteCaddyManager.php` | Caddy template with cache headers + DNS-01 TLS |
| `packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php` | Auto-provisions CF token on nodes |
| `packages/cli/app/Actions/Install/Linux/InstallCaddy.php` | Installs Caddy with cloudflare DNS module |
| `packages/cli/app/Services/CaddyfileGenerator.php` | Dev Caddyfile `(cache_headers)` snippet |
| `packages/cli/stubs/caddy/production-site.caddy.stub` | Production Caddy template |
| `packages/app/src/Mcp/Tools/Gateway/GatewayCloudflareFlushCacheTool.php` | MCP: manual purge |
| `packages/app/src/Mcp/Tools/Gateway/GatewayCloudflareCreateCacheRuleTool.php` | MCP: create cache rule |

## Gotchas

- **`Set-Cookie` bypasses cache**: Cloudflare will NOT cache responses with `Set-Cookie` headers, regardless of `Cache-Control`. This is why the separate stateless middleware group is essential.
- **Don't strip cookies**: Some packages forcibly remove `Set-Cookie` from responses to "fix" caching. This is dangerous — it can cache user-specific content and break session behavior.
- **Cache purge is zone-wide**: `purgeCache()` purges everything in the zone. If multiple sites share a zone, all get purged. Use `purgeCacheByUrls()` for targeted purging.
- **DNS propagation**: After switching `proxied: false` to `true`, DNS propagation takes seconds to minutes. During this window, some users hit the origin directly.
- **Caddy DNS-01 requires cloudflare module**: The standard apt Caddy package doesn't include it. `Linux\InstallCaddy` downloads a custom build. Existing servers need manual upgrade (backup at `/usr/bin/caddy.bak`).
- **CF API token is IP-restricted**: The token only works from the VPN network. Use MCP tools (which run on the gateway) for Cloudflare operations, not direct API calls from production servers.
- **CF API token permissions**: The token needs `Zone:DNS:Edit` for DNS records, `Zone:Cache Purge:Edit` for cache purging, and `Zone:Cache Rules:Edit` for creating cache rules via API.
- **CSP nonces are static in cached pages**: When Cloudflare caches a page with CSP nonces, the nonce is the same for all visitors until cache purge. Acceptable for public marketing sites.
- **HEAD requests**: `curl -sI` sends a HEAD request. The `CacheControl` middleware only sets headers on GET. Use `curl -s -D- -o /dev/null` to test GET request headers. (Note: Cloudflare itself caches by URL, so a cached GET response is also served for HEAD.)
- **`trustProxies(at: '*')`**: Production Laravel apps behind Cloudflare proxy need `$middleware->trustProxies(at: '*')` in `bootstrap/app.php` so `$request->ip()` returns the real client IP instead of Cloudflare's.
