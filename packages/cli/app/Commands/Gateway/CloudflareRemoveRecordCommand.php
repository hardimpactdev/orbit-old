<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareRemoveRecordCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:remove-record {record-id}
        {--zone-id= : Cloudflare zone ID}
        {--json}';

    protected $description = 'Remove a DNS record from Cloudflare';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = [
            'cloudflare:record-remove',
            $this->argument('record-id'),
        ];

        if ($zoneId = $this->option('zone-id')) {
            $args[] = '--zone-id='.$zoneId;
        }

        $args[] = '--json';

        $result = $adapter->forwardJson(implode(' ', $args));

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($result['data']);
        }

        $this->info("DNS record '{$this->argument('record-id')}' deleted.");

        return self::SUCCESS;
    }
}
