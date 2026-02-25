<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareStatusCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'cloudflare:status {zone-id?} {--json}';

    protected $description = 'Show Cloudflare zone info and SSL mode';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = ['cloudflare:zone-status'];

        if ($zoneId = $this->argument('zone-id')) {
            $args[] = $zoneId;
        }

        $args[] = '--json';

        $result = $adapter->forwardJson(implode(' ', $args));

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($result['data']);
        }

        $this->renderForHumans([
            'id' => $result['data']['id'],
            'name' => $result['data']['name'],
            'status' => $result['data']['status'],
            'plan' => $result['data']['plan'],
            'name_servers' => $result['data']['name_servers'] ?? [],
        ], 'Cloudflare Zone');

        return self::SUCCESS;
    }
}
