<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use HardImpact\Orbit\Core\Models\Setting;
use LaravelZero\Framework\Commands\Command;

final class GatewaySetPasswordCommand extends Command
{
    protected $signature = 'gateway:set-password {password}';

    protected $description = 'Store the wg-easy password in the local database';

    public function handle(): int
    {
        $password = $this->argument('password');

        Setting::set('wg_easy_password', $password);

        $this->info('Password stored.');

        return self::SUCCESS;
    }
}
