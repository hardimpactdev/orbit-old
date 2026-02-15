<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use HardImpact\Orbit\Core\Models\Setting;
use LaravelZero\Framework\Commands\Command;

final class CloudflareStoreCommand extends Command
{
    protected $signature = 'cloudflare:store';

    protected $description = 'Store Cloudflare API token in the local database (runs on gateway, reads token from stdin)';

    protected $hidden = true;

    public function handle(): int
    {
        $token = trim(fgets(STDIN) ?: '');

        if ($token === '') {
            $this->error('No API token provided via stdin.');

            return self::FAILURE;
        }

        Setting::set('cloudflare_api_token', $token);

        $this->info('Cloudflare API token stored.');

        return self::SUCCESS;
    }
}
