<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use App\Services\ServiceManager;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PullServiceImages
{
    public function __construct(
        private ServiceManager $serviceManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Get only enabled services from configuration
        $enabledServices = $this->serviceManager->getEnabled();

        if ($enabledServices === []) {
            $logger->skip('No services enabled, skipping image pull');

            return StepResult::success();
        }

        $composePath = $context->configDir.'/docker-compose.yaml';

        foreach (array_keys($enabledServices) as $service) {
            $logger->step("Pulling {$service} image...");

            // Pull using the unified compose file
            $result = Process::run("docker compose -f {$composePath} pull {$service}");

            if (! $result->successful()) {
                $error = $result->errorOutput() ?: $result->output();
                $logger->warn("Failed to pull {$service}: {$error}");
                // Non-critical, continue with other services
            }
        }

        $logger->success('Service images pulled');

        return StepResult::success();
    }
}
