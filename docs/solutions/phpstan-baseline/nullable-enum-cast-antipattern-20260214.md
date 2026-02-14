---
date: 2026-02-14
problem_type: static-analysis
component: app/Mcp
severity: minor
symptoms:
  - "Expression on left side of ?? is not nullable"
  - PHPStan baseline growing with nullCoalesce.expr suppressions
root_cause: Using null-safe operator on non-nullable enum cast property
tags: [phpstan, enum, cast, null-safe-operator]
---

# Nullable Enum Cast Anti-Pattern

## Symptom

PHPStan reports `Expression on left side of ?? is not nullable` for code like:

```php
'environment' => $node->environment?->value ?? 'development',
```

Developers add baseline suppressions instead of fixing the code.

## Root Cause

When a model property is cast to a backed enum:

```php
// Node model
protected $casts = [
    'environment' => NodeEnvironment::class,
];
```

And annotated as non-nullable in the docblock:

```php
/** @property NodeEnvironment $environment */
```

PHPStan knows the property is never null. The `?->` operator and `?? 'default'` fallback are dead code.

## Solution

Remove the null-safe operator and fallback:

```php
// Before (unnecessary defensive coding)
'environment' => $node->environment?->value ?? 'development',

// After (correct)
'environment' => $node->environment->value,
```

## Files Fixed

- `packages/app/src/Mcp/Resources/Gateway/GatewayDeploymentsResource.php:49`
- `packages/app/src/Mcp/Tools/Gateway/GatewayDeploymentsTool.php:71`
- `packages/app/src/Mcp/Tools/Gateway/GatewayNodesTool.php:50`
- `packages/app/phpstan-baseline.neon` (cleared 3 suppressions)

## Prevention

- **Rule**: Never use `?->` on enum-cast properties unless the column is nullable in the schema AND the docblock declares `?EnumType`.
- **Fix the code, not the baseline**: If PHPStan says `??` is unnecessary, remove the `??`, don't suppress the warning.
- **Check before adding baseline entries**: Run `composer analyse`, read the actual error. If it says "not nullable", the code is wrong, not PHPStan.

## Related

- [PHPStan Baseline Maintenance](../phpstan-baseline/baseline-maintenance-20260130.md)
