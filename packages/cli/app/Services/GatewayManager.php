<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Gateway;
use Illuminate\Support\Facades\Process;

final class GatewayManager
{
    /**
     * @return array<int, array{id: int, name: string, ip: string, subnet: string, wg_password: string|null, wg_api_port: int}>
     */
    public function all(): array
    {
        return Gateway::all()->map(fn (Gateway $g) => $this->toArray($g))->values()->all();
    }

    public function hasAny(): bool
    {
        return Gateway::exists();
    }

    /**
     * @return array{id: int, name: string, ip: string, subnet: string, wg_password: string|null, wg_api_port: int}|null
     */
    public function get(int|string $id): ?array
    {
        $gateway = Gateway::find($id);

        return $gateway ? $this->toArray($gateway) : null;
    }

    /**
     * @return array{id: int, name: string, ip: string, subnet: string, wg_password: string|null, wg_api_port: int}
     */
    public function add(string $name, string $ip, string $subnet): array
    {
        $gateway = Gateway::create([
            'name' => $name,
            'ip_address' => $ip,
            'subnet' => $subnet,
        ]);

        return $this->toArray($gateway);
    }

    public function remove(int|string $id): bool
    {
        return Gateway::destroy($id) > 0;
    }

    /**
     * @return array<int|string, string>
     */
    public function getOptions(): array
    {
        return Gateway::all()->mapWithKeys(
            fn (Gateway $g) => [$g->id => "{$g->name} ({$g->ip_address})"]
        )->all();
    }

    public function getPassword(int|string $id): ?string
    {
        return Gateway::find($id)?->wg_password;
    }

    public function setPassword(int|string $id, string $password): void
    {
        Gateway::where('id', $id)->update(['wg_password' => $password]);
    }

    public function getApiPort(int|string $id): int
    {
        return Gateway::find($id)->wg_api_port ?? 51821;
    }

    /**
     * @return array{id: int, name: string, ip: string, subnet: string, wg_password: string|null, wg_api_port: int}|null
     */
    public function findBySubnet(string $ip): ?array
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return null;
        }

        foreach ($this->all() as $gateway) {
            $subnet = $gateway['subnet'];
            $parts = explode('/', $subnet);
            if (count($parts) !== 2) {
                continue;
            }

            $networkLong = ip2long($parts[0]);
            if ($networkLong === false) {
                continue;
            }

            $prefix = (int) $parts[1];
            $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));

            if (($ipLong & $mask) === ($networkLong & $mask)) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Detect the active gateway by checking network interfaces against configured subnets.
     *
     * @return array{gateway: array{id: int, name: string, ip: string, subnet: string, wg_password: string|null, wg_api_port: int}, vpn_ip: string}|null
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

            $gateway = $this->findBySubnet($ip);
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
                escapeshellarg($user),
                escapeshellarg($ip),
                escapeshellarg("export PATH=/home/linuxbrew/.linuxbrew/bin:\$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin:\$PATH && orbit {$command}"),
            )
        );

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output());
    }

    public function findByIp(string $ip): ?Gateway
    {
        return Gateway::where('ip_address', $ip)->first();
    }

    public function registerVpnClient(
        int|string $gatewayId,
        string $clientName,
    ): ?string {
        $gateway = $this->get($gatewayId);
        if ($gateway === null) {
            return null;
        }

        $password = $this->getPassword($gatewayId);
        if ($password === null) {
            throw new \RuntimeException("Gateway {$gatewayId} has no WG Easy password configured");
        }

        $wgService = WgEasyService::forGateway(
            $gateway['vpn_gateway_ip'],
            $gateway['wg_api_port'],
            $password
        );

        $client = $wgService->createClient($clientName);

        return $client['ip'] ?? null;
    }

    /**
     * @return array{id: int, name: string, ip: string, subnet: string, wg_password: string|null, wg_api_port: int, vpn_gateway_ip: string}
     */
    private function toArray(Gateway $gateway): array
    {
        return [
            'id' => $gateway->id,
            'name' => $gateway->name,
            'ip' => $gateway->ip_address,
            'subnet' => $gateway->subnet,
            'wg_password' => $gateway->wg_password,
            'wg_api_port' => $gateway->wg_api_port,
            'vpn_gateway_ip' => $gateway->getVpnGatewayIp(),
        ];
    }
}
