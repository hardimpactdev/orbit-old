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

        $active = $adapter->detectActive();

        if ($active === null) {
            return $this->failWithMessage('No active WireGuard connection found matching a configured gateway subnet.');
        }

        $gateway = $active['gateway'];

        $output = $adapter->sshCommand($gateway->id, 'gateway:clients --json');

        if ($output === null) {
            return $this->failWithMessage("Failed to connect to gateway '{$gateway->name}' via SSH.");
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
            $error = $decoded['error'] ?? 'Unknown error from gateway';

            return $this->failWithMessage("Gateway error: {$error}");
        }

        $clients = $decoded['data']['clients'] ?? [];

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'gateway' => $gateway->name,
                'clients' => $clients,
            ]);
        }

        $this->line("  <fg=gray>Gateway:</> {$gateway->name}");

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

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonError($message);
        }

        $this->error($message);

        return self::FAILURE;
    }
}
