<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Services\CloudflareService;
use LaravelZero\Framework\Commands\Command;

final class CloudflareRecordRemoveCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:record-remove {record-id}
        {--zone-id= : Cloudflare zone ID}
        {--json}';

    protected $description = 'Remove a DNS record from Cloudflare (runs on gateway)';

    protected $hidden = true;

    public function handle(CloudflareService $cloudflare): int
    {
        $zoneId = $this->option('zone-id');

        if (! $cloudflare->isConfigured($zoneId)) {
            return $this->outputJsonError('Cloudflare is not configured. Set API token and zone ID first.');
        }

        try {
            $deleted = $cloudflare->deleteRecord(
                recordId: $this->argument('record-id'),
                zoneId: $zoneId,
            );
        } catch (\Throwable $e) {
            return $this->outputJsonError("Cloudflare API error: {$e->getMessage()}");
        }

        if (! $deleted) {
            return $this->outputJsonError('Failed to delete DNS record.');
        }

        return $this->outputJsonSuccess([
            'deleted' => $this->argument('record-id'),
        ]);
    }
}
