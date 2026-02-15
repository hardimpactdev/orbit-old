---
date: 2026-02-15
problem_type: integration-issue
component: deployment
severity: moderate
symptoms:
  - "Deployment fails after starting remote commands"
  - "15+ back-and-forth messages to diagnose missing prerequisites"
  - "Errors about repo access, CLI not found, SSH failures mid-deployment"
root_cause: "No pre-flight validation before attempting deployment"
tags: [deployment, validation, ssh, github, preflight]
---

# Missing Pre-flight Deployment Validation

## Symptom

Production deployment to `lindaretel` failed with cryptic errors after the deployment process had already started. Required 15+ messages to diagnose that the issue was missing GitHub authentication on the target node.

Other common failure modes discovered:
- SSH connectivity issues (node unreachable)
- CLI binary not installed on target node
- GitHub repo inaccessible (private repo, no `gh` auth)

All of these could be detected BEFORE attempting the actual deployment.

## Investigation

1. **Attempted**: Check deployment logs
   - Result: Only showed "deployment failed" - no specifics

2. **Attempted**: Manual SSH to debug
   - Result: Found `gh repo view` failed with auth error
   - Issue: Production node needs `gh auth login` for private repos

3. **Root discovery**: No validation before deployment starts
   - Result: Deployments fail partway through, leaving partial state

## Root Cause

The `GatewayDeployTool` immediately dispatched to `deployWithProject()` or `deployLegacy()` without checking:

1. Can we reach the target node via SSH?
2. Is the orbit CLI installed on the node?
3. Can the node access the GitHub repo?

These checks are fast (< 5 seconds total) but save minutes of debugging when they fail.

## Solution

Add `preflight()` method to `GatewayDeployTool` that runs BEFORE deployment dispatch:

```php
private function preflight(Node $node, ?string $repo): ?ResponseFactory
{
    // 1. SSH connectivity
    $ssh = app(SshService::class)->testConnection($node);
    if (! $ssh['success']) {
        return Response::structured([
            'success' => false,
            'error' => "Cannot reach node '{$node->name}' via SSH: {$ssh['message']}",
        ]);
    }

    // 2. CLI binary exists
    $binary = app(CommandService::class)->findBinary($node);
    if (! $binary) {
        return Response::structured([
            'success' => false,
            'error' => "Orbit CLI not found on node '{$node->name}'. Install it first.",
        ]);
    }

    // 3. Repo access from node via gh CLI
    if ($repo) {
        $repoCheck = app(SshService::class)->execute(
            $node,
            "gh repo view {$repo} --json name 2>&1"
        );
        if (! $repoCheck['success'] || str_contains($repoCheck['output'] ?? '', 'Could not resolve') || str_contains($repoCheck['error'] ?? '', 'auth login')) {
            return Response::structured([
                'success' => false,
                'error' => "Node '{$node->name}' cannot access repo '{$repo}'. Run `gh auth login` on the node to authenticate: ssh {$node->user}@{$node->host}",
            ]);
        }
    }

    return null; // All checks passed
}
```

### Integration

Wire into `handle()` method after node validation:

```php
public function handle(Request $request): ResponseFactory
{
    // ... node validation ...

    $repo = $request->get('clone') ?? GatewayProject::where('slug', $request->get('project_slug'))->value('github_repo');
    $preflight = $this->preflight($node, $repo);
    if ($preflight) {
        return $preflight;  // Fail fast with actionable error
    }

    // ... proceed with deployment ...
}
```

## Prevention

1. **Always validate prerequisites before long operations** - SSH, files, credentials
2. **Fail fast with actionable errors** - tell user exactly how to fix it
3. **Include SSH commands in error messages** - makes it copy-pasteable
4. **Test each check independently** - ensure they catch the right failures

## Warning Signs

- Deployments fail partway through
- Error messages don't explain what to fix
- Users need to manually SSH to debug
- Multiple back-and-forth messages to diagnose simple issues

## Test Cases

```php
it('detects SSH connectivity failure', function () {
    $ssh = mock(SshService::class);
    $ssh->shouldReceive('testConnection')->andReturn(['success' => false, 'message' => 'Connection refused']);

    $response = $tool->handle($request);

    expect($response['error'])->toContain('Cannot reach node');
});
```

## Related

- See `SshService::testConnection()` for SSH validation
- See `CommandService::findBinary()` for CLI detection
- Production nodes use `gh` CLI (not SSH keys) for GitHub access
