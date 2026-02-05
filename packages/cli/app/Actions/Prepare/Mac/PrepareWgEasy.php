<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Mac;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

/**
 * Prepare WG Easy on macOS - checks prerequisites.
 */
final readonly class PrepareWgEasy
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check if Docker is available (WG Easy runs in Docker)
        $dockerCheck = Process::run('docker info');
        if (! $dockerCheck->successful()) {
            return StepResult::failed('Docker is required for WG Easy. Please install Docker first.');
        }

        // Check if required ports are available
        $port51820 = Process::run('lsof -i :51820 2>/dev/null');
        if ($port51820->successful() && trim($port51820->output()) !== '') {
            $logger->warn('Port 51820 is already in use');
            $logger->info('WG Easy may conflict with existing WireGuard installation');
        }

        $port51821 = Process::run('lsof -i :51821 2>/dev/null');
        if ($port51821->successful() && trim($port51821->output()) !== '') {
            $logger->warn('Port 51821 is already in use');
            $logger->info('WG Easy web UI may conflict with existing service');
        }

        $logger->success('WG Easy prerequisites verified');

        return StepResult::success();
    }
}
