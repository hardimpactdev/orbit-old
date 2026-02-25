<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Models\GatewayProject;
use LaravelZero\Framework\Commands\Command;

final class ProjectListCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'gateway:project-list {--json}';

    protected $description = 'List registered gateway projects (runs on gateway)';

    protected $hidden = true;

    public function handle(): int
    {
        $projects = GatewayProject::withCount('deployments')->get();

        $data = $projects->map(fn (GatewayProject $project) => [
            'slug' => $project->slug,
            'name' => $project->name,
            'production_domain' => $project->production_domain,
            'cloudflare_zone_name' => $project->cloudflare_zone_name,
            'github_repo' => $project->github_repo,
            'deployment_count' => $project->deployments_count,
        ])->all();

        return $this->outputJsonSuccess(['projects' => $data]);
    }
}
