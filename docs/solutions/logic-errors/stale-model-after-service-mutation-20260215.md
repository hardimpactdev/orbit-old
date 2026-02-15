---
date: 2026-02-15
problem_type: logic-error
component: GatewayUndeployTool, DeploymentService
severity: moderate
symptoms:
  - "MCP tool reports cloudflare_cleaned=false even though record was deleted"
  - "Model property returns stale value after service call modifies it"
root_cause: Reading Eloquent model property after a service method updates the same model without refreshing
tags: [eloquent, stale-data, mcp, deployment]
---

# Stale Eloquent Model After Service Mutation

## Symptom

`GatewayUndeployTool` reported `cloudflare_cleaned: false` in its response even though the Cloudflare record was successfully deleted by `DeploymentService::undeploy()`.

```php
$this->deploymentService->undeploy($deployment);

// BUG: $deployment still has the OLD cloudflare_record_id in memory
// undeploy() set it to null via $deployment->update([...])
// but the in-memory model wasn't refreshed
return Response::structured([
    'cloudflare_cleaned' => $deployment->hasCloudflareRecord(), // Always false after undeploy!
]);
```

## Root Cause

`DeploymentService::undeploy()` updates the deployment's status to `Removed` via `$deployment->update()`. While `update()` modifies the database and the in-memory attributes, the `cloudflare_record_id` itself isn't cleared by undeploy — however the pattern is dangerous because `hasCloudflareRecord()` reads from a model that the service just mutated.

The broader issue: when a service method modifies a model, the caller's reference may have stale data depending on what the service did.

## Solution

Capture any needed values BEFORE passing the model to a mutating service:

```php
// Before (stale data)
$this->deploymentService->undeploy($deployment);
$cloudflare_cleaned = $deployment->hasCloudflareRecord(); // reads post-mutation state

// After (safe - captured before mutation)
$hadCloudflareRecord = $deployment->hasCloudflareRecord();
$this->deploymentService->undeploy($deployment);
// Use the pre-captured value
$cloudflare_cleaned = $hadCloudflareRecord;
```

## Prevention

1. **Capture needed values before service calls** that mutate the model
2. **Use `->fresh()`** if you need post-mutation state from the database
3. **Be suspicious** when reading model properties after calling a service/repository method
4. **Pattern**: If a method takes a model and returns void/bool, assume it mutated the model

## Related

- Laravel docs: Eloquent model hydration and `fresh()`
- Similar to React's stale closure problem — captured reference vs current state
