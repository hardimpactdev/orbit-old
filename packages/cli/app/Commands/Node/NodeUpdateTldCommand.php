<?php

declare(strict_types=1);

namespace App\Commands\Node;

use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\TldService;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

final class NodeUpdateTldCommand extends Command
{
    protected $signature = 'node:update-tld
                            {node? : Node ID or name}
                            {tld? : New TLD (e.g., bear, staging)}
                            {--json : Output as JSON}';

    protected $description = 'Update a node\'s TLD (changes database, config, Caddy, DNS)';

    public function handle(
        TldService $tldService,
        ConfigurationService $configService,
        CommandService $commandService,
        GatewayDnsService $dnsService,
    ): int {
        $nodeId = $this->argument('node');

        if ($nodeId === null) {
            error('Node ID or name is required');

            return self::FAILURE;
        }

        $node = is_numeric($nodeId)
            ? Node::find($nodeId)
            : Node::where('name', $nodeId)->first();

        if ($node === null) {
            error("Node not found: {$nodeId}");

            return self::FAILURE;
        }

        $tld = $this->argument('tld');

        if ($tld === null) {
            $tld = text(
                label: 'New TLD',
                placeholder: 'e.g., bear, staging',
                required: true,
                validate: fn ($value) => $tldService->validateTld($value),
            );
        }

        $validationError = $tldService->validateTld($tld);
        if ($validationError !== null) {
            error($validationError);

            return self::FAILURE;
        }

        $oldTld = $node->tld;

        if ($this->option('json')) {
            return $this->handleJson($node, $tld, $tldService, $configService, $commandService, $dnsService);
        }

        $this->info("Updating TLD for node '{$node->name}': .{$oldTld} → .{$tld}");
        $this->newLine();

        // Step 1: Database
        try {
            $dbResult = spin(
                fn () => $tldService->updateNodeTld($node, $tld),
                'Updating database records...',
            );
            $this->info("✓ Database updated ({$dbResult['deployments_updated']} deployments)");
        } catch (\InvalidArgumentException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        // Step 2: Node config.json + dns_mappings
        $configResult = spin(function () use ($node, $tld, $configService) {
            $config = $configService->getConfig($node);
            if (! $config['success']) {
                return ['success' => false, 'error' => 'Failed to read config'];
            }
            $data = $config['data'];
            $data['tld'] = $tld;

            // Also update dns_mappings to match the new TLD
            $dnsMappings = $data['dns_mappings'] ?? [];
            $tldUpdated = false;
            foreach ($dnsMappings as &$mapping) {
                if ($mapping['type'] === 'address') {
                    $mapping['tld'] = $tld;
                    $tldUpdated = true;
                    break;
                }
            }
            unset($mapping);

            if (! $tldUpdated) {
                array_unshift($dnsMappings, ['type' => 'address', 'tld' => $tld, 'value' => '127.0.0.1']);
            }
            $data['dns_mappings'] = $dnsMappings;

            return $configService->saveConfig($node, $data);
        }, 'Updating node config.json...');

        if ($configResult['success']) {
            $this->info('✓ Node config.json updated');
        } else {
            error('✗ Failed to update node config: '.($configResult['error'] ?? 'Unknown error'));
        }

        // Step 3: Node Caddy
        $caddyResult = spin(
            fn () => $commandService->executeCommand($node, 'caddy:reload --json'),
            'Regenerating node Caddyfile...',
        );

        if ($caddyResult['success'] ?? false) {
            $this->info('✓ Node Caddyfile regenerated');
        } else {
            error('✗ Failed to regenerate Caddyfile: '.($caddyResult['error'] ?? 'Unknown error'));
        }

        // Step 3b: Node DNS (regenerate dnsmasq.conf + restart DNS service)
        $dnsRegenResult = spin(function () use ($node, $commandService) {
            try {
                $commandService->executeCommand($node, 'service:restart dns --json');

                return ['success' => true];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }, 'Regenerating node DNS config...');

        if ($dnsRegenResult['success'] ?? false) {
            $this->info('✓ Node DNS config regenerated');
        } else {
            $this->warn('⚠ Node DNS restart skipped (service may not be running)');
        }

        // Steps 4-5: Gateway DNS + Caddy (only for nodes with custom TLD + VPN)
        $node->refresh();
        if ($node->custom_tld !== null && $node->vpn_ip !== null) {
            // Step 4: Gateway DNS
            $dnsResult = spin(function () use ($oldTld, $tld, $node, $dnsService, $commandService) {
                if ($oldTld !== null && $oldTld !== '') {
                    $dnsService->removeTldMapping($oldTld);
                }
                $dnsService->addTldMapping($tld, $node->vpn_ip);
                $commandService->executeLocalCommand('service:restart dns');

                return ['success' => true];
            }, 'Updating gateway DNS...');

            $this->info('✓ Gateway DNS updated');

            // Step 5: Gateway Caddy
            $gatewayCaddyResult = spin(
                fn () => $this->updateGatewayCaddy(),
                'Updating gateway Caddy...',
            );

            if ($gatewayCaddyResult['success']) {
                $this->info('✓ Gateway Caddy updated');
            } else {
                error('✗ Failed to update gateway Caddy: '.($gatewayCaddyResult['error'] ?? 'Unknown error'));
            }
        }

        $this->newLine();
        $this->info('TLD updated successfully!');
        $this->newLine();

        if ($oldTld !== null && ! $node->isLocal()) {
            $this->warn('Remember to update your local macOS resolver:');
            $this->line("  sudo rm /etc/resolver/{$oldTld}");
            $this->line("  echo 'nameserver 10.6.0.1' | sudo tee /etc/resolver/{$tld}");
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function handleJson(
        Node $node,
        string $tld,
        TldService $tldService,
        ConfigurationService $configService,
        CommandService $commandService,
        GatewayDnsService $dnsService,
    ): int {
        $oldTld = $node->tld;
        $steps = [];

        try {
            $dbResult = $tldService->updateNodeTld($node, $tld);
            $steps['database'] = ['success' => true, ...$dbResult];
        } catch (\InvalidArgumentException $e) {
            $this->line(json_encode(['success' => false, 'error' => $e->getMessage()]));

            return self::FAILURE;
        }

        try {
            $config = $configService->getConfig($node);
            if ($config['success']) {
                $data = $config['data'];
                $data['tld'] = $tld;

                // Also update dns_mappings to match the new TLD
                $dnsMappings = $data['dns_mappings'] ?? [];
                $tldUpdated = false;
                foreach ($dnsMappings as &$mapping) {
                    if ($mapping['type'] === 'address') {
                        $mapping['tld'] = $tld;
                        $tldUpdated = true;
                        break;
                    }
                }
                unset($mapping);

                if (! $tldUpdated) {
                    array_unshift($dnsMappings, ['type' => 'address', 'tld' => $tld, 'value' => '127.0.0.1']);
                }
                $data['dns_mappings'] = $dnsMappings;

                $saveResult = $configService->saveConfig($node, $data);
                $steps['node_config'] = ['success' => $saveResult['success']];
            } else {
                $steps['node_config'] = ['success' => false];
            }
        } catch (\Exception $e) {
            $steps['node_config'] = ['success' => false, 'error' => $e->getMessage()];
        }

        try {
            $caddyResult = $commandService->executeCommand($node, 'caddy:reload --json');
            $steps['node_caddy'] = ['success' => $caddyResult['success'] ?? false];
        } catch (\Exception $e) {
            $steps['node_caddy'] = ['success' => false, 'error' => $e->getMessage()];
        }

        try {
            $commandService->executeCommand($node, 'service:restart dns --json');
            $steps['node_dns'] = ['success' => true];
        } catch (\Exception $e) {
            $steps['node_dns'] = ['success' => false, 'error' => $e->getMessage()];
        }

        $node->refresh();
        if ($node->custom_tld !== null && $node->vpn_ip !== null) {
            try {
                if ($oldTld !== null && $oldTld !== '') {
                    $dnsService->removeTldMapping($oldTld);
                }
                $dnsService->addTldMapping($tld, $node->vpn_ip);
                $commandService->executeLocalCommand('service:restart dns');
                $steps['gateway_dns'] = ['success' => true];
            } catch (\Exception $e) {
                $steps['gateway_dns'] = ['success' => false, 'error' => $e->getMessage()];
            }

            try {
                $steps['gateway_caddy'] = $this->updateGatewayCaddy();
            } catch (\Exception $e) {
                $steps['gateway_caddy'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        $this->line(json_encode([
            'success' => true,
            'old_tld' => $oldTld,
            'new_tld' => $tld,
            'deployments_updated' => $dbResult['deployments_updated'],
            'steps' => $steps,
        ]));

        return self::SUCCESS;
    }

    /**
     * @return array{success: bool, error?: string}
     */
    private function updateGatewayCaddy(): array
    {
        $nodes = Node::where('node_type', NodeType::Client)
            ->whereNotNull('custom_tld')
            ->whereNotNull('vpn_ip')
            ->get();

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

        $result = Process::run(
            sprintf(
                'sudo mkdir -p /etc/caddy/orbit && echo %s | sudo tee /etc/caddy/orbit/tld-proxies.caddy > /dev/null && sudo systemctl reload caddy',
                escapeshellarg($proxyBlocks)
            )
        );

        if (! $result->successful()) {
            return ['success' => false, 'error' => $result->errorOutput()];
        }

        return ['success' => true];
    }
}
