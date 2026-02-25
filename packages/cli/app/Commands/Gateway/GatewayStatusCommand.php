<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class GatewayStatusCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'gateway:status {--json}';

    protected $description = 'Show gateway health status';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $result = $adapter->forwardJson('gateway:health --json');

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($result['data']);
        }

        $this->renderForHumans([
            'vpn_clients' => $result['data']['vpn_clients'] ?? 0,
            'dns_mappings' => $result['data']['dns_mappings'] ?? 0,
            'nodes' => $result['data']['nodes'] ?? 0,
            'active_deployments' => $result['data']['active_deployments'] ?? 0,
            'uptime' => $result['data']['uptime'] ?? 'unknown',
        ], 'Gateway Status');

        return self::SUCCESS;
    }
}
