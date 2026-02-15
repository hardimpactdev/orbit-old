<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\RunsOnGateway;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Models\Gateway;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareConfigureCommand extends Command
{
    use RunsOnGateway;

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

        $zonesOutput = $this->runOnGateway($gateway, 'cloudflare:zones --json', $token);

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
        $stored = $this->runOnGateway($gateway, 'cloudflare:store', $token);

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
}
