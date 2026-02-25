<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class GatewayFlushDnsCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'gateway:flush-dns {--json}';

    protected $description = 'Flush DNS cache on the active gateway';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $result = $adapter->forwardJson('gateway:dns-restart --json');

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($result['data']);
        }

        $this->info('Gateway DNS cache flushed.');

        return self::SUCCESS;
    }
}
