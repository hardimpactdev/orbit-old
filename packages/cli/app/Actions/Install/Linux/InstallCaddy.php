<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class InstallCaddy
{
    private const string DOWNLOAD_URL = 'https://caddyserver.com/api/download?os=linux&arch=amd64&p=github.com%2Fcaddy-dns%2Fcloudflare';

    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $hasModule = false;

        if ($this->platformService->commandExists('caddy')) {
            $moduleCheck = Process::run('caddy list-modules 2>&1 | grep -q dns.providers.cloudflare');
            $hasModule = $moduleCheck->successful();

            if ($hasModule) {
                $logger->skip('Caddy with cloudflare DNS module already installed');

                return StepResult::success();
            }

            $logger->step('Caddy installed but missing cloudflare DNS module, upgrading...');
        }

        $this->ensureSystemdInfrastructure($logger);

        $logger->step('Downloading Caddy with cloudflare DNS module...');
        $result = Process::timeout(120)->run(
            'curl -fsSL -o /tmp/caddy-cloudflare '.escapeshellarg(self::DOWNLOAD_URL)
            .' && chmod +x /tmp/caddy-cloudflare'
            .' && sudo mv /tmp/caddy-cloudflare /usr/bin/caddy'
            .' && sudo chown root:root /usr/bin/caddy'
            .' && sudo chmod 755 /usr/bin/caddy'
        );

        if (! $result->successful()) {
            return StepResult::failed('Failed to install Caddy: '.$result->errorOutput());
        }

        // Verify the module is present
        $verify = Process::run('caddy list-modules 2>&1 | grep -q dns.providers.cloudflare');
        if (! $verify->successful()) {
            return StepResult::failed('Caddy installed but cloudflare DNS module not detected');
        }

        $logger->success('Caddy with cloudflare DNS module installed');

        $logger->step('Starting Caddy service...');
        $startResult = Process::run('sudo systemctl enable caddy && sudo systemctl start caddy');

        if (! $startResult->successful()) {
            $logger->warn('Failed to start Caddy service: '.$startResult->errorOutput());
            $logger->warn('You may need to start it manually: sudo systemctl start caddy');
        } else {
            $logger->success('Caddy service started');
        }

        return StepResult::success();
    }

    /**
     * Ensure the caddy user, group, and systemd service file exist.
     * These are normally provided by the apt package, so we create them
     * manually when installing via direct download.
     */
    private function ensureSystemdInfrastructure(InstallLogger $logger): void
    {
        // Create caddy user/group if they don't exist
        $userCheck = Process::run('id caddy 2>/dev/null');
        if (! $userCheck->successful()) {
            $logger->step('Creating caddy system user...');
            Process::run('sudo groupadd --system caddy 2>/dev/null; sudo useradd --system --gid caddy --create-home --home-dir /var/lib/caddy --shell /usr/sbin/nologin caddy');
        }

        // Create systemd service file if it doesn't exist
        $serviceCheck = Process::run('test -f /usr/lib/systemd/system/caddy.service || test -f /etc/systemd/system/caddy.service');
        if (! $serviceCheck->successful()) {
            $logger->step('Creating Caddy systemd service...');
            $serviceFile = <<<'UNIT'
[Unit]
Description=Caddy
Documentation=https://caddyserver.com/docs/
After=network.target network-online.target
Requires=network-online.target

[Service]
Type=notify
User=caddy
Group=caddy
ExecStart=/usr/bin/caddy run --environ --config /etc/caddy/Caddyfile
ExecReload=/usr/bin/caddy reload --config /etc/caddy/Caddyfile --force
TimeoutStopSec=5s
LimitNOFILE=1048576
PrivateTmp=true
ProtectSystem=full
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
UNIT;

            Process::run('echo '.escapeshellarg($serviceFile).' | sudo tee /etc/systemd/system/caddy.service > /dev/null');
            Process::run('sudo mkdir -p /etc/caddy');
            Process::run('sudo systemctl daemon-reload');
        }
    }
}
