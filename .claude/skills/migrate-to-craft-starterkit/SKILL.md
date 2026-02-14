---
name: migrate-to-craft-starterkit
description: Migrate an existing Laravel website into a new craft-starterkit project with Orbit hosting. Use when moving a site to the craft stack.
allowed-tools: Bash(gh:*), Bash(composer:*), Bash(bun:*), Bash(php:*), Bash(cp:*), Bash(rm:*), Bash(mkdir:*), Bash(orbit:*), Bash(ls:*), Read, Edit, Write, Glob, Grep
---

# Migrate Website to Craft Starterkit

Migrate an existing Laravel + Vue + Inertia website into a new project based on `hardimpactdev/craft-starterkit`, served locally via Orbit.

## Parameters

Ask the user for:
- **Source project path** (e.g., `~/Projects/srpm`)
- **New project slug** (e.g., `srpm-craft` — becomes `{slug}.test` via Orbit)
- **GitHub org** (default: `hardimpactdev`)

## Phase 1: Scaffold

```bash
# Create repo from template
gh repo create {org}/{slug} --template hardimpactdev/craft-starterkit --private --clone -p ~/Projects

cd ~/Projects/{slug}
```

Skip `setup.php` — it uses `fgets(STDIN)` and calls `herd secure/link` which is incompatible with Orbit and non-interactive agents. Replicate manually:

```bash
cp .env.example .env
```

Update `.env` and `.env.example`:
- `APP_NAME={ProjectName}`
- `APP_URL=https://{slug}.test`
- `MAIL_FROM_ADDRESS=app@{slug}.test`

```bash
composer install
bun install
php artisan key:generate
./vendor/bin/whisky install -n
php artisan migrate --force
rm setup.php
```

Update `composer.json` name from `hardimpactdev/craft-starterkit` to `{org}/{slug}`.

Register with Orbit:

```bash
orbit caddy:reload
```

## Phase 2: Add Project-Specific Packages

Install any packages the source project uses that aren't in the starterkit.

**Common additions:**

```bash
# Spam protection
composer require spatie/laravel-honeypot

# Response caching
composer require spatie/laravel-responsecache

# Headless UI components
bun add @headlessui/vue
```

**Packages you do NOT need to install** (already in starterkit):
- `inertiajs/inertia-laravel` (Inertia)
- `spatie/laravel-data` (DTOs)
- `spatie/laravel-typescript-transformer` (TS types)
- `spatie/laravel-csp` (Content Security Policy)
- `hardimpactdev/waymaker` (attribute routing)
- `laravel/wayfinder` (TS route helpers)

**Packages to DROP from the source project** (replaced by craft stack):
- `tightenco/ziggy` — replaced by Wayfinder
- `based/momentum-trail` — replaced by Wayfinder
- `laravel-precognition-vue-inertia` — Inertia v2.3+ has precognition built in
- `unplugin-auto-import` / `unplugin-vue-components` — craft uses explicit imports

Publish configs only when defaults don't work. Most Spatie packages work without publishing.

## Phase 3: Migrate Backend

### Config files
Copy any custom config files (e.g., `config/about.php`).

### Enums
Copy to `app/Enums/`, update namespace to `App\Enums`, add `declare(strict_types=1)`.

### Data objects (DTOs)
Copy to `app/Data/`, add `declare(strict_types=1)`.

**Important:** If the source project has validation rules on Data classes (spatie/laravel-data pattern), extract those rules into a FormRequest. Craft pattern keeps Data classes as pure DTOs.

### FormRequests
Create in `app/Http/Requests/` with:
- Validation rules + messages
- `getData(): SomeData` method returning the DTO

```php
public function getData(): ContactFormData
{
    return ContactFormData::from($this->validated());
}
```

### Controllers (Waymaker)

Convert from traditional routing to Waymaker attribute-based routing.

**Key rules:**

1. Use `#[Get]`, `#[Post]`, `#[Put]`, `#[Delete]` attributes from `HardImpact\Waymaker`
2. Only resourceful method names: `index`, `show`, `create`, `store`, `edit`, `update`, `destroy`
3. **NEVER use `__invoke()`** — Waymaker skips all `__` prefixed methods
4. URI is auto-derived from controller name (kebab-case). `ContactController` → `/contact`
5. Use `uri: '/'` for the homepage controller, `uri: ''` to use just the base without appending method slug

**Gotcha — URI prefixing:**

Waymaker auto-prefixes the method's URI with the controller base. If your controller is `PrivacyController` and method is `show()`, the default URI becomes `/privacy/{id}`. To get just `/privacy`, set `uri: ''` on the method:

```php
#[Get(uri: '', name: 'privacy')]
public function show(): Response
```

**Gotcha — single-quoted strings with apostrophes:**

Dutch text like `risico's` breaks single-quoted PHP strings. Use double quotes.

```bash
php artisan waymaker:generate
```

### Mail
Copy Mailables to `app/Mail/`. Architecture tests require `implements ShouldQueue`.

### Views
Copy blade templates (e.g., email templates) to `resources/views/`.

### Generate route helpers

```bash
php artisan waymaker:generate
php artisan wayfinder:generate
php artisan typescript:transform
```

### Controllers barrel export

Create `resources/js/controllers/index.ts`:

```typescript
export { default as HomePageController } from '@/actions/App/Http/Controllers/HomePageController';
export { default as ContactController } from '@/actions/App/Http/Controllers/ContactController';
// ... etc
```

## Phase 4: Migrate Frontend

### Static assets
```bash
cp -r {source}/resources/fonts/* {target}/resources/fonts/
cp -r {source}/public/images/* {target}/public/images/
```

### CSS (`resources/css/app.css`)
Merge into the existing craft app.css (don't replace):
- `@font-face` declarations
- `@theme` block with project-specific variables (colors, fonts)
- Any global element styles (e.g., heading font-family)

### Vue components
Copy to lowercase directories per craft convention:
- `Components/` → `components/`
- `Icons/` → `icons/`
- `Layouts/` → `layouts/`
- `Pages/` → `pages/`

### Adapt Vue files

**Replace route helpers:**
```typescript
// Before (Ziggy/momentum-trail)
route('home')
route('privacy')

// After (Wayfinder)
import { HomePageController, PrivacyController } from '@/controllers';
HomePageController.show.url()
PrivacyController.show.url()
```

**Replace precognition form handling:**
```typescript
// Before (laravel-precognition-vue-inertia)
import { useForm } from 'laravel-precognition-vue-inertia';
const form = useForm('post', route('submit.form'), props.contactForm);
form.submit({ ... });

// After (Inertia v2.3+)
import { useForm } from '@inertiajs/vue3';
const form = useForm(props.contactForm);
form.post(ContactController.store.url(), { ... });
```

**Remove auto-imports** — craft uses explicit imports:
```typescript
// Add explicit imports for Vue APIs
import { ref, reactive, onMounted, useTemplateRef } from 'vue';
import { Link, router } from '@inertiajs/vue3';
```

### Layout setup

Update `resources/js/app.ts` to use the project's layout:

```typescript
import '../css/app.css';
import { initializeCraft } from 'virtual:craft';
import WebsiteLayout from '@/layouts/website-layout.vue';

initializeCraft({
    layouts: {
        default: WebsiteLayout,
    },
});
```

### Delete starterkit placeholders
- `resources/js/pages/Home.vue` (uppercase — replaced by lowercase)
- `resources/js/components/AppLogoIcon.vue` (template placeholder)
- `app/Http/Controllers/HomeController.php` (template placeholder)

## Phase 5: Quality Checks

```bash
bun run build
composer lint
composer analyse --memory-limit=512M
composer test
bun run format
```

## Phase 6: Orbit & Verify

```bash
orbit caddy:reload
```

Visit `https://{slug}.test` and verify:
- All pages render
- Navigation works (including hash links like `#services`)
- Forms submit correctly
- Images and fonts load
- Email delivery works (check Mailpit)

## Pitfalls & Lessons Learned

| Issue | Cause | Fix |
|-------|-------|-----|
| Waymaker skips `__invoke()` | Filters all `__` prefixed methods | Use named methods (`show`, `store`) |
| Route gets `/{id}` suffix | Waymaker appends method name as URI segment for `show()` | Set `uri: ''` on the method attribute |
| Double-prefixed URI (`/contact-form/contact`) | Controller name becomes base prefix | Rename controller or set explicit `uri` |
| PHP ParseError with apostrophes | Single-quoted strings containing `'` | Use double quotes for Dutch/French text |
| `laravel/precognition` not found on Packagist | `HandlePrecognitiveRequests` is part of Laravel framework | Don't `composer require` it |
| Vite `@actions` alias not resolved | Only in tsconfig, not in Vite config | Use `@/actions/...` (resolved via `@/` alias) |
| Architecture test: Mailable must queue | Craft enforces `ShouldQueue` on all Mailables | Add `implements ShouldQueue` |
| CSP blocks scripts with IP-based APP_URL | Development preset constructs `https://127.0.0.1:8765:*` (double port) | Only affects `php artisan serve`; works fine with Orbit domain URLs |
| `setup.php` incompatible with agents | Uses `fgets(STDIN)` and `herd secure/link` | Skip it, replicate steps manually |

## Checklist

- [ ] Scaffold from template, skip setup.php
- [ ] Install project-specific packages
- [ ] Copy config, enums, data objects
- [ ] Create FormRequests with `getData()` (extract rules from Data classes)
- [ ] Create controllers with Waymaker attributes (no `__invoke`)
- [ ] Copy mailables (add `ShouldQueue`), blade templates
- [ ] Run `waymaker:generate`, `wayfinder:generate`, `typescript:transform`
- [ ] Create controllers barrel export
- [ ] Copy fonts, images
- [ ] Merge CSS theme into app.css
- [ ] Copy + adapt Vue files (explicit imports, Wayfinder routes, lowercase dirs)
- [ ] Update app.ts with project layout
- [ ] Delete template placeholder files
- [ ] `bun run build` passes
- [ ] `composer lint` passes
- [ ] `composer analyse` passes
- [ ] `composer test` passes
- [ ] `bun run format` passes
- [ ] `orbit caddy:reload` and verify site at `https://{slug}.test`
