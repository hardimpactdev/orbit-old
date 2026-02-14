<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Services\CloudflareService;
use LaravelZero\Framework\Commands\Command;

final class ProjectStoreCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:store
        {name : Project display name}
        {slug : URL-safe slug}
        {--repo= : GitHub repository}
        {--domain= : Production domain}
        {--zone-id= : Cloudflare zone ID}
        {--zone-name= : Cloudflare zone name}
        {--json}';

    protected $description = 'Store a gateway project in the local database (runs on gateway)';

    protected $hidden = true;

    public function handle(): int
    {
        $slug = $this->argument('slug');

        if (GatewayProject::where('slug', $slug)->exists()) {
            return $this->outputJsonError("Project with slug '{$slug}' already exists");
        }

        $data = [
            'name' => $this->argument('name'),
            'slug' => $slug,
            'github_repo' => $this->option('repo'),
            'production_domain' => $this->option('domain'),
            'cloudflare_zone_id' => $this->option('zone-id'),
            'cloudflare_zone_name' => $this->option('zone-name'),
        ];

        // Auto-detect zone from production domain if not explicitly provided
        $domain = $this->option('domain');
        if ($domain && ! $this->option('zone-id')) {
            $cloudflare = app(CloudflareService::class);
            $zone = $cloudflare->detectZoneForDomain($domain);
            if ($zone) {
                $data['cloudflare_zone_id'] = $zone['zone_id'];
                $data['cloudflare_zone_name'] = $zone['zone_name'];
            }
        }

        $project = GatewayProject::create($data);

        return $this->outputJsonSuccess([
            'id' => $project->id,
            'slug' => $project->slug,
            'name' => $project->name,
            'github_repo' => $project->github_repo,
            'production_domain' => $project->production_domain,
            'cloudflare_zone_id' => $project->cloudflare_zone_id,
            'cloudflare_zone_name' => $project->cloudflare_zone_name,
        ]);
    }
}
