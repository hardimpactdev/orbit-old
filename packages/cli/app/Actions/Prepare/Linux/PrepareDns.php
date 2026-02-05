<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PrepareDns
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check port 53
        $portCheck = Process::run('ss -tuln | grep -q :53');
        if ($portCheck->successful()) {
            // Try to get process info (may require sudo, so fall back to checking systemd-resolved directly)
            $processCheck = Process::run('ss -tulnp | grep :53');
            $output = $processCheck->output();

            // Check if it's our own orbit-dns container
            if (str_contains($output, 'docker-proxy') || str_contains($output, 'containerd')) {
                $dockerCheck = Process::run('docker ps --filter "name=orbit-dns" --filter "status=running" --format "{{.Names}}" 2>/dev/null');
                if ($dockerCheck->successful() && trim($dockerCheck->output()) === 'orbit-dns') {
                    $logger->success('Port 53 in use by orbit-dns (our DNS service)');

                    return StepResult::success();
                }
            }

            // Check for systemd-resolved (direct check since ss may not show process names without sudo)
            $resolvedCheck = Process::run('systemctl is-active systemd-resolved 2>/dev/null');
            if ($resolvedCheck->successful() || str_contains($output, 'systemd-resolve')) {
                $logger->warn('systemd-resolved is using port 53');
                $logger->info('  Will disable systemd-resolved stub to free port 53 for gateway DNS');
            } else {
                return StepResult::failed('Port 53 is in use by another process. DNS resolution may conflict.');
            }
        } else {
            $logger->success('Port 53 available');
        }

        // Check systemd-resolved status
        $resolvedCheck = Process::run('systemctl is-active systemd-resolved 2> /dev/null');
        if ($resolvedCheck->successful()) {
            $logger->info('systemd-resolved is active - will be configured for DNS');
        }

        // Check resolv.conf writability
        $resolvCheck = Process::run('test -w /etc/resolv.conf');
        if ($resolvCheck->failed()) {
            $logger->warn('/etc/resolv.conf is not writable - may require sudo during installation');
        } else {
            $logger->success('/etc/resolv.conf is writable');
        }

        return StepResult::success();
    }
}
