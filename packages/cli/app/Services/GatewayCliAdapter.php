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
     * @return array{gateway: array{id: int, name: string, ip: string, subnet: string, wg_password: string|null, wg_api_port: int, vpn_gateway_ip: string}, vpn_ip: string}|null
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
