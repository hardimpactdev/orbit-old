<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Gateway;

/**
 * Service for managing Gateway DNS mappings.
 *
 * Handles custom TLD routing to VPN client IPs via dnsmasq.
 * Does NOT restart DNS — callers must handle restart when methods return true.
 */
final class GatewayDnsService
{
    public function __construct(
        private string $configPath,
    ) {}

    /**
     * Add a TLD mapping to route to a VPN client IP.
     *
     * @return bool True if DNS restart is needed
     */
    public function addTldMapping(string $tld, string $ip): bool
    {
        $dnsPath = $this->configPath.'/dns';
        $mappingsFile = $dnsPath.'/gateway-mappings.conf';

        if (! is_dir($dnsPath)) {
            mkdir($dnsPath, 0755, true);
        }

        $content = '';
        if (file_exists($mappingsFile)) {
            $content = file_get_contents($mappingsFile);
        }

        $lines = explode("\n", $content);
        $filteredLines = array_filter($lines, function ($line) use ($tld) {
            return ! str_contains($line, "address=/.{$tld}/");
        });

        $filteredLines[] = "# {$tld} -> {$ip}";
        $filteredLines[] = "address=/.{$tld}/{$ip}";
        $filteredLines[] = '';

        file_put_contents($mappingsFile, implode("\n", $filteredLines));

        return true;
    }

    /**
     * Remove a TLD mapping.
     *
     * @return bool True if DNS restart is needed
     */
    public function removeTldMapping(string $tld): bool
    {
        $mappingsFile = $this->configPath.'/dns/gateway-mappings.conf';

        if (! file_exists($mappingsFile)) {
            return false;
        }

        $content = file_get_contents($mappingsFile);
        $lines = explode("\n", $content);

        $filteredLines = array_filter($lines, function ($line) use ($tld) {
            return ! str_contains($line, $tld);
        });

        file_put_contents($mappingsFile, implode("\n", $filteredLines));

        return true;
    }

    /**
     * Get all TLD mappings.
     *
     * @return array<int, array{tld: string, ip: string}>
     */
    public function getMappings(): array
    {
        $mappingsFile = $this->configPath.'/dns/gateway-mappings.conf';

        if (! file_exists($mappingsFile)) {
            return [];
        }

        $content = file_get_contents($mappingsFile);
        $lines = explode("\n", $content);

        $mappings = [];
        foreach ($lines as $line) {
            if (preg_match('/^address=\/\.(\w+)\/([\d.]+)$/', $line, $matches)) {
                $mappings[] = [
                    'tld' => $matches[1],
                    'ip' => $matches[2],
                ];
            }
        }

        return $mappings;
    }
}
