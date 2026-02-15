---
date: 2026-02-15
problem_type: security
component: CLI CloudflareConfigureCommand, CloudflareZonesCommand, CloudflareStoreCommand
severity: critical
symptoms:
  - "API tokens visible in process list (ps aux, /proc/*/cmdline)"
  - "Token passed as CLI argument: orbit cloudflare:zones {token}"
root_cause: Sensitive values passed as positional CLI arguments are visible to all users via process listing
tags: [security, credentials, stdin, cli, cloudflare]
---

# API Token Leaked via CLI Argument in Process List

## Symptom

Cloudflare API tokens were passed as positional arguments to CLI commands executed via SSH:

```php
// Token visible in `ps aux` on the gateway server
$this->runOnGateway($gateway, "cloudflare:zones {$token} --json");
$this->runOnGateway($gateway, "cloudflare:store {$token}");
```

Any user on the gateway server could see the token by running `ps aux | grep orbit`.

## Root Cause

CLI command signatures accepted tokens as positional arguments:

```php
protected $signature = 'cloudflare:zones {token} {--json}';
protected $signature = 'cloudflare:store {token}';
```

When executed via SSH, the full command (including token) appears in the process table.

## Solution

Read tokens from stdin instead of CLI arguments.

### Gateway commands (receive token)

```php
// Before (vulnerable)
protected $signature = 'cloudflare:zones {token} {--json}';
$token = $this->argument('token');

// After (safe)
protected $signature = 'cloudflare:zones {--json}';
$token = trim(fgets(STDIN) ?: '');
if ($token === '') {
    return $this->outputJsonError('No API token provided via stdin.');
}
```

### Client commands (send token)

```php
// Before (token in process list)
$this->runOnGateway($gateway, "cloudflare:zones {$token} --json");

// After (token piped via stdin, not visible in ps)
$this->runOnGateway($gateway, 'cloudflare:zones --json', $token);
```

### RunsOnGateway trait (stdin support)

```php
private function runOnGateway(Gateway $gateway, string $orbitCommand, ?string $stdin = null): ?string
{
    // ... SSH command building ...

    if ($stdin !== null) {
        $sshCmd = sprintf('echo %s | %s', escapeshellarg($stdin), $sshCmd);
    }

    $result = Process::timeout(20)->run($sshCmd);
    // ...
}
```

## Files Changed

- `packages/cli/app/Commands/Gateway/CloudflareZonesCommand.php` - Read token from stdin
- `packages/cli/app/Commands/Gateway/CloudflareStoreCommand.php` - Read token from stdin
- `packages/cli/app/Commands/Gateway/CloudflareConfigureCommand.php` - Pipe token via stdin
- `packages/cli/app/Concerns/RunsOnGateway.php` - New trait with stdin support

## Prevention

1. **Never pass secrets as CLI arguments** - they appear in process lists
2. **Use stdin for sensitive data** - `echo $secret | command` keeps it out of `ps`
3. **Audit pattern**: Search for `{token}`, `{password}`, `{secret}` in command signatures
4. **Environment variables** are also acceptable but stdin is simpler for SSH piping

## Audit Command

```bash
grep -rn '{token}\|{password}\|{secret}\|{key}' --include="*.php" app/Commands/
```

## Related

- CWE-214: Invocation of Process Using Visible Sensitive Information
- `docs/solutions/security-issues/command-injection-shell-exec-20260131.md`
