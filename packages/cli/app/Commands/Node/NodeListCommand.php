<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use LaravelZero\Framework\Commands\Command;

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

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'nodes' => $nodes->map(fn ($node) => [
                    'id' => $node->id,
                    'name' => $node->name,
                    'host' => $node->host,
                    'user' => $node->user,
                    'port' => $node->port,
                    'node_type' => $node->node_type->value,
                    'status' => $node->status->value,
                    'is_active' => $node->is_active,
                ])->toArray(),
                'count' => $nodes->count(),
            ]);
        }

        if ($nodes->isEmpty()) {
            $this->warn('No nodes found');

            return self::SUCCESS;
        }

        $activeNodeId = Node::where('is_active', true)->value('id');

        $rows = $nodes->map(function ($node) use ($activeNodeId) {
            $active = $node->id === $activeNodeId ? '✓' : '';

            return [
                $node->id,
                $node->name,
                $node->node_type->value,
                $node->host,
                $node->status->value,
                $active,
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'Type', 'Host', 'Status', 'Active'],
            $rows
        );

        return self::SUCCESS;
    }
}
