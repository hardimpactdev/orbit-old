<?php

declare(strict_types=1);

namespace App\Commands\Node;

use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

final class NodeRegisterTldCommand extends Command
{
    protected $signature = 'node:register-tld
                            {node? : Node ID or name}
                            {tld? : Custom TLD (e.g., beast, dev, staging)}
                            {--remove : Remove TLD delegation}';

    protected $description = 'Register a custom TLD for a client node (gateway will proxy requests)';

    public function handle(GatewayManager $gatewayManager): int
    {
        $nodeId = $this->argument('node');

        if ($nodeId === null) {
            error('Node ID or name is required');

            return self::FAILURE;
        }

        // Find node by ID or name
        $node = is_numeric($nodeId)
            ? Node::find($nodeId)
            : Node::where('name', $nodeId)->first();

        if ($node === null) {
            error("Node not found: {$nodeId}");

            return self::FAILURE;
        }

        // Only client nodes can have custom TLDs
        if ($node->node_type !== NodeType::Client) {
            error('Only client nodes can have custom TLDs');
            info("Node '{$node->name}' is type: {$node->node_type->value}");

            return self::FAILURE;
        }

        // Must have VPN IP
        if ($node->vpn_ip === null) {
            error('Node must have a VPN IP to register a TLD');
            info('Register the node with a gateway first using: orbit setup:remote --gateway=<id>');

            return self::FAILURE;
        }

        // Must have gateway
        if ($node->gateway_id === null) {
            error('Node must be associated with a gateway');

            return self::FAILURE;
        }

        $gateway = $gatewayManager->get($node->gateway_id);
        if ($gateway === null) {
            error("Gateway not found: {$node->gateway_id}");

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            return $this->removeTld($node, $gateway, $gatewayManager);
        }

        $tld = $this->argument('tld');

        if ($tld === null) {
            $tld = text(
                label: 'Custom TLD',
                placeholder: 'e.g., beast, dev, staging',
                required: true,
                validate: fn ($value) => $this->validateTld($value),
            );
        }

        $validationError = $this->validateTld($tld);
        if ($validationError !== null) {
            error($validationError);

            return self::FAILURE;
        }

        // Normalize TLD (remove leading dot, lowercase)
        $tld = strtolower(ltrim($tld, '.'));

        $this->info("Registering .{$tld} for node '{$node->name}'");
        $this->newLine();
        $this->info("Gateway: {$gateway->name} ({$gateway->ip_address})");
        $this->info("Node VPN IP: {$node->vpn_ip}");
        $this->newLine();

        // Update node record
        $node->update(['custom_tld' => $tld]);
        $this->info('✓ Node record updated');

        // Update gateway Caddy
        $caddyResult = spin(
            fn () => $this->updateGatewayCaddy($gateway, $gatewayManager),
            'Updating gateway Caddy...',
        );

        if (! $caddyResult['success']) {
            error('Failed to update gateway Caddy');
            warning($caddyResult['error'] ?? 'Unknown error');

            return self::FAILURE;
        }
        $this->info('✓ Gateway Caddy updated');

        $this->newLine();
        $this->info('TLD registered successfully!');
        $this->newLine();
        $this->line("  Gateway will proxy *.{$tld} to {$node->name} ({$node->vpn_ip})");
        $this->newLine();
        $this->warn("NOTE: You must configure DNS to resolve *.{$tld} to gateway ({$gateway->ip_address})");
        $this->line('  Option 1: Add to /etc/hosts on your machine');
        $this->line("  Option 2: Configure your router's DNS");
        $this->line('  Option 3: Use a public DNS service like Cloudflare');
        $this->newLine();

        return self::SUCCESS;
    }

    private function removeTld(Node $node, \HardImpact\Orbit\Core\Models\Gateway $gateway, GatewayManager $gatewayManager): int
    {
        if ($node->custom_tld === null) {
            warning("Node '{$node->name}' has no custom TLD");

            return self::SUCCESS;
        }

        $tld = $node->custom_tld;

        $this->info("Removing .{$tld} delegation from node '{$node->name}'");
        $this->newLine();

        // Update node record
        $node->update(['custom_tld' => null]);
        $this->info('✓ Node record updated');

        // Update gateway Caddy
        $caddyResult = spin(
            fn () => $this->updateGatewayCaddy($gateway, $gatewayManager),
            'Updating gateway Caddy...',
        );

        if (! $caddyResult['success']) {
            error('Failed to update gateway Caddy');
            warning($caddyResult['error'] ?? 'Unknown error');

            return self::FAILURE;
        }
        $this->info('✓ Gateway Caddy updated');

        $this->newLine();
        $this->info('TLD delegation removed!');
        $this->newLine();

        return self::SUCCESS;
    }

    private function validateTld(string $tld): ?string
    {
        $tld = strtolower(ltrim($tld, '.'));

        if (empty($tld)) {
            return 'TLD cannot be empty';
        }

        if (! preg_match('/^[a-z0-9-]+$/', $tld)) {
            return 'TLD must contain only lowercase letters, numbers, and hyphens';
        }

        if (strlen($tld) < 2 || strlen($tld) > 63) {
            return 'TLD must be between 2 and 63 characters';
        }

        // Prevent using common real TLDs
        $reserved = ['com', 'net', 'org', 'edu', 'gov', 'mil', 'int', 'io', 'co', 'uk', 'us', 'dev'];
        if (in_array($tld, $reserved, true)) {
            return "Cannot use reserved TLD: {$tld}";
        }

        return null;
    }

    /**
     * @return array{success: bool, error?: string}
     */
    private function updateGatewayCaddy(\HardImpact\Orbit\Core\Models\Gateway $gateway, GatewayManager $gatewayManager): array
    {
        try {
            // Get all client nodes with custom TLDs for this gateway
            $nodes = Node::where('gateway_id', $gateway->id)
                ->where('node_type', NodeType::Client)
                ->whereNotNull('custom_tld')
                ->whereNotNull('vpn_ip')
                ->get();

            // Build Caddy proxy config
            $proxyBlocks = $nodes->map(fn (Node $node) => <<<CADDY
*.{$node->custom_tld} {
    reverse_proxy {$node->vpn_ip}:80 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY)->implode("\n");

            // Write Caddy config and reload
            $result = \Illuminate\Support\Facades\Process::run(
                sprintf(
                    'sudo mkdir -p /etc/caddy/orbit && echo %s | sudo tee /etc/caddy/orbit/tld-proxies.caddy > /dev/null && sudo systemctl reload caddy',
                    escapeshellarg($proxyBlocks)
                )
            );

            if (! $result->successful()) {
                return ['success' => false, 'error' => $result->errorOutput()];
            }

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
