---
date: 2026-02-15
problem_type: test-failure
component: CLI CaddyfileGenerator, Mockery
severity: moderate
symptoms:
  - "Non-readonly class Mockery_10_App_Services_CaddyfileGenerator cannot extend readonly class App\\Services\\CaddyfileGenerator"
  - "PHP Fatal error in EvalLoader.php(30) : eval()'d code on line 32"
root_cause: Mockery generates subclasses to create mocks; PHP readonly classes cannot be extended by non-readonly classes
tags: [mockery, readonly, php, testing, mocking]
---

# Mockery Cannot Mock PHP `readonly` Classes

## Symptom

After removing `final` from a class (to make it mockable) but keeping `readonly`, all tests that mock the class crash with a PHP fatal error:

```
PHP Fatal error: Non-readonly class Mockery_10_App_Services_CaddyfileGenerator
cannot extend readonly class App\Services\CaddyfileGenerator
```

This crashes the entire test suite, not just the affected test.

## Investigation

1. Attempted: Removed `final` keyword, kept `readonly` → Same fatal error
2. Attempted: Checked if Mockery has a `readonly` adapter → It does not (as of Mockery 1.x)

## Root Cause

PHP 8.2+ `readonly` classes enforce that ALL subclasses must also be `readonly`. Mockery generates mock subclasses dynamically via `eval()`, and these generated classes are NOT marked `readonly`. This is a PHP language constraint, not a Mockery bug.

Key distinction:
- `final` prevents subclassing entirely → Mockery can't mock
- `readonly` allows subclassing but requires subclasses to also be `readonly` → Mockery can't mock (different error)

Both `final` and `readonly` on a class prevent Mockery from creating mocks.

## Solution

Remove `readonly` from the class declaration. Keep properties readonly individually if needed:

```php
// Before (not mockable)
final readonly class CaddyfileGenerator
{
    protected string $caddyfilePath;
    // ...
}

// After (mockable)
class CaddyfileGenerator
{
    protected string $caddyfilePath;
    // ...
}
```

If you need immutability, use `readonly` on individual properties instead of the class:

```php
class CaddyfileGenerator
{
    public function __construct(
        protected readonly ConfigManager $configManager,
        protected readonly ProjectScanner $projectScanner,
    ) {}
}
```

## Prevention

1. **Don't use `readonly` on classes that need mocking** — use `readonly` on individual properties instead
2. **When removing interfaces**, also check if the class has `final` or `readonly` modifiers that prevent mocking
3. **Test after interface removal** — run the full test suite, not just the directly affected tests (fatal errors crash everything)
4. **Mockery alternatives**: For truly readonly/final classes, use `Mockery::mock(ClassName::class)->makePartial()` with an interface, or extract an interface specifically for testing

## Checklist: Removing an Interface from a Class

When removing an interface and making consumers use the concrete class directly:

1. Delete interface file
2. Remove `implements InterfaceName` from class
3. Remove `final` if present (for mockability)
4. Remove `readonly` from class declaration if present (for mockability)
5. Update service container bindings
6. Update all type hints in consumers (~grep for the interface name)
7. Run full test suite (not just targeted tests)

## Related

- PHP RFC: Readonly classes (PHP 8.2)
- Mockery issue: Cannot mock readonly classes
- `docs/solutions/test-failures/escapeshellarg-breaks-mock-str-contains-20260215.md`
