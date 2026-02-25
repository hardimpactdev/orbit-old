<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareCacheRuleCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:cache-rule
        {--zone-id= : Cloudflare zone ID}
        {--project= : Resolve zone from project slug}
        {--json}';

    protected $description = 'Create a "Cache Everything" cache rule for a Cloudflare zone';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = ['cloudflare:cache-create-rule'];

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

        $this->info('Cache rule created.');

        return self::SUCCESS;
    }
}
