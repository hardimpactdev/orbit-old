---
date: 2026-02-08
problem_type: refactoring
component: monorepo (all packages)
severity: moderate
symptoms:
  - "Class not found after rename"
  - "Stale vendored copy in path repository"
  - "Test assertions don't match controller behavior after rename"
root_cause: Multi-package renames require coordinated updates across DB, models, services, controllers, routes, frontend, tests, and documentation
tags: [refactoring, rename, monorepo, migration, concept-rename]
---

# Monorepo Concept Rename Workflow

## Context

Renamed "Environment" to "Node" across 5 packages (core, app, cli, desktop, web) touching ~100 files. This documents the workflow and pitfalls.

## Execution Order

Phases must run in dependency order:

1. **Database migration** (core) -- rename table, columns, indexes
2. **Core models + enums + factory** -- rename classes, update fillable/casts
3. **Core services** -- update type hints, method calls, property access
4. **Config keys** -- rename config keys and env vars across all packages
5. **CLI package** -- update actions, commands, templates
6. **App controllers + routes + middleware** -- rename controllers, route files, middleware
7. **Vue frontend** -- rename pages, components, types, URL paths
8. **Desktop/Web packages** -- update providers, middleware aliases, factories
9. **Tests across all packages** -- update factories, assertions, route names
10. **Documentation** -- AGENTS.md, docs/, CI workflow
11. **Grep sweep** -- final verification for orphaned references

## Parallel Execution Strategy

Phases 1-3 are sequential (each depends on prior). After that, use parallel agents:
- Phase 4+5 together (config + CLI)
- Phase 6 alone (app controllers)
- Phase 7 alone (Vue frontend)
- Phase 8 alone (desktop)
- Phases 9-11 after all above complete

## Gotchas Discovered

### 1. Path Repository Stale Vendor Copy

When package A depends on package B via Composer `path` repository, the vendor copy may be a **copy** not a symlink. After modifying B, A's vendor still has the old code.

**Fix:** Delete `composer.lock`, re-run `composer install`. Verify with:
```bash
ls -la packages/app/vendor/hardimpactdev/orbit-core
# Should show -> ../../../core/ (symlink)
```

### 2. Test Assertions vs Actual Behavior

When renaming a DB column (e.g., removing `is_local`), tests that asserted guard behavior based on that column need updating. The refactor agent may write tests assuming the old guard logic still exists.

**Fix:** Read the actual controller code before writing test assertions. Match tests to real behavior, not assumed behavior.

### 3. Computed vs Stored Properties

Replacing a DB column (`is_local`) with a computed method (`isLocal()`) requires:
- `$appends = ['is_local']` on the model for API/Inertia backward compat
- `getIsLocalAttribute()` accessor calling `isLocal()`
- Frontend TypeScript interfaces keep `is_local` field (backend appends it)
- All PHP `$model->is_local` reads become `$model->isLocal()` calls
- Factory `local()` state sets `host=127.0.0.1` instead of `is_local=true`

### 4. SQLite Migration Quirks

See: `docs/solutions/database-issues/sqlite-table-rename-index-fk-20260208.md`

### 5. Config Key Propagation

When renaming a config key (e.g., `multi_environment` -> `multi_node`), check:
- `config/orbit.php` in every package
- `phpunit.xml` env vars
- `.env.example` files
- Middleware that reads the config
- Vue pages that read Inertia shared props
- HandleInertiaRequests shared prop names

### 6. Route Name Propagation

Route names cascade to: route definitions, `route()` calls in controllers, test assertions, Vue `router.visit()` / `router.post()` calls, Inertia `component` paths, and navigation builders.

## Grep Sweep Patterns

Final verification patterns (exclude vendor/, node_modules/, migrations, docs/solutions/):

```bash
# Model references
grep -r "Environment::" --include="*.php" packages/*/src/ packages/*/app/
grep -r "EnvironmentStatus" --include="*.php" packages/*/src/
grep -r "EnvironmentManager" --include="*.php" packages/*/src/

# DB columns
grep -r "environment_id" --include="*.php" packages/*/src/ packages/*/app/

# Config keys
grep -r "multi_environment" --include="*.php" packages/
grep -r "MULTI_ENVIRONMENT" packages/*/config/ packages/*/.env*

# Routes and URLs
grep -r "/environments/" --include="*.vue" --include="*.ts" packages/app/resources/
grep -r "environments\.\*" --include="*.php" packages/*/routes/

# Middleware
grep -r "ImplicitEnvironment" --include="*.php" packages/
```

## Checklist for Future Concept Renames

- [ ] Write migration (handle SQLite quirks)
- [ ] Rename model + enum + factory
- [ ] Update all services (type hints, method calls, property access)
- [ ] Update config keys across all packages
- [ ] Rename controllers, routes, middleware
- [ ] Rename Vue pages/components, update TypeScript interfaces
- [ ] Update all test files (factories, assertions, route names)
- [ ] Update CI workflow references
- [ ] Update documentation (AGENTS.md, docs/, README)
- [ ] Run tests per package
- [ ] Run comprehensive grep sweep
- [ ] Verify path repository symlinks are fresh
