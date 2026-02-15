---
date: 2026-02-15
problem_type: integration
component: CLI Commands / SSH Execution
severity: critical
symptoms:
  - "Failed to parse JSON: Syntax error"
  - "Deployment succeeds but marked as failed"
  - "SshService::executeJson() regex fallback fails"
root_cause: "Sub-command output polluting stdout when parent uses --json"
tags: [cli, json, ssh, output-buffering, laravel-zero]
---

# CLI --json Output Contaminated by Sub-Command Output

## Symptom

When executing CLI commands with `--json` flag via SSH, the output contains multiple JSON objects or mixed text+JSON, breaking JSON parsing:

```bash
ssh node "orbit project:deploy app --json"
# Output:
{"success":true,"data":{"action":"caddy:reload","reloaded":true}}
{"success":true,"data":{"name":"app",...}}
```

**Result**: `SshService::executeJson()` fails to parse, returns:
```json
{
  "success": false,
  "error": "Failed to parse JSON: Syntax error"
}
```

**Impact**: Deployments succeed on the node but are marked as failed in the gateway registry, skipping all post-deploy steps (DNS records, domain assignment, etc.).

## Investigation

### Attempted 1: Check ProvisionLogger output
**Code**:
```php
$this->logger = new ProvisionLogger(
    broadcaster: $broadcaster,
    command: $this->option('json') ? null : $this,  // Already suppressed
    slug: $slug,
    projectId: $project->id,
);
```

**Result**: Logger already suppresses console output when `--json` is used. Not the source.

### Attempted 2: Rely on regex fallback in SshService
**Code** (line 79):
```php
if (json_last_error() !== JSON_ERROR_NONE && preg_match_all('/(\{(?:[^{}]|(?1))*\})/s', $output, $matches)) {
    $decoded = json_decode(end($matches[0]), true);
}
```

**Result**: Regex should extract last JSON object, but still fails. The multiple JSON objects confuse the parser.

### Root Cause Found
Sub-commands called with `$this->call('caddy:reload', ['--json' => true])` output their own JSON to stdout, creating multiple JSON objects in the final output stream.

## Solution

### Pattern: SupportsJsonMode Trait

Create a reusable trait to suppress sub-command output when parent uses `--json`:

```php
// packages/cli/app/Concerns/SupportsJsonMode.php
<?php

namespace App\Concerns;

trait SupportsJsonMode
{
    /**
     * Call a command silently when parent command is in JSON mode.
     * Prevents sub-command output from polluting JSON stream.
     */
    protected function callSilentlyWhenJson(string $command, array $arguments = []): int
    {
        if ($this->option('json')) {
            // Buffer and discard sub-command output
            ob_start();
            $exitCode = $this->call($command, $arguments);
            ob_end_clean();
            return $exitCode;
        }

        // Normal execution when not in JSON mode
        return $this->call($command, $arguments);
    }
}
```

### Usage in Commands

```php
// Before (broken) - caddy:reload pollutes JSON output
use WithJsonOutput;

class ProjectDeployCommand extends Command
{
    use WithJsonOutput;

    private function regenerateCaddy(): void
    {
        $result = $this->call('caddy:reload', ['--json' => true]);
    }
}

// After (fixed) - sub-command output suppressed
use SupportsJsonMode;
use WithJsonOutput;

class ProjectDeployCommand extends Command
{
    use SupportsJsonMode;
    use WithJsonOutput;

    private function regenerateCaddy(): void
    {
        $result = $this->callSilentlyWhenJson('caddy:reload', ['--json' => true]);
    }
}
```

### Additional Output Sources

**Process::run() calls** may also leak output. Wrap with output buffering:

```php
private function clearConfigCache(string $basePath): void
{
    // Suppress all output when in JSON mode
    if ($this->option('json')) {
        ob_start();
    }

    $result = Process::path(dirname($artisan))
        ->run('php artisan config:clear 2>&1');  // Redirect stderr too

    if ($this->option('json')) {
        ob_end_clean();
    }
}
```

## Prevention

### CLI Command Checklist

When adding `--json` support to a command:

- [ ] Use `SupportsJsonMode` trait
- [ ] Replace `$this->call()` with `$this->callSilentlyWhenJson()`
- [ ] Wrap `Process::run()` calls with output buffering
- [ ] Test actual JSON output via SSH: `ssh node "orbit cmd --json" | jq .`
- [ ] Verify logger uses `command: $this->option('json') ? null : $this`

### Convention

**From CLAUDE.md**:
> **CLI `--json` output must be single object**: When adding `--json` support to CLI commands, suppress all intermediate output and only emit the final result as a single JSON object.

### Warning Signs

- "Failed to parse JSON" errors despite successful operations
- Multiple `{...}` objects in CLI output
- `SshService::executeJson()` regex fallback activating
- Deployments marked as failed but actually succeed

### Test Case

```php
/** @test */
public function it_outputs_clean_json_without_sub_command_pollution(): void
{
    // Deploy with --json flag
    $output = $this->artisan('project:deploy', [
        'name' => 'test',
        '--clone' => 'org/repo',
        '--json' => true,
    ])->run();

    // Should be valid JSON
    $decoded = json_decode($output, true);
    $this->assertNotNull($decoded, 'Output should be valid JSON');

    // Should be single object (not multiple)
    $this->assertEquals(1, substr_count($output, '{"success"'));
}
```

## Related

- **Issue**: ditis-hr deployment post-mortem (2026-02-15)
- **Convention**: `docs/solutions/integration-issues/cli-multiple-json-output-breaks-parser-20260215.md`
- **Fixed in**: v0.1.108 (initial), v0.1.109 (enhanced)

## Files Modified

- `packages/cli/app/Concerns/SupportsJsonMode.php` (NEW)
- `packages/cli/app/Commands/ProjectDeployCommand.php`
- `packages/cli/app/Commands/ProjectCreateCommand.php`

## Remaining Work

While the pattern is established, full end-to-end testing is needed to verify all output sources are suppressed. The regex fallback in `SshService::executeJson()` should be a last resort, not a regular occurrence.
