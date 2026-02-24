<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Gateway;

use HardImpact\Orbit\Core\Models\Gateway;
use Illuminate\Database\Eloquent\Collection;

final class GatewayManager
{
    /**
     * @return Collection<int, Gateway>
     */
    public function all(): Collection
    {
        return Gateway::all();
    }

    public function hasAny(): bool
    {
        return Gateway::exists();
    }

    public function get(int|string $id): ?Gateway
    {
        return Gateway::find($id);
    }

    public function add(string $name, string $ip, string $subnet): Gateway
    {
        return Gateway::create([
            'name' => $name,
            'ip_address' => $ip,
            'subnet' => $subnet,
        ]);
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

    public function findBySubnet(string $ip): ?Gateway
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return null;
        }

        foreach ($this->all() as $gateway) {
            $subnet = $gateway->subnet;
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

        if ($gateway->wg_password === null) {
            throw new \RuntimeException("Gateway {$gatewayId} has no WG Easy password configured");
        }

        $wgService = WgEasyService::forGateway(
            $gateway->getVpnGatewayIp(),
            $gateway->wg_api_port,
            $gateway->wg_password
        );

        $client = $wgService->createClient($clientName);

        return $client['ip'] ?? null;
    }
}
