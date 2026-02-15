---
date: 2026-02-15
problem_type: security
component: CLI ProjectRegisterCommand, RunsOnGateway trait
severity: critical
symptoms:
  - "User input passed unescaped to SSH remote command"
  - "Shell metacharacters in project name could execute arbitrary commands on gateway"
root_cause: User-supplied values interpolated into orbit command string without escapeshellarg()
tags: [security, injection, shell, escapeshellarg, ssh, gateway]
---

# Unescaped User Input in SSH Remote Command Execution

## Symptom

Multi-agent code review (security sentinel) flagged command injection in `ProjectRegisterCommand`. User-supplied project name, slug, repo, and domain were interpolated directly into a command string that executes via SSH on the gateway server.

## Investigation

1. `ProjectRegisterCommand::handle()` collects user input via `$this->ask()`
2. Values concatenated directly into command string: `"project:store {$name} {$slug}"`
3. String passed to `RunsOnGateway::runOnGateway()` which executes via SSH
4. The outer `escapeshellarg()` in `runOnGateway` wraps the ENTIRE command as one SSH argument — but does NOT protect against injection WITHIN the orbit command itself

## Root Cause

Two-layer escaping confusion. The `RunsOnGateway` trait correctly escapes the SSH transport layer (the whole command is a single argument to `ssh`), but the orbit command arguments within that string are unescaped. A project name like `test; rm -rf /` would execute as two separate commands inside the SSH session.

## Solution

### Fix 1: Escape at the caller (ProjectRegisterCommand)

```php
// Before (vulnerable)
$args = "project:store {$name} {$slug}";
if ($repo) {
    $args .= " --repo={$repo}";
}

// After (safe)
$args = ['project:store', escapeshellarg($name), escapeshellarg($slug)];
if ($repo) {
    $args[] = '--repo=' . escapeshellarg($repo);
}
$output = $this->runOnGateway($gateway, implode(' ', $args));
```

### Fix 2: Defensive check in RunsOnGateway trait

Added runtime metacharacter detection as defense-in-depth:

```php
// Strip single-quoted segments (escapeshellarg output) then check for metacharacters
if (preg_match('/[;|&`$]/', preg_replace("/'.+?'/", '', $orbitCommand) ?? '')) {
    throw new \InvalidArgumentException(
        'Shell metacharacters detected in orbit command. Use escapeshellarg() on all user-supplied values.'
    );
}
```

This strips `escapeshellarg()`'d segments (which are single-quoted) before checking, so properly escaped values pass through while unescaped metacharacters throw.

## Files Fixed

- `packages/cli/app/Commands/Gateway/ProjectRegisterCommand.php:71-80`
- `packages/cli/app/Concerns/RunsOnGateway.php:27-29`

## Prevention

1. **Always use array-based command building** for commands sent via `runOnGateway()`:
   ```php
   $args = ['command:name', escapeshellarg($userInput)];
   $this->runOnGateway($gateway, implode(' ', $args));
   ```
2. **The RunsOnGateway docblock** now explicitly states callers MUST escape user values
3. **The runtime check** catches violations at execution time as a safety net
4. **Convention documented** in AGENTS.md: "CLI argument escaping" and "Never pass secrets as CLI arguments"

## Audit Command

Check for any other `runOnGateway` callers that might interpolate unescaped values:

```bash
grep -rn 'runOnGateway' packages/cli/ --include="*.php" | grep -v escapeshellarg | grep -v '\.md'
```

## Related

- `docs/solutions/security-issues/command-injection-shell-exec-20260131.md` — Same class of vulnerability in different context
- `docs/solutions/security-issues/api-token-cli-argument-leaks-process-list-20260215.md` — Complementary fix for secret handling
