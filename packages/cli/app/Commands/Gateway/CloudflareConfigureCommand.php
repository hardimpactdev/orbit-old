<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Models\Gateway;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class CloudflareConfigureCommand extends Command
{
    protected $signature = 'cloudflare:configure';

    protected $description = 'Configure Cloudflare API credentials on the gateway';

    public function handle(): int
    {
        /** @var GatewayManager $gatewayManager */
        $gatewayManager = app(GatewayManager::class);
        /** @var GatewayCliAdapter $adapter */
        $adapter = app(GatewayCliAdapter::class);

        if (! $gatewayManager->hasAny()) {
            $this->error('No gateways configured. Add one with: orbit gateway:add');

            return self::FAILURE;
        }

        $active = $adapter->detectActive();

        if ($active === null) {
            $this->error('No active WireGuard connection found.');

            return self::FAILURE;
        }

        $gatewayData = $active['gateway'];
        $gateway = Gateway::find($gatewayData['id']);

        if ($gateway === null) {
            $this->error('Gateway not found in database.');

            return self::FAILURE;
        }

        $this->info('Configure Cloudflare');
        $this->line("  Gateway: {$gateway->name}");
        $this->newLine();

        $token = $this->secret('Cloudflare API token');

        if (! $token) {
            $this->error('API token is required.');

            return self::FAILURE;
        }

        $this->line('Validating token via gateway...');

        $zonesOutput = $this->runOnGateway($gateway, "cloudflare:zones {$token} --json");

        if ($zonesOutput === null) {
            $this->error('Failed to validate token via gateway.');

            return self::FAILURE;
        }

        $decoded = json_decode($zonesOutput, true);

        if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
            $error = $decoded['error'] ?? 'Unknown error';
            $this->error("Could not retrieve zones: {$error}");

            return self::FAILURE;
        }

        // Store token on the gateway
        $stored = $this->runOnGateway($gateway, "cloudflare:store {$token}");

        if ($stored === null) {
            $this->error('Failed to store token on gateway.');

            return self::FAILURE;
        }

        $zones = $decoded['data']['zones'] ?? [];
        $zoneNames = array_map(fn (array $z) => $z['name'], $zones);

        $this->newLine();
        $this->info('Cloudflare API token stored.');
        $this->line('Available zones: '.implode(', ', $zoneNames));

        return self::SUCCESS;
    }

    /**
     * Run an orbit command on the gateway via SSH.
     *
     * Unlike GatewayCliAdapter::sshCommand(), this allows any argument characters
     * and returns output even on non-zero exit codes (for JSON error responses).
     */
    private function runOnGateway(Gateway $gateway, string $orbitCommand): ?string
    {
        $user = $gateway->ssh_user ?: 'orbit';
        $ip = $gateway->ip_address;

        $result = Process::timeout(20)->run(sprintf(
            'ssh -o ConnectTimeout=10 -o BatchMode=yes %s@%s %s',
            escapeshellarg((string) $user),
            escapeshellarg((string) $ip),
            escapeshellarg("export PATH=/home/linuxbrew/.linuxbrew/bin:\$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin:\$PATH && orbit {$orbitCommand}"),
        ));

        $output = trim($result->output());

        // Return output if we got any (even on non-zero exit), null only on connection failure
        return $output !== '' ? $output : null;
    }
}
