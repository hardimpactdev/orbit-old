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
        '127.0.0.1/32',    // Localhost (development)
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

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnetIp);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
