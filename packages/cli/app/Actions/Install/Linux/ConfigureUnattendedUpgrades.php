<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class ConfigureUnattendedUpgrades
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->step('Installing unattended-upgrades...');

        $result = Process::timeout(120)->run('sudo apt-get install -y unattended-upgrades 2>&1');
        if (! $result->successful()) {
            $logger->warn('Failed to install unattended-upgrades: '.trim($result->errorOutput() ?: $result->output()));

            return StepResult::success(); // Non-fatal
        }

        // Write unattended-upgrades configuration (security-only)
        $config = <<<'CONF'
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
    "${distro_id}ESMApps:${distro_codename}-apps-security";
    "${distro_id}ESM:${distro_codename}-infra-security";
};

Unattended-Upgrade::AutoFixInterruptedDpkg "true";
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
CONF;

        $result = Process::input($config)
            ->run('sudo tee /etc/apt/apt.conf.d/50unattended-upgrades');
        if (! $result->successful()) {
            $logger->warn('Failed to write unattended-upgrades config');

            return StepResult::success(); // Non-fatal
        }

        // Enable the auto-upgrade timer
        Process::run('sudo systemctl enable unattended-upgrades 2>&1');

        $logger->success('Unattended security updates configured');

        return StepResult::success();
    }
}
