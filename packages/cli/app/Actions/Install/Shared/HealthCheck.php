<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\DockerManager;
use App\Services\Install\InstallLogger;
use App\Services\PhpManager;
use App\Services\ServiceManager;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class HealthCheck
{
    public function __construct(
        private ServiceManager $serviceManager,
        private PhpManager $phpManager,
        private DockerManager $dockerManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->info('Running post-installation health checks...');

        // Check database tables exist
        if (! $this->checkDatabaseTables($logger)) {
            return StepResult::failed('Database tables not found');
        }

        // Check node record exists
        if (! $this->checkNode($logger)) {
            return StepResult::failed('Node record not found');
        }

        // Check PHP-FPM services
        if (! $this->checkPhpFpmServices($logger)) {
            return StepResult::failed('PHP-FPM services not running');
        }

        // Check Docker services
        if (! $this->checkDockerServices($logger)) {
            return StepResult::failed('Required Docker services not running');
        }

        $logger->success('All health checks passed');

        return StepResult::success();
    }

    private function checkDatabaseTables(InstallLogger $logger): bool
    {
        try {
            $nodesCount = DB::table('nodes')->count();
            $logger->info("Found {$nodesCount} node(s) in database");

            // Check if projects table exists
            $projectsCount = DB::table('projects')->count();
            $logger->info("Found {$projectsCount} project(s) in database");

            return true;
        } catch (\Exception $e) {
            $logger->error("Database check failed: {$e->getMessage()}");

            return false;
        }
    }

    private function checkNode(InstallLogger $logger): bool
    {
        try {
            $node = Node::getSelf();

            if (! $node) {
                $logger->error('Node record not found in database');

                return false;
            }

            $logger->info("Node found: {$node->getAttribute('name')}");

            return true;
        } catch (\Exception $e) {
            $logger->error("Node check failed: {$e->getMessage()}");

            return false;
        }
    }

    private function checkPhpFpmServices(InstallLogger $logger): bool
    {
        $installedVersions = $this->phpManager->getInstalledVersions();

        if (empty($installedVersions)) {
            $logger->error('No PHP versions installed');

            return false;
        }

        $allRunning = true;

        foreach ($installedVersions as $version) {
            if ($this->phpManager->isRunning($version)) {
                $logger->info("PHP-FPM {$version} is running");
            } else {
                $logger->error("PHP-FPM {$version} is not running");
                $allRunning = false;
            }
        }

        return $allRunning;
    }

    private function checkDockerServices(InstallLogger $logger): bool
    {
        $enabledServices = $this->serviceManager->getEnabled();

        // Only check services that are actually enabled
        $allRunning = true;

        foreach ($enabledServices as $serviceName => $config) {
            // Skip non-Docker services (like DNS which is handled differently)
            if ($serviceName === 'dns') {
                continue;
            }

            $containerName = "orbit-{$serviceName}";

            if ($this->dockerManager->isRunning($containerName)) {
                $health = $this->dockerManager->getHealthStatus($containerName);
                if ($health === 'healthy' || $health === null) {
                    $logger->info("Docker service {$serviceName} is running".($health ? " ({$health})" : ''));
                } elseif ($health === 'starting') {
                    $logger->info("Docker service {$serviceName} is starting");
                } else {
                    $logger->warn("Docker service {$serviceName} is running but {$health}");
                }
            } else {
                $logger->error("Docker service {$serviceName} is not running");
                $allRunning = false;
            }
        }

        return $allRunning;
    }
}
