<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\TldService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayUpdateTldTool extends Tool
{
    protected string $name = 'gateway_update_tld';

    protected string $description = 'Update a node\'s TLD — changes database records, node config, Caddy, gateway DNS, and gateway Caddy proxy';

    public function __construct(
        protected TldService $tldService,
        protected ConfigurationService $configService,
        protected CommandService $commandService,
        protected GatewayDnsService $dnsService,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->required()->description('The node ID to update'),
            'new_tld' => $schema->string()->required()->description('The new TLD (e.g. "bear", "staging")'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'node_id' => 'required|integer',
            'new_tld' => 'required|string|max:63',
        ]);

        $node = Node::find($request->get('node_id'));

        if ($node === null) {
            return Response::structured([
                'success' => false,
                'error' => 'Node not found',
            ]);
        }

        $newTld = $request->get('new_tld');
        $steps = [];

        // Step 1: Database updates
        try {
            $dbResult = $this->tldService->updateNodeTld($node, $newTld);
            $steps['database'] = ['success' => true, ...$dbResult];
        } catch (\InvalidArgumentException $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }

        $oldTld = $dbResult['old_tld'];

        // Step 2: Update node config.json via SSH
        try {
            $configResult = $this->configService->getConfig($node);
            if ($configResult['success']) {
                $config = $configResult['data'];
                $config['tld'] = $newTld;
                $saveResult = $this->configService->saveConfig($node, $config);
                $steps['node_config'] = ['success' => $saveResult['success']];
            } else {
                $steps['node_config'] = ['success' => false, 'error' => 'Failed to read config'];
            }
        } catch (\Exception $e) {
            $steps['node_config'] = ['success' => false, 'error' => $e->getMessage()];
        }

        // Step 3: Regenerate node Caddyfile
        try {
            $caddyResult = $this->commandService->executeCommand($node, 'caddy:reload --json');
            $steps['node_caddy'] = ['success' => $caddyResult['success'] ?? false];
        } catch (\Exception $e) {
            $steps['node_caddy'] = ['success' => false, 'error' => $e->getMessage()];
        }

        // Steps 4-5 only apply if the node has a custom TLD + VPN IP
        if ($node->fresh()->custom_tld !== null && $node->vpn_ip !== null) {
            // Step 4: Update gateway DNS (dnsmasq)
            try {
                if ($oldTld !== '') {
                    $this->dnsService->removeTldMapping($oldTld);
                }
                $this->dnsService->addTldMapping($newTld, $node->vpn_ip);
                $this->commandService->executeLocalCommand('service:restart dns');
                $steps['gateway_dns'] = ['success' => true];
            } catch (\Exception $e) {
                $steps['gateway_dns'] = ['success' => false, 'error' => $e->getMessage()];
            }

            // Step 5: Regenerate gateway Caddy TLD proxies
            try {
                $proxyResult = $this->regenerateGatewayCaddyProxies();
                $steps['gateway_caddy'] = $proxyResult;
            } catch (\Exception $e) {
                $steps['gateway_caddy'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return Response::structured([
            'success' => true,
            'old_tld' => $oldTld,
            'new_tld' => $newTld,
            'deployments_updated' => $dbResult['deployments_updated'],
            'steps' => $steps,
            'note' => "Remember to add a local macOS resolver for the new TLD if needed: echo 'nameserver 10.6.0.1' | sudo tee /etc/resolver/{$newTld}. Only remove /etc/resolver/{$oldTld} if no local installation depends on it.",
        ]);
    }

    /**
     * Regenerate gateway Caddy proxy config for all client nodes with custom TLDs.
     *
     * @return array{success: bool, error?: string}
     */
    private function regenerateGatewayCaddyProxies(): array
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
    }
}
