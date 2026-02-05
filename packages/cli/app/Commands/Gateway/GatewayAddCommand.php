<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Services\GatewayManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

/**
 * Add a new gateway server configuration.
 */
final class GatewayAddCommand extends Command
{
    protected $signature = 'gateway:add
                            {name? : Gateway name}
                            {ip? : External IP address}
                            {subnet? : VPN subnet (e.g., 10.8.0.0/24)}';

    protected $description = 'Add a new gateway server configuration';

    public function handle(GatewayManager $gatewayManager): int
    {
        $this->info('Add Gateway Server');
        $this->newLine();

        // Get name
        $name = $this->argument('name');
        if ($name === null) {
            $name = text(
                label: 'Gateway name',
                placeholder: 'e.g., production-gateway, home-vpn',
                required: true,
            );
        }

        // Get IP
        $ip = $this->argument('ip');
        if ($ip === null) {
            $ip = text(
                label: 'External IP address',
                placeholder: 'e.g., 203.0.113.10',
                required: true,
                validate: fn ($value) => $this->validateIp($value),
            );
        }

        // Get subnet
        $subnet = $this->argument('subnet');
        if ($subnet === null) {
            $subnet = text(
                label: 'VPN subnet',
                placeholder: 'e.g., 10.8.0.0/24',
                default: '10.8.0.0/24',
                required: true,
                validate: fn ($value) => $this->validateSubnet($value),
            );
        }

        $baseId = $gatewayManager->generateId($name);
        $id = $baseId;
        $counter = 1;
        while ($gatewayManager->idExists($id)) {
            $id = $baseId.'-'.$counter;
            $counter++;
        }

        // If ID was modified, append suffix to name temporarily for storage
        $originalName = $name;
        if ($id !== $baseId) {
            $name = $name.' ('.$counter.')';
        }

        $gateway = $gatewayManager->add($name, $ip, $subnet);

        $this->newLine();
        $this->info('Gateway added successfully!');
        $this->newLine();
        $this->line("  Name:   {$originalName}");
        $this->line("  ID:     {$gateway['id']}");
        $this->line("  IP:     {$gateway['ip']}");
        $this->line("  Subnet: {$gateway['subnet']}");
        $this->newLine();
        $this->line('To set up this gateway server, run:');
        $this->line("  <fg=cyan>orbit setup:gateway {$gateway['ip']}</>");

        return self::SUCCESS;
    }

    /**
     * Validate IP address (must be a public/routable IP).
     */
    private function validateIp(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return 'Invalid IP address format';
            }

            return 'Gateway IP must be a public, routable address';
        }

        return null;
    }

    /**
     * Validate subnet format (CIDR notation).
     */
    private function validateSubnet(string $subnet): ?string
    {
        $parts = explode('/', $subnet);
        if (count($parts) !== 2) {
            return 'Invalid subnet format (expected: x.x.x.x/x)';
        }

        if (! filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'Invalid IP address in subnet';
        }

        $prefix = (int) $parts[1];
        if ($prefix < 0 || $prefix > 32 || $parts[1] !== (string) $prefix) {
            return 'Invalid prefix length (expected: 0-32)';
        }

        return null;
    }
}
