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

        $cliArgs = "project:create {$name} --json";
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

    public function deployProject(GatewayProject $project, Node $target, array $options = []): Deployment
    {
        $domain = $project->domainForNode($target);

        $deployment = $this->deploy($target, array_merge([
            'name' => $project->slug,
            'clone' => $options['clone'] ?? $project->github_repo,
        ], $options));

        $deployment->update([
            'gateway_project_id' => $project->id,
        ]);

        if ($domain) {
            $deployment->update(['domain' => $domain]);
        }

        if ($domain && $target->external_host && $project->hasCloudflareZone()) {
            $zoneId = $project->cloudflare_zone_id;
            if ($this->cloudflare->isConfigured($zoneId) && $this->cloudflare->isDomainAvailable($domain, $zoneId)) {
                $record = $this->cloudflare->createRecord($domain, $target->external_host, zoneId: $zoneId);
                if ($record) {
                    $deployment->update(['cloudflare_record_id' => $record['id']]);
                }
            }
        }

        return $deployment->fresh();
    }

    public function undeploy(Deployment $deployment): bool
    {
        $node = $deployment->node;
        $result = $this->command->executeCommand($node, "project:delete {$deployment->project_slug} --force --json");

        if ($deployment->hasCloudflareRecord()) {
            $zoneId = $deployment->gatewayProject?->cloudflare_zone_id;
            if ($this->cloudflare->isConfigured($zoneId)) {
                $this->cloudflare->deleteRecord($deployment->cloudflare_record_id, $zoneId);
            }
        }

        $deployment->update(['status' => DeploymentStatus::Removed]);

        return $result['success'] ?? true;
    }

    public function syncNode(Node $node): array
    {
        $result = $this->command->executeCommand($node, 'project:list --json');

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to list projects'];
        }

        $remoteSites = $result['data']['projects'] ?? $result['data']['sites'] ?? $result['data'] ?? [];
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
