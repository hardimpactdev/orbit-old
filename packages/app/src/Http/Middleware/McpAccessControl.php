<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpAccessControl
{
    /**
     * Allowed IP ranges for MCP access.
     *
     * @var array<string>
     */
    private array $allowedSubnets = [
        '127.0.0.1/32',    // Localhost IPv4
        '::1/128',         // Localhost IPv6
        '10.6.0.0/24',     // Gateway VPN network
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip();

        // Check if IP is in allowed subnets
        if ($this->isAllowedIp($clientIp)) {
            return $next($request);
        }

        // Log the blocked attempt
        logger()->warning('MCP access denied', [
            'ip' => $clientIp,
            'path' => $request->path(),
            'user_agent' => $request->userAgent(),
        ]);

        abort(403, 'MCP access restricted to authorized networks');
    }

    /**
     * Check if IP is in any allowed subnet.
     */
    private function isAllowedIp(string $ip): bool
    {
        foreach ($this->allowedSubnets as $subnet) {
            if ($this->ipInSubnet($ip, $subnet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP address is within a CIDR subnet.
     */
    private function ipInSubnet(string $ip, string $subnet): bool
    {
        [$subnetIp, $mask] = str_contains($subnet, '/')
            ? explode('/', $subnet)
            : [$subnet, '32'];

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnetIp);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Both must be the same address family (IPv4 = 4 bytes, IPv6 = 16 bytes)
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maskInt = (int) $mask;
        $byteLen = strlen($ipBin);
        $fullBytes = intdiv($maskInt, 8);
        $remainingBits = $maskInt % 8;

        // Compare full bytes
        for ($i = 0; $i < $fullBytes && $i < $byteLen; $i++) {
            if ($ipBin[$i] !== $subnetBin[$i]) {
                return false;
            }
        }

        // Compare remaining bits
        if ($remainingBits > 0 && $fullBytes < $byteLen) {
            $bitMask = 0xFF << (8 - $remainingBits) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $bitMask) !== (ord($subnetBin[$fullBytes]) & $bitMask)) {
                return false;
            }
        }

        return true;
    }
}
