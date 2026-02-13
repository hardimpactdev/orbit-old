<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class GatewayListCommand extends Command
{
    protected $signature = 'list:gateways';

    protected $description = 'List configured gateway servers';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        $gateways = $gatewayManager->all();

        if ($gateways === []) {
            $this->warn('No gateways configured.');
            $this->info('Add a gateway with: orbit gateway:add');

            return self::SUCCESS;
        }

        $active = $adapter->detectActive();
        $activeId = $active['gateway']['id'] ?? null;

        $this->newLine();

        foreach ($gateways as $gateway) {
            $isActive = $gateway['id'] === $activeId;
            $dot = $isActive ? '<fg=green>●</>' : '<fg=gray>○</>';
            $nameColor = $isActive ? 'green' : 'white';
            $badge = $isActive ? ' <fg=green>connected</>' : '';

            $this->line("  {$dot}  <fg={$nameColor}>{$gateway['name']}</>  <fg=gray>{$gateway['ip']}</>  <fg=gray>{$gateway['subnet']}</>{$badge}");
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
