<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class GatewayListClientsCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'list:gateway-clients {--json}';

    protected $description = 'List WireGuard clients on the active gateway';

    public function handle(GatewayManager $gatewayManager): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $active = $gatewayManager->detectActive();

        if ($active === null) {
            return $this->failWithMessage('No active WireGuard connection found matching a configured gateway subnet.');
        }

        $gateway = $active['gateway'];

        $output = $gatewayManager->sshCommand($gateway['id'], 'gateway:clients --json');

        if ($output === null) {
            return $this->failWithMessage("Failed to connect to gateway '{$gateway['name']}' via SSH.");
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
            $error = $decoded['error'] ?? 'Unknown error from gateway';

            return $this->failWithMessage("Gateway error: {$error}");
        }

        $clients = $decoded['data']['clients'] ?? [];

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'gateway' => $gateway['name'],
                'clients' => $clients,
            ]);
        }

        $this->line("  <fg=gray>Gateway:</> {$gateway['name']}");
        $this->newLine();

        if ($clients === []) {
            $this->line('  <fg=gray>No clients found.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            $online = $client['online'] ?? false;
            $dot = $online ? '<fg=green>●</>' : '<fg=gray>○</>';
            $nameColor = $online ? 'white' : 'gray';
            $disabled = ($client['enabled'] ?? true) ? '' : ' <fg=yellow>[disabled]</>';
            $tld = ! empty($client['tld']) ? " <fg=cyan>.{$client['tld']}</>" : '';

            $this->line("  {$dot}  <fg={$nameColor}>{$client['name']}</>  <fg=gray>{$client['ip']}</>{$tld}{$disabled}");
        }

        $this->newLine();

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
