<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\GatewayManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Enums\NodeType;

final readonly class RegisterWithGatewayVpn
{
    public function __construct(
        private GatewayManager $gatewayManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->nodeType !== NodeType::Client) {
            $logger->skip('Not a client node, skipping VPN registration');

            return StepResult::success();
        }

        if ($context->gatewayId === null) {
            $logger->skip('No gateway configured, skipping VPN registration');

            return StepResult::success();
        }

        $logger->info('Registering client with gateway VPN...');

        try {
            $gateway = $this->gatewayManager->get($context->gatewayId);
            if ($gateway === null) {
                $logger->warn("Gateway {$context->gatewayId} not found, skipping VPN registration");

                return StepResult::success();
            }

            $clientName = $context->nodeName ?? gethostname();

            $vpnIp = $this->gatewayManager->registerVpnClient(
                $gateway['id'],
                $clientName,
            );

            if ($vpnIp === null) {
                $logger->warn('VPN registration failed but provisioning continues');

                return StepResult::success();
            }

            $logger->success("VPN client created with IP: {$vpnIp}");

            $context->metadata['vpn_ip'] = $vpnIp;
            $context->metadata['vpn_registered_at'] = now()->toIso8601String();

            return StepResult::success();
        } catch (\Exception $e) {
            $logger->warn("VPN registration failed: {$e->getMessage()}");

            return StepResult::success();
        }
    }
}
