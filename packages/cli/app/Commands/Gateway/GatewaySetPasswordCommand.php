<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use LaravelZero\Framework\Commands\Command;

final class GatewaySetPasswordCommand extends Command
{
    protected $signature = 'gateway:set-password {password}';

    protected $description = 'Store the wg-easy password in the local database';

    public function handle(): int
    {
        $password = $this->argument('password');

        // Validate by attempting to connect
        try {
            $service = WgEasyService::forGateway('127.0.0.1', 51821, $password);
            $clients = $service->getClients();

            Setting::set('wg_easy_password', $password);

            $this->info('Password stored and verified.');
            $this->line('  <fg=gray>'.count($clients).' VPN client(s) found</>');
        } catch (\Exception) {
            // Store anyway — the API might not be reachable right now
            Setting::set('wg_easy_password', $password);

            $this->info('Password stored.');
            $this->warn('  Could not verify — WireGuard API not reachable on 127.0.0.1:51821');
        }

        return self::SUCCESS;
    }
}
