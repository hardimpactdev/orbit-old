<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareSetSslCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:set-ssl {zone-id} {mode}
        {--json}';

    protected $description = 'Set Cloudflare zone SSL/TLS mode';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = [
            'cloudflare:ssl-set',
            $this->argument('zone-id'),
            $this->argument('mode'),
            '--json',
        ];

        $result = $adapter->forwardJson(implode(' ', $args));

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($result['data']);
        }

        $this->info("SSL mode set to '{$this->argument('mode')}' for zone {$this->argument('zone-id')}.");

        return self::SUCCESS;
    }
}
