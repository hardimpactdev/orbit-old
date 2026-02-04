<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PrepareCaddy
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check ports 80 and 443
        $webPorts = [80, 443];
        foreach ($webPorts as $port) {
            $portCheck = Process::run("ss -tuln | grep -q :{$port}");
            if ($portCheck->successful()) {
                $processCheck = Process::run("ss -tulnp | grep :{$port}");
                $output = $processCheck->output();
                if (str_contains($output, 'nginx') || str_contains($output, 'apache') || str_contains($output, 'httpd')) {
                    return StepResult::failed("Port {$port} is in use by another web server (nginx/apache). Please stop it before installing.");
                }

                return StepResult::failed("Port {$port} is already in use. Please free this port before installing.");
            }
        }
        $logger->success('Ports 80 and 443 available');

        // Check for competing web servers
        $servers = ['nginx', 'apache2', 'httpd'];
        foreach ($servers as $server) {
            $check = Process::run("pgrep {$server} 2> /dev/null");
            if ($check->successful()) {
                return StepResult::failed("{$server} appears to be running. Please stop it before installing Caddy.");
            }
        }
        $logger->success('No competing web servers detected');

        // Check Homebrew availability (Caddy will be installed via Homebrew)
        $brewCheck = Process::run('which brew');
        if ($brewCheck->failed()) {
            return StepResult::failed('Homebrew is not installed. It will be installed during the installation.');
        }
        $logger->success('Homebrew available for Caddy installation');

        return StepResult::success();
    }
}
