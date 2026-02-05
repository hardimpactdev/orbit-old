<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Services\GatewayManager;
use LaravelZero\Framework\Commands\Command;

/**
 * List configured gateway servers.
 */
final class GatewayListCommand extends Command
{
    protected $signature = 'gateway:list';

    protected $description = 'List configured gateway servers';

    public function handle(GatewayManager $gatewayManager): int
    {
        $gateways = $gatewayManager->all();

        if ($gateways === []) {
            $this->warn('No gateways configured.');
            $this->info('Add a gateway with: orbit gateway:add');

            return self::SUCCESS;
        }

        $this->info('Configured Gateways:');
        $this->newLine();

        foreach ($gateways as $gateway) {
            $this->line("  <fg=green>{$gateway['name']}</>");
            $this->line("    ID:     {$gateway['id']}");
            $this->line("    IP:     {$gateway['ip']}");
            $this->line("    Subnet: {$gateway['subnet']}");
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
