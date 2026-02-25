<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class GatewayListCommand extends Command
{
    use WithHumanOutput;

    protected $signature = 'list:gateways';

    protected $description = 'List configured gateway servers';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        $gateways = $gatewayManager->all();

        if ($gateways->isEmpty()) {
            $this->warn('No gateways configured.');
            $this->info('Add a gateway with: orbit gateway:add');

            return self::SUCCESS;
        }

        $active = $adapter->detectActive();
        $activeId = $active['gateway']->id ?? null;

        $tableData = $gateways->map(fn ($gateway) => [
            'name' => $gateway->name,
            'ip_address' => $gateway->ip_address,
            'subnet' => $gateway->subnet,
            'status' => $gateway->id === $activeId ? 'connected' : 'offline',
        ])->toArray();

        $this->renderForHumans($tableData, 'Gateways');

        return self::SUCCESS;
    }
}
