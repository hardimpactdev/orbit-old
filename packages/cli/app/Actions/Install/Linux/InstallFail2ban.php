<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class InstallFail2ban
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->step('Installing fail2ban...');

        $result = Process::timeout(120)->run('sudo apt-get install -y fail2ban 2>&1');
        if (! $result->successful()) {
            $logger->warn('Failed to install fail2ban: '.trim($result->errorOutput() ?: $result->output()));

            return StepResult::success(); // Non-fatal
        }

        // Write jail configuration
        $jailConfig = <<<'CONF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log

[caddy-auth]
enabled = true
port = http,https
filter = caddy-auth
logpath = /var/log/caddy/*.log
maxretry = 10
findtime = 300
bantime = 1800
CONF;

        $result = Process::input($jailConfig)
            ->run('sudo tee /etc/fail2ban/jail.local');
        if (! $result->successful()) {
            $logger->warn('Failed to write fail2ban jail config');

            return StepResult::success(); // Non-fatal
        }

        // Write Caddy auth filter
        $filterConfig = <<<'CONF'
[Definition]
failregex = ^.*"remote_ip":"<HOST>".*"status":(401|403).*$
ignoreregex =
CONF;

        $result = Process::input($filterConfig)
            ->run('sudo tee /etc/fail2ban/filter.d/caddy-auth.conf');
        if (! $result->successful()) {
            $logger->warn('Failed to write caddy-auth filter');

            return StepResult::success(); // Non-fatal
        }

        // Enable and restart fail2ban
        Process::run('sudo systemctl enable fail2ban 2>&1');
        $result = Process::run('sudo systemctl restart fail2ban 2>&1');
        if (! $result->successful()) {
            $logger->warn('Failed to start fail2ban service');

            return StepResult::success(); // Non-fatal
        }

        $logger->success('fail2ban installed and configured');

        return StepResult::success();
    }
}
