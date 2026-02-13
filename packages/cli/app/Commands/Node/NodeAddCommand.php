<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Models\Gateway;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Services\NodeService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;

final class NodeAddCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'node:add
        {host? : Server IP address}
        {--user=orbit : SSH user}
        {--port=22 : SSH port}
        {--type=client : Node type (client, gateway)}
        {--name= : Node name (auto-generated if not provided)}
        {--gateway= : Gateway ID for client nodes (prompts if not provided)}
        {--json : Output as JSON}';

    protected $description = 'Add a new node to the database';

    public function handle(NodeService $nodeService): int
    {
        $host = $this->argument('host');
        if ($host === null) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Host argument required in JSON mode');
            }
            $host = $this->ask('Server IP address');
        }

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->wantsJson()
                ? $this->outputJsonError('Invalid IP address format')
                : $this->error('Invalid IP address format') && self::FAILURE;
        }

        $user = $this->option('user');
        $port = (int) $this->option('port');
        $typeInput = $this->option('type');
        $name = $this->option('name');

        try {
            $type = NodeType::from($typeInput);
        } catch (\ValueError) {
            $message = "Invalid node type: {$typeInput}. Must be 'client' or 'gateway'";

            return $this->wantsJson()
                ? $this->outputJsonError($message)
                : $this->error($message) && self::FAILURE;
        }

        $node = $nodeService->addNode($host, $user, $port, $type, $name);

        if ($type === NodeType::Client) {
            $gatewayId = $this->option('gateway');

            if ($gatewayId === null && ! $this->wantsJson()) {
                $gateways = Gateway::all();

                if ($gateways->isNotEmpty()) {
                    $choices = $gateways->mapWithKeys(fn (Gateway $g) => [
                        $g->id => "{$g->name} ({$g->ip_address})",
                    ])->all();

                    $choices['skip'] = 'Skip VPN registration';

                    $selected = select(
                        label: 'Which gateway should this client connect to?',
                        options: $choices,
                    );

                    if ($selected !== 'skip') {
                        $gatewayId = $selected;
                    }
                }
            }

            if ($gatewayId !== null && $gatewayId !== 'skip') {
                $node->update(['gateway_id' => (int) $gatewayId]);
            }
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'id' => $node->id,
                'name' => $node->name,
                'host' => $node->host,
                'user' => $node->user,
                'port' => $node->port,
                'node_type' => $node->node_type->value,
                'gateway_id' => $node->gateway_id,
            ]);
        }

        $this->line('');
        $this->info('✓ Node added successfully!');
        $this->line('');
        $this->line("  Name: {$node->name}");
        $this->line("  Host: {$node->host}");
        $this->line("  Type: {$node->node_type->value}");
        $this->line('');
        $this->comment("Next: orbit node:provision {$node->id}");
        $this->line('');

        return self::SUCCESS;
    }
}
