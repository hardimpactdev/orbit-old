<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

/**
 * Install WG Easy (WireGuard VPN) via Docker.
 *
 * Sets up a WireGuard VPN server with a web UI for easy client management.
 */
final readonly class InstallWgEasy
{
    public function __construct(
        private ConfigManager $configManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $logger->step('Setting up WG Easy VPN');

        $configPath = $this->configManager->getConfigPath();
        $wgEasyPath = $configPath.'/wg-easy';

        // Check if WG Easy is already running
        $containerCheck = Process::run('docker ps --filter "name=orbit-wg-easy" --format "{{.Names}}"');
        if ($containerCheck->successful() && trim($containerCheck->output()) === 'orbit-wg-easy') {
            $logger->skip('WG Easy already running');
            $hostIp = $this->configManager->get('wg_easy.host', $this->getHostIp());
            $webUiPort = $this->configManager->get('wg_easy.web_ui_port', 51821);
            $logger->info('Web UI: http://'.$hostIp.':'.$webUiPort);

            return StepResult::success();
        }

        // Create WG Easy directories
        if (! is_dir($wgEasyPath)) {
            mkdir($wgEasyPath, 0755, true);
            mkdir($wgEasyPath.'/data', 0755, true);
        }

        // Generate a secure password for the web UI
        $webUiPassword = $this->generatePassword();

        // Get the host IP for the VPN
        $hostIp = $this->getHostIp();

        // Create docker-compose override for WG Easy
        $composeContent = $this->generateComposeContent($hostIp, $webUiPassword);
        file_put_contents($wgEasyPath.'/docker-compose.yml', $composeContent);

        // Start WG Easy
        $result = Process::timeout(300)->run("cd {$wgEasyPath} && docker compose up -d");

        if (! $result->successful()) {
            $logger->warn('Could not start WG Easy automatically');
            $logger->info('You can start it manually later with: cd '.$wgEasyPath.' && docker compose up -d');

            return StepResult::success();
        }

        $logger->success('WG Easy VPN installed');
        $logger->info('Web UI: http://'.$hostIp.':51821');
        $logger->info('Password: '.$webUiPassword);
        $logger->info('VPN Endpoint: '.$hostIp.':51820');
        $logger->newLine();
        $logger->info('To configure VPN clients:');
        $logger->info('1. Open the Web UI at http://'.$hostIp.':51821');
        $logger->info('2. Log in with the password above');
        $logger->info('3. Create new clients for each machine');

        // Store configuration
        $this->configManager->set('wg_easy.enabled', true);
        $this->configManager->set('wg_easy.host', $hostIp);
        $this->configManager->set('wg_easy.web_ui_port', 51821);
        $this->configManager->set('wg_easy.vpn_port', 51820);
        $this->configManager->set('wg_easy.password', $webUiPassword);

        return StepResult::success();
    }

    /**
     * Generate a random password for the web UI.
     */
    private function generatePassword(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get the host IP address for VPN endpoint.
     */
    private function getHostIp(): string
    {
        // Try to get the primary IP address
        $result = Process::run("ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print \$2}'");

        if ($result->successful()) {
            $ip = trim($result->output());
            if ($ip !== '') {
                return $ip;
            }
        }

        // Fallback to configured IP or localhost
        return $this->configManager->getHostIp();
    }

    /**
     * Generate docker-compose.yml content for WG Easy.
     */
    private function generateComposeContent(string $hostIp, string $password): string
    {
        // Generate bcrypt hash for the password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        // Escape $ for docker-compose (convert $ to $$)
        $passwordHash = str_replace('$', '$$', $passwordHash);

        return <<<YAML
services:
  wg-easy:
    image: ghcr.io/wg-easy/wg-easy:latest
    container_name: orbit-wg-easy
    restart: unless-stopped
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    sysctls:
      - net.ipv4.conf.all.src_valid_mark=1
      - net.ipv4.ip_forward=1
    ports:
      - "51820:51820/udp"
      - "51821:51821/tcp"
    volumes:
      - ./data:/etc/wireguard
    environment:
      - WG_HOST={$hostIp}
      - PASSWORD_HASH={$passwordHash}
      - WG_PORT=51820
      - WG_DEFAULT_ADDRESS=10.8.0.x
      - WG_DEFAULT_DNS=10.8.0.1,8.8.8.8
      - WG_ALLOWED_IPS=0.0.0.0/0, ::/0
      - WG_PERSISTENT_KEEPALIVE=25
    networks:
      - orbit

networks:
  orbit:
    external: true
    name: orbit
YAML;
    }
}
