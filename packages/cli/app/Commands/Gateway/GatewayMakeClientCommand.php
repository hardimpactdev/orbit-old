<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Services\ConfigManager;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class GatewayMakeClientCommand extends Command
{
    protected $signature = 'gateway:make:client
                            {name? : Client name (e.g., laptop, server)}
                            {tld? : Custom TLD for the client (optional, e.g., testa, corp)}';

    protected $description = 'Create a new WireGuard VPN client with custom TLD routing';

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly WgEasyService $wgEasyService,
        private readonly GatewayDnsService $dnsService,
        private readonly GatewayCliAdapter $cliAdapter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Check if gateway is installed
        if (! $this->isGatewayInstalled()) {
            $this->error('Gateway is not installed. Run: orbit install --template=gateway');

            return self::FAILURE;
        }

        // Check if WG Easy is running
        if (! $this->cliAdapter->isWgEasyRunning()) {
            $this->error('WG Easy is not running. Start it with: orbit start');

            return self::FAILURE;
        }

        // Get client name
        $name = $this->argument('name');
        if ($name === null) {
            $name = text(
                label: 'Client name',
                placeholder: 'e.g., laptop, server, mac-studio',
                required: true,
                validate: fn ($value) => $this->validateClientName($value),
            );
        }

        // Normalize name
        $name = $this->normalizeClientName($name);

        // Check if client already exists
        if ($this->clientExists($name)) {
            $this->error("Client '{$name}' already exists");
            $existing = $this->getClient($name);
            if ($existing !== null) {
                $this->info("TLD: {$existing['tld']}");
                $this->info("IP: {$existing['ip']}");
            }

            return self::FAILURE;
        }

        // Get TLD (optional)
        $tld = $this->argument('tld');
        if ($tld === null) {
            $defaultTld = $this->generateTldFromName($name);
            $tld = text(
                label: 'Custom TLD for this client (optional)',
                placeholder: 'e.g., testa, corp, dev (leave empty to skip)',
                default: $defaultTld,
                validate: fn ($value) => $value === '' ? null : $this->validateTld($value),
            );
        }

        // Normalize TLD (empty string stays empty)
        $tld = $tld === '' ? '' : $this->normalizeTld($tld);

        // Check if TLD is already in use (only if TLD is provided)
        if ($tld !== '' && $this->tldExists($tld)) {
            $existing = $this->getClientByTld($tld);
            $this->error("TLD '.{$tld}' is already assigned to client '{$existing['name']}'");

            return self::FAILURE;
        }

        $this->info("Creating client: {$name}");
        if ($tld !== '') {
            $this->info("TLD: .{$tld}");
        } else {
            $this->info('TLD: (none - no DNS mapping)');
        }
        $this->newLine();

        // Create client in WG Easy
        $this->info('Creating WireGuard client...');
        $client = $this->wgEasyService->createClient($name);

        if ($client === null) {
            $this->error('Failed to create WireGuard client');

            return self::FAILURE;
        }

        $this->info('✓ Client created');
        $this->info("  VPN IP: {$client['ip']}");
        $this->newLine();

        // Store mapping
        $this->storeClientMapping($name, $tld, $client['ip'], $client['id']);

        // Configure DNS only if TLD is provided
        if ($tld !== '') {
            $this->info('Configuring DNS...');
            $needsRestart = $this->dnsService->addTldMapping($tld, $client['ip']);
            if ($needsRestart) {
                $this->cliAdapter->restartDns();
            }
            $this->info("✓ DNS mapping added: *.{$tld} -> {$client['ip']}");
            $this->newLine();
        }

        // Show connection info
        $this->showClientInfo($name, $tld !== '' ? $tld : null, $client);

        return self::SUCCESS;
    }

    /**
     * Check if gateway template is installed.
     */
    private function isGatewayInstalled(): bool
    {
        return $this->configManager->getTemplate() === 'gateway'
            || $this->configManager->get('wg_easy.enabled', false);
    }

    /**
     * Check if a client already exists.
     */
    private function clientExists(string $name): bool
    {
        $clients = $this->configManager->get('gateway.clients', []);

        return isset($clients[$name]);
    }

    /**
     * Get a client by name.
     */
    private function getClient(string $name): ?array
    {
        $clients = $this->configManager->get('gateway.clients', []);

        return $clients[$name] ?? null;
    }

    /**
     * Check if a TLD is already assigned.
     */
    private function tldExists(string $tld): bool
    {
        return $this->getClientByTld($tld) !== null;
    }

    /**
     * Get client by TLD.
     */
    private function getClientByTld(string $tld): ?array
    {
        $clients = $this->configManager->get('gateway.clients', []);

        foreach ($clients as $client) {
            if (($client['tld'] ?? '') === $tld) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Store client mapping in configuration.
     */
    private function storeClientMapping(string $name, string $tld, string $ip, string $wgId): void
    {
        $clients = $this->configManager->get('gateway.clients', []);
        $clientData = [
            'name' => $name,
            'ip' => $ip,
            'wg_id' => $wgId,
            'created_at' => now()->toIso8601String(),
        ];

        // Only store TLD if provided
        if ($tld !== '') {
            $clientData['tld'] = $tld;
        }

        $clients[$name] = $clientData;
        $this->configManager->set('gateway.clients', $clients);
    }

    /**
     * Show client connection information.
     */
    private function showClientInfo(string $name, ?string $tld, array $client): void
    {
        $this->info('Client created successfully!');
        $this->newLine();
        $this->line('<fg=green>Connection Details:</>');
        $this->line("  Name: {$name}");
        if ($tld !== null) {
            $this->line("  TLD: .{$tld}");
        }
        $this->line("  VPN IP: {$client['ip']}");
        $this->newLine();

        $hostIp = $this->configManager->get('wg_easy.host', 'YOUR_GATEWAY_IP');
        $webUiPort = $this->configManager->get('wg_easy.web_ui_port', 51821);

        $this->line('<fg=yellow>Next steps:</>');
        $this->line("1. Open WG Easy web UI: http://{$hostIp}:{$webUiPort}");
        $this->line("2. Find client '{$name}' and download the configuration");
        $this->line('3. Import the config into your WireGuard client');
        $this->line('4. Connect to the VPN');
        $this->newLine();
        $this->line('Once connected, you can access this machine via:');
        $this->line("  <fg=cyan>https://anything.{$tld}</> (where 'anything' is any hostname)");
        $this->newLine();

        // Show QR code option
        $showQr = confirm(
            label: 'Show QR code for mobile setup?',
            default: false,
        );

        if ($showQr) {
            $this->showQrCode($name);
        }
    }

    /**
     * Show QR code for the client configuration.
     */
    private function showQrCode(string $name): void
    {
        $config = $this->wgEasyService->getClientConfig($name);

        if ($config === null) {
            $this->warn('Could not retrieve client configuration');

            return;
        }

        $this->newLine();
        $this->info('Scan this QR code with your WireGuard mobile app:');
        $this->newLine();

        $result = Process::run('which qrencode');
        if ($result->successful()) {
            $escaped = escapeshellarg($config);
            $qrResult = Process::run("echo {$escaped} | qrencode -t ANSIUTF8");
            $this->line($qrResult->output());
        } else {
            $this->warn('qrencode not installed. Install with: brew install qrencode');
            $this->line('Configuration:');
            $this->line($config);
        }
    }

    /**
     * Validate client name.
     */
    private function validateClientName(string $value): ?string
    {
        if ($value === '') {
            return 'Client name is required';
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            return 'Name can only contain letters, numbers, underscores, and hyphens';
        }

        if (strlen($value) > 32) {
            return 'Name is too long (max 32 characters)';
        }

        return null;
    }

    /**
     * Validate TLD.
     */
    private function validateTld(string $value): ?string
    {
        if ($value === '') {
            return 'TLD is required';
        }

        // Remove leading dot if present
        $value = ltrim($value, '.');

        if (! preg_match('/^[a-zA-Z0-9-]+$/', $value)) {
            return 'TLD can only contain letters, numbers, and hyphens';
        }

        if (strlen($value) > 63) {
            return 'TLD is too long (max 63 characters)';
        }

        // Check for reserved/real TLDs
        $reserved = ['com', 'org', 'net', 'io', 'dev', 'app', 'co', 'test', 'local'];
        if (in_array(strtolower($value), $reserved, true)) {
            return "'.{$value}' is a reserved/real TLD. Choose something unique.";
        }

        return null;
    }

    /**
     * Normalize client name.
     */
    private function normalizeClientName(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));
    }

    /**
     * Normalize TLD.
     */
    private function normalizeTld(string $tld): string
    {
        return strtolower(ltrim($tld, '.'));
    }

    /**
     * Generate a default TLD from client name.
     */
    private function generateTldFromName(string $name): string
    {
        return $name.'a'; // e.g., laptop -> laptopa
    }
}
