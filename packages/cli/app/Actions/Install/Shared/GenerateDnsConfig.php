<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;

final readonly class GenerateDnsConfig
{
    public function __construct(
        private ConfigManager $configManager,
        private GatewayManager $gatewayManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Ensure dns_mappings address entry uses the actual TLD from install context
        $this->configManager->updateTldInDnsMappings($context->tld);

        // If connected to a gateway, use it as DNS upstream so all TLD routing
        // is managed centrally by the gateway rather than per-node.
        if ($context->gatewayId !== null) {
            $gateway = $this->gatewayManager->get($context->gatewayId);
            if ($gateway !== null) {
                $gatewayDnsIp = $gateway->getVpnGatewayIp();
                $mappings = $this->configManager->getDnsMappings();

                // Replace any existing server entries with the gateway DNS
                $mappings = array_values(array_filter($mappings, fn ($m) => $m['type'] !== 'server'));
                $mappings[] = ['type' => 'server', 'value' => $gatewayDnsIp];

                $this->configManager->setDnsMappings($mappings);
                $logger->info("Using gateway DNS: {$gatewayDnsIp}");
            }
        }

        $this->configManager->writeDnsmasqConf();

        $logger->success('DNS config generated');

        return StepResult::success();
    }
}
