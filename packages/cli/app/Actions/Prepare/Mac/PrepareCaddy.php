<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Mac;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PrepareCaddy
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check if Caddy is already installed
        $caddyInstalled = Process::run('which caddy')->successful();

        // Check ports 80 and 443
        $webPorts = [80, 443];
        $caddyRunning = false;
        foreach ($webPorts as $port) {
            $portCheck = Process::run("lsof -i :{$port} 2> /dev/null");
            if ($portCheck->successful()) {
                $processes = $portCheck->output();

                // If Caddy is using the port, that's fine
                if (str_contains($processes, 'caddy')) {
                    $caddyRunning = true;

                    continue;
                }

                // Other web servers are a problem
                if (str_contains($processes, 'nginx') || str_contains($processes, 'apache') || str_contains($processes, 'httpd')) {
                    return StepResult::failed("Port {$port} is in use by another web server (nginx/apache). Please stop it before installing.");
                }

                return StepResult::failed("Port {$port} is already in use. Please free this port before installing.");
            }
        }

        if ($caddyRunning) {
            $logger->success('Caddy is already running on ports 80 and 443');
        } else {
            $logger->success('Ports 80 and 443 available');
        }

        // Check for competing web servers (only if Caddy isn't already running)
        if (! $caddyRunning) {
            $servers = ['nginx', 'apachectl', 'httpd'];
            foreach ($servers as $server) {
                $check = Process::run("pgrep {$server} 2> /dev/null");
                if ($check->successful()) {
                    return StepResult::failed("{$server} appears to be running. Please stop it before installing Caddy.");
                }
            }
            $logger->success('No competing web servers detected');
        }

        if ($caddyInstalled) {
            $logger->success('Caddy is already installed');
        }

        return StepResult::success();
    }
}
