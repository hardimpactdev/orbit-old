<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

/**
 * Health check for gateway installations.
 * Simplified version that doesn't check for local environment or PHP-FPM.
 */
final readonly class GatewayHealthCheck
{
    public function __construct(
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->info('Running post-installation health checks...');

        // Check Docker is running
        if (! $this->checkDocker($logger)) {
            return StepResult::failed('Docker is not running');
        }

        // Check DNS service (port 53)
        if (! $this->checkDnsService($logger)) {
            return StepResult::failed('DNS service not available on port 53');
        }

        // Check WG Easy is running
        if (! $this->checkWgEasy($logger)) {
            return StepResult::failed('WG Easy VPN is not running');
        }

        $logger->success('All health checks passed');

        return StepResult::success();
    }

    private function checkDocker(InstallLogger $logger): bool
    {
        $result = Process::run('docker info > /dev/null 2>&1');

        if ($result->successful()) {
            $logger->success('Docker is running');

            return true;
        }

        $logger->error('Docker is not running');

        return false;
    }

    private function checkDnsService(InstallLogger $logger): bool
    {
        // Check if something is listening on port 53
        $result = Process::run('ss -tuln | grep -q :53');

        if ($result->successful()) {
            $logger->success('DNS service is running on port 53');

            return true;
        }

        $logger->error('No DNS service found on port 53');

        return false;
    }

    private function checkWgEasy(InstallLogger $logger): bool
    {
        $containerName = 'wg-easy';

        if ($this->dockerManager->isRunning($containerName)) {
            $logger->success('WG Easy VPN is running');

            return true;
        }

        $logger->error('WG Easy VPN is not running');

        return false;
    }
}
