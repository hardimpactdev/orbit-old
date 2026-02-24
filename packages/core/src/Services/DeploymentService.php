<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\Provision\GitHubService;
use HardImpact\Orbit\Core\Services\RemoteDeploy\RemoteDeployContext;
use HardImpact\Orbit\Core\Services\RemoteDeploy\RemoteDeploymentOrchestrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DeploymentService
{
    public function __construct(
        protected CommandService $command,
        protected CloudflareService $cloudflare,
        protected RemoteDeploymentOrchestrator $orchestrator,
    ) {}

    public function deploy(Node $target, array $options, ?GatewayProject $project = null): Deployment
    {
        $slug = $options['name'];
        $name = $options['name'];

        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            throw new \InvalidArgumentException("Invalid project slug '{$slug}': must contain only lowercase alphanumeric characters and hyphens");
        }
        $repo = $options['clone'] ?? null;
        $template = $options['template'] ?? null;
        $phpVersion = $options['php_version'] ?? null;

        $existing = Deployment::where('node_id', $target->id)
            ->where('project_slug', $slug)
            ->first();

        if ($existing && ! in_array($existing->status, [DeploymentStatus::Removed, DeploymentStatus::Failed])) {
            throw new \RuntimeException("Active deployment for '{$slug}' already exists on node '{$target->name}'");
        }

        $deployment = $existing
            ? tap($existing)->update([
                'project_name' => $name,
                'github_repo' => $repo,
                'php_version' => $phpVersion,
                'status' => DeploymentStatus::Deploying,
                'error_message' => null,
            ])
            : Deployment::create([
                'node_id' => $target->id,
                'project_slug' => $slug,
                'project_name' => $name,
                'github_repo' => $repo,
                'php_version' => $phpVersion,
                'status' => DeploymentStatus::Deploying,
            ]);

        $useRemoteDeploy = $target->isProduction() || $target->isStaging();

        if ($useRemoteDeploy) {
            $result = $this->deployRemote($target, $deployment, $slug, $repo, $phpVersion, $project);
        } else {
            $result = $this->deployViaCli($target, $name, $repo, $template, $phpVersion);
        }

        if (! ($result['success'] ?? false)) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'error_message' => trim($result['error'] ?? '') ?: 'Deployment failed — check node connectivity',
            ]);

            $this->cleanupDnsOnFailure($deployment);

            return $deployment->fresh();
        }

        $deployment->update([
            'status' => DeploymentStatus::Active,
            'domain' => $result['domain'] ?? null,
            'url' => $result['url'] ?? null,
        ]);

        return $deployment->fresh();
    }

    /**
     * Deploy via the RemoteDeploymentOrchestrator (prod/staging — no CLI needed on target).
     */
    private function deployRemote(Node $target, Deployment $deployment, string $slug, ?string $repo, ?string $phpVersion, ?GatewayProject $project): array
    {
        // Auto-detect PHP version from the server if not specified
        if (! $phpVersion) {
            $phpVersion = $this->orchestrator->detectPhpVersion($target);
            Log::info("Auto-detected PHP {$phpVersion} on node {$target->name}");
        }

        $ctx = new RemoteDeployContext(
            node: $target,
            slug: $slug,
            repo: $repo ?? $project?->github_repo ?? '',
            timestamp: date('Ymd_His'),
            deployment: $deployment,
            project: $project,
            phpVersion: $phpVersion,
        );

        return $this->orchestrator->deploy($ctx);
    }

    /**
     * Deploy via orbit CLI on the target node (dev nodes).
     */
    private function deployViaCli(Node $target, string $name, ?string $repo, ?string $template, ?string $phpVersion): array
    {
        $args = ['project:create', escapeshellarg($name), '--json'];
        if ($repo) {
            $args[] = '--clone=' . escapeshellarg($repo);
        }
        if ($template) {
            $args[] = '--template=' . escapeshellarg($template);
        }
        if ($phpVersion) {
            $args[] = '--php=' . escapeshellarg($phpVersion);
        }

        $result = $this->command->executeCommand($target, implode(' ', $args), 120);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => trim($result['error'] ?? '') ?: 'CLI deployment command failed — check node connectivity and CLI installation',
            ];
        }

        $siteData = $result['data'] ?? $result;

        return [
            'success' => true,
            'domain' => $siteData['domain'] ?? null,
            'url' => $siteData['url'] ?? null,
        ];
    }

    private function cleanupDnsOnFailure(Deployment $deployment): void
    {
        if (! $deployment->hasCloudflareRecord()) {
            return;
        }

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

    public function deployProject(GatewayProject $project, Node $target, array $options = []): Deployment
    {
        if (empty($options['php_version']) && $project->github_repo) {
            $detected = app(GitHubService::class)->detectPhpVersion($project->github_repo);
            if ($detected) {
                $options['php_version'] = $detected;
            }
        }

        $domain = $project->domainForNode($target);

        $deployment = $this->deploy($target, array_merge([
            'name' => $project->slug,
            'clone' => $options['clone'] ?? $project->github_repo,
        ], $options), $project);

        // Don't create DNS for failed deployments (cleanup already handled by deploy())
        if ($deployment->isFailed()) {
            $deployment->update(['gateway_project_id' => $project->id, 'domain' => $domain]);

            return $deployment->fresh();
        }

        $updates = ['gateway_project_id' => $project->id];

        if ($domain) {
            $updates['domain'] = $domain;
        }

        if ($domain && $target->external_host && $project->hasCloudflareZone()) {
            $zoneId = $project->cloudflare_zone_id;

            if ($target->isProduction()) {
                try {
                    $this->cloudflare->setSslMode($zoneId, 'strict');
                } catch (\Throwable $e) {
                    Log::warning("Failed to set SSL mode for zone {$zoneId}: {$e->getMessage()}");
                }
            }

            if ($this->cloudflare->isConfigured($zoneId) && $this->cloudflare->isDomainAvailable($domain, $zoneId)) {
                $record = $this->cloudflare->createRecord(
                    $domain,
                    $target->external_host,
                    proxied: $target->isProduction(),
                    zoneId: $zoneId
                );
                if ($record) {
                    $updates['cloudflare_record_id'] = $record['id'];
                }
            }
        }

        $deployment->update($updates);

        if ($project->hasCloudflareZone()) {
            try {
                $this->cloudflare->purgeCache($project->cloudflare_zone_id);
            } catch (\Throwable $e) {
                Log::warning("Cache purge failed for deployment {$deployment->id}: {$e->getMessage()}");
            }
        }

        return $deployment->fresh();
    }

    public function undeploy(Deployment $deployment): bool
    {
        $node = $deployment->node;
        $useRemote = $node->isProduction() || $node->isStaging();

        if ($useRemote) {
            $ctx = new RemoteDeployContext(
                node: $node,
                slug: $deployment->project_slug,
                repo: $deployment->github_repo ?? '',
                timestamp: date('Ymd_His'),
                deployment: $deployment,
                project: $deployment->gatewayProject,
                phpVersion: $deployment->php_version,
            );
            $result = $this->orchestrator->undeploy($ctx);
        } else {
            $result = $this->command->executeCommand(
                $node,
                'project:delete ' . escapeshellarg($deployment->project_slug) . ' --force --json',
            );
        }

        if ($deployment->hasCloudflareRecord()) {
            try {
                $zoneId = $deployment->gatewayProject?->cloudflare_zone_id;
                if ($this->cloudflare->isConfigured($zoneId)) {
                    $this->cloudflare->deleteRecord($deployment->cloudflare_record_id, $zoneId);
                    $this->cloudflare->purgeCache($zoneId);
                }
            } catch (\Throwable $e) {
                Log::warning("Cloudflare cleanup failed for deployment {$deployment->id}: {$e->getMessage()}");
            }
        }

        try {
            $deployment->update(['status' => DeploymentStatus::Removed]);
        } catch (\Throwable $e) {
            Log::warning("Failed to mark deployment {$deployment->id} as removed: {$e->getMessage()}");
        }

        return $result['success'] ?? false;
    }

    public function syncNode(Node $node): array
    {
        $result = $this->command->executeCommand($node, 'project:list --json');

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => trim($result['error'] ?? '') ?: 'Failed to list projects on node'];
        }

        $remoteSites = $result['data']['projects'] ?? $result['data']['sites'] ?? $result['data'] ?? [];

        $upsertRows = [];
        $remoteSlugs = [];

        foreach ($remoteSites as $site) {
            $slug = $site['slug'] ?? $site['name'] ?? null;
            if (! $slug) {
                continue;
            }

            $remoteSlugs[] = $slug;
            $upsertRows[] = [
                'node_id' => $node->id,
                'project_slug' => $slug,
                'project_name' => $site['name'] ?? $slug,
                'domain' => $site['domain'] ?? null,
                'url' => $site['url'] ?? null,
                'php_version' => $site['php_version'] ?? $site['php'] ?? null,
                'status' => DeploymentStatus::Active->value,
            ];
        }

        if ($upsertRows !== []) {
            Deployment::upsert(
                $upsertRows,
                ['node_id', 'project_slug'],
                ['project_name', 'domain', 'url', 'php_version', 'status'],
            );
        }
        Deployment::where('node_id', $node->id)
            ->where('status', DeploymentStatus::Active)
            ->whereNotIn('project_slug', $remoteSlugs)
            ->update(['status' => DeploymentStatus::Removed]);

        return ['success' => true, 'synced' => count($upsertRows)];
    }

    public function deploymentsForProject(string $slug): Collection
    {
        return Deployment::where('project_slug', $slug)
            ->with('node')
            ->get();
    }

    public function nodesByEnvironment(NodeEnvironment $env): Collection
    {
        return Node::where('environment', $env)
            ->where('node_type', '!=', NodeType::Gateway)
            ->where('is_active', true)
            ->get();
    }
}
