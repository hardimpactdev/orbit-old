<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use Illuminate\Support\Collection;

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
            ->whereNotIn('status', [DeploymentStatus::Removed, DeploymentStatus::Failed])
            ->first();

        if ($existing) {
            throw new \RuntimeException("Active deployment for '{$slug}' already exists on node '{$target->name}'");
        }

        $deployment = Deployment::create([
            'node_id' => $target->id,
            'project_slug' => $slug,
            'project_name' => $name,
            'github_repo' => $repo,
            'php_version' => $phpVersion,
            'status' => DeploymentStatus::Deploying,
        ]);

        $cliArgs = "site:create {$name} --json";
        if ($repo) {
            $cliArgs .= " --clone={$repo}";
        }
        if ($template) {
            $cliArgs .= " --template={$template}";
        }
        if ($phpVersion) {
            $cliArgs .= " --php={$phpVersion}";
        }

        $result = $this->command->executeCommand($target, $cliArgs, 120);

        if (! ($result['success'] ?? false)) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'error_message' => $result['error'] ?? 'Deployment failed',
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

    public function undeploy(Deployment $deployment): bool
    {
        $node = $deployment->node;
        $result = $this->command->executeCommand($node, "site:delete {$deployment->project_slug} --force --json");

        if ($deployment->hasCloudflareRecord() && $this->cloudflare->isConfigured()) {
            $this->cloudflare->deleteRecord($deployment->cloudflare_record_id);
        }

        $deployment->update(['status' => DeploymentStatus::Removed]);

        return $result['success'] ?? true;
    }

    public function syncNode(Node $node): array
    {
        $result = $this->command->executeCommand($node, 'site:list --json');

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to list projects'];
        }

        $remoteSites = $result['data']['sites'] ?? $result['data'] ?? [];
        $synced = [];

        foreach ($remoteSites as $site) {
            $slug = $site['slug'] ?? $site['name'] ?? null;
            if (! $slug) {
                continue;
            }

            $deployment = Deployment::updateOrCreate(
                ['node_id' => $node->id, 'project_slug' => $slug],
                [
                    'project_name' => $site['name'] ?? $slug,
                    'domain' => $site['domain'] ?? null,
                    'url' => $site['url'] ?? null,
                    'php_version' => $site['php_version'] ?? $site['php'] ?? null,
                    'status' => DeploymentStatus::Active,
                ],
            );

            $synced[] = $deployment;
        }

        $remoteSlugs = array_map(fn ($s) => $s['slug'] ?? $s['name'] ?? '', $remoteSites);
        Deployment::where('node_id', $node->id)
            ->where('status', DeploymentStatus::Active)
            ->whereNotIn('project_slug', $remoteSlugs)
            ->update(['status' => DeploymentStatus::Removed]);

        return ['success' => true, 'synced' => count($synced)];
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
