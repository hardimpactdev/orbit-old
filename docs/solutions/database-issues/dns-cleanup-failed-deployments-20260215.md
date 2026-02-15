---
date: 2026-02-15
problem_type: database-issue
component: deployment-tracking
severity: moderate
symptoms:
  - "Stale DNS records after failed deployments"
  - "Old IPs in Cloudflare for removed/failed projects"
  - "DNS points to wrong server after re-deploy"
root_cause: "No cleanup logic when deployments fail"
tags: [dns, cloudflare, deployment, cleanup, state-management]
---

# Stale DNS Records from Failed Deployments

## Symptom

After a failed re-deployment:
1. Old DNS record still exists in Cloudflare
2. Points to previous/wrong IP address
3. Subsequent successful deploy creates NEW record (duplicate)
4. Or domain shows old version of site

## Root Cause

No cleanup when `deploy()` fails - deployment marked as Failed but Cloudflare record left in place.

## Solution

Add DNS cleanup in `deploy()` method when deployment fails:

```php
if (! ($result['success'] ?? false)) {
    $deployment->update([
        'status' => DeploymentStatus::Failed,
        'error_message' => trim($result['error'] ?? '') ?: 'Deployment command failed',
    ]);

    // Clean up existing DNS record on failed re-deploy
    if ($deployment->hasCloudflareRecord()) {
        try {
            $zoneId = $deployment->gatewayProject?->cloudflare_zone_id;
            if ($this->cloudflare->isConfigured($zoneId)) {
                $this->cloudflare->deleteRecord($deployment->cloudflare_record_id, $zoneId);
                Log::info("Cleaned up DNS record for failed deployment {$deployment->id}");
            }
            $deployment->update(['cloudflare_record_id' => null]);
        } catch (\Throwable $e) {
            Log::warning("DNS cleanup failed for deployment {$deployment->id}: {$e->getMessage()}");
        }
    }

    return $deployment->fresh();
}
```

### Also: Skip DNS creation for failed deployments

In `deployProject()`, don't create DNS if deployment failed:

```php
// Don't create DNS for failed deployments
if ($deployment->isFailed()) {
    $deployment->update(['gateway_project_id' => $project->id, 'domain' => $domain]);
    return $deployment->fresh();
}
```

## Prevention

1. **Clean up on failure** - any resource created during deploy should be removed on failure
2. **Test failure paths** - verify cleanup happens when things go wrong
3. **Log cleanup actions** - make it visible when DNS is removed

## Related

- Convention: Always clean up resources when operations fail
