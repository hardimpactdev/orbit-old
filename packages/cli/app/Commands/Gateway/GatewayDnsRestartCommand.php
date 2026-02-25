<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use LaravelZero\Framework\Commands\Command;

final class GatewayDnsRestartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'gateway:dns-restart {--json}';

    protected $description = 'Restart dnsmasq to flush DNS cache (runs on gateway)';

    protected $hidden = true;

    public function handle(GatewayCliAdapter $adapter): int
    {
        try {
            $adapter->restartDns();
        } catch (\Throwable $e) {
            return $this->outputJsonError("Failed to restart DNS: {$e->getMessage()}");
        }

        return $this->outputJsonSuccess(['restarted' => true]);
    }
}
