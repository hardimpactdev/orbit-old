<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareFlushCacheCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:flush-cache
        {--zone-id= : Cloudflare zone ID}
        {--project= : Resolve zone from project slug}
        {--json}';

    protected $description = 'Purge Cloudflare cache for a zone';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = ['cloudflare:cache-purge'];

        if ($zoneId = $this->option('zone-id')) {
            $args[] = '--zone-id='.$zoneId;
        }

        if ($project = $this->option('project')) {
            $args[] = '--project='.$project;
        }

        $args[] = '--json';

        $result = $adapter->forwardJson(implode(' ', $args));

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($result['data']);
        }

        $this->info('Cloudflare cache purged.');

        return self::SUCCESS;
    }
}
