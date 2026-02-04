<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Mac;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PrepareDns
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check port 53 for dnsmasq
        $portCheck = Process::run('lsof -i :53 2> /dev/null');
        if ($portCheck->successful()) {
            $output = $portCheck->output();
            if (! str_contains($output, 'dnsmasq')) {
                return StepResult::failed('Port 53 is in use by another process. DNS resolution may conflict.');
            }
            $logger->success('Port 53 in use by dnsmasq (expected)');
        } else {
            $logger->success('Port 53 available');
        }

        // Check /etc/resolver/ directory
        $resolverDir = '/etc/resolver';
        if (! is_dir($resolverDir)) {
            $logger->step('/etc/resolver/ directory will be created during installation');
        } else {
            $logger->success('/etc/resolver/ directory exists');

            // Check for conflicting TLD resolver files
            $tld = $context->tld;
            $resolverFile = "{$resolverDir}/{$tld}";
            if (file_exists($resolverFile)) {
                $content = file_get_contents($resolverFile);
                if (! str_contains($content, '127.0.0.1')) {
                    return StepResult::failed("Existing /etc/resolver/{$tld} file points to a different DNS server. Please check your DNS configuration.");
                }
                $logger->success("Resolver file for .{$tld} TLD already configured");
            }
        }

        return StepResult::success();
    }
}
