<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Mac;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PrepareDocker
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check if Docker/OrbStack is installed
        $dockerCheck = Process::run('which docker');
        if ($dockerCheck->failed()) {
            return StepResult::failed('Docker is not installed. Please install OrbStack or Docker Desktop.');
        }
        $logger->success('Docker CLI available');

        // Check if Docker daemon is accessible
        $daemonCheck = Process::run('docker info > /dev/null 2>&1');
        if ($daemonCheck->failed()) {
            return StepResult::failed('Docker daemon is not running. Please start OrbStack or Docker Desktop.');
        }
        $logger->success('Docker daemon accessible');

        // Check required ports
        $requiredPorts = [5432, 6379, 8025];
        foreach ($requiredPorts as $port) {
            $portCheck = Process::run("lsof -i :{$port} 2> /dev/null");
            if ($portCheck->successful()) {
                return StepResult::failed("Port {$port} is already in use. Please free this port before installing.");
            }
        }
        $logger->success('Ports '.implode(', ', $requiredPorts).' available');

        // Check Docker socket
        $socketCheck = Process::run('test -S /var/run/docker.sock || test -S ~/.orbstack/run/docker.sock');
        if ($socketCheck->failed()) {
            return StepResult::failed('Docker socket not found. Please ensure Docker is properly installed.');
        }
        $logger->success('Docker socket accessible');

        return StepResult::success();
    }
}
