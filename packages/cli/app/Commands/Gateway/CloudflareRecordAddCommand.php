<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Services\CloudflareService;
use LaravelZero\Framework\Commands\Command;

final class CloudflareRecordAddCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:record-add {name} {content}
        {--zone-id= : Cloudflare zone ID}
        {--type=A : Record type (A, CNAME, TXT, etc.)}
        {--proxied : Proxy through Cloudflare}
        {--json}';

    protected $description = 'Add a DNS record to Cloudflare (runs on gateway)';

    protected $hidden = true;

    public function handle(CloudflareService $cloudflare): int
    {
        $zoneId = $this->option('zone-id');

        if (! $cloudflare->isConfigured($zoneId)) {
            return $this->outputJsonError('Cloudflare is not configured. Set API token and zone ID first.');
        }

        try {
            $record = $cloudflare->createRecord(
                name: $this->argument('name'),
                content: $this->argument('content'),
                type: $this->option('type'),
                proxied: (bool) $this->option('proxied'),
                zoneId: $zoneId,
            );
        } catch (\Throwable $e) {
            return $this->outputJsonError("Cloudflare API error: {$e->getMessage()}");
        }

        if ($record === null) {
            return $this->outputJsonError('Failed to create DNS record.');
        }

        return $this->outputJsonSuccess([
            'id' => $record['id'],
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
        ]);
    }
}
