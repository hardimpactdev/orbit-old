---
date: 2026-02-15
problem_type: Integration issue
component: CLI JSON output parsing
severity: moderate
symptoms:
  - "Deployment succeeds but gateway reports failure"
  - "Error: Failed to parse JSON: Syntax error"
  - "SshService::executeJson() returns success: false despite CLI success"
root_cause: CLI outputs multiple JSON objects when --json flag is used
tags: [cli, json, parsing, deployment]
---

# CLI Multiple JSON Output Breaks Parser

## Symptom

The `project:deploy --json` command completes successfully on the remote node, but the gateway reports a deployment failure with:

```json
{
  "success": false,
  "error": "Failed to parse JSON: Syntax error",
  "deployment_id": 2
}
```

Manual SSH execution shows the site was actually deployed successfully.

## Investigation

**Attempted:** Check if the CLI was returning invalid JSON
**Result:** CLI output is valid JSON, but there are **two separate JSON objects**:

```bash
$ ssh orbit@node "orbit project:deploy mysite --json --clone=org/repo"
{
    "success": true,
    "data": {
        "action": "caddy:reload",
        "reloaded": true
    }
}
{
    "success": true,
    "data": {
        "name": "mysite",
        "slug": "mysite",
        "project_id": 2,
        "status": "ready",
        "url": "https://mysite.test",
        "path": "/home/orbit/Projects/mysite",
        "release": "20260215_143731",
        "first_deploy": false
    }
}
```

The first JSON object is intermediate progress (caddy reload status).
The second JSON object is the final deployment result.

## Root Cause

**CLI side:**
The `project:deploy` command outputs progress updates as separate JSON objects when `--json` is used. It doesn't buffer them into a single response.

**Gateway side:**
`SshService::executeJson()` tries to parse the entire output as a single JSON object:

```php
// packages/core/src/Services/SshService.php line 74
$decoded = json_decode((string) $result['output'], true);

if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    return [
        'success' => true,
        'exit_code' => $result['exit_code'],
        'data' => $decoded,
    ];
}
```

When multiple JSON objects are present, `json_decode()` fails on the combined string and returns the "Syntax error" fallback.

## Solution

**Option 1: Fix CLI output (Recommended)**

Suppress intermediate JSON when `--json` flag is present:

```php
// In ProjectDeployCommand.php
if ($this->wantsJson()) {
    // Buffer all output, only emit final result
    $this->logger->setQuiet(true);
}

// At the end
return $this->outputJsonSuccess([...]);
```

**Option 2: Fix parser to handle multiple JSON**

Update `SshService::executeJson()` to parse the last JSON object:

```php
public function executeJson(Node $node, string $command): array
{
    $result = $this->execute($node, $command);

    // Split by newlines and parse each as JSON
    $lines = array_filter(explode("\n", trim($result['output'])));
    $decoded = null;

    foreach (array_reverse($lines) as $line) {
        $parsed = json_decode($line, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $decoded = $parsed;
            break;
        }
    }

    if ($decoded !== null) {
        return [
            'success' => true,
            'exit_code' => $result['exit_code'],
            'data' => $decoded,
        ];
    }

    // ... existing error handling
}
```

**Option 3: Use NDJSON format**

Have CLI output newline-delimited JSON and parse the last valid line.

## Prevention

### CLI Command Convention

When adding `--json` support to commands:

```php
// ❌ BAD - Outputs multiple JSON objects
public function handle()
{
    $this->outputJsonSuccess(['step' => 1]);
    // ... more work ...
    $this->outputJsonSuccess(['step' => 2]);
    return 0;
}

// ✅ GOOD - Single JSON output
public function handle()
{
    if (!$this->wantsJson()) {
        $this->info('Step 1...');
    }
    // ... work ...
    if (!$this->wantsJson()) {
        $this->info('Step 2...');
    }
    return $this->outputJsonSuccess(['final': 'result']);
}
```

### Parser Convention

When parsing CLI JSON output via SSH:

```php
// ❌ BAD - Assumes single JSON object
$decoded = json_decode($output, true);

// ✅ GOOD - Handle multiple JSON objects
$lines = array_filter(explode("\n", trim($output)));
foreach (array_reverse($lines) as $line) {
    $decoded = json_decode($line, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        break;
    }
}
```

### Test Case

Add to CLI command tests:

```php
it('outputs only final result when --json flag is used', function () {
    $this->artisan('project:deploy', ['name' => 'test', '--json' => true])
        ->assertExitCode(0);

    $output = $this->artisan->output();
    $lines = array_filter(explode("\n", trim($output)));

    // Should only have ONE JSON object
    expect($lines)->toHaveCount(1);

    $decoded = json_decode($lines[0], true);
    expect($decoded)->toHaveKey('success');
    expect($decoded)->toHaveKey('data');
});
```

## Workaround (Current)

Until the CLI is fixed, use `gateway_sync_node` after deployments to reconcile state:

```bash
# Deploy (may report failure even if successful)
curl -X POST http://orbit.gateway/mcp/gateway -d '{
  "method": "tools/call",
  "params": {
    "name": "gateway_deploy",
    "arguments": {"project_slug": "mysite", "node_id": 5}
  }
}'

# Sync to get actual state
curl -X POST http://orbit.gateway/mcp/gateway -d '{
  "method": "tools/call",
  "params": {
    "name": "gateway_sync_node",
    "arguments": {"node_id": 5}
  }
}'
```

## Related

- Similar issue in `project:delete --json` and other CLI commands
- Affects all commands that use `WithJsonOutput` trait
- Web UI deployments might not be affected (they use queue jobs, not direct CLI)
