<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\File;

/**
 * Configure DNS for gateway - enables routing custom TLDs to VPN clients.
 *
 * Sets up dnsmasq to resolve custom TLDs (like .testa) to specific VPN client IPs.
 */
final readonly class ConfigureGatewayDns
{
    public function __construct(
        private ConfigManager $configManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->step('Configuring gateway DNS');

        $configPath = $this->configManager->getConfigPath();
        $dnsConfigPath = $configPath.'/dns';

        // Check if already configured
        $mappingsFile = $dnsConfigPath.'/gateway-mappings.conf';
        if (file_exists($mappingsFile)) {
            $logger->skip('Gateway DNS already configured');
            $logger->info('DNS mappings directory: '.$dnsConfigPath);

            return StepResult::success();
        }

        // Create DNS configuration directory
        if (! is_dir($dnsConfigPath)) {
            mkdir($dnsConfigPath, 0755, true);
        }

        // Create gateway DNS mappings file
        $mappingsFile = $dnsConfigPath.'/gateway-mappings.conf';
        if (! file_exists($mappingsFile)) {
            file_put_contents($mappingsFile, $this->getDefaultMappings());
        }

        // Update dnsmasq config to include gateway mappings
        $dnsmasqConf = $configPath.'/dnsmasq.conf';
        if (file_exists($dnsmasqConf)) {
            $content = file_get_contents($dnsmasqConf);

            // Add conf-dir for gateway mappings if not present
            if (! str_contains($content, 'conf-dir=')) {
                $content .= "\n# Gateway DNS mappings\n";
                $content .= "conf-dir={$dnsConfigPath}/,*.conf\n";
                file_put_contents($dnsmasqConf, $content);
            }
        }

        // Create example client mappings
        $exampleFile = $dnsConfigPath.'/example-client-mappings.conf';
        if (! file_exists($exampleFile)) {
            file_put_contents($exampleFile, $this->getExampleClientMappings());
        }

        $logger->success('Gateway DNS configured');
        $logger->info('DNS mappings directory: '.$dnsConfigPath);
        $logger->newLine();
        $logger->info('To route custom TLDs to VPN clients:');
        $logger->info('1. Edit: '.$dnsConfigPath.'/gateway-mappings.conf');
        $logger->info('2. Add entries like: address=/client1.testa/10.8.0.2');
        $logger->info('3. Restart DNS: orbit service:restart dns');
        $logger->newLine();
        $logger->info('See example: '.$exampleFile);

        return StepResult::success();
    }

    /**
     * Get default gateway DNS mappings content.
     */
    private function getDefaultMappings(): string
    {
        return <<<'CONF'
# Gateway DNS mappings
# These mappings route custom TLDs to VPN client IPs
#
# Format: address=/hostname.tld/vpn-client-ip
#
# Example:
# address=/laptop.testa/10.8.0.2
# address=/server.testa/10.8.0.3

# VPN Gateway itself
address=/gateway.testa/10.8.0.1

CONF;
    }

    /**
     * Get example client mappings content.
     */
    private function getExampleClientMappings(): string
    {
        return <<<'CONF'
# Example VPN Client DNS Mappings
#
# After connecting a client to the VPN, you can assign it a custom hostname.
# The client's VPN IP can be found in the WG Easy web UI.
#
# Example mappings:
#
# address=/my-laptop.testa/10.8.0.2
# address=/my-server.testa/10.8.0.3
# address=/mac-studio.testa/10.8.0.4
#
# You can then access these machines via:
# - https://my-laptop.testa
# - https://my-server.testa
# - https://mac-studio.testa
#
# Note: Make sure your clients' DNS is configured to use this gateway's DNS server.

CONF;
    }
}
