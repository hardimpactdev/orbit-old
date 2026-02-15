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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DeploymentService
{
    public function __construct(
        protected CommandService $command,
        protected CloudflareService $cloudflare,
    ) {}

    public function deploy(Node $target, array $options): Deployment
    {
        $slug = $options['name'];
        $name = $options['name'];
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

        $useReleaseDeploy = $target->isProduction() || $target->isStaging();
        $command = $useReleaseDeploy ? 'project:deploy' : 'project:create';

        $args = [$command, escapeshellarg($name), '--json'];
        if ($repo) {
            $args[] = '--clone=' . escapeshellarg($repo);
        }
        if (! $useReleaseDeploy && $template) {
            $args[] = '--template=' . escapeshellarg($template);
        }
        if ($phpVersion) {
            $args[] = '--php=' . escapeshellarg($phpVersion);
        }

        $result = $this->command->executeCommand($target, implode(' ', $args), 120);

        if (! ($result['success'] ?? false)) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'error_message' => trim($result['error'] ?? '') ?: 'Deployment command failed — check node connectivity and CLI installation',
            ]);

            return $deployment->fresh();
        }

        $siteData = $result['data'] ?? $result;
        $deployment->update([
            'status' => DeploymentStatus::Active,
            'domain' => $siteData['domain'] ?? null,
            'url' => $siteData['url'] ?? null,
        ]);

        return $deployment->fresh();
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
        ], $options));

        $updates = ['gateway_project_id' => $project->id];

        if ($domain) {
            $updates['domain'] = $domain;
        }

        if ($domain && $target->external_host && $project->hasCloudflareZone()) {
            $zoneId = $project->cloudflare_zone_id;
            if ($this->cloudflare->isConfigured($zoneId) && $this->cloudflare->isDomainAvailable($domain, $zoneId)) {
                $record = $this->cloudflare->createRecord($domain, $target->external_host, zoneId: $zoneId);
                if ($record) {
                    $updates['cloudflare_record_id'] = $record['id'];
                }
            }
        }

        $deployment->update($updates);

        return $deployment->fresh();
    }

    public function undeploy(Deployment $deployment): bool
    {
        $node = $deployment->node;
        $result = $this->command->executeCommand(
            $node,
            'project:delete ' . escapeshellarg($deployment->project_slug) . ' --force --json',
        );

        if ($deployment->hasCloudflareRecord()) {
            try {
                $zoneId = $deployment->gatewayProject?->cloudflare_zone_id;
                if ($this->cloudflare->isConfigured($zoneId)) {
                    $this->cloudflare->deleteRecord($deployment->cloudflare_record_id, $zoneId);
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

        return $result['success'] ?? true;
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
