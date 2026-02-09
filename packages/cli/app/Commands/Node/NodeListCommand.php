<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Concerns\WithJsonOutput;
use App\Models\Gateway;
use App\Services\WgEasyService;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

final class NodeListCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'list:nodes
        {--type= : Filter by node type (local, gateway, client)}
        {--json : Output as JSON}';

    protected $description = 'List all nodes';

    protected $aliases = ['node:list'];

    public function handle(): int
    {
        $typeFilter = $this->option('type');

        if ($typeFilter !== null) {
            try {
                $type = NodeType::from($typeFilter);
            } catch (\ValueError) {
                $message = "Invalid node type: {$typeFilter}. Must be 'local', 'gateway', or 'client'";

                return $this->wantsJson()
                    ? $this->outputJsonError($message)
                    : $this->error($message) && self::FAILURE;
            }

            $nodes = Node::where('node_type', $type->value)->get();
        } else {
            $nodes = Node::all();
        }

        $vpnClients = $this->fetchVpnClients();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'nodes' => $nodes->map(function ($node) use ($vpnClients) {
                    $vpnStatus = $this->getVpnStatus($node, $vpnClients);

                    return [
                        'id' => $node->id,
                        'name' => $node->name,
                        'host' => $node->host,
                        'user' => $node->user,
                        'port' => $node->port,
                        'node_type' => $node->node_type->value,
                        'status' => $node->status->value,
                        'is_active' => $node->is_active,
                        'vpn_ip' => $node->hasVpn() ? $node->getAttribute('vpn_ip') : null,
                        'gateway_id' => $node->getAttribute('gateway_id'),
                        'vpn_status' => $vpnStatus['status'],
                        'vpn_last_seen' => $vpnStatus['last_seen'],
                    ];
                })->toArray(),
                'count' => $nodes->count(),
            ]);
        }

        if ($nodes->isEmpty()) {
            $this->warn('No nodes found');

            return self::SUCCESS;
        }

        $activeNodeId = Node::where('is_active', true)->value('id');
        $gateways = Gateway::all()->keyBy('id');

        $rows = $nodes->map(function ($node) use ($activeNodeId, $gateways, $vpnClients) {
            $active = $node->id === $activeNodeId ? '✓' : '';
            $vpn = $node->hasVpn() ? $node->getAttribute('vpn_ip') : '-';

            $gateway = '-';
            $gatewayId = $node->getAttribute('gateway_id');
            if ($gatewayId !== null && $gateways->has($gatewayId)) {
                $gateway = $gateways->get($gatewayId)->name;
            }

            $vpnStatus = $this->getVpnStatus($node, $vpnClients);

            return [
                $node->id,
                $node->name,
                $node->node_type->value,
                $node->host,
                $vpn,
                $gateway,
                $vpnStatus['display'],
                $node->status->value,
                $active,
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'Type', 'Host', 'VPN IP', 'Gateway', 'VPN Status', 'Status', 'Active'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * Fetch VPN clients from all gateways.
     *
     * @return array<string, array{enabled: bool, latestHandshakeAt: string|null}>
     */
    private function fetchVpnClients(): array
    {
        $gateways = Gateway::all();
        $vpnClients = [];

        foreach ($gateways as $gateway) {
            if ($gateway->wg_password === null) {
                continue;
            }

            try {
                $clients = spin(
                    function () use ($gateway) {
                        $wgService = WgEasyService::forGateway(
                            $gateway->getVpnGatewayIp(),
                            $gateway->wg_api_port,
                            $gateway->wg_password
                        );

                        return $wgService->getClients();
                    },
                    "Fetching VPN clients from {$gateway->name}..."
                );

                foreach ($clients as $client) {
                    $vpnClients[$client['ip']] = [
                        'enabled' => $client['enabled'],
                        'latestHandshakeAt' => $client['latestHandshakeAt'],
                        'name' => $client['name'],
                    ];
                }
            } catch (\Exception $e) {
                // Gateway unreachable, skip
            }
        }

        return $vpnClients;
    }

    /**
     * Get VPN status for a node.
     *
     * @param  array<string, array{enabled: bool, latestHandshakeAt: string|null}>  $vpnClients
     * @return array{status: string, last_seen: string|null, display: string}
     */
    private function getVpnStatus(Node $node, array $vpnClients): array
    {
        if (! $node->hasVpn()) {
            return [
                'status' => 'none',
                'last_seen' => null,
                'display' => '-',
            ];
        }

        $vpnIp = $node->getAttribute('vpn_ip');
        if (! isset($vpnClients[$vpnIp])) {
            return [
                'status' => 'not_found',
                'last_seen' => null,
                'display' => 'Not Found',
            ];
        }

        $client = $vpnClients[$vpnIp];

        if (! $client['enabled']) {
            return [
                'status' => 'disabled',
                'last_seen' => null,
                'display' => 'Disabled',
            ];
        }

        if ($client['latestHandshakeAt'] === null) {
            return [
                'status' => 'never_connected',
                'last_seen' => null,
                'display' => 'Never',
            ];
        }

        $lastSeen = new \DateTime($client['latestHandshakeAt']);
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $lastSeen->getTimestamp();

        if ($diff < 180) {
            return [
                'status' => 'online',
                'last_seen' => $client['latestHandshakeAt'],
                'display' => '<fg=green>Online</>',
            ];
        }

        $lastSeenHuman = $this->formatTimeDiff($diff);

        return [
            'status' => 'offline',
            'last_seen' => $client['latestHandshakeAt'],
            'display' => "<fg=red>Offline</> ({$lastSeenHuman})",
        ];
    }

    private function formatTimeDiff(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s ago";
        }

        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes}m ago";
        }

        $hours = floor($minutes / 60);
        if ($hours < 24) {
            return "{$hours}h ago";
        }

        $days = floor($hours / 24);

        return "{$days}d ago";
    }
}
