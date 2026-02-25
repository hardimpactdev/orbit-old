<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Services\CloudflareService;
use LaravelZero\Framework\Commands\Command;

final class CloudflareCacheRuleCreateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:cache-create-rule
        {--zone-id= : Cloudflare zone ID}
        {--project= : Resolve zone from project slug}
        {--json}';

    protected $description = 'Create a "Cache Everything" cache rule (runs on gateway)';

    protected $hidden = true;

    public function handle(CloudflareService $cloudflare): int
    {
        $zoneId = $this->option('zone-id');

        if ($zoneId === null && $this->option('project')) {
            $project = GatewayProject::where('slug', $this->option('project'))->first();

            if ($project === null) {
                return $this->outputJsonError("Project not found: {$this->option('project')}");
            }

            $zoneId = $project->cloudflare_zone_id;

            if ($zoneId === null) {
                return $this->outputJsonError("Project '{$project->slug}' has no Cloudflare zone configured.");
            }
        }

        if (! $cloudflare->isConfigured($zoneId)) {
            return $this->outputJsonError('Cloudflare is not configured. Provide --zone-id or --project.');
        }

        $success = $cloudflare->createCacheRule($zoneId);

        if (! $success) {
            return $this->outputJsonError('Failed to create cache rule.');
        }

        return $this->outputJsonSuccess([
            'created' => true,
            'zone_id' => $zoneId,
        ]);
    }
}
