<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PrepareDocker
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check if Docker is installed
        $dockerCheck = Process::run('which docker');
        if ($dockerCheck->successful()) {
            $logger->success('Docker already installed');
        } else {
            $logger->info('Docker will be installed during installation');
        }

        // Check if Docker daemon is running (if installed)
        if ($dockerCheck->successful()) {
            $daemonCheck = Process::run('docker info > /dev/null 2>&1');
            if ($daemonCheck->successful()) {
                $logger->success('Docker daemon running');
            } else {
                $logger->warn('Docker daemon not running (will be started during installation)');
            }
        }

        return StepResult::success();
    }
}
