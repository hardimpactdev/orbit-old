---
date: 2026-02-14
problem_type: security
component: GatewayCliAdapter, Node model
severity: critical
symptoms:
  - "SSH command string needs injection protection"
  - "escapeshellarg() breaks multi-argument CLI commands over SSH"
  - "Faker-generated usernames rejected by model validation"
root_cause: escapeshellarg wraps entire string in quotes, making it one argument; regex too strict for valid Unix usernames
tags: [security, ssh, injection, validation, escapeshellarg, faker]
---

# SSH Command Escaping vs Input Validation

## Problem: escapeshellarg() breaks multi-argument SSH commands

When constructing SSH commands, `escapeshellarg()` is the standard fix for injection.
But for CLI commands passed as a single string to `ssh user@host "orbit command --flag"`,
wrapping the full command in `escapeshellarg()` passes it as one argument:

```php
// BROKEN: orbit receives 'gateway:clients --json' as a SINGLE argument
$sshCommand = "ssh user@host " . escapeshellarg("orbit gateway:clients --json");
// Executes: ssh user@host 'orbit gateway:clients --json'
// orbit sees argv[1] = "gateway:clients --json" (one string, not two args)

// CORRECT for individual arguments, but orbit needs the string split:
// orbit expects: argv[1]="gateway:clients" argv[2]="--json"
```

## Solution: Input validation instead of escaping

When the command string must be parsed as multiple arguments on the remote shell,
use an allowlist regex instead of escaping:

```php
// Validate command contains only safe characters
if (preg_match('/[^a-zA-Z0-9\s:_\-\.\/=,]/', $command)) {
    return null; // Reject suspicious input
}

// Safe to interpolate since only whitelisted characters pass
$sshCommand = "ssh user@host \"orbit {$command}\"";
```

**Important**: Verify this is safe by checking all callers. If the command comes from
user input, this approach is insufficient - but if it comes from hardcoded strings in
the codebase (like `'gateway:clients --json'`), the validation is defense-in-depth.

## Related Problem: Model validation too strict for Faker

Adding SSH user validation with `/^[a-z_][a-z0-9_\-]*$/i` rejected Faker's `userName()`
output which includes dots (e.g., `river.carter`, `fahey.karli`).

Real Unix usernames CAN contain dots. The `useradd` command allows: letters, digits,
underscores, hyphens, and dots (with restrictions on first character).

```php
// Too strict - rejects valid usernames with dots
preg_match('/^[a-z_][a-z0-9_\-]*$/i', $node->user)

// Correct - allows dots like real Unix systems
preg_match('/^[a-z_][a-z0-9_\-\.]*$/i', $node->user)
```

## Prevention

1. **Don't blindly apply escapeshellarg**: Understand the escaping context. For SSH remote
   commands, the quoting happens at two levels (local shell + remote shell).
2. **Check all callers**: Before choosing validation vs escaping, audit every code path
   that provides the input value.
3. **Test model validation against factories**: If adding `booted()` validators, run
   `Node::factory()->create()` to verify Faker-generated data still passes.
4. **Unix username rules**: Allow `[a-zA-Z0-9._-]` with first char `[a-zA-Z_]`.

## Related

- `packages/cli/app/Services/GatewayCliAdapter.php:sshCommand()` - SSH command validation
- `packages/core/src/Models/Node.php:booted()` - SSH parameter validation
- `docs/solutions/security-issues/command-injection-shell-exec-20260131.md` - related
