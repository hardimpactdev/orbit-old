<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use LaravelZero\Framework\Commands\Command;

final class GatewayDnsMappingsCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'gateway:dns-list {--json}';

    protected $description = 'List DNS TLD mappings (runs on gateway)';

    protected $hidden = true;

    public function handle(GatewayDnsService $dnsService): int
    {
        $mappings = $dnsService->getMappings();

        return $this->outputJsonSuccess(['mappings' => $mappings]);
    }
}
