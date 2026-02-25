<?php

declare(strict_types=1);

namespace App\Services;

use HardImpact\Orbit\Core\Models\Gateway;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use Illuminate\Support\Facades\Process;

/**
 * CLI-specific gateway operations that require Process (SSH, Docker, ifconfig).
 * Wraps the core GatewayManager for pure business logic.
 */
final readonly class GatewayCliAdapter
{
    public function __construct(
        private GatewayManager $gatewayManager,
    ) {}

    /**
     * Detect the active gateway by checking network interfaces against configured subnets.
     *
     * @return array{gateway: \HardImpact\Orbit\Core\Models\Gateway, vpn_ip: string}|null
     */
    public function detectActive(): ?array
    {
        $result = Process::run('ifconfig');

        if (! $result->successful()) {
            return null;
        }

        preg_match_all('/inet\s+(\d+\.\d+\.\d+\.\d+)/', $result->output(), $matches);

        foreach ($matches[1] as $ip) {
            if ($ip === '127.0.0.1') {
                continue;
            }

            $gateway = $this->gatewayManager->findBySubnet($ip);
            if ($gateway !== null) {
                return ['gateway' => $gateway, 'vpn_ip' => $ip];
            }
        }

        return null;
    }

    public function sshCommand(int|string $gatewayId, string $command, int $timeout = 30): ?string
    {
        $gateway = Gateway::find($gatewayId);
        if ($gateway === null) {
            return null;
        }

        $user = $gateway->ssh_user ?: 'orbit';
        $ip = $gateway->ip_address;

        // Validate command contains only safe characters to prevent injection
        if (preg_match('/[^a-zA-Z0-9\s:_\-\.\/=,]/', $command)) {
            return null;
        }

        $result = Process::timeout($timeout)->run(
            sprintf(
                'ssh -o ServerAliveInterval=30 -o ConnectTimeout=10 -o BatchMode=yes %s@%s %s',
                escapeshellarg((string) $user),
                escapeshellarg((string) $ip),
                escapeshellarg("export PATH=/home/linuxbrew/.linuxbrew/bin:\$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin:\$PATH && orbit {$command}"),
            )
        );

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output());
    }

    /**
     * Forward a command to the gateway via SSH and parse the JSON response.
     *
     * @return array{success: bool, data?: array, error?: string, gateway?: string}
     */
    public function forwardJson(string $command, int $timeout = 30): array
    {
        $active = $this->detectActive();

        if ($active === null) {
            return ['success' => false, 'error' => 'No active WireGuard connection found matching a configured gateway subnet.'];
        }

        $gateway = $active['gateway'];
        $output = $this->sshCommand($gateway->id, $command, $timeout);

        if ($output === null) {
            return ['success' => false, 'error' => "Failed to connect to gateway '{$gateway->name}' via SSH."];
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return ['success' => false, 'error' => 'Invalid JSON response from gateway.'];
        }

        if (! ($decoded['success'] ?? false)) {
            return ['success' => false, 'error' => $decoded['error'] ?? 'Unknown error from gateway'];
        }

        return ['success' => true, 'data' => $decoded['data'] ?? [], 'gateway' => $gateway->name];
    }

    public function isWgEasyRunning(): bool
    {
        foreach (['wg-easy', 'orbit-wg-easy'] as $name) {
            $result = Process::run("docker ps --filter \"name=^{$name}\$\" --format \"{{.Names}}\"");
            if ($result->successful() && trim($result->output()) === $name) {
                return true;
            }
        }

        return false;
    }

    public function restartDns(): void
    {
        $result = Process::run('docker restart orbit-dns 2>/dev/null');

        if (! $result->successful()) {
            Process::run('killall -HUP dnsmasq 2>/dev/null');
        }
    }
}
