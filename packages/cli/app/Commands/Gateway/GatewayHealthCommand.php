<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class GatewayHealthCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'gateway:health {--json}';

    protected $description = 'Show gateway health status (runs on gateway)';

    protected $hidden = true;

    public function handle(GatewayDnsService $dnsService): int
    {
        $vpnClients = 0;
        $password = Setting::get('wg_easy_password');

        if ($password !== null) {
            try {
                $service = WgEasyService::forGateway('127.0.0.1', 51821, $password);
                $vpnClients = count($service->getClients());
            } catch (\Throwable) {
                // VPN service unavailable
            }
        }

        $dnsMappings = count($dnsService->getMappings());
        $nodeCount = Node::count();
        $deploymentCount = Deployment::where('status', 'active')->count();

        $uptime = trim(Process::run('uptime -p 2>/dev/null || uptime')->output());

        return $this->outputJsonSuccess([
            'vpn_clients' => $vpnClients,
            'dns_mappings' => $dnsMappings,
            'nodes' => $nodeCount,
            'active_deployments' => $deploymentCount,
            'uptime' => $uptime,
        ]);
    }
}
