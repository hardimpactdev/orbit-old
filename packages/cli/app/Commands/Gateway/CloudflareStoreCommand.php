<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use HardImpact\Orbit\Core\Models\Setting;
use LaravelZero\Framework\Commands\Command;

final class CloudflareStoreCommand extends Command
{
    protected $signature = 'cloudflare:store {token}';

    protected $description = 'Store Cloudflare API token in the local database (runs on gateway)';

    protected $hidden = true;

    public function handle(): int
    {
        Setting::set('cloudflare_api_token', $this->argument('token'));

        $this->info('Cloudflare API token stored.');

        return self::SUCCESS;
    }
}
