<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class StartServices
{
    public function __construct(
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Stop and remove containers that are no longer in the compose file
        // This handles the case where services were disabled
        $logger->step('Syncing Docker services...');
        $this->pruneOrphanedContainers($context->configDir);

        $this->dockerManager->startAll();

        $logger->success('Docker services started');

        return StepResult::success();
    }

    /**
     * Stop and remove containers that are no longer defined in docker-compose.yaml.
     */
    private function pruneOrphanedContainers(string $configDir): void
    {
        $composePath = $configDir.'/docker-compose.yaml';

        if (! file_exists($composePath)) {
            return;
        }

        // Get list of services currently defined in compose file
        $result = Process::run("docker compose -f {$composePath} config --services 2>/dev/null");
        if (! $result->successful()) {
            return;
        }

        $definedServices = array_filter(explode("\n", trim($result->output())));

        // Get all running orbit containers
        $runningResult = Process::run("docker ps --filter 'name=orbit-' --format '{{.Names}}' 2>/dev/null");
        if (! $runningResult->successful()) {
            return;
        }

        $runningContainers = array_filter(explode("\n", trim($runningResult->output())));

        // Find and stop containers not in the compose file
        foreach ($runningContainers as $container) {
            // Extract service name from container name (orbit-redis -> redis)
            $serviceName = str_replace('orbit-', '', $container);

            if (! in_array($serviceName, $definedServices, true)) {
                // Container is running but not in compose file - stop and remove it
                Process::run("docker stop {$container} >/dev/null 2>&1");
                Process::run("docker rm {$container} >/dev/null 2>&1");
            }
        }
    }
}
