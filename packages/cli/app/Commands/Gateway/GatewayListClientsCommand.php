<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class GatewayListClientsCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'list:gateway-clients {--json}';

    protected $description = 'List WireGuard clients on the active gateway';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $result = $adapter->forwardJson('gateway:clients --json');

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        $clients = $result['data']['clients'] ?? [];

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'gateway' => $result['gateway'],
                'clients' => $clients,
            ]);
        }

        $this->line("  <fg=gray>Gateway:</> {$result['gateway']}");

        if ($clients === []) {
            $this->newLine();
            $this->line('  <fg=gray>No clients found.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $tableData = array_map(fn (array $client) => [
            'name' => $client['name'],
            'ip' => $client['ip'],
            'tld' => ! empty($client['tld']) ? '.'.$client['tld'] : null,
            'status' => ($client['online'] ?? false) ? 'online' : 'offline',
            'enabled' => $client['enabled'] ?? true,
        ], $clients);

        $this->renderForHumans($tableData);

        return self::SUCCESS;
    }
}
