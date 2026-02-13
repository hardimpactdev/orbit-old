<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class InstallHorizon
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->step('Installing Horizon systemd service...');

        $user = posix_getpwuid(posix_getuid())['name'] ?? 'orbit';
        $configDir = $context->configDir;

        $serviceContent = <<<SERVICE
[Unit]
Description=Orbit Horizon Queue Worker
After=network.target

[Service]
Type=simple
User={$user}
WorkingDirectory={$configDir}
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
SERVICE;

        file_put_contents('/tmp/orbit-horizon.service', $serviceContent);

        $commands = [
            'sudo mv /tmp/orbit-horizon.service /etc/systemd/system/orbit-horizon.service',
            'sudo systemctl daemon-reload',
            'sudo systemctl enable orbit-horizon',
            'sudo systemctl start orbit-horizon',
        ];

        foreach ($commands as $command) {
            $result = Process::run($command);
            if (! $result->successful()) {
                return StepResult::failed("Failed: {$command} - ".$result->errorOutput());
            }
        }

        $logger->success('Horizon systemd service installed and started');

        return StepResult::success();
    }
}
