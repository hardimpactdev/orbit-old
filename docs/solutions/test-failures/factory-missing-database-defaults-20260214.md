---
date: 2026-02-14
problem_type: test-failure
component: core/Models/Node, core/Services/DeploymentService
severity: moderate
symptoms:
  - "Failed asserting that null is identical to NodeEnvironment::Development"
  - "UNIQUE constraint failed: deployments.node_id, deployments.project_slug"
  - "Failed asserting that actual size 0 matches expected size 2"
  - "Failed asserting that 1 is identical to 0 (cascade delete)"
root_cause: Factory definition missing columns that rely on database defaults; deploy creates duplicate instead of reusing; SQLite foreign keys not enabled
tags: [factory, database-defaults, eloquent, sqlite, foreign-keys]
---

# Factory Missing Database Defaults + Deploy Reuse + SQLite FK

## Symptom 1: Node environment is null after factory create

```
NodeEnvironmentTest::defaults to development
Failed asserting that null is identical to NodeEnvironment::Development
```

### Root Cause

Eloquent's `Factory::create()` does an INSERT with only the attributes specified in the factory
definition. It does NOT do a SELECT after INSERT. Database-level defaults (like
`->default('development')` in the migration) are applied by the database engine but never loaded
into the PHP model instance.

### Fix Applied

Added `environment` and `is_active` to `NodeFactory::definition()`:

```php
// packages/core/database/factories/NodeFactory.php
'is_active' => true,
'environment' => NodeEnvironment::Development,
```

## Symptom 2: nodesByEnvironment returns 0 results

```
DeploymentServiceTest::nodesByEnvironment - Expected 2 nodes, got 0
```

### Root Cause

`nodesByEnvironment()` filters `where('is_active', true)` but factory only set
`'status' => NodeStatus::Active` (different column). `is_active` defaults to `false`.

### Fix Applied

Same as above - added `'is_active' => true` to factory definition.

## Symptom 3: Unique constraint on deploy test

```
UniqueConstraintViolationException: UNIQUE constraint failed: deployments.node_id, deployments.project_slug
```

### Root Cause

`deploy()` checked for existing non-Removed deployments, then tried to INSERT a new row.
But the Removed deployment still occupies the unique `(node_id, project_slug)` slot.

### Fix Applied

Changed `deploy()` to reuse the existing Removed/Failed record instead of creating a new one:

```php
// packages/core/src/Services/DeploymentService.php
$existing = Deployment::where('node_id', $target->id)
    ->where('project_slug', $slug)
    ->first();

if ($existing && ! in_array($existing->status, [DeploymentStatus::Removed, DeploymentStatus::Failed])) {
    throw new \RuntimeException("Active deployment...");
}

$deployment = $existing
    ? tap($existing)->update([...status => Deploying...])
    : Deployment::create([...]);
```

## Symptom 4: Cascade delete not working

```
NodeEnvironmentTest::cascades delete - Failed asserting that 1 is identical to 0
```

### Root Cause

SQLite `:memory:` doesn't enforce foreign key constraints (including CASCADE DELETE)
unless `PRAGMA foreign_keys = ON`. The test database config didn't enable this.

### Fix Applied

Added `foreign_key_constraints` to test database config:

```php
// packages/core/tests/TestCase.php
config()->set('database.connections.testing', [
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
    'foreign_key_constraints' => true,
]);
```

## Prevention

1. **Always include all model columns in factory definitions** - Don't rely on database-level
   defaults, because Eloquent won't load them after INSERT.
2. **Match query filters to factory state** - If a query filters `where('is_active', true)`,
   the factory must produce models with `is_active = true`.
3. **Enable SQLite foreign keys in tests** - Always set `foreign_key_constraints => true`
   when using SQLite test databases with CASCADE constraints.
4. **Handle unique constraints in business logic** - When a unique constraint exists and
   records can be soft-removed/failed, reuse the existing record instead of inserting.

## Related

- `packages/core/database/factories/NodeFactory.php` - Factory definition
- `packages/core/src/Services/DeploymentService.php:30-46` - Deploy with reuse logic
- `packages/core/tests/TestCase.php` - SQLite FK config
- `packages/core/tests/Unit/Models/NodeEnvironmentTest.php` - Environment + cascade tests
- `packages/core/tests/Unit/Services/DeploymentServiceTest.php` - Deploy + nodesByEnvironment tests
