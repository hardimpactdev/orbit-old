<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Services\CloudflareService;
use LaravelZero\Framework\Commands\Command;

final class CloudflareDnsListCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:dns-list
        {--zone-id= : Cloudflare zone ID}
        {--name= : Filter by record name (FQDN)}
        {--type= : Filter by record type (A, CNAME, TXT, etc.)}
        {--json}';

    protected $description = 'List Cloudflare DNS records (runs on gateway)';

    protected $hidden = true;

    public function handle(CloudflareService $cloudflare): int
    {
        $zoneId = $this->option('zone-id');

        if (! $cloudflare->isConfigured($zoneId)) {
            return $this->outputJsonError('Cloudflare is not configured. Set API token and zone ID first.');
        }

        try {
            $records = $cloudflare->listRecords(
                name: $this->option('name'),
                type: $this->option('type'),
                zoneId: $zoneId,
            );
        } catch (\Throwable $e) {
            return $this->outputJsonError("Cloudflare API error: {$e->getMessage()}");
        }

        $data = array_map(fn (array $record) => [
            'id' => $record['id'],
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'proxied' => $record['proxied'] ?? false,
            'ttl' => $record['ttl'],
        ], $records);

        return $this->outputJsonSuccess(['records' => $data]);
    }
}
