<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Services\CloudflareService;
use LaravelZero\Framework\Commands\Command;

final class CloudflareZoneStatusCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:zone-status {zone-id?} {--json}';

    protected $description = 'Show Cloudflare zone info and SSL mode (runs on gateway)';

    protected $hidden = true;

    public function handle(CloudflareService $cloudflare): int
    {
        $zoneId = $this->argument('zone-id');

        if (! $cloudflare->isConfigured($zoneId)) {
            return $this->outputJsonError('Cloudflare is not configured. Set API token and zone ID first.');
        }

        try {
            $zone = $cloudflare->getZone($zoneId);
        } catch (\Throwable $e) {
            return $this->outputJsonError("Cloudflare API error: {$e->getMessage()}");
        }

        if ($zone === null) {
            return $this->outputJsonError('Zone not found.');
        }

        return $this->outputJsonSuccess([
            'id' => $zone['id'],
            'name' => $zone['name'],
            'status' => $zone['status'],
            'plan' => $zone['plan']['name'] ?? 'unknown',
            'name_servers' => $zone['name_servers'] ?? [],
        ]);
    }
}
