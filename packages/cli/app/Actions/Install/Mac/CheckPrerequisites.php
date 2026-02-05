<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class CheckPrerequisites
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Detect system
        $os = $this->detectSystem();
        $logger->success("System requirements met ({$os})");

        // Check for conflicting software
        if (! $context->nonInteractive) {
            $this->checkConflictingSoftware($logger);
        }

        return StepResult::success();
    }

    /**
     * Check for software that conflicts with Orbit.
     */
    private function checkConflictingSoftware(InstallLogger $logger): void
    {
        // Check for Laravel Herd
        $herdRunning = $this->platformService->isProcessRunning('Herd');
        $herdInstalled = is_dir('/Applications/Herd.app') || is_dir(getenv('HOME').'/Applications/Herd.app');

        if ($herdRunning || $herdInstalled) {
            $logger->newLine();
            $logger->warn('Laravel Herd detected');
            $logger->info('Herd conflicts with Orbit - both manage PHP and DNS');

            if ($herdRunning) {
                $logger->info('Please quit Herd before continuing:');
                $logger->info('  1. Click Herd icon in menu bar');
                $logger->info('  2. Select "Quit"');
                $logger->info('  3. Run orbit install again');
            } else {
                $logger->info('Herd is installed but not running. You can proceed.');
            }
            $logger->newLine();
        }
    }

    private function detectSystem(): string
    {
        $arch = php_uname('m');
        $version = $this->platformService->getCommandOutput('sw_vers -productVersion');

        return "macOS {$version} ({$arch})";
    }
}
