---
date: 2026-02-15
problem_type: security-issue
component: provisioning
severity: moderate
symptoms:
  - "Production deployments include dev dependencies"
  - "PHPUnit, testing tools visible in production vendor/"
  - "Larger deployment sizes than necessary"
root_cause: "No environment-based composer install flags"
tags: [composer, security, production, dependencies, build]
---

# Production Deployments Include Dev Dependencies

## Symptom

Production deployments contained development dependencies:
- PHPUnit, Pest, Laravel Telescope
- Debugging tools, testing frameworks
- Larger attack surface, wasted disk space

## Root Cause

The `InstallComposerDependencies` action used the same composer command for all environments - no differentiation between production and development.

## Solution

Use environment-based composer flags:

```php
// packages/core/src/Services/Provision/Actions/InstallComposerDependencies.php

$noScripts = $context->minimal ? '' : ' --no-scripts';

// Production/staging: use --no-dev --optimize-autoloader
$prodFlags = $context->isReleaseDeploy ? ' --no-dev --optimize-autoloader' : '';

$command = "composer install --no-interaction{$noScripts}{$prodFlags}";
```

**Result:**
- Production/staging: `composer install --no-interaction --no-scripts --no-dev --optimize-autoloader`
- Development: `composer install --no-interaction --no-scripts`

### How isReleaseDeploy is Set

Production/Staging nodes → `project:deploy` → `isReleaseDeploy: true` → `--no-dev`

## Prevention

1. **Environment-aware commands** - different flags for prod vs dev
2. **Use context flags** - `isReleaseDeploy`, `minimal`, etc.
3. **Test both paths** - verify dev deps excluded in production
4. **Audit deployed files** - check vendor/ on production nodes

## Related

- See `ProvisionContext` for available environment flags
- Convention: Production = `project:deploy`, Development = `project:create`
