<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Service for managing Gateway DNS mappings.
 *
 * Handles custom TLD routing to VPN client IPs via dnsmasq.
 */
final class GatewayDnsService
{
    public function __construct(
        private ConfigManager $configManager,
    ) {}

    /**
     * Add a TLD mapping to route to a VPN client IP.
     */
    public function addTldMapping(string $tld, string $ip): void
    {
        $configPath = $this->configManager->getConfigPath();
        $dnsPath = $configPath.'/dns';
        $mappingsFile = $dnsPath.'/gateway-mappings.conf';

        // Ensure directory exists
        if (! is_dir($dnsPath)) {
            mkdir($dnsPath, 0755, true);
        }

        // Read existing content
        $content = '';
        if (file_exists($mappingsFile)) {
            $content = file_get_contents($mappingsFile);
        }

        // Remove existing entry for this TLD if present
        $lines = explode("\n", $content);
        $filteredLines = array_filter($lines, function ($line) use ($tld) {
            return ! str_contains($line, "address=/.{$tld}/");
        });

        // Add new entry
        $filteredLines[] = "# {$tld} -> {$ip}";
        $filteredLines[] = "address=/.{$tld}/{$ip}";
        $filteredLines[] = ''; // Empty line for readability

        // Write back
        file_put_contents($mappingsFile, implode("\n", $filteredLines));

        // Restart DNS service to apply changes
        $this->restartDnsService();
    }

    /**
     * Remove a TLD mapping.
     */
    public function removeTldMapping(string $tld): void
    {
        $configPath = $this->configManager->getConfigPath();
        $mappingsFile = $configPath.'/dns/gateway-mappings.conf';

        if (! file_exists($mappingsFile)) {
            return;
        }

        $content = file_get_contents($mappingsFile);
        $lines = explode("\n", $content);

        // Remove lines related to this TLD
        $filteredLines = array_filter($lines, function ($line) use ($tld) {
            return ! str_contains($line, $tld);
        });

        file_put_contents($mappingsFile, implode("\n", $filteredLines));

        $this->restartDnsService();
    }

    /**
     * Get all TLD mappings.
     *
     * @return array<int, array{tld: string, ip: string}>
     */
    public function getMappings(): array
    {
        $configPath = $this->configManager->getConfigPath();
        $mappingsFile = $configPath.'/dns/gateway-mappings.conf';

        if (! file_exists($mappingsFile)) {
            return [];
        }

        $content = file_get_contents($mappingsFile);
        $lines = explode("\n", $content);

        $mappings = [];
        foreach ($lines as $line) {
            // Parse address=/tld/ip format
            if (preg_match('/^address=\/\.(\w+)\/([\d.]+)$/', $line, $matches)) {
                $mappings[] = [
                    'tld' => $matches[1],
                    'ip' => $matches[2],
                ];
            }
        }

        return $mappings;
    }

    /**
     * Restart the DNS service to apply changes.
     */
    private function restartDnsService(): void
    {
        // Try to restart via docker
        $result = Process::run('docker restart orbit-dns 2>/dev/null');

        if (! $result->successful()) {
            // Fallback: try to signal dnsmasq if running locally
            Process::run('killall -HUP dnsmasq 2>/dev/null');
        }
    }
}
